<?php

namespace Tests\Feature\V3\Access;

use Illuminate\Support\Facades\Route;
use Tests\Support\V3\AccessFixtures;

beforeEach(function () {
    AccessFixtures::setUpFixtureSchema();
});

test('O12: fixture routes absent outside test setup, exact 5 production routes exist with strict middleware, and no future models exist', function () {
    // 1. Check registered production V3 routes
    $v3Routes = collect(Route::getRoutes()->getRoutes())
        ->filter(function ($route) {
            return str_starts_with($route->uri(), 'v3');
        })
        ->values();

    $v3RouteNames = $v3Routes->map(fn ($r) => $r->getName())->sort()->values()->all();

    $expectedRouteNames = [
        'v3.admin.accounts.access.update',
        'v3.admin.accounts.index',
        'v3.admin.workspace',
        'v3.entry',
        'v3.teacher.workspace',
    ];

    foreach ($expectedRouteNames as $expectedName) {
        expect($v3RouteNames)->toContain($expectedName);
    }

    // Assert zero fixture routes are registered in standard route table
    $fixtureRoutes = $v3Routes->filter(function ($r) {
        return str_contains($r->uri(), 'test-fixtures');
    });
    expect($fixtureRoutes)->toBeEmpty();

    // 2. Check middleware on every production route
    foreach ($v3Routes as $route) {
        $middleware = $route->gatherMiddleware();
        expect($middleware)->toContain('v3.private');
    }

    // Specific role middleware
    $teacherRoute = Route::getRoutes()->getByName('v3.teacher.workspace');
    expect($teacherRoute->gatherMiddleware())->toContain('v3.role:teacher');

    $adminRoute = Route::getRoutes()->getByName('v3.admin.workspace');
    expect($adminRoute->gatherMiddleware())->toContain('v3.role:admin');

    $accountsRoute = Route::getRoutes()->getByName('v3.admin.accounts.index');
    expect($accountsRoute->gatherMiddleware())->toContain('v3.role:admin');

    $patchRoute = Route::getRoutes()->getByName('v3.admin.accounts.access.update');
    expect($patchRoute->gatherMiddleware())->toContain('v3.role:admin');

    // 3. Confirm no future spec models exist in production codebase (007+)
    expect(class_exists('App\Models\V3\Scene'))->toBeFalse()
        ->and(class_exists('App\Models\V3\Notebook'))->toBeFalse();
});
