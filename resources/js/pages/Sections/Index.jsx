import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import DataTable from '../../components/DataTable';
import Pagination from '../../components/Pagination';
import ConfirmDialog from '../../components/ConfirmDialog';

export default function SectionsIndex({ sections }) {
    const { auth } = usePage().props;
    const isOwner = Boolean(auth?.is_owner);
    const isTeacher = auth?.user?.role === 'teacher';
    const canManage = isOwner || isTeacher;

    const [deleteTarget, setDeleteTarget] = useState(null);

    const handleDelete = () => {
        if (!deleteTarget) return;
        router.delete(`/sections/${deleteTarget.id}`, {
            onSuccess: () => setDeleteTarget(null),
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Sections' },
    ];

    const columns = [
        {
            key: 'title',
            label: 'Section Title',
            render: (sec) => (
                <div>
                    <Link
                        href={`/sections/${sec.id}`}
                        className="font-bold text-teal-800 dark:text-teal-400 hover:underline"
                    >
                        {sec.title}
                    </Link>
                    {sec.quick_summary && (
                        <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5 line-clamp-1">
                            {sec.quick_summary}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'lecture',
            label: 'Lecture & Subject',
            render: (sec) => (
                <div className="text-xs">
                    <span className="font-semibold text-slate-800 dark:text-slate-200 block truncate max-w-xs">
                        {sec.lecture?.title || 'Unknown Lecture'}
                    </span>
                    <span className="text-slate-500 dark:text-slate-400">
                        {sec.lecture?.subject?.name || ''}
                    </span>
                </div>
            ),
        },
        {
            key: 'actions',
            label: 'Actions',
            align: 'right',
            render: (sec) => (
                <div className="flex items-center justify-end gap-2">
                    <Button href={`/sections/${sec.id}`} variant="ghost" size="sm">
                        View
                    </Button>
                    {canManage && (
                        <>
                            <Button href={`/sections/${sec.id}/edit`} variant="secondary" size="sm">
                                Edit
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setDeleteTarget(sec)}
                                className="text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40"
                            >
                                Delete
                            </Button>
                        </>
                    )}
                </div>
            ),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Curriculum Sections — EduPlatform" />

            <PageHeader
                title="Curriculum Sections"
                eyebrow="Pedagogical Breakdown"
                description="Browse, write, and refine bilingual section explanations, formulas, and real-life analogies."
                actions={
                    canManage ? (
                        <Button href="/sections/create" variant="primary">
                            + New Section
                        </Button>
                    ) : null
                }
            />

            <DataTable
                columns={columns}
                data={sections?.data || sections || []}
                emptyTitle="No sections found"
                emptyDescription="No curriculum sections have been defined yet."
                emptyAction={
                    canManage ? (
                        <Button href="/sections/create" variant="primary" size="sm">
                            Create Section
                        </Button>
                    ) : null
                }
            />

            {sections?.links && <Pagination pagination={sections} className="mt-4" />}

            <ConfirmDialog
                isOpen={Boolean(deleteTarget)}
                onClose={() => setDeleteTarget(null)}
                onConfirm={handleDelete}
                title="Delete Section?"
                description={`Are you sure you want to delete "${deleteTarget?.title}"? This action cannot be undone.`}
                confirmText="Yes, delete"
                cancelText="Cancel"
                variant="danger"
            />
        </AppLayout>
    );
}
