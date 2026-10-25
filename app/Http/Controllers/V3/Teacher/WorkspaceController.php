<?php

namespace App\Http\Controllers\V3\Teacher;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $teacher = $request->attributes->get('v3_actor') ?? $request->user();

        return \App\Support\InertiaPage::render('v3.teacher.workspace', [
            'teacher' => $teacher,
        ]);
    }
}
