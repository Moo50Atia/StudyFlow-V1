import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import DataTable from '../../components/DataTable';
import Pagination from '../../components/Pagination';
import ConfirmDialog from '../../components/ConfirmDialog';

export default function LecturesIndex({ lectures }) {
    const { auth } = usePage().props;
    const isOwner = Boolean(auth?.is_owner);
    const isTeacher = auth?.user?.role === 'teacher';
    const canManage = isOwner || isTeacher;

    const [deleteTarget, setDeleteTarget] = useState(null);

    const handleDelete = () => {
        if (!deleteTarget) return;
        router.delete(`/lectures/${deleteTarget.id}`, {
            onSuccess: () => setDeleteTarget(null),
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Lectures' },
    ];

    const columns = [
        {
            key: 'title',
            label: 'Lecture Title',
            render: (lec) => (
                <div>
                    <Link
                        href={`/lectures/${lec.id}`}
                        className="font-bold text-teal-800 dark:text-teal-400 hover:underline"
                    >
                        {lec.title}
                    </Link>
                    {lec.description && (
                        <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5 line-clamp-1">
                            {lec.description}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'subject',
            label: 'Subject',
            render: (lec) => (
                <span className="text-xs font-semibold px-2.5 py-1 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                    {lec.subject?.name || 'Unassigned'}
                </span>
            ),
        },
        {
            key: 'actions',
            label: 'Actions',
            align: 'right',
            render: (lec) => (
                <div className="flex items-center justify-end gap-2">
                    <Button href={`/lectures/${lec.id}`} variant="ghost" size="sm">
                        View
                    </Button>
                    {canManage && (
                        <>
                            <Button href={`/lectures/${lec.id}/edit`} variant="secondary" size="sm">
                                Edit
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setDeleteTarget(lec)}
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
            <Head title="Lectures Catalog — EduPlatform" />

            <PageHeader
                title="Lectures Catalog"
                eyebrow="Curriculum Management"
                description="Browse, structure, and edit course lectures, notes, and uploaded PDF courseware."
                actions={
                    canManage ? (
                        <Button href="/lectures/create" variant="primary">
                            + New Lecture
                        </Button>
                    ) : null
                }
            />

            <DataTable
                columns={columns}
                data={lectures?.data || lectures || []}
                emptyTitle="No lectures found"
                emptyDescription="No lectures have been created or published for your account."
                emptyAction={
                    canManage ? (
                        <Button href="/lectures/create" variant="primary" size="sm">
                            Create Lecture
                        </Button>
                    ) : null
                }
            />

            {lectures?.links && <Pagination pagination={lectures} className="mt-4" />}

            <ConfirmDialog
                isOpen={Boolean(deleteTarget)}
                onClose={() => setDeleteTarget(null)}
                onConfirm={handleDelete}
                title="Delete Lecture?"
                description={`Are you sure you want to delete "${deleteTarget?.title}"? This action cannot be undone.`}
                confirmText="Yes, delete"
                cancelText="Cancel"
                variant="danger"
            />
        </AppLayout>
    );
}
