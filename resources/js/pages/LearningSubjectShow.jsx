import React from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '../components/AppLayout';
import PageHeader from '../components/PageHeader';
import Button from '../components/Button';
import EmptyState from '../components/EmptyState';

export default function LearningSubjectShow({ subject }) {
    const { auth } = usePage().props;
    const isOwner = Boolean(auth?.is_owner);
    const isTeacher = auth?.user?.role === 'teacher';
    const canManage = isOwner || isTeacher;
    const lectures = subject.lectures || [];

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Subjects', url: '/subjects' },
        { label: subject.name },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${subject.name} — EduPlatform`} />

            <PageHeader
                title={subject.name}
                eyebrow="Academic Curriculum"
                description={subject.description || 'Comprehensive syllabus and lecture sequence.'}
                actions={
                    canManage ? (
                        <div className="flex items-center gap-2">
                            {isTeacher && <Button href={`/v3/teacher/subjects/${subject.id}/runs/create`} variant="primary" size="sm">
                                Upload Syllabus PDFs
                            </Button>}
                            <Button href={`/lectures/create?subject_id=${subject.id}`} variant="secondary" size="sm">
                                + Add Lecture
                            </Button>
                        </div>
                    ) : null
                }
            />

            <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
                {/* Lectures List (8 cols) */}
                <div className="lg:col-span-8">
                    <section className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 sm:p-8 shadow-sm">
                        <div className="flex items-center justify-between mb-6">
                            <h2 className="text-xl font-bold text-slate-900 dark:text-slate-100">
                                Lectures & Learning Modules
                            </h2>
                            <span className="text-xs px-2.5 py-1 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold">
                                {lectures.length} Modules
                            </span>
                        </div>

                        {lectures.length > 0 ? (
                            <ol className="divide-y divide-slate-100 dark:divide-slate-800 space-y-2">
                                {lectures.map((lec, idx) => (
                                    <li key={lec.id} className="pt-4 first:pt-0 flex items-start justify-between gap-4">
                                        <div className="flex items-start gap-3 min-w-0">
                                            <span className="w-8 h-8 rounded-xl bg-teal-100 dark:bg-teal-950/60 text-teal-800 dark:text-teal-300 font-bold text-xs flex items-center justify-center flex-shrink-0 mt-0.5">
                                                {String(idx + 1).padStart(2, '0')}
                                            </span>
                                            <div>
                                                <Link
                                                    href={`/lectures/${lec.id}`}
                                                    className="font-bold text-base text-slate-900 dark:text-slate-100 hover:text-teal-700 dark:hover:text-teal-400 transition-colors"
                                                >
                                                    {lec.title}
                                                </Link>
                                                <p className="text-xs sm:text-sm text-slate-600 dark:text-slate-400 mt-1 line-clamp-2 leading-relaxed">
                                                    {lec.description || lec.summary || 'Structured concept lessons available.'}
                                                </p>
                                            </div>
                                        </div>

                                        <Button
                                            href={`/lectures/${lec.id}`}
                                            variant="ghost"
                                            size="sm"
                                            className="flex-shrink-0"
                                        >
                                            Open →
                                        </Button>
                                    </li>
                                ))}
                            </ol>
                        ) : (
                            <EmptyState
                                title="No lectures published yet"
                                description="Course faculty are preparing syllabus lessons. Please check back soon."
                                action={
                                    canManage ? (
                                        <Button href={`/lectures/create?subject_id=${subject.id}`} variant="primary" size="sm">
                                            Add First Lecture
                                        </Button>
                                    ) : null
                                }
                            />
                        )}
                    </section>
                </div>

                {/* Course Metadata Sidebar (4 cols) */}
                <div className="lg:col-span-4 space-y-6">
                    <div className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                        <h3 className="text-base font-bold text-slate-900 dark:text-slate-100 mb-4">
                            Course Details
                        </h3>

                        <dl className="space-y-3 text-sm">
                            <div>
                                <dt className="text-xs text-slate-500 dark:text-slate-400">Course Identifier</dt>
                                <dd className="font-semibold text-slate-900 dark:text-slate-100 mt-0.5">{subject.name}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-slate-500 dark:text-slate-400">Total Lectures</dt>
                                <dd className="font-semibold text-slate-900 dark:text-slate-100 mt-0.5">{lectures.length}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-slate-500 dark:text-slate-400">Bilingual Support</dt>
                                <dd className="font-semibold text-teal-700 dark:text-teal-400 mt-0.5">English & Egyptian Arabic</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
