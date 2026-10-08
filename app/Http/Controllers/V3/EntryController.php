<?php

namespace App\Http\Controllers\V3;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EntryController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $role = $request->attributes->get('v3_role') ?? $request->user()?->role;

        if ($role === 'teacher') {
            return redirect()->route('v3.teacher.workspace');
        }

        if ($role === 'admin') {
            return redirect()->route('v3.admin.workspace');
        }

        abort(403, 'V3 access is unavailable.');
    }
}
