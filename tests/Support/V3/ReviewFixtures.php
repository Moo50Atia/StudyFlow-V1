<?php

namespace Tests\Support\V3;

use App\Models\V3\AuthoringRequest;
use App\Models\V3\CodingAttempt;
use App\Models\V3\SceneCandidate;
use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckAttempt;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\SceneJevAttempt;
use App\Models\V3\SceneQualification;
use App\Models\V3\TeacherSection;
use App\Services\V3\Authoring\ClaimAuthoringRequest;
use App\Services\V3\Authoring\ClaimCodingAttempt;
use App\Services\V3\Authoring\RecordAuthoringResult;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewFixtures
{
    public static function postSigned(TestCase $test, string $path, array $body)
    {
        $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $body);
        return $test->postJson($path, $body, $headers, JSON_UNESCAPED_SLASHES);
    }

    public static function getSigned(TestCase $test, string $path)
    {
        $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);
        return $test->withHeaders($headers)->get($path);
    }

    public static function createQualifiedCandidate(array $env, int $ordinal = 1, array $files = [], ?string $promptText = null): array
    {
        $case = $env['case'];
        $map = $env['map'];

        // Find or create section
        $sec = AuthoringFixtures::createQualifiedSection(
            $case,
            $map,
            $ordinal,
            $promptText ?? "Qualified section {$ordinal} prompt body",
            true
        );

        $claimService = app(ClaimAuthoringRequest::class);
        $res = $claimService->claimInitial($case->id, $sec['version']->id);
        $request = $res['request'];

        $attemptService = app(ClaimCodingAttempt::class);
        $attRes = $attemptService->claim($case->id, $request->id, [
            'schema_version' => 1,
            'ordinal' => 1,
        ]);
        $attemptRef = $attRes['data']['attempt_ref'];

        if (empty($files)) {
            $files = [
                [
                    'path' => 'index.html',
                    'content' => "<!DOCTYPE html><html lang=\"en\"><head><title>Test Scene</title></head><body><h1>Section {$ordinal}</h1><button id=\"btn\" aria-label=\"Action\">Action</button></body></html>",
                ],
                [
                    'path' => 'app.js',
                    'content' => "document.getElementById('btn').addEventListener('click', () => { document.body.style.backgroundColor = 'blue'; });",
                ],
            ];
        }

        $resultService = app(RecordAuthoringResult::class);
        $payload = [
            'schema_version' => 1,
            'outcome' => 'candidate',
            'returned_model' => 'gemini-2.5-pro',
            'provider_response_id' => 'resp-' . Str::random(8),
            'provider_response_json' => ['id' => 'test'],
            'package' => [
                'schema_version' => 1,
                'files' => $files,
            ],
            'failure_code' => null,
            'usage' => [
                'input_tokens' => 500,
                'output_tokens' => 200,
                'cost_minor_units' => 5,
                'currency' => 'USD',
            ],
            'duration_ms' => 1000,
        ];
        $recordRes = $resultService->record($case->id, $attemptRef, $payload, json_encode($payload, JSON_UNESCAPED_SLASHES));
        $candidateRef = $recordRes['data']['candidate_ref'];
        $candidate = SceneCandidate::find($candidateRef);

        // Transition candidate to qualified via check run and JEV qualification
        $checkAttempt = SceneCheckAttempt::forceCreate([
            'id' => (string) Str::uuid(),
            'candidate_id' => $candidate->id,
            'case_id' => $case->id,
            'attempt_no' => 1,
            'manifest_sha256' => $candidate->manifest_sha256,
            'request_sha256' => str_repeat('a', 64),
            'checker_version' => 'chromium-pw-1.63.0-v1',
            'state' => 'recorded',
        ]);

        $checkRun = SceneCheckRun::forceCreate([
            'id' => (string) Str::uuid(),
            'candidate_id' => $candidate->id,
            'winning_attempt_id' => $checkAttempt->id,
            'case_id' => $case->id,
            'section_id' => $candidate->section_id,
            'prompt_version_id' => $candidate->prompt_version_id,
            'settings_version_id' => $case->settings_version_id,
            'manifest_sha256' => $candidate->manifest_sha256,
            'report_sha256' => str_repeat('a', 64),
            'screenshot_sha256' => str_repeat('b', 64),
            'report_path' => "reports/{$candidate->id}.json",
            'screenshot_path' => "screens/{$candidate->id}.png",
            'verdict' => 'pass',
            'failed_checks' => [],
            'duration_ms' => 100,
        ]);

        $jevAttempt = SceneJevAttempt::forceCreate([
            'id' => (string) Str::uuid(),
            'candidate_id' => $candidate->id,
            'check_run_id' => $checkRun->id,
            'case_id' => $case->id,
            'attempt_no' => 1,
            'request_sha256' => str_repeat('a', 64),
            'question_sha256' => str_repeat('a', 64),
            'evidence_sha256' => str_repeat('a', 64),
            'evidence_path' => "ev/{$candidate->id}.json",
            'requested_model' => 'gemini-2.5-pro',
            'state' => 'recorded',
        ]);

        $qual = SceneQualification::forceCreate([
            'id' => (string) Str::uuid(),
            'candidate_id' => $candidate->id,
            'check_run_id' => $checkRun->id,
            'winning_jev_attempt_id' => $jevAttempt->id,
            'case_id' => $case->id,
            'section_id' => $candidate->section_id,
            'prompt_version_id' => $candidate->prompt_version_id,
            'settings_version_id' => $case->settings_version_id,
            'settings_checksum' => str_repeat('c', 64),
            'manifest_sha256' => $candidate->manifest_sha256,
            'evidence_sha256' => str_repeat('d', 64),
            'request_sha256' => str_repeat('e', 64),
            'question_sha256' => str_repeat('f', 64),
            'answer_sha256' => str_repeat('0', 64),
            'evidence_path' => "ev/{$candidate->id}.json",
            'answer_path' => "ans/{$candidate->id}.json",
            'requested_model' => 'gemini-2.5-pro',
            'returned_model' => 'gemini-2.5-pro',
            'choice' => 'pass',
            'choice_confidence' => 0.95,
            'score' => 95.0,
            'score_confidence' => 0.95,
            'noul_probability' => 0.95,
            'noul_yes' => true,
            'min_score' => 75,
            'min_confidence' => 70,
            'decision' => 'pass',
            'failed_checks' => [],
        ]);

        SceneCandidateState::forceCreate([
            'candidate_id' => $candidate->id,
            'root_candidate_id' => $candidate->id,
            'case_id' => $case->id,
            'section_id' => $candidate->section_id,
            'prompt_version_id' => $candidate->prompt_version_id,
            'version_no' => 1,
            'status' => 'qualified',
            'qualification_id' => $qual->id,
            'check_run_id' => $checkRun->id,
        ]);

        return [
            'section' => $sec['section'],
            'promptVersion' => $sec['version'],
            'candidate' => $candidate,
            'qualification' => $qual,
            'checkRun' => $checkRun,
        ];
    }
}
