<?php

namespace App\Http\Controllers\V3;

use App\Http\Controllers\Controller;
use App\Models\V3\SubjectRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RunLocationController extends Controller
{
    public function show(Request $request, SubjectRun $run): RedirectResponse
    {
        return $this->location($request, $run, false);
    }

    public function scenes(Request $request, SubjectRun $run): RedirectResponse
    {
        return $this->location($request, $run, true);
    }

    private function location(Request $request, SubjectRun $run, bool $scenes): RedirectResponse
    {
        $actor = $request->user();
        if ($actor->role === 'admin') {
            return redirect()->route('v3.admin.subject-runs.show', $run);
        }
        abort_unless($actor->role === 'teacher' && (int) $run->owner_user_id === (int) $actor->id, 404);
        return redirect()->route($scenes ? 'v3.teacher.subject-runs.scenes' : 'v3.teacher.subject-runs.show', $run);
    }
}
