<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeacherPermissionRequest;
use App\Models\TeacherPermission;
use App\Models\User;
use App\Models\Subject;
use App\Models\Lecture;

class TeacherPermissionController extends Controller
{
    public function index()
    {
        $teacher_permissions = TeacherPermission::with(['teacher', 'subject', 'lecture'])
            ->latest()
            ->paginate(10);
        return \App\Support\InertiaPage::render('teacher_permissions.index', compact('teacher_permissions'));
    }

    public function create()
    {
        $teachers = User::where('role', 'teacher')->get();
        $subjects = Subject::all();
        $lectures = Lecture::all();
        return \App\Support\InertiaPage::render('teacher_permissions.create', compact('teachers', 'subjects', 'lectures'));
    }

    public function store(TeacherPermissionRequest $request): \Illuminate\Http\RedirectResponse
    {
        TeacherPermission::create($request->validated());
        return redirect()->route('teacher_permissions.index')->with('success', 'Permission created successfully');
    }

    public function show(TeacherPermission $teacherPermission)
    {
        $teacherPermission->load(['teacher', 'subject', 'lecture']);
        return \App\Support\InertiaPage::render('teacher_permissions.show', compact('teacherPermission'));
    }

    public function edit(TeacherPermission $teacherPermission)
    {
        $teachers = User::where('role', 'teacher')->get();
        $subjects = Subject::all();
        $lectures = Lecture::all();
        return \App\Support\InertiaPage::render('teacher_permissions.edit', compact('teacherPermission', 'teachers', 'subjects', 'lectures'));
    }

    public function update(TeacherPermissionRequest $request, TeacherPermission $teacherPermission): \Illuminate\Http\RedirectResponse
    {
        $teacherPermission->update($request->validated());
        return redirect()->route('teacher_permissions.index')->with('success', 'Permission updated successfully');
    }

    public function destroy(TeacherPermission $teacherPermission): \Illuminate\Http\RedirectResponse
    {
        $teacherPermission->delete();
        return redirect()->route('teacher_permissions.index')->with('success', 'Permission deleted successfully');
    }
}
