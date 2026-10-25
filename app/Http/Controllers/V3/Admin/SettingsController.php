<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\V3\PublishSettingsRequest;
use App\Http\Resources\V3\SettingsResource;
use App\Models\V3\ActiveSettings;
use App\Models\V3\ApprovedTemplate;
use App\Models\V3\SettingsVersion;
use App\Services\V3\Settings\PublishSettings;
use App\Services\V3\Settings\SettingsConflict;
use App\Services\V3\Settings\SettingsUnavailable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SettingsController extends Controller
{
    public function __construct(
        protected PublishSettings $publishSettings
    ) {}

    public function show(Request $request)
    {
        $active = ActiveSettings::where('key', 'pilot')->first();
        $version = $active?->active_settings_version_id
            ? SettingsVersion::find($active->active_settings_version_id)
            : null;

        if ($request->expectsJson()) {
            return (new SettingsResource($version))
                ->response()
                ->header('Cache-Control', 'private, no-store');
        }

        return \App\Support\InertiaPage::render('v3.admin.settings.show', [
            'activeVersion' => $version,
            'settings' => $version?->payload ?? [],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function templates(Request $request)
    {
        $templates = ApprovedTemplate::orderBy('kind')
            ->orderBy('id')
            ->get(['id', 'kind', 'checksum', 'registered_at']);

        $data = $templates->map(fn ($t) => [
            'id' => $t->id,
            'kind' => $t->kind,
            'checksum' => $t->checksum,
            'registered_at' => $t->registered_at->toIso8601String(),
        ])->values()->all();

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $data,
            ])->header('Cache-Control', 'private, no-store');
        }

        return \App\Support\InertiaPage::render('v3.admin.settings.templates', [
            'templates' => $data,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(PublishSettingsRequest $request)
    {
        try {
            $version = $this->publishSettings->publish(
                payload: $request->all(),
                expectedActiveVersion: (int) $request->input('expected_active_version'),
                actorUserId: (int) $request->user()->id
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'data' => [
                        'version_id' => $version->id,
                        'version' => $version->version,
                        'checksum' => $version->checksum,
                        'active_version' => $version->version,
                    ],
                ], 201)->header('Cache-Control', 'private, no-store');
            }

            return redirect()
                ->route('v3.admin.settings.show')
                ->setStatusCode(303)
                ->with('status', 'Settings published successfully.')
                ->header('Cache-Control', 'private, no-store');
        } catch (SettingsConflict $e) {
            throw $e;
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new SettingsUnavailable('V3 settings are unavailable.');
        }
    }

    public function history(Request $request)
    {
        if ($request->has('page')) {
            $pageInput = $request->input('page');
            if (is_bool($pageInput) || ! is_numeric($pageInput) || (int) $pageInput < 1 || (string) (int) $pageInput !== (string) $pageInput) {
                throw ValidationException::withMessages([
                    'page' => ['The page must be a positive integer.'],
                ]);
            }
        }

        $page = (int) $request->input('page', 1);
        $perPage = 20;

        $versions = SettingsVersion::with('publication')
            ->orderBy('version', 'desc')
            ->forPage($page, $perPage)
            ->get();

        $items = $versions->map(function ($v) {
            return [
                'version_id' => $v->id,
                'version' => $v->version,
                'publisher_user_id' => $v->published_by_user_id,
                'published_at' => $v->published_at->toIso8601String(),
                'checksum' => $v->checksum,
                'changed_paths' => $v->publication?->changed_paths ?? [],
            ];
        })->values()->all();

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $items,
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                ],
            ])->header('Cache-Control', 'private, no-store');
        }

        return \App\Support\InertiaPage::render('v3.admin.settings.history', [
            'versions' => $items,
            'page' => $page,
            'perPage' => $perPage,
        ])->header('Cache-Control', 'private, no-store');
    }
}
