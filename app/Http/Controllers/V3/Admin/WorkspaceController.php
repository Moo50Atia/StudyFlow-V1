<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        $admin = $request->attributes->get('v3_actor') ?? $request->user();

        return view('v3.admin.workspace', [
            'admin' => $admin,
        ]);
    }
}
