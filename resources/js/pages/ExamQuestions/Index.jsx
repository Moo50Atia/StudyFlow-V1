import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import DataTable from '../../components/DataTable';
import Pagination from '../../components/Pagination';
import ConfirmDialog from '../../components/ConfirmDialog';

export default function ExamQuestionsIndex({ examQuestions, subjects = [], lectures = [] }) {
    const [deleteTarget, setDeleteTarget] = useState(null);

    const handleDelete = () => {
        if (!deleteTarget) return;
        router.delete(`/exam_questions/${deleteTarget.id}`, {
            onSuccess: () => setDeleteTarget(null),
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Exams Bank' },
    ];

    const columns = [
        {
            key: 'id',
            label: 'Exam Item',
            render: (eq) => (
                <div>
                    <Link
                        href={`/exam_questions/${eq.id}`}
                        className="font-bold text-teal-800 dark:text-teal-400 hover:underline"
                    >
                        Exam Question #{eq.id}
                    </Link>
                    {eq.idea && (
                        <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5 line-clamp-1">
                            {eq.idea}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'lecture',
            label: 'Curriculum Scope',
            render: (eq) => (
                <div className="text-xs">
                    <span className="font-semibold text-slate-800 dark:text-slate-200 block truncate max-w-xs">
                        {eq.lecture?.title || 'General'}
                    </span>
                    <span className="text-slate-500 dark:text-slate-400">
                        {eq.lecture?.subject?.name || ''}
                    </span>
                </div>
            ),
        },
        {
            key: 'assets',
            label: 'Artifacts',
            render: (eq) => (
                <div className="flex items-center gap-1.5 text-xs">
                    {eq.question_image && (
                        <span className="px-2 py-0.5 rounded bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 font-medium">
                            Exam Paper
                        </span>
                    )}
                    {eq.solution_image && (
                        <span className="px-2 py-0.5 rounded bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 font-medium">
                            Model Key
                        </span>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            label: 'Actions',
            align: 'right',
            render: (eq) => (
                <div className="flex items-center justify-end gap-2">
                    <Button href={`/exam_questions/${eq.id}`} variant="ghost" size="sm">
                        View
                    </Button>
                    <Button href={`/exam_questions/${eq.id}/edit`} variant="secondary" size="sm">
                        Edit
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setDeleteTarget(eq)}
                        className="text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40"
                    >
                        Delete
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Exams Bank — EduPlatform" />

            <PageHeader
                title="Exams Bank (Owner Controlled)"
                eyebrow="Summative Assessment"
                description="Secure vault of past exam questions, high-stakes problems, and verified answer keys."
                actions={
                    <Button href="/exam_questions/create" variant="primary">
                        + New Exam Question
                    </Button>
                }
            />

            <DataTable
                columns={columns}
                data={examQuestions?.data || examQuestions || []}
                emptyTitle="No exam questions found"
                emptyDescription="The exam vault is currently empty."
                emptyAction={
                    <Button href="/exam_questions/create" variant="primary" size="sm">
                        Create Exam Question
                    </Button>
                }
            />

            {examQuestions?.links && <Pagination pagination={examQuestions} className="mt-4" />}

            <ConfirmDialog
                isOpen={Boolean(deleteTarget)}
                onClose={() => setDeleteTarget(null)}
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
