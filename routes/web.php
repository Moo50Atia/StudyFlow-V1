<?php

use App\Http\Controllers\APIfilterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExamQuestionController;
use App\Http\Controllers\LectureController;
use App\Http\Controllers\LoveController;
use App\Http\Controllers\MohammedTestController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\StudyFlowController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\TeacherPermissionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// Main dashboard route - redirects based on role
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/owner-assets/{kind}/{id}/{field}/{index?}', [\App\Http\Controllers\OwnerAssetController::class, 'show'])->name('owner-assets.show');
    Route::post('/lessons/{node}/publication', [\App\Http\Controllers\V3\LessonPublicationController::class, 'update'])->name('lessons.publication.update');
    Route::post('/scenes/{approval}/publication', [\App\Http\Controllers\V3\StudentSceneController::class, 'publication'])->name('student-scenes.publication');
    Route::get('/scenes/{approval}/{file}', [\App\Http\Controllers\V3\StudentSceneController::class, 'file'])->name('student-scenes.file');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Love toggle route
    Route::post('/love/toggle', [LoveController::class, 'toggle'])->name('love.toggle');
});

require __DIR__.'/auth.php';

// Admin-only routes
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::resource('/users', UserController::class);
    Route::resource('/teacher_permissions', TeacherPermissionController::class)->middleware('platform.owner');

    // Study Flow routes (Admin only)
    Route::prefix('study-flow')->middleware('platform.owner')->group(function () {
        Route::get('/start', [StudyFlowController::class, 'start'])->name('study-flow.start');
        Route::post('/subjects/{subject}/lectures', [StudyFlowController::class, 'storeBulkLectures'])->name('study-flow.bulk-lectures');
        Route::post('/lectures/{lecture}/sections', [StudyFlowController::class, 'storeBulkSections'])->name('study-flow.bulk-sections');
        Route::post('/sections/{section}/content', [StudyFlowController::class, 'storeBulkContent'])->name('study-flow.bulk-content');
        Route::get('/exit', [StudyFlowController::class, 'exit'])->name('study-flow.exit');
    });
});

// Teacher Study Flow routes
use App\Http\Controllers\TeacherStudyFlowController;

Route::middleware(['auth', 'role:teacher'])->prefix('teacher-study-flow')->group(function () {
    Route::get('/start', [TeacherStudyFlowController::class, 'start'])->name('teacher-study-flow.start');
    Route::get('/lectures', [TeacherStudyFlowController::class, 'lectures'])->name('teacher-study-flow.lectures');
    Route::post('/lectures', [TeacherStudyFlowController::class, 'storeLecture'])->name('teacher-study-flow.store-lecture');
    Route::post('/lectures/{lecture}/sections', [TeacherStudyFlowController::class, 'storeBulkSections'])->name('teacher-study-flow.bulk-sections');
    // Question creation is temporarily reserved for the platform owner.
    Route::get('/exit', [TeacherStudyFlowController::class, 'exit'])->name('teacher-study-flow.exit');
});

// Admin and Teacher routes (with teacher access check)
Route::middleware(['auth', 'teacher.access'])->group(function () {
    Route::resource('/subjects', SubjectController::class);
    Route::resource('/lectures', LectureController::class);
    Route::resource('/sections', SectionController::class);
    Route::resource('/questions', QuestionController::class);
    Route::resource('/exam_questions', ExamQuestionController::class);
});

// API routes for dynamic filtering
use App\Models\Lecture;

// Route::middleware('auth')->prefix('api')->group(function () {
//     Route::get('/subjects/{subject}/lectures', function ($subjectId) {
//         return [APIfilterController::class, 'getLectures'];
//     });
//     Route::get('/lectures/{lecture}/sections', function ($lectureId) {
//         return [APIfilterController::class, 'getSections'];
//     });
// });
Route::middleware('auth')->prefix('api')->group(function () {
    // الطريقة القياسية والصحيحة
    Route::get('/subjects/{subject}/lectures', [APIfilterController::class, 'getLectures']);
    Route::get('/lectures/{lecture}/sections', [APIfilterController::class, 'getSections']);
});

Route::get('/mohammed-test', [MohammedTestController::class, 'index'])->middleware('auth')->name('mohammed-test');

// Load V3 routes
require __DIR__.'/v3.php';
