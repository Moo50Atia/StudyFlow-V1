<?php

namespace App\Http\Controllers\V3\Teacher;

use App\Http\Controllers\Controller;
use App\Models\V3\Document;
use App\Models\V3\ProcessingCase;
use App\Models\V3\UploadAttempt;
use App\Services\V3\Intake\PrivatePdfStore;
use App\Services\V3\Intake\ValidateUploadInput;
use App\Services\V3\Settings\SelectCaseSettings;
use App\Services\V3\Settings\SettingsUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PdfUploadController extends Controller
{
    public function __construct(
        protected PrivatePdfStore $store,
        protected ValidateUploadInput $validator,
        protected SelectCaseSettings $settingsSelector
    ) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 20;

        $attempts = UploadAttempt::ownedBy($request->user())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => collect($attempts->items())->map(fn ($a) => $this->formatAttemptSummary($a))->values(),
                'pagination' => [
                    'page' => $attempts->currentPage(),
                    'per_page' => $perPage,
                    'has_more' => $attempts->hasMorePages(),
                ],
            ])->header('Cache-Control', 'private, no-store');
        }

        return \App\Support\InertiaPage::render('v3.teacher.pdf-uploads.index', [
            'teacher' => $request->user(),
            'attempts' => $attempts,
        ]);
    }

    public function show(Request $request, string $attempt)
    {
        if (! Str::isUuid($attempt)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'resource_unavailable',
                        'message' => 'V3 access is unavailable.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store');
            }
            abort(404, 'V3 access is unavailable.');
        }

        $uploadAttempt = UploadAttempt::find($attempt);

        if (! $uploadAttempt || ! Gate::forUser($request->user())->allows('view', $uploadAttempt)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'resource_unavailable',
                        'message' => 'V3 access is unavailable.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store');
            }
            abort(404, 'V3 access is unavailable.');
        }

        $summary = $this->formatAttemptSummary($uploadAttempt);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $summary,
            ])->header('Cache-Control', 'private, no-store');
        }

        return \App\Support\InertiaPage::render('v3.teacher.pdf-uploads.show', [
            'teacher' => $request->user(),
            'attempt' => $uploadAttempt,
            'summary' => $summary,
            'nextIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $attemptId = (string) Str::uuid();

        // 1. Transaction: lock access singleton, recheck teacher, capture 005 settings, insert attempt
        $settingsResult = DB::transaction(function () use ($user, $attemptId) {
            $lockAffected = DB::table('v3_access_control_locks')
                ->where('id', 1)
                ->increment('revision');

            if ($lockAffected !== 1) {
                throw new HttpException(503, 'V3 access is unavailable.');
            }

            $freshUser = DB::table('users')->where('id', $user->id)->first();
            $access = DB::table('v3_account_access')->where('user_id', $user->id)->first();

            if (! $freshUser || $freshUser->role !== 'teacher' || ! $access || ! (bool) $access->is_active) {
                throw new HttpException(403, 'V3 access is unavailable.');
            }

            try {
                $settings = $this->settingsSelector->selectForNewCase();
                $versionId = $settings->id;
                $payload = is_array($settings->payload) ? $settings->payload : json_decode($settings->payload, true);
                $maxBytes = (int) ($payload['limits']['max_pdf_bytes'] ?? 104857600);

                UploadAttempt::forceCreate([
                    'id' => $attemptId,
                    'owner_user_id' => $user->id,
                    'upload_settings_version_id' => $versionId,
                    'state' => 'receiving',
                    'expires_at' => now()->addHours(24),
                ]);

                return [
                    'success' => true,
                    'version_id' => $versionId,
                    'max_bytes' => $maxBytes,
                ];
            } catch (SettingsUnavailable $e) {
                UploadAttempt::forceCreate([
                    'id' => $attemptId,
                    'owner_user_id' => $user->id,
                    'upload_settings_version_id' => null,
                    'state' => 'blocked',
                    'reason_code' => 'settings_unavailable',
                    'expires_at' => now()->addHours(24),
                    'finished_at' => now(),
                ]);

                return [
                    'success' => false,
                    'error_code' => 'settings_unavailable',
                ];
            }
        });

        if (! $settingsResult['success']) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'settings_unavailable',
                        'message' => 'V3 settings are unavailable.',
                    ],
                    'attempt_id' => $attemptId,
                ], 503)->header('Cache-Control', 'private, no-store');
            }

            return redirect()->route('v3.teacher.pdf-uploads.show', $attemptId, 303);
        }

        $attempt = UploadAttempt::findOrFail($attemptId);
        $maxBytes = $settingsResult['max_bytes'];

        // 2. Validate application fields AFTER attempt row creation
        $validation = $this->validator->validate($request, $maxBytes);

        if (! $validation['valid']) {
            $reasonCode = $validation['reason_code'];
            $message = $validation['message'];

            $attempt->state = 'rejected';
            $attempt->reason_code = $reasonCode;
            $attempt->finished_at = now();
            $attempt->save();

            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => $reasonCode,
                        'message' => $message,
                    ],
                    'attempt_id' => $attemptId,
                ], 422)->header('Cache-Control', 'private, no-store');
            }

            return redirect()->route('v3.teacher.pdf-uploads.show', $attemptId, 303);
        }

        // 3. Valid input: stream into private quarantine
        $file = $validation['file'];
        $content = file_get_contents($file->getRealPath());
        $byteSize = $this->store->storeQuarantine($attemptId, $content);

        $attempt->state = 'quarantined';
        $attempt->staging_path = $this->store->quarantinePath($attemptId);
        $attempt->byte_size = $byteSize;
        $attempt->save();

        // Dispatch background validation job (Task T010 will implement job)
        if (class_exists(\App\Jobs\V3\ValidatePdfUpload::class)) {
            \App\Jobs\V3\ValidatePdfUpload::dispatch($attemptId)
                ->onQueue('v3_intake')
                ->afterCommit();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'attempt_id' => $attemptId,
                    'state' => 'quarantined',
                    'status_url' => route('v3.teacher.pdf-uploads.show', $attemptId),
                ],
            ], 202)->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('v3.teacher.pdf-uploads.show', $attemptId, 303);
    }

    protected function formatAttemptSummary(UploadAttempt $attempt): array
    {
        $latestCaseId = null;
        if ($attempt->document_id) {
            $latestCaseId = ProcessingCase::where('owner_user_id', $attempt->owner_user_id)
                ->where('document_id', $attempt->document_id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('id');
        }

        return [
            'attempt_id' => $attempt->id,
            'state' => $attempt->state,
            'reason_code' => $attempt->reason_code,
            'document_id' => $attempt->state === 'accepted' ? $attempt->document_id : null,
            'duplicate' => $attempt->state === 'accepted' ? (bool) $attempt->duplicate : null,
            'latest_case_id' => $latestCaseId,
            'created_at' => $attempt->created_at?->toISOString(),
            'finished_at' => $attempt->finished_at?->toISOString(),
        ];
    }
}
