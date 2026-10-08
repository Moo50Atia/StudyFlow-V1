<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::policy(\App\Models\V3\Document::class, \App\Policies\V3\DocumentPolicy::class);
        \Illuminate\Support\Facades\Gate::policy(\App\Models\V3\UploadAttempt::class, \App\Policies\V3\UploadAttemptPolicy::class);
        \Illuminate\Support\Facades\Gate::policy(\App\Models\V3\ProcessingCase::class, \App\Policies\V3\ProcessingCasePolicy::class);
    }
}
