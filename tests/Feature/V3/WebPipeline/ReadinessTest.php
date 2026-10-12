<?php

namespace Tests\Feature\V3\WebPipeline;

use App\Services\V3\WebPipeline\Readiness;
use Tests\TestCase;

class ReadinessTest extends TestCase
{
    protected Readiness $readiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->readiness = new Readiness();
    }

    public function test_parse_size_to_bytes(): void
    {
        $this->assertSame(104857600, $this->readiness->parseSizeToBytes('100M'));
        $this->assertSame(1073741824, $this->readiness->parseSizeToBytes('1G'));
        $this->assertSame(1024, $this->readiness->parseSizeToBytes('1K'));
        $this->assertSame(500, $this->readiness->parseSizeToBytes('500'));
        $this->assertSame(PHP_INT_MAX, $this->readiness->parseSizeToBytes('-1'));
    }

    public function test_readiness_checks_return_safe_diagnostics_without_leaking_secrets(): void
    {
        config(['services.v3.web_pipeline_enabled' => false]);
        config(['services.v3.default_mode' => 'export_manual']);
        config(['services.v3.notebook_protocol' => 'legacy_staged_v1']);
        config(['services.v3.bridge_url' => 'http://127.0.0.1:8765']);
        config(['services.v3.bridge_hmac_key' => 'super-secret-hmac-key']);

        $report = $this->readiness->check(enforceLimits: false);

        $this->assertFalse($report['opt_in']);
        $this->assertSame('export_manual', $report['default_mode']);
        $this->assertSame('legacy_staged_v1', $report['legacy_protocol_default']);
        $this->assertArrayHasKey('upload_max_filesize', $report['checks']);
        $this->assertArrayHasKey('post_max_size', $report['checks']);
        $this->assertArrayHasKey('max_file_uploads', $report['checks']);
        $this->assertArrayHasKey('upload_tmp_dir', $report['checks']);
        $this->assertArrayHasKey('private_storage', $report['checks']);
        $this->assertArrayHasKey('bridge_configuration', $report['checks']);
        $this->assertArrayHasKey('provider_credentials', $report['checks']);

        // Assert zero secret leakage in serialized report
        $serialized = json_encode($report);
        $this->assertStringNotContainsString('super-secret-hmac-key', $serialized);
        $this->assertTrue($report['checks']['bridge_configuration']['detail']['hmac_key_present']);
    }

    public function test_readiness_fails_when_enforcing_insufficient_upload_limits(): void
    {
        // When running under CLI where upload_max_filesize is 2M, enforcing limits marks ready=false
        $report = $this->readiness->check(enforceLimits: true);

        // If CLI limits are lower than 100M/1100M, ready must be false
        $uploadBytes = $this->readiness->parseSizeToBytes(ini_get('upload_max_filesize') ?: '2M');
        if ($uploadBytes < Readiness::REQUIRED_UPLOAD_MAX_FILESIZE_BYTES) {
            $this->assertFalse($report['ready']);
            $this->assertSame('fail', $report['checks']['upload_max_filesize']['status']);
        }
    }
}
