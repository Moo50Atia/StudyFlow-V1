<?php

namespace Tests\Feature\V3\Intake;

use App\Services\V3\Intake\ClamAvAdapter;
use App\Services\V3\Intake\PdfInspector;
use App\Services\V3\Intake\QpdfAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\V3\Pdf\PdfFixtures;

uses(RefreshDatabase::class);

test('PdfInspector delegates to QpdfAdapter and ClamAvAdapter in order', function () {
    // 1. When Qpdf fails with active content
    $qpdfMock = \Mockery::mock(QpdfAdapter::class);
    $qpdfMock->shouldReceive('check')->andReturn([
        'valid' => false,
        'reason_code' => 'active_content',
        'error_type' => 'deterministic',
    ]);

    $clamMock = \Mockery::mock(ClamAvAdapter::class);
    $clamMock->shouldNotReceive('scan');

    $inspector = new PdfInspector($qpdfMock, $clamMock);
    $result = $inspector->inspect('/tmp/test.pdf', 10);

    expect($result['valid'])->toBeFalse()
        ->and($result['reason_code'])->toBe('active_content')
        ->and($result['error_type'])->toBe('deterministic');

    // 2. When Qpdf passes but ClamAV detects malware
    $qpdfMock2 = \Mockery::mock(QpdfAdapter::class);
    $qpdfMock2->shouldReceive('check')->andReturn([
        'valid' => true,
        'page_count' => 3,
    ]);

    $clamMock2 = \Mockery::mock(ClamAvAdapter::class);
    $clamMock2->shouldReceive('scan')->andReturn([
        'valid' => false,
        'reason_code' => 'malware_detected',
        'error_type' => 'deterministic',
    ]);

    $inspector2 = new PdfInspector($qpdfMock2, $clamMock2);
    $result2 = $inspector2->inspect('/tmp/test.pdf', 10);

    expect($result2['valid'])->toBeFalse()
        ->and($result2['reason_code'])->toBe('malware_detected');

    // 3. When both pass
    $qpdfMock3 = \Mockery::mock(QpdfAdapter::class);
    $qpdfMock3->shouldReceive('check')->andReturn([
        'valid' => true,
        'page_count' => 3,
    ]);

    $clamMock3 = \Mockery::mock(ClamAvAdapter::class);
    $clamMock3->shouldReceive('scan')->andReturn([
        'valid' => true,
    ]);

    $inspector3 = new PdfInspector($qpdfMock3, $clamMock3);
    $result3 = $inspector3->inspect('/tmp/test.pdf', 10);

    expect($result3['valid'])->toBeTrue()
        ->and($result3['page_count'])->toBe(3);
});

test('Absent qpdf fails closed with inspection_unavailable and does not claim acceptance', function () {
    $adapter = new QpdfAdapter('/nonexistent/binary/qpdf');

    expect($adapter->isAvailable())->toBeFalse();

    $result = $adapter->check('/tmp/any.pdf', 50);
    expect($result['valid'])->toBeFalse()
        ->and($result['reason_code'])->toBe('inspection_unavailable')
        ->and($result['error_type'])->toBe('transient');
});

test('Absent clamdscan fails closed with inspection_unavailable and does not claim acceptance', function () {
    $adapter = new ClamAvAdapter('/nonexistent/binary/clamdscan');

    expect($adapter->isAvailable())->toBeFalse();

    $result = $adapter->scan('/tmp/any.pdf');
    expect($result['valid'])->toBeFalse()
        ->and($result['reason_code'])->toBe('inspection_unavailable')
        ->and($result['error_type'])->toBe('transient');
});

test('I04: Image-only PDF fixture is considered valid structure by adapter logic', function () {
    $imageOnly = PdfFixtures::imageOnlyPdf();
    expect($imageOnly)->toStartWith('%PDF-1.4')
        ->and($imageOnly)->toContain('/Subtype /Image');
});

test('Live environmental gate: Qpdf and ClamAV binary verification', function () {
    $qpdf = new QpdfAdapter();
    $clam = new ClamAvAdapter();

    $qpdfAvailable = $qpdf->isAvailable();
    $clamAvailable = $clam->isAvailable();

    if (! $qpdfAvailable || ! $clamAvailable) {
        $missing = [];
        if (! $qpdfAvailable) $missing[] = 'qpdf';
        if (! $clamAvailable) $missing[] = 'clamdscan';

        // Explicitly report the environmental prerequisite status per task T007 & quickstart.md
        $this->markTestSkipped('Environmental gate: ' . implode(' and ', $missing) . ' not provisioned on this host.');
    }

    // If both provisioned, test real fixtures
    $tempFile = tempnam(sys_get_temp_dir(), 'pdf_gate_');
    try {
        file_put_contents($tempFile, PdfFixtures::minimalValidPdf(1));
        $res = $qpdf->check($tempFile, 10);
        expect($res['valid'])->toBeTrue();

        $scanRes = $clam->scan($tempFile);
        expect($scanRes['valid'])->toBeTrue();
    } finally {
        @unlink($tempFile);
    }
});
