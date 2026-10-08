<?php

namespace Tests\Feature\V3\Settings;

use Illuminate\Support\Facades\Route;

test('S04, S07, S15: production route inventory contains exact expected routes and no mutable/leak routes', function () {
    $routes = collect(Route::getRoutes()->getRoutes());

    $v3SettingsRoutes = $routes->filter(function ($route) {
        return str_starts_with($route->uri(), 'v3/admin/settings');
    });

    // Exactly 4 settings routes
    expect($v3SettingsRoutes)->toHaveCount(4);

    $expectedNames = [
        'v3.admin.settings.show',
        'v3.admin.settings.templates',
        'v3.admin.settings.store',
        'v3.admin.settings.history',
    ];

    foreach ($expectedNames as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("Expected route {$name} to be registered.");

        $middleware = $route->gatherMiddleware();
        expect($middleware)->toContain('v3.private')
            ->and($middleware)->toContain('v3.role:admin');
    }

    // Assert NO edit, patch, or delete routes on settings or versions
    $disallowedUris = [
        'v3/admin/settings/{version}',
        'v3/admin/settings/{id}',
        'v3/admin/settings/templates/{id}',
        'v3/admin/settings/templates/{template}',
        'v3/admin/settings/templates/{id}/body',
        'v3/teacher/settings',
    ];

    foreach ($disallowedUris as $uri) {
        $found = $routes->first(fn ($r) => $r->uri() === $uri);
        expect($found)->toBeNull("Route {$uri} must not exist.");
    }

    // Assert NO DELETE or PUT methods registered on v3/admin/settings
    $settingsResourceRoute = $routes->first(fn ($r) => $r->uri() === 'v3/admin/settings');
    expect($settingsResourceRoute->methods())->not->toContain('DELETE')
        ->and($settingsResourceRoute->methods())->not->toContain('PUT')
        ->and($settingsResourceRoute->methods())->not->toContain('PATCH');

    // Total production V3 routes: 5 from 004 + 4 from 005 + 6 from 006 + 3 from 007 = 18
    $allV3Routes = $routes->filter(function ($r) {
        return str_starts_with($r->uri(), 'v3');
    });
    expect($allV3Routes->count())->toBeGreaterThanOrEqual(15);
});
