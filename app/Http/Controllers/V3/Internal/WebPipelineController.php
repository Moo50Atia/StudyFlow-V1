<?php

namespace App\Http\Controllers\V3\Internal;

use App\Http\Controllers\Controller;
use App\Models\V3\PipelineCommand;
use App\Models\V3\PipelineEvent;
use App\Models\V3\Document;
use App\Models\V3\NotebookFrameworkVersion;
use App\Models\V3\NotebookInstance;
use App\Models\V3\KnowledgeSourceBinding;
use App\Models\V3\KnowledgeSourceFile;
use App\Models\V3\SubjectRun;
use App\Models\V3\LessonMap;
use App\Models\V3\LessonCompletion;
use App\Models\V3\SubjectRunItem;
use App\Services\V3\Intake\PrivatePdfStore;
use App\Services\V3\WebPipeline\AcceptRunItem;
use App\Services\V3\WebPipeline\AcceptWebLesson;
use App\Services\V3\WebPipeline\ClaimOperation;
use App\Services\V3\WebPipeline\CommitOperation;
use App\Services\V3\WebPipeline\RunEventStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class WebPipelineController extends Controller
{
    protected const MAX_METADATA_BYTES = 32768; // 32 KiB

    /** Accept and validate one staged PDF before the worker uploads it to NotebookLM. */
    public function prepareRunItem(Request $request, string $item, AcceptRunItem $acceptor): JsonResponse
    {
        $payload = $request->json()->all();
        if (array_diff(array_keys($payload), ['run_id'])) {
            return response()->json(['error' => ['code' => 'contract_invalid', 'message' => 'Unknown input preparation fields']], 422);
        }

        $runId = $payload['run_id'] ?? '';
        $subjectItem = SubjectRunItem::where('id', $item)->where('run_id', $runId)->first();
        if (! $subjectItem) {
            return response()->json(['error' => ['code' => 'artifact_missing', 'message' => 'Run item not found']], 404);
        }

        $result = $acceptor->accept($subjectItem);
        if (($result['outcome'] ?? null) !== 'completed') {
            return response()->json([
                'outcome' => $result['outcome'] ?? 'failed',
                'safe_code' => $result['safe_code'] ?? 'source_error',
                'item_id' => $subjectItem->id,
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        $subjectItem->refresh();
        $document = Document::find($subjectItem->document_id);
        if (! $document || $document->state !== 'ready' || $document->id !== $subjectItem->accepted_input_ref) {
            return response()->json(['error' => ['code' => 'artifact_missing', 'message' => 'Accepted input receipt is incomplete']], 409);
        }

        return response()->json([
            'outcome' => 'completed',
            'item_id' => $subjectItem->id,
            'case_id' => $subjectItem->case_id,
            'binding_ref' => KnowledgeSourceBinding::where('case_id', $subjectItem->case_id)->value('id'),
            'input_ref' => $document->id,
            'input_sha256' => $document->sha256,
            'input_bytes' => (int) $document->byte_size,
            'page_count' => (int) $document->page_count,
        ])->header('Cache-Control', 'private, no-store');
    }

    /** Persist the run's private shared NotebookLM identifiers in Laravel. */
    public function recordRunNotebook(Request $request, string $run): JsonResponse
    {
        $payload = $request->json()->all();
        $allowed = ['notebook_id', 'framework_source_id', 'framework_sha256', 'configured'];
        if (array_diff(array_keys($payload), $allowed)
            || ! is_string($payload['notebook_id'] ?? null)
            || $payload['notebook_id'] === '' || strlen($payload['notebook_id']) > 255) {
            return response()->json(['error' => ['code' => 'contract_invalid', 'message' => 'Notebook receipt is invalid']], 422);
        }

        $instance = NotebookInstance::where('run_id', $run)->first();
        if (! $instance) {
            return response()->json(['error' => ['code' => 'artifact_missing', 'message' => 'Run notebook is unavailable']], 404);
        }

        $frameworkSourceId = $payload['framework_source_id'] ?? null;
        if ($frameworkSourceId !== null && (! is_string($frameworkSourceId) || $frameworkSourceId === '' || strlen($frameworkSourceId) > 255)) {
            return response()->json(['error' => ['code' => 'contract_invalid', 'message' => 'Framework source receipt is invalid']], 422);
        }

        $configured = $payload['configured'] ?? false;
        if (! is_bool($configured)) {
            return response()->json(['error' => ['code' => 'contract_invalid', 'message' => 'Configuration receipt is invalid']], 422);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($instance, $payload, $frameworkSourceId, $configured) {
            $locked = NotebookInstance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $notebookId = $payload['notebook_id'];
            $otherRunUsesNotebook = NotebookInstance::where('id', '!=', $locked->id)
                ->where('provider_notebook_id', $notebookId)->exists();
            if ($otherRunUsesNotebook || ($locked->provider_notebook_id && $locked->provider_notebook_id !== $notebookId)
                || ($frameworkSourceId && $locked->provider_framework_source_id
                    && $locked->provider_framework_source_id !== $frameworkSourceId)) {
                return response()->json(['error' => ['code' => 'operation_conflict', 'message' => 'Notebook receipt conflicts with captured run']], 409);
            }

            $locked->provider_notebook_id = $notebookId;
            if ($frameworkSourceId !== null) {
                $framework = NotebookFrameworkVersion::find($locked->run->framework_version_id);
                if (! $framework || ! is_string($payload['framework_sha256'] ?? null)
                    || ! hash_equals($framework->body_sha256, strtolower($payload['framework_sha256']))) {
                    return response()->json(['error' => ['code' => 'artifact_missing', 'message' => 'Captured framework is unavailable']], 404);
                }
                $locked->provider_framework_source_id = $frameworkSourceId;
            }
            if ($configured) {
                if (! $locked->provider_framework_source_id || ! $locked->provider_notebook_id) {
                    return response()->json(['error' => ['code' => 'source_error', 'message' => 'Framework source is not registered']], 409);
                }
                $locked->state = 'ready';
            } else {
                $locked->state = $frameworkSourceId ? 'framework_ready' : 'created';
            }
            $locked->save();

            if ($locked->provider_framework_source_id) {
                $items = \App\Models\V3\SubjectRunItem::where('run_id', $locked->run_id)->whereNotNull('case_id')->pluck('case_id');
                foreach ($items as $caseId) {
                    $binding = KnowledgeSourceBinding::where('case_id', $caseId)->first();
                    if (! $binding || $binding->notebook_instance_id !== $locked->id) {
                        continue;
                    }
                    $frameworkFile = KnowledgeSourceFile::where('binding_id', $binding->id)->where('role', 'framework_steps')->first();
                    if ($frameworkFile) {
                        $frameworkFile->update([
                            'provider_source_id' => $locked->provider_framework_source_id,
                            'state' => 'ready',
                            'registered_at' => $frameworkFile->registered_at ?? now(),
                            'ready_at' => now(),
                        ]);
                    }
                    if ($configured && KnowledgeSourceFile::where('binding_id', $binding->id)->where('role', 'educational_pdf')->where('state', 'ready')->exists()) {
                        $binding->update(['chat_configuration_state' => 'ready', 'state' => 'bound', 'transfer_status' => 'confirmed']);
                    }
                }
            }

            return response()->json(['outcome' => 'completed', 'safe_code' => 'ok'], 200)->header('Cache-Control', 'private, no-store');
        });
    }

    /** Record the exact source ID returned after uploading one accepted PDF. */
    public function recordRunItemSource(Request $request, string $item): JsonResponse
    {
        $payload = $request->json()->all();
        if (array_diff(array_keys($payload), ['run_id', 'source_id', 'input_sha256'])
            || ! is_string($payload['run_id'] ?? null)
            || ! is_string($payload['source_id'] ?? null) || $payload['source_id'] === '' || strlen($payload['source_id']) > 255
            || ! is_string($payload['input_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/i', $payload['input_sha256'])) {
            return response()->json(['error' => ['code' => 'contract_invalid', 'message' => 'Source receipt is invalid']], 422);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($item, $payload) {
            $subjectItem = SubjectRunItem::where('id', $item)->where('run_id', $payload['run_id'])->lockForUpdate()->first();
            $document = $subjectItem ? Document::find($subjectItem->document_id) : null;
            $binding = $subjectItem ? KnowledgeSourceBinding::where('case_id', $subjectItem->case_id)->first() : null;
            if (! $subjectItem || $subjectItem->state !== 'accepted' || ! $document || ! $binding
                || ! hash_equals($document->sha256, strtolower($payload['input_sha256']))) {
                return response()->json(['error' => ['code' => 'artifact_missing', 'message' => 'Accepted PDF source target is unavailable']], 409);
            }
            $notebook = NotebookInstance::whereKey($binding->notebook_instance_id)->first();
            if (! $notebook || $notebook->run_id !== $subjectItem->run_id || ! $notebook->provider_notebook_id) {
                return response()->json(['error' => ['code' => 'source_error', 'message' => 'Run notebook is not ready']], 409);
            }
            $file = KnowledgeSourceFile::where('binding_id', $binding->id)->where('role', 'educational_pdf')->firstOrFail();
            if ($file->artifact_sha256 !== $document->sha256 || (int) $file->artifact_bytes !== (int) $document->byte_size
                || ($file->provider_source_id && $file->provider_source_id !== $payload['source_id'])) {
                return response()->json(['error' => ['code' => 'operation_conflict', 'message' => 'PDF source receipt conflicts with accepted input']], 409);
            }
            $file->update([
                'provider_source_id' => $payload['source_id'], 'state' => 'ready',
                'registered_at' => $file->registered_at ?? now(), 'ready_at' => now(),
            ]);
            $frameworkReady = KnowledgeSourceFile::where('binding_id', $binding->id)->where('role', 'framework_steps')->where('state', 'ready')->exists();
            if ($frameworkReady && $notebook->state === 'ready') {
                $binding->update(['chat_configuration_state' => 'ready', 'state' => 'bound', 'transfer_status' => 'confirmed']);
            }

            return response()->json(['outcome' => 'completed', 'safe_code' => 'ok'], 200)->header('Cache-Control', 'private, no-store');
        });
    }

    /** Finalize one PDF only after its saved Laravel map/bundles match the receipt. */
    public function recordRunItemArchive(Request $request, string $item): JsonResponse
    {
        $payload = $request->json()->all();
        $allowed = ['run_id', 'work_count', 'lessons_saved', 'prompts_saved', 'source_sha256', 'archive_sha256'];
        if (array_diff(array_keys($payload), $allowed)
            || ! is_string($payload['run_id'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/i', $payload['source_sha256'] ?? '')
            || ! preg_match('/^[a-f0-9]{64}$/i', $payload['archive_sha256'] ?? '')
            || ! is_int($payload['work_count'] ?? null) || ! is_int($payload['lessons_saved'] ?? null)
            || ! is_int($payload['prompts_saved'] ?? null)) {
            return response()->json(['error' => ['code' => 'contract_invalid', 'message' => 'PDF archive receipt is invalid']], 422);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($item, $payload) {
            $subjectItem = SubjectRunItem::where('id', $item)->where('run_id', $payload['run_id'])->lockForUpdate()->first();
            if ($subjectItem && in_array($subjectItem->state, ['archived', 'completed'], true)
                && (int) $subjectItem->work_count === $payload['work_count']
                && (int) $subjectItem->saved_lesson_count === $payload['lessons_saved']
                && (int) $subjectItem->prompt_count === $payload['prompts_saved']
                && hash_equals((string) $subjectItem->input_sha256, strtolower($payload['source_sha256']))) {
                return response()->json([
                    'outcome' => 'completed', 'safe_code' => 'ok', 'archive_ref' => $subjectItem->archive_ref,
                    'counts' => ['lessons_saved' => (int) $subjectItem->saved_lesson_count, 'prompts_saved' => (int) $subjectItem->prompt_count],
                ], 200)->header('Cache-Control', 'private, no-store');
            }
            if (! $subjectItem || $subjectItem->state !== 'accepted' || ! $subjectItem->case_id
                || ! hash_equals((string) $subjectItem->input_sha256, strtolower($payload['source_sha256']))) {
                return response()->json(['error' => ['code' => 'artifact_missing', 'message' => 'PDF archive target is unavailable']], 409);
            }
            $map = LessonMap::where('case_id', $subjectItem->case_id)->where('state', 'ready')->first();
            $completions = LessonCompletion::where('case_id', $subjectItem->case_id)->get();
            $actualPrompts = $completions->sum(fn ($completion) => count($completion->third_output_refs ?? []));
            if (! $map || (int) $map->work_count !== $payload['work_count']
                || $completions->count() !== $payload['work_count']
                || $payload['lessons_saved'] !== $completions->count()
                || $payload['prompts_saved'] !== $actualPrompts
                || $actualPrompts < 1) {
                return response()->json(['error' => ['code' => 'receipt_invalid', 'message' => 'PDF archive counts do not match saved lesson evidence']], 409);
            }

            $receiptRef = (string) \Illuminate\Support\Str::uuid();
            $receipt = [
                'schema_version' => 1,
                'run_id' => $subjectItem->run_id,
                'item_id' => $subjectItem->id,
                'case_id' => $subjectItem->case_id,
                'pdf_ordinal' => (int) $subjectItem->pdf_ordinal,
                'source_sha256' => strtolower($payload['source_sha256']),
                'archive_sha256' => strtolower($payload['archive_sha256']),
                'map_ref' => $map->id,
                'work_count' => (int) $map->work_count,
                'lessons_saved' => $completions->count(),
                'prompts_saved' => $actualPrompts,
                'completion_refs' => $completions->pluck('completion_ref')->values()->all(),
            ];
            Storage::disk('v3_private')->put("artifacts/{$receiptRef}", json_encode($receipt, JSON_UNESCAPED_SLASHES));
            $subjectItem->update([
                'state' => 'archived', 'outcome' => 'completed', 'safe_code' => null,
                'work_count' => (int) $map->work_count,
                'saved_lesson_count' => $completions->count(),
                'prompt_count' => $actualPrompts,
                'archive_ref' => $receiptRef,
                'archive_sha256' => hash_file('sha256', Storage::disk('v3_private')->path("artifacts/{$receiptRef}")),
                'state_version' => $subjectItem->state_version + 1,
            ]);
            $run = SubjectRun::whereKey($subjectItem->run_id)->lockForUpdate()->firstOrFail();
            return response()->json([
                'outcome' => 'completed', 'safe_code' => 'ok', 'archive_ref' => $receiptRef,
                'counts' => ['lessons_saved' => $completions->count(), 'prompts_saved' => $actualPrompts],
            ], 200)->header('Cache-Control', 'private, no-store');
        });
    }

    /** Mark the PDF complete only after NotebookLM history is verified empty. */
    public function markRunItemResetVerified(Request $request, string $item): JsonResponse
    {
        $payload = $request->json()->all();
        if (array_diff(array_keys($payload), ['run_id', 'archive_ref', 'reset_history_sha256'])
            || ! is_string($payload['run_id'] ?? null) || ! is_string($payload['archive_ref'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/i', $payload['reset_history_sha256'] ?? '')) {
            return response()->json(['error' => ['code' => 'contract_invalid', 'message' => 'Reset receipt is invalid']], 422);
        }
        return \Illuminate\Support\Facades\DB::transaction(function () use ($item, $payload) {
            $subjectItem = SubjectRunItem::where('id', $item)->where('run_id', $payload['run_id'])->lockForUpdate()->first();
            if (! $subjectItem || ! $subjectItem->archive_ref || $subjectItem->archive_ref !== $payload['archive_ref']) {
                return response()->json(['error' => ['code' => 'receipt_invalid', 'message' => 'No matching committed archive exists']], 409);
            }
            if ($subjectItem->state === 'completed') {
                return response()->json(['outcome' => 'completed', 'safe_code' => 'ok'], 200)->header('Cache-Control', 'private, no-store');
            }
            if ($subjectItem->state !== 'archived') {
                return response()->json(['error' => ['code' => 'operation_conflict', 'message' => 'PDF archive is not ready']], 409);
            }
            $subjectItem->update(['state' => 'completed', 'state_version' => $subjectItem->state_version + 1]);
            $run = SubjectRun::whereKey($subjectItem->run_id)->lockForUpdate()->firstOrFail();
            $allCompleted = ! SubjectRunItem::where('run_id', $run->id)->where('state', '!=', 'completed')->exists();
            $run->update([
                'state' => $allCompleted ? 'completed' : 'running',
                'total_lessons' => (int) SubjectRunItem::where('run_id', $run->id)->sum('saved_lesson_count'),
                'total_scenes' => (int) SubjectRunItem::where('run_id', $run->id)->sum('prompt_count'),
                'last_updated_at' => now(),
                'state_version' => $run->state_version + 1,
            ]);
            return response()->json(['outcome' => 'completed', 'safe_code' => 'ok'], 200)->header('Cache-Control', 'private, no-store');
        });
    }

    /** Build the downloadable export from prompt versions actually stored for this run. */
    public function exportRunPrompts(string $run): JsonResponse
    {
        $subjectRun = SubjectRun::find($run);
        if (! $subjectRun) {
            return response()->json(['error' => ['code' => 'artifact_missing', 'message' => 'Run is unavailable']], 404);
        }
        $data = app(\App\Services\V3\WebPipeline\ExportScenePrompts::class)->export($subjectRun);
        if (($data['total_prompts'] ?? 0) < 1) {
            return response()->json(['error' => ['code' => 'context_incomplete', 'message' => 'No saved scene prompts are available']], 409);
        }
        $ref = (string) \Illuminate\Support\Str::uuid();
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        Storage::disk('v3_private')->put("artifacts/{$ref}", $encoded);
        return response()->json([
            'outcome' => 'completed', 'safe_code' => 'ok', 'artifact_ref' => $ref,
            'result_sha256' => hash('sha256', $encoded),
            'counts' => ['prompts_saved' => (int) $data['total_prompts']],
        ], 200)->header('Cache-Control', 'private, no-store');
    }

    /** Stream only an accepted, hash-verified private PDF to the signed worker. */
    public function acceptedInput(string $item, PrivatePdfStore $store): Response
    {
        $subjectItem = SubjectRunItem::where('id', $item)->where('state', 'accepted')->first();
        $document = $subjectItem ? Document::find($subjectItem->document_id) : null;
        if (! $subjectItem || ! $document || $document->state !== 'ready'
            || $subjectItem->accepted_input_ref !== $document->id
            || ! $store->exists($document->storage_path)) {
            return response()->json(['error' => ['code' => 'artifact_missing', 'message' => 'Accepted PDF is unavailable']], 404);
        }

        $path = $store->fullPath($document->storage_path);
        if (is_link($path) || $store->size($document->storage_path) !== (int) $document->byte_size
            || $store->sha256($document->storage_path) !== $document->sha256) {
            return response()->json(['error' => ['code' => 'artifact_corrupt', 'message' => 'Accepted PDF integrity check failed']], 409);
        }

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $document->id . '.pdf"',
        ]);
    }

    /** Stream the exact immutable framework bytes captured for this web run. */
    public function frameworkBody(string $version): Response
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $version)) {
            return response()->json(['error' => ['code' => 'contract_invalid', 'message' => 'Invalid framework reference']], 422);
        }

        $record = NotebookFrameworkVersion::find($version);
        $disk = Storage::disk('v3_private');
        if (! $record || ! $disk->exists($record->body_path)) {
            return response()->json(['error' => ['code' => 'artifact_missing', 'message' => 'Framework artifact is unavailable']], 404);
        }

        $path = $disk->path($record->body_path);
        $root = realpath($disk->path(''));
        $resolved = realpath($path);
        if (! $root || ! $resolved || is_link($path)
            || ! str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            || filesize($resolved) !== (int) $record->body_bytes
            || hash_file('sha256', $resolved) !== $record->body_sha256) {
            return response()->json(['error' => ['code' => 'artifact_corrupt', 'message' => 'Framework integrity check failed']], 409);
        }

        return response()->file($resolved, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * POST /claims
     */
    public function claim(Request $request, ClaimOperation $service): JsonResponse
    {
        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_METADATA_BYTES) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Claim payload exceeds 32 KiB limit',
                ],
            ], 422);
        }

        $allowedKeys = ['run_id', 'operation_id', 'kind', 'item_id', 'expected_version', 'request_sha256'];
        $payload = $request->json()->all();
        $diff = array_diff(array_keys($payload), $allowedKeys);
        if (! empty($diff)) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Unknown keys in claim request: ' . implode(', ', $diff),
                ],
            ], 422);
        }

        $res = $service->claim($payload);
        $status = $res['status'] ?? 201;
        unset($res['status']);

        return response()->json($res, $status)->header('Cache-Control', 'private, no-store');
    }

    /**
     * GET /contexts/{ref}
     */
    public function getContext(Request $request, string $ref): JsonResponse
    {
        $cached = Cache::get("v3_web_context:{$ref}");
        if ($cached) {
            return response()->json($cached)->header('Cache-Control', 'private, no-store');
        }

        $path = "contexts/{$ref}.json";
        if (Storage::disk('v3_private')->exists($path)) {
            $data = json_decode(Storage::disk('v3_private')->get($path), true);
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }

        return response()->json([
            'error' => [
                'code' => 'artifact_missing',
                'message' => "Context {$ref} not found",
            ],
        ], 404)->header('Cache-Control', 'private, no-store');
    }

    /**
     * GET /manifests/{ref}
     */
    public function getManifest(Request $request, string $ref, \App\Services\V3\WebPipeline\CaptureSubjectRunManifest $manifestService): JsonResponse
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ref)) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => "Invalid manifest reference UUID: {$ref}",
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        try {
            $manifest = $manifestService->resolve($ref);
            return response()->json($manifest)->header('Cache-Control', 'private, no-store');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'error' => [
                    'code' => $e->getStatusCode() === 404 ? 'artifact_missing' : 'contract_invalid',
                    'message' => $e->getMessage(),
                ],
            ], $e->getStatusCode())->header('Cache-Control', 'private, no-store');
        }
    }

    /**
     * POST /results
     */
    public function result(Request $request, CommitOperation $service, AcceptWebLesson $acceptor): JsonResponse
    {
        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_METADATA_BYTES) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Result payload exceeds 32 KiB limit',
                ],
            ], 422);
        }

        $allowedKeys = [
            'operation_id',
            'lease_token',
            'expected_version',
            'result_ref',
            'result_sha256',
            'outcome',
            'safe_code',
            'bundle',
        ];
        $payload = $request->json()->all();
        $diff = array_diff(array_keys($payload), $allowedKeys);
        if (! empty($diff)) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Unknown keys in result request: ' . implode(', ', $diff),
                ],
            ], 422);
        }

        $res = DB::transaction(function () use (&$payload, $service, $acceptor) {
            if (isset($payload['bundle']) && is_array($payload['bundle'])) {
                $bundleResult = $acceptor->accept($payload['bundle']);
                if (empty($payload['result_ref']) && ! empty($bundleResult['prompt_version_id'])) {
                    $payload['result_ref'] = $bundleResult['prompt_version_id'];
                }
            }

            return $service->commit($payload);
        });

        $status = $res['status'] ?? 201;
        unset($res['status']);

        return response()->json($res, $status)->header('Cache-Control', 'private, no-store');
    }

    /**
     * GET /commands/{command}/receipt
     */
    public function commandReceipt(Request $request, string $commandId): JsonResponse
    {
        $cmd = PipelineCommand::find($commandId);
        if (! $cmd) {
            return response()->json([
                'error' => [
                    'code' => 'command_missing',
                    'message' => "Command {$commandId} not found",
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        return response()->json([
            'command_id' => $cmd->id,
            'state' => $cmd->state,
            'consumed' => in_array($cmd->state, ['applied', 'consumed', 'rejected']),
            'request_sha256' => $cmd->request_sha256,
            'expected_version' => $cmd->expected_version,
            'safe_code' => $cmd->safe_code,
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * POST /events
     */
    public function recordEvent(Request $request, RunEventStore $eventStore): JsonResponse
    {
        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_METADATA_BYTES) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Event payload exceeds 32 KiB limit',
                ],
            ], 422);
        }

        $allowedKeys = [
            'id',
            'run_id',
            'operation_id',
            'attempt',
            'kind',
            'phase',
            'outcome',
            'safe_code',
            'counts',
            'expected_version',
        ];
        $payload = $request->json()->all();
        $diff = array_diff(array_keys($payload), $allowedKeys);
        if (! empty($diff)) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Unknown keys in event payload: ' . implode(', ', $diff),
                ],
            ], 422);
        }

        $eventId = $payload['id'] ?? null;
        if ($eventId) {
            $existing = PipelineEvent::find($eventId);
            if ($existing) {
                return response()->json([
                    'status' => 'duplicate',
                    'event_id' => $existing->id,
                    'sequence' => $existing->sequence,
                ], 200)->header('Cache-Control', 'private, no-store');
            }
        }

        $runId = $payload['run_id'] ?? null;
        if (! $runId) {
            return response()->json([
                'error' => [
                    'code' => 'contract_invalid',
                    'message' => 'Missing run_id in event payload',
                ],
            ], 422);
        }

        $event = $eventStore->recordEvent($runId, $payload);

        return response()->json([
            'status' => 'created',
            'event_id' => $event->id,
            'sequence' => $event->sequence,
        ], 201)->header('Cache-Control', 'private, no-store');
    }

    /**
     * GET /artifacts/{ref}
     */
    public function getArtifact(Request $request, string $ref): Response
    {
        $path = "artifacts/{$ref}";
        if (! Storage::disk('v3_private')->exists($path)) {
            return response()->json([
                'error' => [
                    'code' => 'artifact_missing',
                    'message' => "Artifact {$ref} not found",
                ],
            ], 404)->header('Cache-Control', 'private, no-store');
        }

        return Storage::disk('v3_private')->response($path, null, [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * PUT /operations/{operation}/artifacts/{ref}
     */
    public function putArtifact(Request $request, string $operation, string $ref): JsonResponse
    {
        $content = $request->getContent();
        $path = "artifacts/{$ref}";
        Storage::disk('v3_private')->put($path, $content);

        return response()->json([
            'status' => 'stored',
            'artifact_ref' => $ref,
            'bytes' => strlen($content),
            'digest' => hash('sha256', $content),
        ], 201)->header('Cache-Control', 'private, no-store');
    }

    /**
     * POST /imports/verify
     */
    public function verifyImport(Request $request, \App\Services\V3\WebPipeline\TerminalArchiveReader $reader): JsonResponse
    {
        $payload = $request->json()->all();
        $res = $reader->verifyAndPlanImport(
            terminalRoot: $payload['terminal_root'] ?? '',
            runId: $payload['run_id'] ?? '',
            subjectId: $payload['subject_id'] ?? 0,
            teacherId: $payload['teacher_id'] ?? 0,
            actorAdminId: $payload['actor_admin_id'] ?? 0,
            importId: $payload['import_id'] ?? (string) \Illuminate\Support\Str::uuid(),
            isDryRun: (bool) ($payload['is_dry_run'] ?? false)
        );

        $status = ($res['is_valid'] ?? false) ? 200 : 422;
        return response()->json($res, $status)->header('Cache-Control', 'private, no-store');
    }

    /**
     * POST /imports/materialize
     */
    public function materializeImport(
        Request $request,
        \App\Services\V3\WebPipeline\TerminalArchiveReader $reader,
        \App\Services\V3\WebPipeline\MaterializeTerminalImport $materializer
    ): JsonResponse {
        $payload = $request->json()->all();
        $allowed = ['terminal_root', 'run_id', 'subject_id', 'teacher_id', 'actor_admin_id', 'import_id', 'expected_digest'];
        if (array_diff(array_keys($payload), $allowed)
            || ! is_string($payload['terminal_root'] ?? null)
            || ! is_string($payload['run_id'] ?? null)
            || ! is_string($payload['import_id'] ?? null)
            || ! is_string($payload['expected_digest'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/i', $payload['expected_digest'])
            || ! is_numeric($payload['subject_id'] ?? null)
            || ! is_numeric($payload['teacher_id'] ?? null)
            || ! is_numeric($payload['actor_admin_id'] ?? null)) {
            return response()->json(['error' => [
                'code' => 'contract_invalid',
                'message' => 'Import request metadata is invalid.',
            ]], 422)->header('Cache-Control', 'private, no-store');
        }

        $plan = $reader->verifyAndPlanImport(
            terminalRoot: $payload['terminal_root'] ?? '',
            runId: $payload['run_id'] ?? '',
            subjectId: $payload['subject_id'] ?? 0,
            teacherId: $payload['teacher_id'] ?? 0,
            actorAdminId: $payload['actor_admin_id'] ?? 0,
            importId: $payload['import_id'] ?? (string) \Illuminate\Support\Str::uuid(),
            isDryRun: false
        );

        if (! ($plan['is_valid'] ?? false) || ! ($plan['capture'] ?? null)) {
            return response()->json([
                'error' => [
                    'code' => $plan['safe_code'] ?? 'receipt_invalid',
                    'message' => 'Historical archive verification failed.',
                ],
            ], 422)->header('Cache-Control', 'private, no-store');
        }

        if (! hash_equals(strtolower($payload['expected_digest']), strtolower($plan['capture']->digest))) {
            return response()->json(['error' => [
                'code' => 'import_conflict',
                'message' => 'The source archive changed after this import was queued.',
            ]], 409)
                ->header('Cache-Control', 'private, no-store');
        }

        try {
            $res = $materializer->materialize($plan['capture']);
        } catch (\App\Services\V3\WebPipeline\CommandConflictException $e) {
            return response()->json(['error' => [
                'code' => $e->getSafeCode(),
                'message' => 'Import conflicts with existing materialized data.',
            ]], 409)
                ->header('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['error' => [
                'code' => 'storage_unavailable',
                'message' => 'Import materialization failed.',
            ]], 503)
                ->header('Cache-Control', 'private, no-store');
        }

        $import = $res['terminal_import'] ?? null;
        return response()->json([
            'state' => $res['status'] ?? 'failed',
            'safe_code' => 'ok',
            'counts' => [
                'pdfs_accepted' => (int) ($import?->committed_item_count ?? count($plan['capture']->pdfArchives)),
                'prompts_saved' => count($plan['capture']->scenePrompts),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}
