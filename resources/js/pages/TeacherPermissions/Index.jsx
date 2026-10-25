import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import DataTable from '../../components/DataTable';
import Pagination from '../../components/Pagination';
import ConfirmDialog from '../../components/ConfirmDialog';

export default function TeacherPermissionsIndex({ teacher_permissions }) {
    const [deleteTarget, setDeleteTarget] = useState(null);

    const handleDelete = () => {
        if (!deleteTarget) return;
        router.delete(`/teacher_permissions/${deleteTarget.id}`, {
            onSuccess: () => setDeleteTarget(null),
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Teacher Scopes' },
    ];

    const columns = [
        {
            key: 'teacher',
            label: 'Educator',
            render: (tp) => (
                <div>
                    <span className="font-bold text-slate-900 dark:text-slate-100">
                        {tp.teacher?.name || 'Unknown Educator'}
                    </span>
                    <p className="text-xs text-slate-500 dark:text-slate-400">
                        {tp.teacher?.email}
                    </p>
                </div>
            ),
        },
        {
            key: 'subject',
            label: 'Subject Scope',
            render: (tp) => (
                <span className="font-semibold text-teal-800 dark:text-teal-400 text-sm">
                    {tp.subject?.name || 'All Subjects'}
                </span>
            ),
        },
        {
            key: 'lecture',
            label: 'Lecture Scope',
            render: (tp) => (
                <span className="text-xs text-slate-600 dark:text-slate-300">
                    {tp.lecture?.title || 'Entire Subject'}
                </span>
            ),
        },
        {
            key: 'actions',
            label: 'Actions',
            align: 'right',
            render: (tp) => (
                <div className="flex items-center justify-end gap-2">
                    <Button href={`/teacher_permissions/${tp.id}`} variant="ghost" size="sm">
                        View
                    </Button>
                    <Button href={`/teacher_permissions/${tp.id}/edit`} variant="secondary" size="sm">
                        Edit
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setDeleteTarget(tp)}
                        className="text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40"
                    >
                        Revoke
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Teacher Scopes — EduPlatform" />

            <PageHeader
                title="Teacher Permission Scopes"
                eyebrow="Access Control"
                description="Manage curriculum authoring and review scopes assigned to faculty educators."
                actions={
                    <Button href="/teacher_permissions/create" variant="primary">
                        + Grant New Scope
                    </Button>
                }
            />

            <DataTable
                columns={columns}
                data={teacher_permissions?.data || teacher_permissions || []}
                emptyTitle="No scopes granted"
                emptyDescription="No teacher access scopes have been configured."
                emptyAction={
                    <Button href="/teacher_permissions/create" variant="primary" size="sm">
                        Grant First Scope
                    </Button>
                }
            />

            {teacher_permissions?.links && <Pagination pagination={teacher_permissions} className="mt-4" />}

            <ConfirmDialog
                isOpen={Boolean(deleteTarget)}
                onClose={() => setDeleteTarget(null)}
                onConfirm={handleDelete}
                title="Revoke Permission Scope?"
                description="Are you sure you want to revoke this educator's authoring scope?"
                confirmText="Yes, revoke"
                cancelText="Cancel"
                variant="danger"
            />
        </AppLayout>
    );
}
