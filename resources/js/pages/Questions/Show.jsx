import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import ConfirmDialog from '../../components/ConfirmDialog';

export default function QuestionsShow({ question }) {
    const [revealHint, setRevealHint] = useState(false);
    const [revealSolution, setRevealSolution] = useState(false);
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);

    const handleDelete = () => {
        router.delete(`/questions/${question.id}`, {
            onSuccess: () => router.visit('/questions'),
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Question Bank', url: '/questions' },
        { label: `Question #${question.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Question #${question.id} — EduPlatform`} />

            <PageHeader
                title={`Question #${question.id}`}
                eyebrow="Formative Practice Review"
                description={`Linked to ${question.lecture?.title || 'Lecture'} (${question.lecture?.subject?.name || 'Subject'})`}
                actions={
                    <div className="flex items-center gap-2">
                        <Button href={`/questions/${question.id}/edit`} variant="secondary" size="sm">
                            Edit
                        </Button>
                        <Button
                            type="button"
                            variant="danger"
                            size="sm"
                            onClick={() => setIsDeleteDialogOpen(true)}
                        >
                            Delete
                        </Button>
                    </div>
                }
            />

            <div className="max-w-3xl space-y-6">
                {/* Prompt Card */}
                <article className="bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                    <h2 className="text-sm font-bold uppercase tracking-wider text-teal-700 dark:text-teal-400 mb-3">
                        Problem Statement
                    </h2>
                    <div className="text-base sm:text-lg text-slate-900 dark:text-slate-100 leading-relaxed whitespace-pre-line">
                        {question.question_text || 'No prompt text specified.'}
                    </div>

                    {question.question_image && (
                        <div className="mt-6 rounded-2xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-black/40 p-2">
                            <img
                                src={`/owner-assets/question/${question.id}/question_image`}
                                alt="Problem diagram"
                                className="max-h-96 mx-auto rounded-xl object-contain"
                            />
                        </div>
                    )}
                </article>

                {/* Scaffolding Hint */}
                {question.hint && (
                    <article className="bg-amber-50/60 dark:bg-amber-950/20 p-6 rounded-3xl border border-amber-200 dark:border-amber-900/40">
                        <div className="flex items-center justify-between">
                            <h3 className="text-sm font-bold text-amber-900 dark:text-amber-300">
                                Guided Hint
                            </h3>
                            <button
                                type="button"
                                onClick={() => setRevealHint(!revealHint)}
                                className="text-xs font-semibold text-amber-800 dark:text-amber-400 underline"
                            >
                                {revealHint ? 'Hide Hint' : 'Reveal Hint'}
                            </button>
                        </div>
                        {revealHint && (
                            <p className="mt-3 text-sm sm:text-base text-slate-800 dark:text-amber-100/90 leading-relaxed">
                                {question.hint}
                            </p>
                        )}
                    </article>
                )}

                {/* Solution Section */}
                <article className="bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                    <div className="flex items-center justify-between mb-2">
                        <h2 className="text-base font-bold text-slate-900 dark:text-slate-100">
                            Worked Solution & Explanation
                        </h2>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            onClick={() => setRevealSolution(!revealSolution)}
                        >
                            {revealSolution ? 'Collapse Solution' : 'Inspect Solution'}
                        </Button>
                    </div>

                    {revealSolution && (
                        <div className="mt-4 pt-4 border-t border-slate-100 dark:border-slate-800 space-y-4">
                            {question.idea_text && (
                                <div className="p-4 rounded-xl bg-slate-50 dark:bg-[#182823] text-sm text-slate-700 dark:text-slate-300">
                                    <strong className="block text-slate-900 dark:text-slate-100 mb-1">Pedagogical Concept:</strong>
                                    {question.idea_text}
                                </div>
                            )}

                            {question.solution_explanation && (
                                <div className="text-sm sm:text-base text-slate-800 dark:text-slate-200 leading-relaxed whitespace-pre-line">
                                    {question.solution_explanation}
                                </div>
                            )}
                        </div>
                    )}
                </article>
            </div>

            <ConfirmDialog
                isOpen={isDeleteDialogOpen}
                onClose={() => setIsDeleteDialogOpen(false)}
                onConfirm={handleDelete}
                title="Delete Question?"
                description="Are you sure you want to delete this question? This action cannot be undone."
                confirmText="Yes, delete"
                cancelText="Cancel"
                variant="danger"
            />
        </AppLayout>
    );
}
