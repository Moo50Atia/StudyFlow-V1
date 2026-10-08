<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\User;
use App\Models\V3\V3AccountAccess;
use App\Services\V3\Access\ChangeAccountAccess;
use App\Services\V3\Access\RetainV3Account;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(): \Illuminate\Contracts\View\View
    {
        $users = User::latest()->paginate(10);

        return view('users.index', compact('users'));
    }

    public function create(): \Illuminate\Contracts\View\View
    {
        return view('users.create');
    }

    public function store(UserRequest $request, ChangeAccountAccess $service): \Illuminate\Http\RedirectResponse
    {
        $service->createFromLegacy($request->user(), $request->validated());

        return redirect()->route('users.index')->with('success', 'User created successfully!');
    }

    public function show(User $user): \Illuminate\Contracts\View\View
    {
        $user->load('teacherPermissions.subject', 'teacherPermissions.lecture');

        return view('users.show', compact('user'));
    }

    public function edit(User $user): \Illuminate\Contracts\View\View
    {
        return view('users.edit', compact('user'));
    }

    public function update(UserRequest $request, User $user, ChangeAccountAccess $service): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validated();

        $service->updateFromLegacy(
            actor: $request->user(),
            targetId: $user->id,
            identityData: $validated,
            expectedVersion: (int) $validated['expected_version'],
            expectedRole: $validated['expected_role']
        );

        return redirect()->route('users.index')->with('success', 'User updated successfully!');
    }

    public function destroy(Request $request, User $user, RetainV3Account $retainedAccount): \Illuminate\Http\RedirectResponse
    {
        $actor = $request->user();
        $actorAccess = V3AccountAccess::where('user_id', $actor->id)->first();
        if (! $actorAccess || ! $actorAccess->is_active) {
            abort(403, 'V3 access is unavailable.');
        }

        $retainedAccount->assertCanDelete($user);

        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted successfully!');
    }
}
