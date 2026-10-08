<?php

namespace Tests\Feature\V3\SceneQualification;

use App\Models\V3\SceneCandidateState;
use App\Models\V3\SceneCheckRun;
use App\Models\V3\SceneQualification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\V3\SceneQualificationFixtures;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::statement('PRAGMA foreign_keys = ON;');
    Storage::fake('v3_private');
    $this->env = SceneQualificationFixtures::createCandidateEnvironment();
    $this->case = $this->env['case'];
    $this->candidate = $this->env['candidate'];
});

test('S21: review eligibility returns 200 only for current passing chain tip, 404 otherwise', function () {
    $eligiblePath = "/api/internal/v1/cases/{$this->case->id}/scene-qualification/review-eligible/{$this->candidate->id}";

    // 1. Not qualified yet -> 404
    $resInitial = SceneQualificationFixtures::getSigned($this, $eligiblePath);
    $resInitial->assertStatus(404);

    // 2. Transition candidate to qualified with fake qualification
    $checkAttempt = \App\Models\V3\SceneCheckAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'candidate_id' => $this->candidate->id,
        'case_id' => $this->case->id,
        'attempt_no' => 1,
        'manifest_sha256' => $this->candidate->manifest_sha256,
        'request_sha256' => str_repeat('a', 64),
        'checker_version' => 'chromium-pw-1.63.0-v1',
        'state' => 'recorded',
    ]);

    $checkRun = SceneCheckRun::forceCreate([
        'id' => (string) Str::uuid(),
        'candidate_id' => $this->candidate->id,
        'winning_attempt_id' => $checkAttempt->id,
        'case_id' => $this->case->id,
        'section_id' => $this->candidate->section_id,
        'prompt_version_id' => $this->candidate->prompt_version_id,
        'settings_version_id' => $this->case->settings_version_id,
        'manifest_sha256' => $this->candidate->manifest_sha256,
        'report_sha256' => str_repeat('a', 64),
        'screenshot_sha256' => str_repeat('b', 64),
        'report_path' => 'report.json',
        'screenshot_path' => 'screen.png',
        'verdict' => 'pass',
        'failed_checks' => [],
        'duration_ms' => 100,
    ]);

    $jevAttempt = \App\Models\V3\SceneJevAttempt::forceCreate([
        'id' => (string) Str::uuid(),
        'candidate_id' => $this->candidate->id,
        'check_run_id' => $checkRun->id,
        'case_id' => $this->case->id,
        'attempt_no' => 1,
        'request_sha256' => str_repeat('a', 64),
        'question_sha256' => str_repeat('a', 64),
        'evidence_sha256' => str_repeat('a', 64),
        'evidence_path' => 'ev.json',
        'requested_model' => 'gemini-2.5-pro',
        'state' => 'recorded',
    ]);

    $qual = SceneQualification::forceCreate([
        'id' => (string) Str::uuid(),
        'candidate_id' => $this->candidate->id,
        'check_run_id' => $checkRun->id,
        'winning_jev_attempt_id' => $jevAttempt->id,
        'case_id' => $this->case->id,
        'section_id' => $this->candidate->section_id,
        'prompt_version_id' => $this->candidate->prompt_version_id,
        'settings_version_id' => $this->case->settings_version_id,
        'settings_checksum' => str_repeat('c', 64),
        'manifest_sha256' => $this->candidate->manifest_sha256,
        'evidence_sha256' => str_repeat('d', 64),
        'request_sha256' => str_repeat('e', 64),
        'question_sha256' => str_repeat('f', 64),
        'answer_sha256' => str_repeat('0', 64),
        'evidence_path' => 'ev.json',
        'answer_path' => 'ans.json',
        'requested_model' => 'gemini-2.5-pro',
        'returned_model' => 'gemini-2.5-pro',
        'choice' => 'pass',
        'choice_confidence' => 0.9,
        'score' => 90.0,
        'score_confidence' => 0.9,
        'noul_probability' => 0.9,
        'noul_yes' => true,
        'min_score' => 85,
        'min_confidence' => 80,
        'decision' => 'pass',
        'failed_checks' => [],
    ]);

    SceneCandidateState::updateOrCreate(
        ['candidate_id' => $this->candidate->id],
        [
            'root_candidate_id' => $this->candidate->id,
            'case_id' => $this->case->id,
            'section_id' => $this->candidate->section_id,
            'prompt_version_id' => $this->candidate->prompt_version_id,
            'version_no' => 1,
            'status' => 'qualified',
            'check_run_id' => $checkRun->id,
            'qualification_id' => $qual->id,
            'safe_error_code' => null,
            'state_version' => 2,
        ]
    );

    // Now eligible -> 200
    $resPass = SceneQualificationFixtures::getSigned($this, $eligiblePath);
    $resPass->assertStatus(200);
    expect($resPass->json('data.candidate_ref'))->toBe($this->candidate->id);
    expect($resPass->json('data.qualification_ref'))->toBe($qual->id);
});
