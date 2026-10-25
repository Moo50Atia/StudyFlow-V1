<?php

namespace App\Services\V3\LocalWorker;

use App\Models\V3\Document;
use App\Models\V3\LocalArtifact;
use App\Models\V3\LocalJob;
use App\Models\V3\LocalTask;
use App\Models\V3\PipelineOutbox;
use App\Models\V3\SubjectRun;
use App\Models\V3\SubjectRunItem;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerProtocolException;
use App\Services\V3\LocalWorker\Protocol\LocalWorkerV1Validator;
use App\Services\V3\Settings\ApprovedTemplateRepository;
use App\Services\V3\Settings\SettingsUnavailable;
use App\Services\V3\WebPipeline\AcceptRunItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LocalWorkerAdmissionCoordinator
{
    private const TOTAL_INPUT_BUDGET = 4294967296; // 4 GiB, independently bounded from output reservations.

    public function __construct(
        protected AcceptRunItem $acceptRunItem
    ) {}

    /**
     * Idempotently admit a SubjectRun into the local worker queue.
     *
     * @param SubjectRun $run
     * @param string $outputScope 'prompts_only' or 'prompts_and_scenes'
     * @return LocalJob
     */
    public function admit(SubjectRun $run, string $outputScope = 'prompts_only'): LocalJob
    {
        return DB::transaction(function () use ($run, $outputScope) {
            $lockedRun = SubjectRun::where('id', $run->id)->lockForUpdate()->firstOrFail();

            $existingJob = LocalJob::where('run_id', $lockedRun->id)->lockForUpdate()->first();
            if ($existingJob) {
                if ($existingJob->output_scope !== $outputScope) {
                    throw new LocalWorkerProtocolException(
                        "Run already admitted under different output_scope '{$existingJob->output_scope}'",
                        'admission_conflict',
                        409
                    );
                }
                return $existingJob;
            }

            // 1. Authorize teacher still active
            $access = DB::table('v3_account_access')->where('user_id', $lockedRun->owner_user_id)->first();
            if (! $access || ! (bool) $access->is_active) {
                throw new LocalWorkerProtocolException(
                    'Teacher V3 access is inactive',
                    'teacher_inactive',
                    403
                );
            }

            // 2. Perform provider-free intake for each item
            $items = SubjectRunItem::where('run_id', $lockedRun->id)
                ->orderBy('pdf_ordinal', 'asc')
                ->get();

            if ($items->isEmpty()) {
                throw new LocalWorkerProtocolException('Subject run has no items', 'empty_run', 422);
            }

            foreach ($items as $item) {
                $intakeRes = $this->acceptRunItem->accept($item);
                if ($intakeRes['outcome'] !== 'completed') {
                    $lockedRun->update(['state' => 'failed']);
                    throw new LocalWorkerProtocolException(
                        "Run item {$item->pdf_ordinal} failed intake: " . ($intakeRes['safe_code'] ?? 'rejected'),
                        'intake_failed',
                        422
                    );
                }
            }

            // Reload accepted items
            $acceptedItems = SubjectRunItem::where('run_id', $lockedRun->id)
                ->orderBy('pdf_ordinal', 'asc')
                ->get();

            $jobId = (string) Str::uuid();
            $taskId = (string) Str::uuid();

            // 3. Build & store portable runtime settings artifact
            $capturedSettings = is_array($lockedRun->captured_settings_json)
                ? $lockedRun->captured_settings_json
                : json_decode((string) $lockedRun->captured_settings_json, true);
            if (!is_array($capturedSettings)) {
                throw new LocalWorkerProtocolException('Captured settings snapshot is unavailable.', 'settings_missing', 422);
            }
            // Publish only the settings fields required by the worker contract.
            // Provider credentials remain local; provider refs are identifiers, never secret values.
            $portableSettings = [
                'limits' => array_intersect_key(is_array($capturedSettings['limits'] ?? null) ? $capturedSettings['limits'] : [], array_flip([
                    'max_pdf_bytes', 'max_pdf_pages', 'max_ocr_pages', 'technical_retries',
                    'retry_delay_seconds', 'prompt_revisions', 'scene_repairs', 'coding_attempts',
                    'review_dormancy_days',
                ])),
                'qualification' => [],
                'providers' => [],
                'templates' => [],
            ];
            $qualificationSettings = is_array($capturedSettings['qualification'] ?? null) ? $capturedSettings['qualification'] : [];
            $providerSettings = is_array($capturedSettings['providers'] ?? null) ? $capturedSettings['providers'] : [];
            $templateSettings = is_array($capturedSettings['templates'] ?? null) ? $capturedSettings['templates'] : [];
            $sceneTemplate = $templateSettings['scene'] ?? null;
            if (!is_array($sceneTemplate) || !is_string($sceneTemplate['id'] ?? null)
                || trim($sceneTemplate['id']) === '' || !is_string($sceneTemplate['checksum'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $sceneTemplate['checksum'])) {
                throw new LocalWorkerProtocolException('Captured scene template identity or checksum is unavailable.', 'settings_missing', 422);
            }
            try {
                $sceneSystemPrompt = app(ApprovedTemplateRepository::class)->getCaptured(
                    $sceneTemplate['id'],
                    $sceneTemplate['checksum'],
                    'scene'
                );
            } catch (SettingsUnavailable $e) {
                throw new LocalWorkerProtocolException('Captured approved scene template is unavailable.', 'settings_missing', 422);
            }
            if ($sceneSystemPrompt === '' || strlen($sceneSystemPrompt) > 65536) {
                throw new LocalWorkerProtocolException('Captured approved scene template has an invalid size.', 'settings_invalid', 422);
            }
            foreach (['prompt', 'scene'] as $role) {
                $source = $qualificationSettings[$role] ?? null;
                $fields = array_intersect_key(is_array($source) ? $source : [], array_flip(['min_score', 'min_confidence']));
                if ($fields !== []) {
                    $portableSettings['qualification'][$role] = $fields;
                }
            }
            foreach (['prompt', 'feedback'] as $role) {
                $source = $providerSettings[$role] ?? null;
                $fields = array_intersect_key(is_array($source) ? $source : [], array_flip(['id', 'model', 'credential_ref']));
                if ($fields !== []) {
                    $portableSettings['providers'][$role] = $fields;
                }
            }
            if (isset($providerSettings['coding_order']) && is_array($providerSettings['coding_order'])) {
                $portableSettings['providers']['coding_order'] = array_values(array_map(
                    static fn ($provider) => is_array($provider)
                        ? array_intersect_key($provider, array_flip(['id', 'model', 'credential_ref']))
                        : $provider,
                    $providerSettings['coding_order']
                ));
            }
            foreach (['feedback', 'scene'] as $role) {
                $source = $templateSettings[$role] ?? null;
                $fields = array_intersect_key(is_array($source) ? $source : [], array_flip(['id', 'checksum']));
                if ($fields !== []) {
                    $portableSettings['templates'][$role] = $fields;
                }
            }
            $settingsPayload = [
                'schema_version' => 1,
                'settings_version_id' => (string) $lockedRun->settings_version_id,
                'settings_sha256' => $lockedRun->capture_sha256,
                'execution_mode' => 'local_worker_v1',
                'output_scope' => $outputScope,
                // These are maps even when empty. PHP otherwise emits [] and
                // violates the Python consumer's object-valued wire contract.
                'captured_settings' => array_map(static fn (array $group) => (object) $group, $portableSettings),
            ];
            $settingsJson = json_encode($settingsPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $settingsSha256 = hash('sha256', $settingsJson);
            $settingsPath = "local_worker/{$jobId}/runtime_settings.json";
            Storage::disk('v3_private')->put($settingsPath, $settingsJson);

            $settingsArtifact = LocalArtifact::create([
                'id' => (string) Str::uuid(),
                'job_id' => $jobId,
                'task_id' => $taskId,
                'purpose' => 'runtime_settings',
                'digest_sha256' => $settingsSha256,
                'size_bytes' => strlen($settingsJson),
                'media_type' => 'application/json',
                'logical_name' => 'runtime_settings.json',
                'storage_path' => $settingsPath,
                'is_quarantine' => false,
                'is_committed' => true,
            ]);

            // 4. Artifact list & manifest items
            $manifestItems = [];
            $allArtifacts = [
                [
                    'artifact_id' => $settingsArtifact->id,
                    'purpose' => 'runtime_settings',
                    'size_bytes' => $settingsArtifact->size_bytes,
                    'digest_sha256' => $settingsArtifact->digest_sha256,
                    'sha256' => $settingsArtifact->digest_sha256,
                    'media_type' => $settingsArtifact->media_type,
                    'logical_name' => $settingsArtifact->logical_name,
                ],
            ];

            // Seal the independently retained original PDFs, not aliases to the
            // prepared upload parts. The preparation capture pins the inventory;
            // re-verify both that inventory and each retained original before
            // advertising their identities to a worker.
            $prepCapture = is_array($lockedRun->pdf_preparation)
                ? $lockedRun->pdf_preparation
                : json_decode((string) $lockedRun->pdf_preparation, true);
            $expectedInventoryPath = 'web-preparations/' . $lockedRun->owner_user_id . '/'
                . strtolower((string) $lockedRun->submission_key) . '/package/inventory.json';
            $inventoryPath = is_array($prepCapture) ? ($prepCapture['inventory_ref'] ?? null) : null;
            $inventorySha = is_array($prepCapture) ? ($prepCapture['inventory_file_sha256'] ?? null) : null;
            $privateDisk = Storage::disk('v3_private');
            if ($inventoryPath !== $expectedInventoryPath || !is_string($inventorySha)
                || !$privateDisk->exists($expectedInventoryPath)) {
                throw new LocalWorkerProtocolException('Captured PDF preparation inventory is unavailable.', 'preparation_missing', 422);
            }
            $inventoryBytes = $privateDisk->get($expectedInventoryPath);
            if (!hash_equals($inventorySha, hash('sha256', $inventoryBytes))) {
                throw new LocalWorkerProtocolException('Captured PDF preparation inventory fingerprint changed.', 'preparation_changed', 409);
            }
            try {
                $inventory = json_decode($inventoryBytes, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new LocalWorkerProtocolException('Captured PDF preparation inventory is invalid.', 'preparation_invalid', 422);
            }
            $originals = $inventory['originals'] ?? null;
            $preparedPdfs = $inventory['prepared_pdfs'] ?? null;
            if (($inventory['kind'] ?? null) !== 'v3_pdf_preparation'
                || ($inventory['state'] ?? null) !== 'complete'
                || ($inventory['verification']['verified'] ?? false) !== true
                || !is_array($originals) || count($originals) !== (int) ($prepCapture['original_count'] ?? 0)
                || !is_array($preparedPdfs) || count($preparedPdfs) !== $acceptedItems->count()) {
                throw new LocalWorkerProtocolException('Captured PDF preparation inventory does not match the accepted run.', 'preparation_invalid', 422);
            }

            $originalArtifacts = [];
            foreach ($originals as $index => $original) {
                $sourceOrdinal = $index + 1;
                $ref = $original['retained_original_ref'] ?? null;
                $relativePath = is_array($ref) ? ($ref['path'] ?? null) : null;
                if (($original['source_ordinal'] ?? null) !== $sourceOrdinal
                    || !is_string($relativePath)
                    || !preg_match('#^originals/[^/\\\\]+$#D', $relativePath)
                    || ($ref['sha256'] ?? null) !== ($original['sha256'] ?? null)
                    || ($ref['bytes'] ?? null) !== ($original['bytes'] ?? null)) {
                    throw new LocalWorkerProtocolException('Captured original PDF identity is invalid.', 'preparation_invalid', 422);
                }
                $storagePath = dirname($expectedInventoryPath) . '/' . $relativePath;
                if (!$privateDisk->exists($storagePath)) {
                    throw new LocalWorkerProtocolException('Retained original PDF is unavailable.', 'preparation_missing', 422);
                }
                $bytes = $privateDisk->get($storagePath);
                $originalSha = (string) $original['sha256'];
                $originalBytes = (int) $original['bytes'];
                if (strlen($bytes) !== $originalBytes || !hash_equals($originalSha, hash('sha256', $bytes))) {
                    throw new LocalWorkerProtocolException('Retained original PDF fingerprint changed.', 'preparation_changed', 409);
                }
                $artifact = LocalArtifact::create([
                    'id' => (string) Str::uuid(),
                    'job_id' => $jobId,
                    'task_id' => $taskId,
                    'purpose' => 'original_pdf',
                    'digest_sha256' => $originalSha,
                    'size_bytes' => $originalBytes,
                    'media_type' => 'application/pdf',
                    'logical_name' => "original_{$sourceOrdinal}.pdf",
                    'storage_path' => $storagePath,
                    'is_quarantine' => false,
                    'is_committed' => true,
                ]);
                $originalArtifacts[$sourceOrdinal] = $artifact;
                $allArtifacts[] = [
                    'artifact_id' => $artifact->id,
                    'purpose' => 'original_pdf',
                    'size_bytes' => $artifact->size_bytes,
                    'digest_sha256' => $artifact->digest_sha256,
                    'sha256' => $artifact->digest_sha256,
                    'media_type' => $artifact->media_type,
                    'logical_name' => $artifact->logical_name,
                ];
            }

            $inventoryArtifact = LocalArtifact::create([
                'id' => (string) Str::uuid(),
                'job_id' => $jobId,
                'task_id' => $taskId,
                'purpose' => 'framework_body',
                'digest_sha256' => $inventorySha,
                'size_bytes' => strlen($inventoryBytes),
                'media_type' => 'application/json',
                'logical_name' => 'preparation_inventory.json',
                'storage_path' => $expectedInventoryPath,
                'is_quarantine' => false,
                'is_committed' => true,
            ]);
            $allArtifacts[] = [
                'artifact_id' => $inventoryArtifact->id,
                'purpose' => 'framework_body',
                'size_bytes' => $inventoryArtifact->size_bytes,
                'digest_sha256' => $inventoryArtifact->digest_sha256,
                'sha256' => $inventoryArtifact->digest_sha256,
                'media_type' => $inventoryArtifact->media_type,
                'logical_name' => $inventoryArtifact->logical_name,
            ];

            // Framework artifact
            $framework = DB::table('v3_notebook_framework_versions')
                ->where('id', $lockedRun->framework_version_id)
                ->first();
            $frameworkBody = Storage::disk('v3_private')->get($framework->body_path);
            $frameworkArtifact = LocalArtifact::create([
                'id' => (string) Str::uuid(),
                'job_id' => $jobId,
                'task_id' => $taskId,
                'purpose' => 'framework_body',
                'digest_sha256' => $framework->body_sha256,
                'size_bytes' => strlen($frameworkBody),
                'media_type' => 'text/plain',
                'logical_name' => 'framework.txt',
                'storage_path' => $framework->body_path,
                'is_quarantine' => false,
                'is_committed' => true,
            ]);

            $allArtifacts[] = [
                'artifact_id' => $frameworkArtifact->id,
                'purpose' => 'framework_body',
                'size_bytes' => $frameworkArtifact->size_bytes,
                'digest_sha256' => $frameworkArtifact->digest_sha256,
                'sha256' => $frameworkArtifact->digest_sha256,
                'media_type' => $frameworkArtifact->media_type,
                'logical_name' => $frameworkArtifact->logical_name,
            ];

            // Freeze the exact prompt/format protocol bytes used by this run.
            // The web application and worker load this shared, versioned bundle;
            // neither side may silently substitute a newer installed prompt.
            $protocolPath = base_path('v3_engine/notebooklm/protocols/local_worker_v1.json');
            if (! is_file($protocolPath) || is_link($protocolPath)) {
                throw new LocalWorkerProtocolException('Versioned NotebookLM authoring protocol bundle is unavailable.', 'protocol_missing', 503);
            }
            $protocolBytes = file_get_contents($protocolPath);
            if (! is_string($protocolBytes) || $protocolBytes === '') {
                throw new LocalWorkerProtocolException('Versioned NotebookLM authoring protocol bundle is unreadable.', 'protocol_missing', 503);
            }
            try {
                $protocolBundle = json_decode($protocolBytes, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new LocalWorkerProtocolException('Versioned NotebookLM authoring protocol bundle is invalid.', 'protocol_invalid', 503);
            }
            if (! is_array($protocolBundle)
                || ($protocolBundle['schema_version'] ?? null) !== 1
                || ($protocolBundle['protocol_id'] ?? null) !== 'notebooklm_v1'
                || ($protocolBundle['protocol_version'] ?? null) !== 'notebooklm_per_pdf_web_v1'
                || ! is_string($protocolBundle['divide_prompt'] ?? null)
                || ! is_string($protocolBundle['execution_prompt'] ?? null)
                || ! is_string($protocolBundle['lesson_start_prompt'] ?? null)
                || ! is_string($protocolBundle['lesson_continue_prompt'] ?? null)
                || ! is_string($protocolBundle['combined_response_format'] ?? null)
                || ! is_array($protocolBundle['framework_sections'] ?? null)
                || array_keys($protocolBundle['framework_sections']) !== ['first', 'second', 'third']
                || array_filter($protocolBundle['framework_sections'], fn ($section) => ! is_string($section) || trim($section) === '') !== []) {
                throw new LocalWorkerProtocolException('Versioned NotebookLM authoring protocol bundle has an unsupported schema.', 'protocol_invalid', 503);
            }
            $protocolSha256 = hash('sha256', $protocolBytes);
            $protocolArtifact = LocalArtifact::create([
                'id' => (string) Str::uuid(),
                'job_id' => $jobId,
                'task_id' => $taskId,
                'purpose' => 'instruction_bundle',
                'digest_sha256' => $protocolSha256,
                'size_bytes' => strlen($protocolBytes),
                'media_type' => 'application/json',
                'logical_name' => 'notebooklm_protocol_v1.json',
                'storage_path' => "local_worker/{$jobId}/notebooklm_protocol_v1.json",
                'is_quarantine' => false,
                'is_committed' => true,
            ]);
            $privateDisk->put($protocolArtifact->storage_path, $protocolBytes);
            $allArtifacts[] = [
                'artifact_id' => $protocolArtifact->id,
                'purpose' => 'instruction_bundle',
                'size_bytes' => $protocolArtifact->size_bytes,
                'digest_sha256' => $protocolArtifact->digest_sha256,
                'sha256' => $protocolArtifact->digest_sha256,
                'media_type' => $protocolArtifact->media_type,
                'logical_name' => $protocolArtifact->logical_name,
            ];

            // Include the approved scene system template itself. A checksum in
            // settings alone cannot prevent a worker laptop from silently
            // substituting its currently installed local template.
            $sceneTemplateArtifact = LocalArtifact::create([
                'id' => (string) Str::uuid(),
                'job_id' => $jobId,
                'task_id' => $taskId,
                'purpose' => 'instruction_bundle',
                'digest_sha256' => hash('sha256', $sceneSystemPrompt),
                'size_bytes' => strlen($sceneSystemPrompt),
                'media_type' => 'text/plain',
                'logical_name' => 'scene_system_template.txt',
                'storage_path' => "local_worker/{$jobId}/scene_system_template.txt",
                'is_quarantine' => false,
                'is_committed' => true,
            ]);
            $privateDisk->put($sceneTemplateArtifact->storage_path, $sceneSystemPrompt);
            $allArtifacts[] = [
                'artifact_id' => $sceneTemplateArtifact->id,
                'purpose' => 'instruction_bundle',
                'size_bytes' => $sceneTemplateArtifact->size_bytes,
                'digest_sha256' => $sceneTemplateArtifact->digest_sha256,
                'sha256' => $sceneTemplateArtifact->digest_sha256,
                'media_type' => $sceneTemplateArtifact->media_type,
                'logical_name' => $sceneTemplateArtifact->logical_name,
            ];

            foreach ($acceptedItems as $idx => $item) {
                $doc = Document::findOrFail($item->document_id);
                $docArtifact = LocalArtifact::create([
                    'id' => (string) Str::uuid(),
                    'job_id' => $jobId,
                    'task_id' => $taskId,
                    'purpose' => 'prepared_pdf',
                    'digest_sha256' => $doc->sha256,
                    'size_bytes' => $doc->byte_size,
                    'media_type' => 'application/pdf',
                    'logical_name' => "part_{$item->pdf_ordinal}.pdf",
                    'storage_path' => $doc->storage_path,
                    'is_quarantine' => false,
                    'is_committed' => true,
                ]);

                $allArtifacts[] = [
                    'artifact_id' => $docArtifact->id,
                    'purpose' => 'prepared_pdf',
                    'size_bytes' => $docArtifact->size_bytes,
                    'digest_sha256' => $docArtifact->digest_sha256,
                    'sha256' => $docArtifact->digest_sha256,
                    'media_type' => $docArtifact->media_type,
                    'logical_name' => $docArtifact->logical_name,
                ];

                $lineage = is_array($item->pdf_lineage) ? $item->pdf_lineage : json_decode((string)$item->pdf_lineage, true);

                $sourceOrdinal = (int) ($lineage['source_ordinal'] ?? 0);
                $originalArtifact = $originalArtifacts[$sourceOrdinal] ?? null;
                $inventoryPart = $preparedPdfs[$idx] ?? null;
                if (!$originalArtifact || !is_array($inventoryPart)
                    || ($inventoryPart['upload_ordinal'] ?? null) !== $idx + 1
                    || ($inventoryPart['source_ordinal'] ?? null) !== $sourceOrdinal
                    || ($inventoryPart['part_ordinal'] ?? null) !== (int) ($lineage['part_ordinal'] ?? 0)
                    || ($inventoryPart['artifact_ref']['sha256'] ?? null) !== $doc->sha256
                    || ($inventoryPart['artifact_ref']['bytes'] ?? null) !== $doc->byte_size
                    || ($inventoryPart['page_count'] ?? null) !== (int) ($doc->page_count ?: 1)
                    || ($lineage['original_sha256'] ?? null) !== $originalArtifact->digest_sha256
                    || ($lineage['original_filename'] ?? null) !== ($originals[$sourceOrdinal - 1]['original_filename'] ?? null)
                    || ($lineage['original_page_count'] ?? null) !== ($originals[$sourceOrdinal - 1]['page_count'] ?? null)
                    || ($lineage['start_page'] ?? null) !== ($inventoryPart['start_page'] ?? null)
                    || ($lineage['end_page'] ?? null) !== ($inventoryPart['end_page'] ?? null)
                    || ($lineage['page_count'] ?? null) !== ($inventoryPart['page_count'] ?? null)) {
                    throw new LocalWorkerProtocolException('Accepted prepared PDF differs from the sealed source inventory.', 'preparation_changed', 409);
                }

                $manifestItems[] = [
                    'item_id' => $item->id,
                    'original_artifact_id' => $originalArtifact->id,
                    'original_sha256' => $originalArtifact->digest_sha256,
                    'original_byte_count' => $originalArtifact->size_bytes,
                    'original_page_count' => (int) $originals[$sourceOrdinal - 1]['page_count'],
                    'upload_ordinal' => (int) ($lineage['upload_ordinal'] ?? $idx + 1),
                    'source_ordinal' => $sourceOrdinal,
                    'part_ordinal' => (int) ($lineage['part_ordinal'] ?? $idx + 1),
                    'original_page_start' => (int) $inventoryPart['start_page'],
                    'original_page_end' => (int) $inventoryPart['end_page'],
                    'prepared_artifact_id' => $docArtifact->id,
                    'prepared_sha256' => $doc->sha256,
                    'prepared_byte_count' => (int) $doc->byte_size,
                    'prepared_page_count' => (int) ($doc->page_count ?: 1),
                ];
            }

            $inputBytes = array_sum(array_map(static fn (array $artifact) => (int) ($artifact['size_bytes'] ?? 0), $allArtifacts));
            if ($inputBytes > self::TOTAL_INPUT_BUDGET) {
                throw new LocalWorkerProtocolException('Sealed worker inputs exceed the 4 GiB input budget.', 'input_budget_exceeded', 413);
            }

            // 5. Build sealed input manifest
            $invArtifactId = $inventoryArtifact->id;
            $invSha = $inventoryArtifact->digest_sha256;

            $manifestData = [
                'schema_version' => 1,
                'kind' => 'local_worker_input_manifest',
                'task_id' => $taskId,
                'job_id' => $jobId,
                'run_id' => $lockedRun->id,
                'task_kind' => 'author_prompts',
                'execution_mode' => 'local_worker_v1',
                'output_scope' => $outputScope,
                'protocol_id' => $protocolBundle['protocol_id'],
                'protocol_version' => $protocolBundle['protocol_version'],
                'settings_snapshot_id' => (string) $lockedRun->settings_version_id,
                'settings_sha256' => $lockedRun->capture_sha256,
                'runtime_settings_artifact_id' => $settingsArtifact->id,
                'runtime_settings_sha256' => $settingsArtifact->digest_sha256,
                'preparation' => [
                    'policy_version' => $prepCapture['policy']['version'] ?? ($prepCapture['policy_version'] ?? 'v2'),
                    'trigger_pages' => $prepCapture['policy']['split_trigger_pages'] ?? ($prepCapture['trigger_pages'] ?? 50),
                    'max_part_pages' => $prepCapture['policy']['max_part_pages'] ?? ($prepCapture['max_part_pages'] ?? 50),
                    'inventory_artifact_id' => $invArtifactId,
                    'inventory_sha256' => $invSha,
                ],
                'items' => $manifestItems,
                'instruction_artifacts' => [
                    [
                        'name' => 'framework',
                        'version' => (string) $lockedRun->framework_version_id,
                        'artifact_id' => $frameworkArtifact->id,
                        'sha256' => $frameworkArtifact->digest_sha256,
                        'size_bytes' => $frameworkArtifact->size_bytes,
                    ],
                    [
                        'name' => 'notebooklm_protocol',
                        'version' => $protocolBundle['protocol_version'],
                        'artifact_id' => $protocolArtifact->id,
                        'sha256' => $protocolArtifact->digest_sha256,
                        'size_bytes' => $protocolArtifact->size_bytes,
                    ],
                    [
                        'name' => 'scene_system_template',
                        'version' => $sceneTemplate['id'],
                        'artifact_id' => $sceneTemplateArtifact->id,
                        'sha256' => $sceneTemplateArtifact->digest_sha256,
                        'size_bytes' => $sceneTemplateArtifact->size_bytes,
                    ],
                ],
                'artifacts' => $allArtifacts,
                'required_outputs' => [
                    'profile' => 'authoring_prompts_v1',
                    'format' => 'v3_notebooklm_scene_prompt_export',
                ],
                'resource_budget' => [
                    'max_provider_effects' => 100,
                    'max_output_bytes' => 536870912, // 512 MiB
                    'timeout_seconds' => 7200,
                ],
                'capabilities' => ['notebooklm_v1', 'static_three_file_v1'],
            ];

            // Validate against protocol specification
            LocalWorkerV1Validator::validateInputManifest($manifestData);

            $manifestJson = json_encode($manifestData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $manifestSha256 = hash('sha256', $manifestJson);
            $manifestPath = "local_worker/{$jobId}/input_manifest.json";
            Storage::disk('v3_private')->put($manifestPath, $manifestJson);

            $manifestArtifact = LocalArtifact::create([
                'id' => (string) Str::uuid(),
                'job_id' => $jobId,
                'task_id' => $taskId,
                'purpose' => 'input_manifest',
                'digest_sha256' => $manifestSha256,
                'size_bytes' => strlen($manifestJson),
                'media_type' => 'application/json',
                'logical_name' => 'input_manifest.json',
                'storage_path' => $manifestPath,
                'is_quarantine' => false,
                'is_committed' => true,
            ]);

            // 6. Create LocalJob and LocalTask
            $job = LocalJob::create([
                'id' => $jobId,
                'run_id' => $lockedRun->id,
                'teacher_id' => $lockedRun->owner_user_id,
                'execution_mode' => 'local_worker_v1',
                'output_scope' => $outputScope,
                'status' => 'queued',
                'settings_snapshot_id' => (string) $lockedRun->settings_version_id,
                'settings_sha256' => $lockedRun->capture_sha256,
                'runtime_settings_artifact_id' => $settingsArtifact->id,
                'policy_budgets' => $manifestData['resource_budget'],
                'control_version' => 1,
            ]);

            LocalTask::create([
                'id' => $taskId,
                'job_id' => $jobId,
                'task_kind' => 'author_prompts',
                'status' => 'queued',
                'input_manifest_id' => $manifestArtifact->id,
                'input_manifest_sha256' => $manifestSha256,
                'control_version' => 1,
                'active_epoch' => 0,
            ]);

            // 7. Update SubjectRun
            $lockedRun->execution_mode = 'local_worker_v1';
            $lockedRun->state = 'queued';
            $lockedRun->save();

            // 8. Cancel/acknowledge any legacy outbox entry to prevent legacy bridge execution
            PipelineOutbox::whereHas('command', fn ($q) => $q->where('run_id', $lockedRun->id))
                ->where('destination', 'application_bridge')
                ->where('state', 'pending')
                ->update([
                    'state' => 'acknowledged',
                    'safe_code' => 'handled_by_local_worker',
                ]);

            return $job;
        });
    }
}
