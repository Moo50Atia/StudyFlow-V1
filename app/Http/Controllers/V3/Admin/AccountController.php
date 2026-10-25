<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\V3\UpdateAccountAccessRequest;
use App\Models\User;
use App\Services\V3\Access\ChangeAccountAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        $paginated = User::query()
            ->leftJoin('v3_account_access', 'users.id', '=', 'v3_account_access.user_id')
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.role',
                'v3_account_access.is_active as v3_is_active_raw',
                'v3_account_access.version as v3_version_raw',
            ])
            ->orderBy('users.id', 'asc')
            ->paginate(20);

        $transformedItems = $paginated->getCollection()->map(function ($u) {
            return [
                'id' => (int) $u->id,
                'name' => (string) $u->name,
                'email' => (string) $u->email,
                'role' => (string) $u->role,
                'v3_is_active' => (bool) $u->v3_is_active_raw,
                'v3_version' => (int) ($u->v3_version_raw ?? 0),
            ];
        });

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $transformedItems->values()->all(),
                'links' => [
                    'first' => $paginated->url(1),
                    'last' => $paginated->url($paginated->lastPage()),
                    'prev' => $paginated->previousPageUrl(),
                    'next' => $paginated->nextPageUrl(),
                ],
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'from' => $paginated->firstItem(),
                    'last_page' => $paginated->lastPage(),
                    'path' => $paginated->path(),
                    'per_page' => $paginated->perPage(),
                    'to' => $paginated->lastItem(),
                    'total' => $paginated->total(),
                ],
            ])->header('Cache-Control', 'private, no-store');
        }

        $paginated->setCollection($transformedItems);

        return \App\Support\InertiaPage::render('v3.admin.accounts.index', [
            'accounts' => $paginated,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function updateAccess(
        UpdateAccountAccessRequest $request,
        User $user,
        ChangeAccountAccess $service
    ): Response {
        $validated = $request->validated();

        $result = $service->updateAccess(
            actor: $request->user(),
            targetId: $user->id,
            role: $validated['role'],
            isActive: $request->booleanActive(),
            expectedVersion: (int) $validated['expected_version'],
            expectedRole: $validated['expected_role'],
            reasonCode: $validated['reason_code']
        );

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $result,
            ], 200)->header('Cache-Control', 'private, no-store');
        }

        $message = $result['changed']
            ? 'Account access updated successfully.'
            : 'No changes were made.';

        return redirect()
            ->route('v3.admin.accounts.index')
            ->setStatusCode(303)
            ->with('status', $message)
            ->header('Cache-Control', 'private, no-store');
    }
}
