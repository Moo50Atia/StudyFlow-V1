import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import DataTable from '../../components/DataTable';
import Pagination from '../../components/Pagination';
import ConfirmDialog from '../../components/ConfirmDialog';

export default function QuestionsIndex({ questions, subjects = [], lectures = [] }) {
    const [deleteTarget, setDeleteTarget] = useState(null);

    const handleDelete = () => {
        if (!deleteTarget) return;
        router.delete(`/questions/${deleteTarget.id}`, {
            onSuccess: () => setDeleteTarget(null),
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Question Bank' },
    ];

    const columns = [
        {
            key: 'question_text',
            label: 'Prompt / Question',
            render: (q) => (
                <div>
                    <Link
                        href={`/questions/${q.id}`}
                        className="font-bold text-teal-800 dark:text-teal-400 hover:underline line-clamp-2"
                    >
                        {q.question_text || `Question #${q.id}`}
                    </Link>
                    {q.hint && (
                        <p className="text-xs text-slate-500 dark:text-slate-400 mt-1 line-clamp-1 italic">
                            Hint: {q.hint}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'lecture',
            label: 'Curriculum Context',
            render: (q) => (
                <div className="text-xs">
                    <span className="font-semibold text-slate-800 dark:text-slate-200 block truncate max-w-xs">
                        {q.lecture?.title || 'General'}
                    </span>
                    <span className="text-slate-500 dark:text-slate-400">
                        {q.lecture?.subject?.name || ''}
                    </span>
                </div>
            ),
        },
        {
            key: 'assets',
            label: 'Media',
            render: (q) => (
                <div className="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                    {q.question_image && (
                        <span className="px-2 py-0.5 rounded bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 font-medium">
                            Prompt Img
                        </span>
                    )}
                    {q.solution_images?.length > 0 && (
                        <span className="px-2 py-0.5 rounded bg-purple-50 dark:bg-purple-950/60 text-purple-700 dark:text-purple-300 font-medium">
                            {q.solution_images.length} Solution Img
                        </span>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            label: 'Actions',
            align: 'right',
            render: (q) => (
                <div className="flex items-center justify-end gap-2">
                    <Button href={`/questions/${q.id}`} variant="ghost" size="sm">
                        View
                    </Button>
                    <Button href={`/questions/${q.id}/edit`} variant="secondary" size="sm">
                        Edit
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setDeleteTarget(q)}
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
            <Head title="Question Bank — EduPlatform" />

            <PageHeader
                title="Question Bank (Owner Protected)"
                eyebrow="Assessment & Practice"
                description="Secure authoring of formative questions, hints, and worked pedagogical solutions."
                actions={
                    <Button href="/questions/create" variant="primary">
                        + New Question
                    </Button>
                }
            />

            <DataTable
                columns={columns}
                data={questions?.data || questions || []}
                emptyTitle="No questions found"
                emptyDescription="The owner question bank is currently empty."
                emptyAction={
                    <Button href="/questions/create" variant="primary" size="sm">
                        Create First Question
                    </Button>
                }
            />

            {questions?.links && <Pagination pagination={questions} className="mt-4" />}

            <ConfirmDialog
                isOpen={Boolean(deleteTarget)}
                onClose={() => setDeleteTarget(null)}
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
