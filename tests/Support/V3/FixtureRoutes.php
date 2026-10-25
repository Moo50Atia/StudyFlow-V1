<?php

namespace Tests\Support\V3;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

class FixtureRoutes
{
    public static function register(): void
    {
        if (Route::has('v3.test.resources.index')) {
            return;
        }

        Route::middleware(['web', 'v3.private', 'v3.role:teacher'])->prefix('v3/test-fixtures')->group(function () {
            Route::get('resources', function (Request $request) {
                $actor = $request->user();
                if (! $actor) {
                    abort(401);
                }
                $fixtures = OwnedFixture::ownedBy($actor)->paginate(10);

                return response()->json([
                    'data' => $fixtures->items(),
                    'total' => $fixtures->total(),
                ]);
            })->name('v3.test.resources.index');

            Route::post('resources', function (Request $request) {
                $actor = $request->user();
                if (! $actor) {
                    abort(401);
                }
                if ($request->has('owner_user_id')) {
                    return response()->json([
                        'error' => [
                            'code' => 'validation_error',
                            'message' => 'Prohibited field: owner_user_id cannot be assigned by client.',
                        ],
                    ], 422);
                }
                $fixture = OwnedFixture::create([
                    'owner_user_id' => $actor->id,
                    'parent_id' => $request->input('parent_id'),
                    'content' => $request->input('content', 'default content'),
                ]);

                return response()->json(['data' => $fixture], 201);
            })->name('v3.test.resources.store');

            Route::get('resources/{fixture}', function (string $fixtureId, Request $request) {
                $actor = $request->user();
                $fixture = OwnedFixture::find($fixtureId);
                if (! $fixture) {
                    return response()->json([
                        'error' => [
                            'code' => 'resource_unavailable',
                            'message' => 'V3 resource is unavailable.',
                        ],
                    ], 404);
                }
                if (Gate::denies('view', $fixture) || $fixture->v3OwnerId() !== $actor?->id) {
                    return response()->json([
                        'error' => [
                            'code' => 'resource_unavailable',
                            'message' => 'V3 resource is unavailable.',
                        ],
                    ], 404);
                }

                return response()->json(['data' => $fixture]);
            })->name('v3.test.resources.show');

            Route::patch('resources/{fixture}', function (string $fixtureId, Request $request) {
                $actor = $request->user();
                $fixture = OwnedFixture::find($fixtureId);
                if (! $fixture) {
                    return response()->json([
                        'error' => [
                            'code' => 'resource_unavailable',
                            'message' => 'V3 resource is unavailable.',
                        ],
                    ], 404);
                }
                if (Gate::denies('update', $fixture) || $fixture->v3OwnerId() !== $actor?->id) {
                    return response()->json([
                        'error' => [
                            'code' => 'resource_unavailable',
                            'message' => 'V3 resource is unavailable.',
                        ],
                    ], 404);
                }
                if ($request->has('owner_user_id')) {
                    return response()->json([
                        'error' => [
                            'code' => 'validation_error',
                            'message' => 'Prohibited field: owner_user_id cannot be modified.',
                        ],
                    ], 422);
                }
                $fixture->update($request->only('content'));

                return response()->json(['data' => $fixture]);
            })->name('v3.test.resources.update');

            Route::post('resources/{fixture}/review', function (string $fixtureId, Request $request) {
                $actor = $request->user();
                $fixture = OwnedFixture::find($fixtureId);
                if (! $fixture) {
                    return response()->json([
                        'error' => [
                            'code' => 'resource_unavailable',
                            'message' => 'V3 resource is unavailable.',
                        ],
                    ], 404);
                }
                if (Gate::denies('review', $fixture) || $fixture->v3OwnerId() !== $actor?->id) {
                    return response()->json([
                        'error' => [
                            'code' => 'resource_unavailable',
                            'message' => 'V3 resource is unavailable.',
                        ],
                    ], 404);
                }

                return response()->json(['data' => ['status' => 'reviewed']]);
            })->name('v3.test.resources.review');

            Route::get('resources/{fixture}/download', function (string $fixtureId, Request $request) {
                $actor = $request->user();
                $fixture = OwnedFixture::find($fixtureId);
                if (! $fixture) {
                    return \App\Support\InertiaPage::render('v3.errors.404', [
                        'message' => 'The requested V3 resource is unavailable.',
                    ], 404);
                }
                if (Gate::denies('download', $fixture) || $fixture->v3OwnerId() !== $actor?->id) {
                    return \App\Support\InertiaPage::render('v3.errors.404', [
                        'message' => 'The requested V3 resource is unavailable.',
                    ], 404);
                }

                return response($fixture->content, 200, [
                    'Content-Type' => 'text/plain',
                ]);
            })->name('v3.test.resources.download');

            Route::get('parents/{parent}/children/{child}', function (string $parentId, string $childId, Request $request) {
                $actor = $request->user();
                $parent = OwnedFixture::find($parentId);
                if (! $parent) {
                    return response()->json([
                        'error' => [
                            'code' => 'resource_unavailable',
                            'message' => 'V3 resource is unavailable.',
                        ],
                    ], 404);
                }
                if (Gate::denies('view', $parent) || $parent->v3OwnerId() !== $actor?->id) {
                    return response()->json([
                        'error' => [
                            'code' => 'resource_unavailable',
                            'message' => 'V3 resource is unavailable.',
                        ],
                    ], 404);
                }

                $child = $parent->children()->where('id', $childId)->first();
                if (! $child) {
                    return response()->json([
                        'error' => [
                            'code' => 'resource_unavailable',
                            'message' => 'V3 resource is unavailable.',
                        ],
                    ], 404);
                }
                if (Gate::denies('view', $child) || $child->v3OwnerId() !== $actor?->id) {
                    return response()->json([
                        'error' => [
                            'code' => 'resource_unavailable',
                            'message' => 'V3 resource is unavailable.',
                        ],
                    ], 404);
                }

                return response()->json(['data' => $child]);
            })->name('v3.test.nested.show');
        });
    }

    public static function reset(): void
    {
        static::$registered = false;
    }
}
