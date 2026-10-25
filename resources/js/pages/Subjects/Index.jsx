import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import DataTable from '../../components/DataTable';
import Pagination from '../../components/Pagination';
import ConfirmDialog from '../../components/ConfirmDialog';

export default function SubjectsIndex({ subjects }) {
    const { auth } = usePage().props;
    const isOwner = Boolean(auth?.is_owner);
    const isTeacher = auth?.user?.role === 'teacher';
    const canCreate = isOwner || isTeacher;

    const [deleteTarget, setDeleteTarget] = useState(null);

    const handleDelete = () => {
        if (!deleteTarget) return;
        router.delete(`/subjects/${deleteTarget.id}`, {
            onSuccess: () => setDeleteTarget(null),
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Subjects' },
    ];

    const columns = [
        {
            key: 'name',
            label: 'Subject Name',
            render: (sub) => (
                <div>
                    <Link
                        href={`/subjects/${sub.id}`}
                        className="font-bold text-teal-800 dark:text-teal-400 hover:underline"
                    >
                        {sub.name}
                    </Link>
                    {sub.description && (
                        <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5 line-clamp-1">
                            {sub.description}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'created_at',
            label: 'Added On',
            render: (sub) => (
                <span className="text-xs text-slate-500 dark:text-slate-400">
                    {sub.created_at ? new Date(sub.created_at).toLocaleDateString() : '—'}
                </span>
            ),
        },
        {
            key: 'actions',
            label: 'Actions',
            align: 'right',
            render: (sub) => (
                <div className="flex items-center justify-end gap-2">
                    <Button href={`/subjects/${sub.id}`} variant="ghost" size="sm">
                        View
                    </Button>
                    {canCreate && (
                        <>
                            <Button href={`/subjects/${sub.id}/edit`} variant="secondary" size="sm">
                                Edit
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setDeleteTarget(sub)}
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
            <Head title="Subjects Catalog — EduPlatform" />

            <PageHeader
                title="Subjects Catalog"
                eyebrow="Curriculum Structure"
                description="Browse, organize, and manage academic subjects and courses across the institution."
                actions={
                    canCreate ? (
                        <Button href="/subjects/create" variant="primary">
                            + Add New Subject
                        </Button>
                    ) : null
                }
            />

            <DataTable
                columns={columns}
                data={subjects?.data || subjects || []}
                emptyTitle="No subjects found"
                emptyDescription="No academic subjects have been created yet."
                emptyAction={
                    canCreate ? (
                        <Button href="/subjects/create" variant="primary" size="sm">
                            Create First Subject
                        </Button>
                    ) : null
                }
            />

            {subjects?.links && <Pagination pagination={subjects} className="mt-4" />}

            <ConfirmDialog
                isOpen={Boolean(deleteTarget)}
                onClose={() => setDeleteTarget(null)}
                onConfirm={handleDelete}
                title="Delete Subject?"
                description={`Are you sure you want to delete "${deleteTarget?.name}"? This action cannot be undone.`}
                confirmText="Yes, delete"
                cancelText="Cancel"
                variant="danger"
            />
        </AppLayout>
    );
}
