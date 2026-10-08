<?php

namespace Tests\Support\V3;

use App\Models\V3\AuthoringRequest;
use App\Models\V3\CodingAttempt;
use App\Models\V3\SceneCandidate;
use App\Services\V3\Authoring\ClaimAuthoringRequest;
use App\Services\V3\Authoring\ClaimCodingAttempt;
use App\Services\V3\Authoring\RecordAuthoringResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SceneQualificationFixtures
{
    public static function postSigned(TestCase $test, string $path, array $body) {
        $headers = WorkflowFixtures::signCallbackRequest('POST', $path, $body);
        return $test->postJson($path, $body, $headers, JSON_UNESCAPED_SLASHES);
    }

    public static function getSigned(TestCase $test, string $path) {
        $headers = WorkflowFixtures::signCallbackRequest('GET', $path, []);
        return $test->withHeaders($headers)->get($path);
    }

    public static function validPngBytes(int $width = 1280, int $height = 720): string
    {
        $im = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        imagedestroy($im);
        return $bytes;
    }

    public static function createCandidateEnvironment(array $files = [], bool $promptPass = true, array $settingsOverrides = []): array
    {
        $env = AuthoringFixtures::createAuthoringEnvironment($settingsOverrides);
        $case = $env['case'];
        $map = $env['map'];

        $sec = AuthoringFixtures::createQualifiedSection($case, $map, 1, 'Scene qualification test prompt body', $promptPass);

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
                    'content' => "<!DOCTYPE html><html lang=\"en\"><head><title>Test Scene</title></head><body><h1>Hello</h1><button id=\"b\" aria-label=\"Run\">Run</button><script src=\"app.js\"></script></body></html>",
                ],
                [
                    'path' => 'app.js',
                    'content' => "document.getElementById('b').addEventListener('click', () => { document.body.style.color = 'red'; });",
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
            'duration_ms' => 1200,
        ];
        $recordRes = $resultService->record($case->id, $attemptRef, $payload, json_encode($payload, JSON_UNESCAPED_SLASHES));

        $candidateRef = $recordRes['data']['candidate_ref'];
        $candidate = SceneCandidate::find($candidateRef);

        return array_merge($env, [
            'section' => $sec['section'],
            'promptVersion' => $sec['version'],
            'request' => $request,
            'attemptRef' => $attemptRef,
            'candidate' => $candidate,
        ]);
    }
}
