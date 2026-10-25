import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import ConfirmDialog from '../../components/ConfirmDialog';

export default function ExamQuestionsShow({ examQuestion }) {
    const [revealKey, setRevealKey] = useState(false);
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);

    const handleDelete = () => {
        router.delete(`/exam_questions/${examQuestion.id}`, {
            onSuccess: () => router.visit('/exam_questions'),
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Exams Bank', url: '/exam_questions' },
        { label: `Exam #${examQuestion.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Exam Question #${examQuestion.id} — EduPlatform`} />

            <PageHeader
                title={`Exam Question #${examQuestion.id}`}
                eyebrow="Summative Assessment Vault"
                description={`Linked to ${examQuestion.lecture?.title || 'Lecture'} (${examQuestion.lecture?.subject?.name || 'Subject'})`}
                actions={
                    <div className="flex items-center gap-2">
                        <Button href={`/exam_questions/${examQuestion.id}/edit`} variant="secondary" size="sm">
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
                {/* Exam Paper Card */}
                <article className="bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                    <div className="flex items-center justify-between mb-4">
                        <h2 className="text-sm font-bold uppercase tracking-wider text-teal-700 dark:text-teal-400">
                            Exam Paper Prompt
                        </h2>
                        {examQuestion.idea && (
                            <span className="text-xs px-2.5 py-1 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-medium">
                                {examQuestion.idea}
                            </span>
                        )}
                    </div>

                    {examQuestion.question_image ? (
                        <div className="rounded-2xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-black/40 p-2">
                            <img
                                src={`/owner-assets/exam-question/${examQuestion.id}/question_image`}
                                alt="Exam question prompt"
                                className="max-h-96 mx-auto rounded-xl object-contain"
                            />
                        </div>
                    ) : (
                        <p className="text-sm text-slate-500 py-4">No exam paper image uploaded.</p>
                    )}
                </article>

                {/* Answer Key Card */}
                <article className="bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                    <div className="flex items-center justify-between mb-2">
                        <h2 className="text-base font-bold text-slate-900 dark:text-slate-100">
                            Authoritative Answer Key
                        </h2>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            onClick={() => setRevealKey(!revealKey)}
                        >
                            {revealKey ? 'Hide Answer Key' : 'Inspect Answer Key'}
                        </Button>
                    </div>

                    {revealKey && (
                        <div className="mt-4 pt-4 border-t border-slate-100 dark:border-slate-800 space-y-4">
                            {examQuestion.explanation && (
                                <div className="text-sm sm:text-base text-slate-800 dark:text-slate-200 leading-relaxed whitespace-pre-line">
                                    {examQuestion.explanation}
                                </div>
                            )}

                            {examQuestion.solution_image && (
                                <div className="rounded-2xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-black/40 p-2">
                                    <img
                                        src={`/owner-assets/exam-question/${examQuestion.id}/solution_image`}
                                        alt="Official solution diagram"
                                        className="max-h-96 mx-auto rounded-xl object-contain"
                                    />
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
                title="Delete Exam Question?"
                description="Are you sure you want to delete this exam question? This action cannot be undone."
                confirmText="Yes, delete"
                cancelText="Cancel"
                variant="danger"
            />
        </AppLayout>
    );
}
