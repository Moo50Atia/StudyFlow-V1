<?php

namespace App\Http\Controllers\V3\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\V3\AuthoringRequest;
use App\Models\V3\CodingAttempt;
use App\Models\V3\ManualSceneImport;
use App\Models\V3\ProcessingCase;
use App\Models\V3\PromptQualification;
use App\Models\V3\PromptSectionState;
use App\Models\V3\PipelineCommand;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\ScenePromptVersion;
use App\Models\V3\SubjectRun;
use App\Models\V3\TeacherSection;
use App\Services\V3\SceneQualification\RecordQualificationGate;
use App\Services\V3\WebPipeline\SelectSceneGeneration;
use App\Services\V3\WebPipeline\SubmitSubjectRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SubjectRunController extends Controller
{
    /**
     * Show form to create a new subject run.
     */
    public function create(Request $request, Subject $subject)
    {
        abort_unless(app(\App\Services\LearningAccess::class)->canTeach($request->user(), $subject->id), 403);
        return \App\Support\InertiaPage::render('v3.teacher.subject-runs.create', [
            'subject' => $subject,
        ]);
    }

    /**
     * Submit an ordered batch of PDFs for a subject run.
     */
    public function store(Request $request, Subject $subject, SubmitSubjectRun $submitter): JsonResponse|RedirectResponse
    {
        $payload = $request->all();
        $files = $request->file('files');
        if (empty($files) && ! empty($payload['files'])) {
            $files = $payload['files'];
        }
        $payload['files'] = $files ?? [];

        $receipt = $submitter->submit($request->user(), $subject->id, $payload);

        if (! $request->header('X-Inertia') && ($request->wantsJson() || $request->ajax() || $request->expectsJson())) {
            return response()->json([
                'data' => $receipt,
            ], 202)->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('v3.teacher.subject-runs.show', $receipt['run_id']);
    }

    /**
     * List runs for a subject owned by the authenticated teacher.
     */
    public function index(Request $request, Subject $subject): JsonResponse
    {
        $user = $request->user();
        abort_unless(app(\App\Services\LearningAccess::class)->canTeach($user, $subject->id), 403);

        $runs = SubjectRun::where('subject_id', $subject->id)
            ->where('owner_user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(min(100, max(1, (int) $request->query('limit', 25))))
            ->get();

        return response()->json([
            'data' => $runs,
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Show detail of a run for the owner teacher.
     */
    public function show(
        Request $request,
        SubjectRun $run,
        \App\Services\V3\WebPipeline\RunProgress $progressProjector
    )
    {
        $user = $request->user();

        if ($run->owner_user_id !== $user->id) {
            throw new HttpException(404, 'Run not found.');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $run->load('items'),
            ])->header('Cache-Control', 'private, no-store');
        }

        return \Inertia\Inertia::render('Teacher/RunShow', [
            'run' => $run->load('items'),
            'initialProgress' => $progressProjector->project($run),
            'progressUrl' => route('v3.teacher.subject-runs.progress', $run),
            'commandUrl' => route('v3.teacher.subject-runs.commands', $run),
            'scenesUrl' => route('v3.teacher.subject-runs.scenes', $run),
            'scenePullUrl' => $run->execution_mode === 'local_worker_v1' ? route('v3.teacher.subject-runs.pull-scenes', $run) : null,
            'subjectUrl' => route('subjects.show', $run->subject_id),
            'pollInterval' => (int) config('v3.progress_poll_interval', 5000),
        ]);
    }

    /**
     * Export exact scene prompt descriptions and associations for a run.
     */
    public function promptExport(
        Request $request,
        SubjectRun $run,
        \App\Services\V3\WebPipeline\ExportScenePrompts $exporter
    ): JsonResponse|Response {
        $user = $request->user();
        if ($run->owner_user_id !== $user->id) {
            throw new HttpException(404, 'Run not found.');
        }

        $itemId = $request->query('item_id');
        $sceneId = $request->query('scene_id');
        $format = $request->query('format');

        $data = $exporter->export($run, $itemId, $sceneId);

        if ($format === 'text' || (! $request->expectsJson() && $request->header('Accept') === 'text/plain')) {
            $text = $exporter->formatText($data);
            return response($text, 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
                'Content-Disposition' => 'attachment; filename="run-' . $run->id . '-prompts.txt"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response()->json($data)
            ->header('Cache-Control', 'private, no-store')
            ->header('Content-Disposition', 'attachment; filename="run-' . $run->id . '-prompts.json"')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Import a static three-file scene package manually submitted by the owner teacher.
     */
    public function importScene(
        Request $request,
        SubjectRun $run,
        \App\Models\V3\TeacherSection $scene,
        \App\Services\V3\WebPipeline\ManualSceneCommand $command
    ): JsonResponse {
        try {
            $result = $command->execute($request->user(), $run, $scene, $request->all());
            $status = ($result['is_replay'] ?? false) ? 200 : 201;

            return response()->json($result, $status)
                ->header('Cache-Control', 'private, no-store');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $decoded = json_decode($e->getMessage(), true);
            if (is_array($decoded)) {
                return response()->json($decoded, $e->getStatusCode())
                    ->header('Cache-Control', 'private, no-store');
            }

            return response()->json([
                'error' => [
                    'code' => 'http_error',
                    'message' => $e->getMessage(),
                ],
            ], $e->getStatusCode())->header('Cache-Control', 'private, no-store');
        }
    }

    /**
     * Show scenes management and overview for a subject run.
     */
    public function scenes(Request $request, SubjectRun $run)
    {
        $user = $request->user();
        if ($run->owner_user_id !== $user->id) {
            throw new HttpException(404, 'Run not found.');
        }

        $run->load(['items.processingCase']);

        $scenesData = [];
        foreach ($run->items->sortBy('pdf_ordinal') as $item) {
            $case = $item->processingCase;
            if (! $case) {
                continue;
            }

            $sections = TeacherSection::where('case_id', $case->id)
                ->with('lessonNode')
                ->get()
                ->sortBy(fn ($section) => [$section->lessonNode?->work_ordinal ?? PHP_INT_MAX,
                    $section->ordinal_in_work, $section->id]);

            foreach ($sections as $section) {
                $sectionState = PromptSectionState::where('section_id', $section->id)
                    ->where('case_id', $case->id)
                    ->first();

                $promptVersion = null;
                $promptText = null;
                if ($sectionState && $sectionState->current_prompt_version_id) {
                    $promptVersion = ScenePromptVersion::find($sectionState->current_prompt_version_id);
                    if ($promptVersion && $promptVersion->body_path) {
                        $disk = \Illuminate\Support\Facades\Storage::disk('v3_private');
                        if ($disk->exists($promptVersion->body_path)) {
                            $promptText = $disk->get($promptVersion->body_path);
                        }
                    }
                }

                $promptGate = DB::table('v3_qualification_gates')
                    ->where('section_id', $section->id)
                    ->where('gate_stage', 'prompt')
                    ->latest('created_at')
                    ->first();

                $promptQualification = null;
                if ($sectionState && $sectionState->qualification_id) {
                    $promptQualification = PromptQualification::find($sectionState->qualification_id);
                }

                $authoringRequest = AuthoringRequest::where('section_id', $section->id)
                    ->where('case_id', $case->id)
                    ->latest('created_at')
                    ->first();

                $candidateState = SceneCandidateState::where('section_id', $section->id)
                    ->where('case_id', $case->id)
                    ->latest('updated_at')
                    ->first();

                $candidate = null;
                if ($candidateState) {
                    $candidate = SceneCandidate::find($candidateState->candidate_id);
                } elseif ($sectionState) {
                    $candidate = SceneCandidate::where('section_id', $section->id)
                        ->where('case_id', $case->id)
                        ->latest('collected_at')
                        ->first();
                }

                $manualImport = null;
                if ($candidate && $candidate->manual_import_id) {
                    $manualImport = ManualSceneImport::find($candidate->manual_import_id);
                }

                $sceneGate = null;
                if ($candidate) {
                    $sceneGate = DB::table('v3_qualification_gates')
                        ->where('candidate_id', $candidate->id)
                        ->where('gate_stage', 'scene')
                        ->first();
                }

                $checkAttempt = null;
                $checkRun = null;
                if ($candidate) {
                    $checkAttempt = SceneCheckAttempt::where('candidate_id', $candidate->id)
                        ->latest('attempt_no')
                        ->first();
                    if ($checkAttempt) {
                        $checkRun = SceneCheckRun::where('winning_attempt_id', $checkAttempt->id)->first();
                    }
                }

                $review = null;
                if ($candidate) {
                    $review = DB::table('v3_teacher_review_items')
                        ->where('candidate_id', $candidate->id)
                        ->first();
                }

                $scenesData[] = [
                    'item' => [
                        'id' => $item->id,
                        'ordinal' => $item->pdf_ordinal,
                        'display_title' => $item->display_title,
                    ],
                    'section' => [
                        'id' => $section->id,
                        'ordinal' => $section->ordinal_in_work,
                        'case_id' => $case->id,
                        'section_identity' => $section->section_identity,
                    ],
                    'prompt' => $promptVersion ? [
                        'id' => $promptVersion->id,
                        'body_sha256' => $promptVersion->body_sha256,
                        'prompt_text' => $promptText ?? 'Prompt details available',
                    ] : null,
                    'prompt_gate' => $promptGate ? [
                        'id' => $promptGate->id,
                        'basis' => $promptGate->basis,
                        'policy_version' => $promptGate->policy_version,
                        'policy_sha256' => $promptGate->policy_sha256,
                    ] : null,
                    'prompt_qualification' => $promptQualification ? [
                        'id' => $promptQualification->id,
                        'passed' => (bool) $promptQualification->passed,
                    ] : null,
                    'authoring_request' => $authoringRequest ? [
                        'id' => $authoringRequest->id,
                        'state' => $authoringRequest->state,
                        'eligibility_basis' => $authoringRequest->eligibility_basis,
                        'origin' => $authoringRequest->origin,
                    ] : null,
                    'candidate' => $candidate ? [
                        'id' => $candidate->id,
                        'origin' => $candidate->origin,
                        'manifest_sha256' => $candidate->manifest_sha256,
                        'file_count' => $candidate->file_count,
                        'total_bytes' => $candidate->total_bytes,
                        'package_profile' => $candidate->package_profile,
                    ] : null,
                    'candidate_state' => $candidateState ? [
                        'status' => $candidateState->status,
                        'version_no' => $candidateState->version_no,
                    ] : null,
                    'manual_import' => $manualImport ? [
                        'id' => $manualImport->id,
                        'package_sha256' => $manualImport->package_sha256,
                        'original_filename' => $manualImport->original_filename,
                    ] : null,
                    'scene_gate' => $sceneGate ? [
                        'id' => $sceneGate->id,
                        'basis' => $sceneGate->basis,
                    ] : null,
                    'check_run' => $checkRun ? [
                        'id' => $checkRun->id,
                        'passed' => (bool) $checkRun->passed,
                        'duration_ms' => $checkRun->duration_ms,
                        'screenshot_path' => $checkRun->screenshot_path,
                    ] : null,
                    'review' => $review ? [
                        'id' => $review->id,
                        'status' => $review->status ?? 'pending',
                    ] : null,
                ];
            }
        }

        $currentSelection = is_array($run->captured_settings_json)
            ? $run->captured_settings_json
            : (json_decode((string) ($run->captured_settings_json ?? '{}'), true) ?: ['mode' => $run->mode]);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'run' => $run,
                    'current_selection' => $currentSelection,
                    'catalog' => SelectSceneGeneration::APPROVED_CATALOG,
                    'scenes' => $scenesData,
                ],
            ])->header('Cache-Control', 'private, no-store');
        }

        return \App\Support\InertiaPage::render('v3.teacher.subject-runs.scenes', [
            'run' => $run,
            'currentSelection' => $currentSelection,
            'catalog' => SelectSceneGeneration::APPROVED_CATALOG,
            'scenes' => $scenesData,
            'paidScenesEnabled' => (bool) config('services.v3_teacher_paid_scenes_enabled', false),
        ]);
    }

    /**
     * Update the generation selection and provider settings for a subject run.
     */
    public function updateGenerationSelection(Request $request, SubjectRun $run): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        if ($run->owner_user_id !== $user->id) {
            throw new HttpException(404, 'Run not found.');
        }

        $validated = $request->validate([
            'mode' => 'required|in:export_manual,selected_api,api_generate',
            'provider_id' => 'nullable|string',
            'model' => 'nullable|string',
            'thinking_budget' => 'nullable|integer',
            'custom_endpoint' => 'nullable|string',
        ]);
        if ($validated['mode'] !== 'export_manual' && ! (bool) config('services.v3_teacher_paid_scenes_enabled', false)) abort(403);

        try {
            $selection = SelectSceneGeneration::captureSelection(
                $validated['mode'],
                $validated['provider_id'] ?? null,
                $validated['model'] ?? null,
                isset($validated['thinking_budget']) ? (int) $validated['thinking_budget'] : null,
                $validated['custom_endpoint'] ?? null
            );
        } catch (\InvalidArgumentException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'provider_id' => [$exception->getMessage()],
            ]);
        }

        $selectionJson = json_encode($selection, JSON_THROW_ON_ERROR);

        $run->update([
            'mode' => $validated['mode'],
            'captured_settings_json' => $selectionJson,
            'state_version' => $run->state_version + 1,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'run_id' => $run->id,
                    'mode' => $run->mode,
                    'selection' => $selection,
                    'state_version' => $run->state_version,
                ],
            ])->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('v3.teacher.subject-runs.scenes', $run)
            ->with('status', 'Generation selection updated successfully.');
    }

    /**
     * Request scene generation for a section prompt.
     */
    public function requestSceneGeneration(
        Request $request,
        SubjectRun $run,
        TeacherSection $scene,
        RecordQualificationGate $gateRecorder
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        if ($run->owner_user_id !== $user->id) {
            throw new HttpException(404, 'Run not found.');
        }

        if (! in_array($run->mode, ['selected_api', 'api_generate'], true)) {
            throw new HttpException(422, 'Select API scene generation for this run before requesting a scene.');
        }
        if (! (bool) config('services.v3_teacher_paid_scenes_enabled', false)) abort(403);

        if ($request->has('waiver_acknowledged') && ! filter_var($request->input('waiver_acknowledged'), FILTER_VALIDATE_BOOLEAN)) {
            throw new HttpException(422, 'Consent/waiver acknowledgment is required to request scene generation.');
        }

        $runSelection = is_array($run->captured_settings_json)
            ? $run->captured_settings_json
            : (json_decode((string) $run->captured_settings_json, true) ?: []);
        $selectedProvider = $runSelection['provider'] ?? [];
        if (empty($selectedProvider) || empty($selectedProvider['id'])) {
            throw new HttpException(422, 'Provider configuration is missing. Configure provider settings before requesting generation.');
        }

        try {
            $providerMeta = SelectSceneGeneration::mapProvider((string) ($selectedProvider['id'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            throw new HttpException(422, 'Provider configuration is invalid or missing: ' . $e->getMessage());
        }

        $providerOverride = [
            'id' => $providerMeta['provider_id'],
            'model' => (string) ($selectedProvider['requested_model'] ?? $providerMeta['default_model']),
            'credential_ref' => $providerMeta['credential_alias'],
            'thinking_budget' => $providerMeta['provider_id'] === 'gemini_api'
                ? (int) ($selectedProvider['gemini_thinking_budget'] ?? $providerMeta['gemini_thinking_budget'])
                : null,
        ];
        if (! preg_match('/^[A-Za-z0-9._:\/-]{1,100}$/', $providerOverride['model'])) {
            throw new HttpException(422, 'Selected model is invalid.');
        }

        $case = ProcessingCase::find($scene->case_id);
        if (! $case || $case->owner_user_id !== $user->id) {
            throw new HttpException(404, 'Case or scene not found.');
        }
        $settingsVersion = $case->settingsVersion;
        $settingsPayload = $settingsVersion?->payload ?? [];
        $templateId = $settingsPayload['templates']['scene']['id'] ?? null;
        $templateSha256 = $settingsPayload['templates']['scene']['checksum'] ?? null;
        if (! is_string($templateId) || ! is_string($templateSha256) || ! $settingsVersion) {
            throw new HttpException(422, 'Approved scene template is unavailable.');
        }
        try {
            app(\App\Services\V3\Settings\ApprovedTemplateRepository::class)
                ->getCaptured($templateId, $templateSha256);
        } catch (\App\Services\V3\Settings\SettingsUnavailable) {
            throw new HttpException(422, 'Approved scene template is unavailable.');
        }

        $sectionState = PromptSectionState::where('section_id', $scene->id)
            ->where('case_id', $case->id)
            ->first();

        if (! $sectionState || ! $sectionState->current_prompt_version_id) {
            throw new HttpException(422, 'Section does not have an active current prompt version.');
        }

        $promptVersion = ScenePromptVersion::find($sectionState->current_prompt_version_id);
        if (! $promptVersion) {
            throw new HttpException(422, 'Current prompt version not found.');
        }

        $promptGateId = null;
        $qualificationId = null;

        if ($run->qualification_mode === 'owner_waived_demo') {
            $commandId = (string) Str::uuid();
            \App\Models\V3\PipelineCommand::create([
                'id' => $commandId,
                'run_id' => $run->id,
                'actor_user_id' => $user->id,
                'action' => 'waive_prompt_gate',
                'request_sha256' => hash('sha256', "waive:{$run->id}:{$scene->id}:{$promptVersion->id}"),
                'expected_version' => $run->state_version,
                'state' => 'applied',
                'created_at' => now(),
            ]);
            $gateResult = $gateRecorder->record([
                'case_id' => $case->id,
                'section_id' => $scene->id,
                'prompt_version_id' => $promptVersion->id,
                'gate_stage' => 'prompt',
                'basis' => 'owner_waived_demo',
                'waived_by_owner_id' => $user->id,
                'policy_version' => 'demo-waiver-v1',
                'policy_sha256' => hash('sha256', 'owner_waived_demo'),
                'prompt_sha256' => $promptVersion->body_sha256,
                'missing_fields' => [],
                'acknowledgment_sha256' => hash('sha256', 'explicit_teacher_demo_request'),
                'command_id' => $commandId,
            ]);
            $promptGateId = $gateResult['id'];
            $eligibilityBasis = 'owner_waived_demo';
        } else {
            $eligibilityBasis = 'real_jev';
            $qualification = PromptQualification::where('section_id', $scene->id)
                ->where('prompt_version_id', $promptVersion->id)
                ->where('passed', true)
                ->first();
            if (! $qualification) {
                throw new HttpException(422, 'Section prompt has not passed real JEV qualification.');
            }
            $qualificationId = $qualification->id;
        }

        $existingRequest = AuthoringRequest::where('case_id', $case->id)
            ->where('section_id', $scene->id)
            ->where('prompt_version_id', $promptVersion->id)
            ->where('eligibility_basis', $eligibilityBasis)
            ->latest('created_at')
            ->first();

        if ($existingRequest && in_array($existingRequest->state, ['uncertain', 'blocked'], true)) {
            $retryCount = AuthoringRequest::where('case_id', $case->id)
                ->where('prompt_version_id', $promptVersion->id)
                ->where('origin', 'like', 'web_retry%')
                ->count();
            $origin = 'web_retry_' . ($retryCount + 1);
            $authoringRequest = AuthoringRequest::create([
                'id' => (string) Str::uuid(),
                'case_id' => $case->id,
                'section_id' => $scene->id,
                'prompt_version_id' => $promptVersion->id,
                'eligibility_basis' => $eligibilityBasis,
                'qualification_id' => $qualificationId,
                'prompt_gate_id' => $promptGateId,
                'prompt_body_sha256' => $promptVersion->body_sha256,
                'settings_checksum' => $settingsVersion->checksum,
                'template_sha256' => $templateSha256,
                'settings_version_id' => $case->settings_version_id,
                'provider_override_json' => $providerOverride,
                'template_id' => $templateId,
                'origin' => $origin,
                'state' => 'pending',
                'state_version' => 1,
                'attempt_limit' => 1,
            ]);
        } elseif ($existingRequest) {
            $authoringRequest = $existingRequest;
        } else {
            $authoringRequest = AuthoringRequest::create([
                'id' => (string) Str::uuid(),
                'case_id' => $case->id,
                'section_id' => $scene->id,
                'prompt_version_id' => $promptVersion->id,
                'eligibility_basis' => $eligibilityBasis,
                'qualification_id' => $qualificationId,
                'prompt_gate_id' => $promptGateId,
                'prompt_body_sha256' => $promptVersion->body_sha256,
                'settings_checksum' => $settingsVersion->checksum,
                'template_sha256' => $templateSha256,
                'settings_version_id' => $case->settings_version_id,
                'provider_override_json' => $providerOverride,
                'template_id' => $templateId,
                'origin' => 'web',
                'state' => 'pending',
                'state_version' => 1,
                'attempt_limit' => 1,
            ]);
        }

        // A pending, never-started request may inherit the teacher's latest
        // immutable run selection. Once an attempt is reserved, the provider
        // choice is frozen with that request and cannot be changed by replay.
        if ($authoringRequest->state === 'pending'
            && ! $authoringRequest->attempts()->exists()
            && $authoringRequest->provider_override_json !== $providerOverride) {
            $authoringRequest->update([
                'provider_override_json' => $providerOverride,
                'attempt_limit' => 1,
            ]);
            $authoringRequest->refresh();
        }

        $command = PipelineCommand::find($authoringRequest->id);
        if ($command && $command->state !== 'pending' && in_array($authoringRequest->state, ['pending', 'authoring'], true)) {
            $command->delete();
            $command = null;
        }
        if (in_array($authoringRequest->state, ['pending', 'authoring'], true) && ! $command) {
            // Keep the scene job in the same durable outbox as PDF runs. The
            // worker receives only an opaque manifest reference; the private
            // manifest resolves to the authoring request through the signed API.
            $manifestRef = (string) Str::uuid();
            $manifest = [
                'schema_version' => 1,
                'kind' => 'web_authoring_v1',
                'manifest_ref' => $manifestRef,
                'run_id' => $run->id,
                'case_id' => $case->id,
                'request_ref' => $authoringRequest->id,
                'section_ref' => $scene->id,
                'prompt_version_ref' => $promptVersion->id,
                'captured_settings' => $runSelection,
                'created_at' => now()->toIso8601String(),
            ];
            $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($manifestJson) > 32768) {
                throw new HttpException(422, 'Scene authoring manifest exceeds 32 KiB.');
            }
            Storage::disk('v3_private')->put("manifests/{$manifestRef}.json", $manifestJson);

            $command = PipelineCommand::create([
                'id' => $authoringRequest->id,
                'run_id' => $run->id,
                'actor_user_id' => $user->id,
                'action' => 'generate_scene',
                'request_sha256' => hash('sha256', $manifestJson),
                'expected_version' => $run->state_version,
                'private_payload_ref' => $manifestRef,
                'state' => 'pending',
            ]);
        }
        if ($command && $command->action === 'generate_scene') {
            app(\App\Services\V3\WebPipeline\RunOutbox::class)->enqueue($command);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'request_id' => $authoringRequest->id,
                    'eligibility_basis' => $authoringRequest->eligibility_basis,
                    'prompt_gate_id' => $authoringRequest->prompt_gate_id,
                    'state' => $authoringRequest->state,
                ],
            ], $authoringRequest->wasRecentlyCreated ? 202 : 200)->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('v3.teacher.subject-runs.scenes', $run)
            ->with('status', 'Scene authoring request submitted.');
    }

    /**
     * Get run progress projection.
     */
    public function progress(
        Request $request,
        SubjectRun $run,
        \App\Services\V3\WebPipeline\RunProgress $projector
    ): JsonResponse {
        $user = $request->user();
        if ($run->owner_user_id !== $user->id) {
            throw new HttpException(404, 'Run not found.');
        }

        $projection = $projector->project($run);

        return response()->json($projection)->header('Cache-Control', 'private, no-store');
    }

    /**
     * Get run events stream.
     */
    public function events(
        Request $request,
        SubjectRun $run,
        \App\Services\V3\WebPipeline\RunEventStore $eventStore
    ): JsonResponse {
        $user = $request->user();
        if ($run->owner_user_id !== $user->id) {
            throw new HttpException(404, 'Run not found.');
        }

        $afterSequence = max(0, (int) $request->query('after_sequence', 0));
        $limit = max(1, min(100, (int) $request->query('limit', 100)));

        $events = $eventStore->getEvents($run->id, $afterSequence, $limit);

        $nextCursor = $events->isNotEmpty() ? $events->last()->sequence : $afterSequence;

        return response()->json([
            'data' => $events,
            'next_cursor' => $nextCursor,
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Submit an application control command for the run.
     */
    public function command(
        Request $request,
        SubjectRun $run,
        \App\Services\V3\WebPipeline\ApplyRunControl $control
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        if ($run->owner_user_id !== $user->id) {
            throw new HttpException(404, 'Run not found.');
        }

        try {
            $result = $control->apply($user, $run, $request->all(), false);
            $statusCode = ($result['status'] ?? '') === 'replayed' ? 200 : 202;

            if (! $request->expectsJson()) {
                return redirect()->route('v3.teacher.subject-runs.show', $run)
                    ->with('status', ucfirst($result['action'] ?? 'Run control') . ' requested.');
            }

            return response()->json($result, $statusCode)->header('Cache-Control', 'private, no-store');
        } catch (\App\Services\V3\WebPipeline\CommandConflictException $e) {
            return response()->json([
                'error' => [
                    'code' => $e->getSafeCode(),
                    'message' => $e->getMessage(),
                ],
            ], 409)->header('Cache-Control', 'private, no-store');
        } catch (\App\Services\V3\WebPipeline\StaleVersionException $e) {
            return response()->json([
                'error' => [
                    'code' => $e->getSafeCode(),
                    'message' => $e->getMessage(),
                ],
            ], 409)->header('Cache-Control', 'private, no-store');
        }
    }
}
