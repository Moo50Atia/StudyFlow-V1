<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $admin = $request->attributes->get('v3_actor') ?? $request->user();

        return \App\Support\InertiaPage::render('v3.admin.workspace', [
            'admin' => $admin,
        ]);
    }
}
