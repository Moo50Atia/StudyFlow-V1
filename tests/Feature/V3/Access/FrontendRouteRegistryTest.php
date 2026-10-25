<?php

use Illuminate\Support\Facades\Route;

test('registered controller route actions are callable', function () {
    foreach (Route::getRoutes() as $route) {
        $action = $route->getActionName();
        if ($action === 'Closure' || !str_contains($action, '@')) continue;
        [$controller, $method] = explode('@', $action, 2);
        expect(class_exists($controller))->toBeTrue($action);
        expect(method_exists($controller, $method))->toBeTrue($action);
        expect((new ReflectionMethod($controller, $method))->isPublic())->toBeTrue($action);
    }
});
