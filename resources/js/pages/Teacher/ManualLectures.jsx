import React, { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import FormField from '../../components/FormField';
import SelectField from '../../components/SelectField';
import Button from '../../components/Button';
import ErrorSummary from '../../components/ErrorSummary';

export default function ManualLectures({ subjects = [], lectures = [], permissions = [] }) {
    const [showCreateModal, setShowCreateModal] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        subject_id: subjects[0]?.id || '',
        title: '',
        summary: '',
    });

    const handleCreateLecture = (e) => {
        e.preventDefault();
        post('/teacher-study-flow/lectures', {
            onSuccess: () => {
                reset();
                setShowCreateModal(false);
            },
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Teacher Studio', url: '/v3/teacher/workspace' },
        { label: 'Curriculum Flow' },
    ];

    const subjectOptions = subjects.map((s) => ({
        value: s.id,
        label: s.name,
    }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Teacher Study Flow — EduPlatform" />

            <PageHeader
                title="Curriculum Authoring Flow"
                eyebrow="Guided Teaching Wizard"
                description="Manage lectures within your permitted subjects or author structured curriculum sections directly."
                actions={
                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="primary"
                            onClick={() => setShowCreateModal(true)}
                        >
                            + Author New Lecture
                        </Button>
                        <Button href="/teacher-study-flow/exit" variant="secondary">
                            Exit Wizard
                        </Button>
                    </div>
                }
            />

            <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
                {/* Permitted Subjects (4 cols) */}
                <div className="lg:col-span-4 space-y-6">
                    <section className="bg-white dark:bg-[#121e1a] p-6 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                        <h2 className="text-base font-bold text-slate-900 dark:text-slate-100 mb-1">
                            Your Permitted Subjects
                        </h2>
                        <p className="text-xs text-slate-500 dark:text-slate-400 mb-4">
                            Subjects you are authorized to author and publish.
                        </p>

                        {subjects.length > 0 ? (
                            <ul className="space-y-2">
                                {subjects.map((sub) => (
                                    <li
                                        key={sub.id}
                                        className="p-3 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/60 dark:border-[#263932] flex items-center justify-between"
                                    >
                                        <span className="font-semibold text-sm text-slate-800 dark:text-slate-200 truncate">
                                            {sub.name}
                                        </span>
                                        <Link
                                            href={`/subjects/${sub.id}`}
                                            className="text-xs font-semibold text-teal-700 dark:text-teal-400 hover:underline"
                                        >
                                            View
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-slate-500 py-2">
                                No subject permissions assigned yet.
                            </p>
                        )}
                    </section>
                </div>

                {/* Permitted Lectures (8 cols) */}
                <div className="lg:col-span-8">
                    <section className="bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                        <div className="flex items-center justify-between mb-4">
                            <div>
                                <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100">
                                    Lectures in Your Scope
                                </h2>
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    Click any lecture to review or author bilingual sections.
                                </p>
                            </div>
                            <span className="text-xs px-2.5 py-1 rounded-full bg-teal-50 dark:bg-teal-950/60 text-teal-800 dark:text-teal-300 font-semibold">
                                {lectures.length} Lectures
                            </span>
                        </div>

                        {lectures.length > 0 ? (
                            <div className="space-y-3">
                                {lectures.map((lec) => (
                                    <div
                                        key={lec.id}
                                        className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/80 dark:border-[#263932] flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:border-teal-500/50 transition-colors"
                                    >
                                        <div>
                                            <h3 className="font-bold text-sm text-slate-900 dark:text-slate-100">
                                                {lec.title}
                                            </h3>
                                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                                {lec.subject?.name}
                                                {lec.summary && ` • ${lec.summary}`}
                                            </p>
                                        </div>

                                        <div className="flex items-center gap-2 self-end sm:self-auto">
                                            <Button href={`/lectures/${lec.id}`} variant="ghost" size="sm">
                                                View
                                            </Button>
                                            <Button href={`/lectures/${lec.id}/edit`} variant="secondary" size="sm">
                                                Edit
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="text-center py-10">
                                <p className="text-sm text-slate-500 dark:text-slate-400 mb-4">
                                    No lectures exist in your permitted subjects.
                                </p>
                                <Button
                                    type="button"
                                    variant="primary"
                                    size="sm"
                                    onClick={() => setShowCreateModal(true)}
                                >
                                    Create First Lecture
                                </Button>
                            </div>
                        )}
                    </section>
                </div>
            </div>

            {/* Quick Create Lecture Modal */}
            {showCreateModal && (
                <div
                    className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 dark:bg-black/75 backdrop-blur-sm flex items-center justify-center p-4"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="modal-create-lecture"
                >
                    <div className="relative w-full max-w-lg bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] shadow-2xl p-6 sm:p-8">
                        <div className="flex items-center justify-between mb-4">
                            <h2 id="modal-create-lecture" className="text-lg font-bold text-slate-900 dark:text-slate-100">
                                Author New Lecture
                            </h2>
                            <button
                                type="button"
                                onClick={() => setShowCreateModal(false)}
                                className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1 rounded-lg"
                                aria-label="Close dialog"
                            >
                                ✕
                            </button>
                        </div>

                        <ErrorSummary errors={errors} className="mb-4" />

                        <form onSubmit={handleCreateLecture} className="space-y-4">
                            <SelectField
                                label="Subject"
                                name="subject_id"
                                value={data.subject_id}
                                onChange={(e) => setData('subject_id', e.target.value)}
                                options={subjectOptions}
                                error={errors.subject_id}
                                required
                            />

                            <FormField
                                label="Lecture Title"
                                name="title"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                error={errors.title}
                                required
                                placeholder="e.g. Centripetal Acceleration & Circular Motion"
                            />

                            <FormField
                                label="Brief Summary"
                                name="summary"
                                type="textarea"
                                rows={3}
                                value={data.summary}
                                onChange={(e) => setData('summary', e.target.value)}
                                error={errors.summary}
                                placeholder="Summary of topics covered..."
                            />

                            <div className="pt-3 flex items-center justify-end gap-3">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => setShowCreateModal(false)}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" variant="primary" isLoading={processing}>
                                    Create & Continue
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
