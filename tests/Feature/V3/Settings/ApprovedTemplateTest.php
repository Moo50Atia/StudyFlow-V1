<?php

namespace Tests\Feature\V3\Settings;

use App\Models\V3\ApprovedTemplate;
use Illuminate\Support\Facades\Artisan;

test('S15: trusted CLI command registers approved templates with valid digest', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'tmpl_');
    $content = "You are an approved scene generator.\nGenerate strict Three.js code.";
    file_put_contents($tempFile, $content);
    $sha256 = hash('sha256', $content);

    $exitCode = Artisan::call('v3:register-approved-template', [
        'kind' => 'scene',
        'id' => 'scene-threejs-v1',
        'path' => $tempFile,
        'sha256' => $sha256,
    ]);

    expect($exitCode)->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('scene-threejs-v1')
        ->and($output)->toContain('scene')
        ->and($output)->toContain($sha256)
        ->and($output)->not->toContain($content); // Template body must NOT be in output

    $record = ApprovedTemplate::find('scene-threejs-v1');
    expect($record)->not->toBeNull()
        ->and($record->kind)->toBe('scene')
        ->and($record->body)->toBe($content)
        ->and($record->checksum)->toBe($sha256);

    unlink($tempFile);
});

test('S15: trusted CLI command rejects invalid kind or malformed ID', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'tmpl_');
    file_put_contents($tempFile, 'valid body');
    $sha256 = hash('sha256', 'valid body');

    // Invalid kind
    $exitCode1 = Artisan::call('v3:register-approved-template', [
        'kind' => 'invalid_kind',
        'id' => 'tmpl-valid-1',
        'path' => $tempFile,
        'sha256' => $sha256,
    ]);
    expect($exitCode1)->not->toBe(0);

    // Invalid ID: starts with number
    $exitCode2 = Artisan::call('v3:register-approved-template', [
        'kind' => 'feedback',
        'id' => '123-invalid',
        'path' => $tempFile,
        'sha256' => $sha256,
    ]);
    expect($exitCode2)->not->toBe(0);

    // Invalid ID: contains uppercase
    $exitCode3 = Artisan::call('v3:register-approved-template', [
        'kind' => 'feedback',
        'id' => 'Invalid_Uppercase',
        'path' => $tempFile,
        'sha256' => $sha256,
    ]);
    expect($exitCode3)->not->toBe(0);

    unlink($tempFile);
});

test('S15: trusted CLI command rejects digest mismatch, missing file, or oversize file', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'tmpl_');
    file_put_contents($tempFile, 'real content');

    // Digest mismatch
    $exitCode1 = Artisan::call('v3:register-approved-template', [
        'kind' => 'feedback',
        'id' => 'tmpl-mismatch-1',
        'path' => $tempFile,
        'sha256' => str_repeat('a', 64),
    ]);
    expect($exitCode1)->not->toBe(0);

    // Missing file
    $exitCode2 = Artisan::call('v3:register-approved-template', [
        'kind' => 'feedback',
        'id' => 'tmpl-missing-1',
        'path' => '/non/existent/path/file.txt',
        'sha256' => str_repeat('a', 64),
    ]);
    expect($exitCode2)->not->toBe(0);

    // Oversize file (> 65536 bytes)
    $largeContent = str_repeat('x', 65537);
    file_put_contents($tempFile, $largeContent);
    $largeSha256 = hash('sha256', $largeContent);
    $exitCode3 = Artisan::call('v3:register-approved-template', [
        'kind' => 'feedback',
        'id' => 'tmpl-oversize-1',
        'path' => $tempFile,
        'sha256' => $largeSha256,
    ]);
    expect($exitCode3)->not->toBe(0);

    unlink($tempFile);
});

test('S15: trusted CLI command is idempotent on exact match and fails on conflicting duplicate', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'tmpl_');
    $content = 'Exact content';
    file_put_contents($tempFile, $content);
    $sha256 = hash('sha256', $content);

    // First registration
    $exit1 = Artisan::call('v3:register-approved-template', [
        'kind' => 'feedback',
        'id' => 'tmpl-idempotent-1',
        'path' => $tempFile,
        'sha256' => $sha256,
    ]);
    expect($exit1)->toBe(0);

    // Re-registration with exact same kind and content is no-op success
    $exit2 = Artisan::call('v3:register-approved-template', [
        'kind' => 'feedback',
        'id' => 'tmpl-idempotent-1',
        'path' => $tempFile,
        'sha256' => $sha256,
    ]);
    expect($exit2)->toBe(0);

    // Conflicting registration with different content fails
    file_put_contents($tempFile, 'Different content');
    $diffSha256 = hash('sha256', 'Different content');
    $exit3 = Artisan::call('v3:register-approved-template', [
        'kind' => 'feedback',
        'id' => 'tmpl-idempotent-1',
        'path' => $tempFile,
        'sha256' => $diffSha256,
    ]);
    expect($exit3)->not->toBe(0);

    // Original row remains unchanged
    $record = ApprovedTemplate::find('tmpl-idempotent-1');
    expect($record->body)->toBe('Exact content')
        ->and($record->checksum)->toBe($sha256);

    unlink($tempFile);
});
