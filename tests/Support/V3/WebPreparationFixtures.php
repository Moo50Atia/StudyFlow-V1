<?php

namespace Tests\Support\V3;

use App\Services\V3\WebPipeline\PrepareWebPdfs;
use App\Services\V3\Settings\SelectCaseSettings;
use Illuminate\Support\Facades\DB;
use Mockery;

class WebPreparationFixtures
{
    /** Isolate existing intake/ownership tests from the local preparation service. */
    public static function fake(): void
    {
        $mock = Mockery::mock(PrepareWebPdfs::class);
        $mock->shouldReceive('prepare')->andReturnUsing(function ($actor, $key, $files, $titles) {
            try {
                $settings = DB::transaction(fn () => app(SelectCaseSettings::class)->selectForNewCase());
            } catch (\Throwable) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(422, 'Settings unavailable');
            }
            return ['files' => $files, 'titles' => $titles, 'settings_version_id' => $settings->id,
                'capture' => null, 'lineages' => array_fill(0, count($files), null)];
        });
        app()->instance(PrepareWebPdfs::class, $mock);
    }
}
