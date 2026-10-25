import React from 'react';
import { Head, Link, useForm, usePoll } from '@inertiajs/react';
import AppLayout from '../components/AppLayout';
import PageHeader from '../components/PageHeader';
import Button from '../components/Button';
import StatusBadge from '../components/StatusBadge';
import EmptyState from '../components/EmptyState';

export default function Dashboard({
    role,
    isOwner = false,
    subjects = [],
    runs = [],
    lessons = [],
    scenes = [],
    lectures = [],
}) {
    const isTeacher = role === 'teacher';
    const isStudent = role === 'student';
    const isAdmin = role === 'admin';
    usePoll(15000, { only: ['runs'] }, { autoStart: isTeacher || (isAdmin && isOwner) });

    let title = 'Student Workspace';
    let description = 'Find your published curriculum, structured lessons, and interactive 3D simulations.';
    let eyebrow = 'Curriculum Portal';

    if (isTeacher) {
        title = 'Educator Studio';
        description = 'Ingest textbooks, monitor 3D scene generation, review bilingual lessons, and publish to students.';
        eyebrow = 'Curriculum Management';
    } else if (isAdmin) {
        if (isOwner) {
            title = 'Platform Owner Studio';
            description = 'Comprehensive institutional administration, curriculum pipelines, question banks, and permissions.';
            eyebrow = 'Sovereign Control';
        } else {
            title = 'Operations Console';
            description = 'Monitor background job execution, system health, and pipeline run operations.';
            eyebrow = 'System Operations';
        }
    }

    return (
        <AppLayout>
            <Head title={`${title} — EduPlatform`} />

            <PageHeader
                title={title}
                eyebrow={eyebrow}
                description={description}
                actions={
                    isTeacher ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <Button href="/subjects/create" variant="secondary" size="sm">
                                + New Subject
                            </Button>
                            <Button href="/subjects" variant="primary" size="sm">
                                Ingest New PDF
                            </Button>
                        </div>
                    ) : isOwner ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <Button href="/subjects/create" variant="secondary" size="sm">
                                + New Subject
                            </Button>
                            <Button href="/v3/admin/workspace" variant="primary" size="sm">
                                Operations Console
                            </Button>
                        </div>
                    ) : null
                }
            />

            {/* 1. TEACHER VIEW */}
            {isTeacher && (
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
                    {/* Left Column (8 cols): Subjects & Active Runs */}
                    <div className="lg:col-span-8 space-y-8">
                        {/* Subjects Card */}
                        <section className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                            <div className="flex items-center justify-between mb-4">
                                <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
                                    <span>Active Subjects</span>
                                    <span className="text-xs px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 font-semibold">
                                        {subjects.length}
                                    </span>
                                </h2>
                                <Link
                                    href="/subjects"
                                    className="text-xs font-semibold text-teal-700 dark:text-teal-400 hover:underline"
                                >
                                    Manage All →
                                </Link>
                            </div>

                            {subjects.length > 0 ? (
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    {subjects.map((sub) => (
                                        <div
                                            key={sub.id}
                                            className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/80 dark:border-[#263932] flex flex-col justify-between hover:border-teal-500/50 transition-colors"
                                        >
                                            <div>
                                                <h3 className="font-bold text-slate-900 dark:text-slate-100 text-base">
                                                    {sub.name}
                                                </h3>
                                                {sub.code && (
                                                    <span className="text-xs font-mono text-slate-500 dark:text-slate-400">
                                                        {sub.code}
                                                    </span>
                                                )}
                                            </div>

                                            <div className="mt-4 pt-3 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between">
                                                <Link
                                                    href={`/subjects/${sub.id}`}
                                                    className="text-xs font-semibold text-slate-700 dark:text-slate-300 hover:text-teal-700 dark:hover:text-teal-400"
                                                >
                                                    View Syllabus
                                                </Link>
                                                <Link
                                                    href={`/v3/teacher/subjects/${sub.id}/runs/create`}
                                                    className="text-xs font-semibold text-teal-700 dark:text-teal-400 hover:underline inline-flex items-center gap-1"
                                                >
                                                    Upload PDF →
                                                </Link>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <EmptyState
                                    title="No subjects assigned"
                                    description="Create your first subject to begin uploading curriculum documents."
                                    action={
                                        <Button href="/subjects/create" variant="primary" size="sm">
                                            Create Subject
                                        </Button>
                                    }
                                />
                            )}
                        </section>

                        {/* Recent Ingestion Runs */}
                        <section className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                            <div className="flex items-center justify-between mb-4">
                                <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100">
                                    Recent Curriculum Runs
                                </h2>
                                <Link
                                    href="/v3/teacher/workspace"
                                    className="text-xs font-semibold text-teal-700 dark:text-teal-400 hover:underline"
                                >
                                    Workspace Hub →
                                </Link>
                            </div>

                            {runs.length > 0 ? (
                                <div className="space-y-3">
                                    {runs.map((run) => {
                                        const counts = run.progress_snapshot?.counts || {};
                                        const saved = counts.lessons_saved || 0;
                                        const status = run.progress_snapshot?.state || run.state || 'unknown';

                                        return (
                                            <div
                                                key={run.id}
                                                className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/80 dark:border-[#263932] flex flex-col sm:flex-row sm:items-center justify-between gap-3"
                                            >
                                                <div>
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-bold text-sm text-slate-900 dark:text-slate-100">
                                                            {run.subject?.name || `Pipeline Run #${run.id}`}
                                                        </span>
                                                        <StatusBadge status={status} />
                                                    </div>
                                                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                                        {saved} lessons persisted • Created{' '}
                                                        {run.created_at ? new Date(run.created_at).toLocaleDateString() : 'recently'}
                                                    </p>
                                                </div>

                                                <Button
                                                    href={`/v3/teacher/subject-runs/${run.id}`}
                                                    variant="secondary"
                                                    size="sm"
                                                    className="self-start sm:self-auto"
                                                >
                                                    View Run Progress →
                                                </Button>
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <EmptyState
                                    title="No pipeline runs yet"
                                    description="Start an ingestion run by uploading textbook or syllabus PDFs."
                                    action={
                                        <Button href="/subjects" variant="primary" size="sm">
                                            Start Ingestion Run
                                        </Button>
                                    }
                                />
                            )}
                        </section>
                    </div>

                    {/* Right Column (4 cols): Lessons Review & Approved Scenes */}
                    <div className="lg:col-span-4 space-y-8">
                        {/* Lessons to Review */}
                        <section className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                            <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100 mb-1">
                                Lessons for Review
                            </h2>
                            <p className="text-xs text-slate-500 dark:text-slate-400 mb-4">
                                Verify content accuracy and publish to student cohorts.
                            </p>

                            {lessons.length > 0 ? (
                                <div className="space-y-3">
                                    {lessons.slice(0, 5).map((lesson) => (
                                        <LessonPublicationCard key={lesson.work_node_id} lesson={lesson} />
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-slate-500 dark:text-slate-400 py-4 text-center">
                                    No lessons awaiting review.
                                </p>
                            )}
                        </section>

                        {/* Approved Scenes */}
                        <section className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                            <div className="flex items-center justify-between mb-3">
                                <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100">
                                    Approved 3D Scenes
                                </h2>
                                <Link
                                    href="/v3/teacher/reviews"
                                    className="text-xs font-semibold text-teal-700 dark:text-teal-400 hover:underline"
                                >
                                    Review Queue →
                                </Link>
                            </div>

                            {scenes.length > 0 ? (
                                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {scenes.slice(0, 6).map((scene) => (
                                        <li key={scene.id} className="py-2.5 flex items-center justify-between text-xs">
                                            <span className="font-medium text-slate-800 dark:text-slate-200 truncate max-w-[180px]">
                                                {scene.section_identity}
                                            </span>
                                            <StatusBadge status={scene.published_at ? 'published' : 'approved'} />
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-sm text-slate-500 dark:text-slate-400 py-4 text-center">
                                    No approved scenes linked yet.
                                </p>
                            )}
                        </section>
                    </div>
                </div>
            )}

            {/* 2. STUDENT VIEW */}
            {isStudent && (
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
                    {/* Enrolled Subjects (7 cols) */}
                    <div className="lg:col-span-7 space-y-8">
                        <section className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                            <h2 className="text-xl font-bold text-slate-900 dark:text-slate-100 mb-1">
                                Enrolled Subjects
                            </h2>
                            <p className="text-sm text-slate-600 dark:text-slate-400 mb-6">
                                Select a subject to explore its lecture syllabus and interactive simulations.
                            </p>

                            {subjects.length > 0 ? (
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    {subjects.map((sub) => (
                                        <Link
                                            key={sub.id}
                                            href={`/subjects/${sub.id}`}
                                            className="p-5 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/80 dark:border-[#263932] hover:border-teal-500 hover:shadow-md transition-all group flex flex-col justify-between"
                                        >
                                            <div>
                                                <h3 className="font-bold text-slate-900 dark:text-slate-100 group-hover:text-teal-700 dark:group-hover:text-teal-400 transition-colors">
                                                    {sub.name}
                                                </h3>
                                                <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                                    {sub.lectures_count !== undefined ? `${sub.lectures_count} published lectures` : 'Curriculum available'}
                                                </p>
                                            </div>

                                            <div className="mt-4 pt-3 border-t border-slate-200/60 dark:border-slate-800 text-xs font-semibold text-teal-700 dark:text-teal-400 flex items-center justify-between">
                                                <span>Open Course</span>
                                                <span>→</span>
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            ) : (
                                <EmptyState
                                    title="No published subjects yet"
                                    description="Your professors have not published course material yet. Check back soon."
                                />
                            )}
                        </section>

                        {/* Recent Lectures */}
                        <section className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                            <h2 className="text-xl font-bold text-slate-900 dark:text-slate-100 mb-4">
                                Continue Learning
                            </h2>

                            {lectures.length > 0 ? (
                                <div className="space-y-3">
                                    {lectures.map((lec) => (
                                        <Link
                                            key={lec.id}
                                            href={`/lectures/${lec.id}`}
                                            className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/80 dark:border-[#263932] flex items-center justify-between hover:border-teal-500/50 transition-colors"
                                        >
                                            <div>
                                                <h3 className="font-semibold text-sm text-slate-900 dark:text-slate-100">
                                                    {lec.title}
                                                </h3>
                                                <span className="text-xs text-slate-500 dark:text-slate-400">
                                                    {lec.subject?.name}
                                                </span>
                                            </div>
                                            <span className="text-xs font-semibold text-teal-700 dark:text-teal-400">
                                                Read Lesson →
                                            </span>
                                        </Link>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-slate-500 dark:text-slate-400 py-4 text-center">
                                    No lectures published yet.
                                </p>
                            )}
                        </section>
                    </div>

                    {/* Interactive 3D Scenes (5 cols) */}
                    <div className="lg:col-span-5">
                        <section className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                            <div className="flex items-center gap-2 mb-1">
                                <span className="w-2.5 h-2.5 rounded-full bg-teal-500 animate-pulse" />
                                <h2 className="text-xl font-bold text-slate-900 dark:text-slate-100">
                                    Interactive 3D Scenes
                                </h2>
                            </div>
                            <p className="text-sm text-slate-600 dark:text-slate-400 mb-6">
                                Approved simulations verified by your course faculty.
                            </p>

                            {scenes.length > 0 ? (
                                <div className="space-y-3">
                                    {scenes.map((sc) => (
                                        <div
                                            key={sc.id}
                                            className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/80 dark:border-[#263932] flex items-center justify-between"
                                        >
                                            <div className="min-w-0 pr-2">
                                                <span className="font-semibold text-sm text-slate-900 dark:text-slate-100 block truncate">
                                                    {sc.section_identity}
                                                </span>
                                                <span className="text-xs text-teal-600 dark:text-teal-400">
                                                    3D WebGL Simulation
                                                </span>
                                            </div>
                                            <StatusBadge status="approved" />
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <EmptyState
                                    title="No 3D simulations ready"
                                    description="Faculty will publish interactive scenes as coursework progresses."
                                />
                            )}
                        </section>
                    </div>
                </div>
            )}

            {/* 3. ADMIN / OWNER VIEW */}
            {isAdmin && (
                <div className="space-y-8">
                    {/* Management Quick Actions */}
                    <section className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 sm:p-8 shadow-sm">
                        <h2 className="text-xl font-bold text-slate-900 dark:text-slate-100 mb-2">
                            {isOwner ? 'Platform Administration' : 'Operations Management'}
                        </h2>
                        <p className="text-sm text-slate-600 dark:text-slate-400 mb-6 max-w-2xl">
                            {isOwner
                                ? 'Full administrative authority: manage curriculum catalog, teacher permissions, system accounts, and question banks.'
                                : 'Monitor system tasks, service health status, and administrative operations.'}
                        </p>

                        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                            {isOwner && (
                                <>
                                    <Link
                                        href="/subjects"
                                        className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/80 dark:border-[#263932] hover:border-teal-500 text-center font-semibold text-sm text-slate-800 dark:text-slate-200 transition-colors"
                                    >
                                        Subjects Catalog
                                    </Link>
                                    <Link
                                        href="/teacher_permissions"
                                        className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/80 dark:border-[#263932] hover:border-teal-500 text-center font-semibold text-sm text-slate-800 dark:text-slate-200 transition-colors"
                                    >
                                        Teacher Scopes
                                    </Link>
                                    <Link
                                        href="/users"
                                        className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/80 dark:border-[#263932] hover:border-teal-500 text-center font-semibold text-sm text-slate-800 dark:text-slate-200 transition-colors"
                                    >
                                        User Accounts
                                    </Link>
                                    <Link
                                        href="/questions"
                                        className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/80 dark:border-[#263932] hover:border-teal-500 text-center font-semibold text-sm text-slate-800 dark:text-slate-200 transition-colors"
                                    >
                                        Question Bank
                                    </Link>
                                    <Link
                                        href="/exam_questions"
                                        className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/80 dark:border-[#263932] hover:border-teal-500 text-center font-semibold text-sm text-slate-800 dark:text-slate-200 transition-colors"
                                    >
                                        Exams Bank
                                    </Link>
                                </>
                            )}
                            <Link
                                href="/v3/admin/workspace"
                                className="p-4 rounded-2xl bg-teal-50/60 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-800 hover:border-teal-500 text-center font-semibold text-sm text-teal-800 dark:text-teal-300 transition-colors"
                            >
                                Operations Hub
                            </Link>
                        </div>
                    </section>

                    {/* Owner Run & Lesson Stream */}
                    {isOwner && (
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <section className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                                <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100 mb-4">
                                    Recent Global Runs
                                </h2>
                                {runs.length > 0 ? (
                                    <div className="space-y-3">
                                        {runs.map((run) => (
                                            <div
                                                key={run.id}
                                                className="p-3.5 rounded-xl bg-slate-50 dark:bg-[#182823] border border-slate-200 dark:border-[#263932] flex items-center justify-between text-sm"
                                            >
                                                <span>Run #{run.id} • {run.subject?.name || 'Curriculum'}</span>
                                                <div className="flex flex-wrap items-center gap-3">
                                                    <StatusBadge status={run.progress_snapshot?.state || run.state || 'unknown'} />
                                                    {run.recovery_task_id && <Link href={`/v3/admin/local-worker/tasks/${run.recovery_task_id}/reconciliation`} className="font-semibold text-teal-700 underline dark:text-teal-300">Recover run</Link>}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-slate-500 py-4 text-center">No runs recorded.</p>
                                )}
                            </section>

                            <section className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                                <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100 mb-4">
                                    Published Curriculum Lessons
                                </h2>
                                {lessons.length > 0 ? (
                                    <div className="space-y-3">
                                        {lessons.slice(0, 5).map((lesson) => (
                                            <LessonPublicationCard key={lesson.work_node_id} lesson={lesson} />
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-slate-500 py-4 text-center">No lessons recorded.</p>
                                )}
                            </section>
                        </div>
                    )}
                </div>
            )}
        </AppLayout>
    );
}

function LessonPublicationCard({ lesson }) {
    const isPublished = Boolean(lesson.published_at);
    const form = useForm({ published: !isPublished });

    const togglePublication = (e) => {
        e.preventDefault();
        form.post(`/lessons/${lesson.work_node_id}/publication`);
    };

    return (
        <article className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/80 dark:border-[#263932] flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div className="min-w-0 flex-1">
                <p className="font-semibold text-sm text-slate-900 dark:text-slate-100 truncate">
                    {lesson.lesson_title || lesson.lecture_title || 'Curriculum Lesson'}
                </p>
                <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                    {lesson.missing_fields?.length
                        ? `Needs attention: ${lesson.missing_fields.join(', ')}`
                        : isPublished
                        ? 'Published for students'
                        : 'Ready for review'}
                </p>
            </div>

            <div className="flex items-center gap-2 self-end sm:self-auto flex-shrink-0">
                {lesson.first_section_id && (
                    <Link
                        href={`/sections/${lesson.first_section_id}`}
                        className="text-xs font-semibold text-teal-700 dark:text-teal-400 hover:underline px-2.5 py-1"
                    >
                        Review
                    </Link>
                )}

                <form onSubmit={togglePublication}>
                    <button
                        type="submit"
                        disabled={form.processing || (!isPublished && lesson.missing_fields?.length > 0)}
                        className={`px-3 py-1.5 text-xs font-semibold rounded-xl transition-colors focus-visible:outline focus-visible:outline-2 ${
                            isPublished
                                ? 'bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-300 dark:hover:bg-slate-700'
                                : 'bg-teal-700 dark:bg-teal-500 text-white dark:text-slate-950 hover:bg-teal-800 dark:hover:bg-teal-400'
                        } disabled:opacity-50 disabled:cursor-not-allowed`}
                    >
                        {isPublished ? 'Unpublish' : 'Publish'}
                    </button>
                </form>
            </div>
        </article>
    );
}
