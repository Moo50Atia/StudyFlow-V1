import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import DataTable from '../../components/DataTable';
import Pagination from '../../components/Pagination';
import ConfirmDialog from '../../components/ConfirmDialog';

export default function UsersIndex({ users }) {
    const [deleteTarget, setDeleteTarget] = useState(null);

    const handleDelete = () => {
        if (!deleteTarget) return;
        router.delete(`/users/${deleteTarget.id}`, {
            onSuccess: () => setDeleteTarget(null),
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'User Accounts' },
    ];

    const getRoleBadge = (role) => {
        switch (role?.toLowerCase()) {
            case 'admin':
                return 'bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300';
            case 'teacher':
                return 'bg-teal-100 text-teal-800 dark:bg-teal-950/60 dark:text-teal-300';
            case 'student':
            default:
                return 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-300';
        }
    };

    const columns = [
        {
            key: 'name',
            label: 'Name & Email',
            render: (u) => (
                <div>
                    <Link
                        href={`/users/${u.id}`}
                        className="font-bold text-teal-800 dark:text-teal-400 hover:underline"
                    >
                        {u.name}
                    </Link>
                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        {u.email}
                    </p>
                </div>
            ),
        },
        {
            key: 'role',
            label: 'Assigned Role',
            render: (u) => (
                <span className={`px-2.5 py-1 text-xs font-semibold rounded-full uppercase tracking-wider ${getRoleBadge(u.role)}`}>
                    {u.role}
                </span>
            ),
        },
        {
            key: 'created_at',
            label: 'Joined On',
            render: (u) => (
                <span className="text-xs text-slate-500 dark:text-slate-400">
                    {u.created_at ? new Date(u.created_at).toLocaleDateString() : '—'}
                </span>
            ),
        },
        {
            key: 'actions',
            label: 'Actions',
            align: 'right',
            render: (u) => (
                <div className="flex items-center justify-end gap-2">
                    <Button href={`/users/${u.id}`} variant="ghost" size="sm">
                        View
                    </Button>
                    <Button href={`/users/${u.id}/edit`} variant="secondary" size="sm">
                        Edit
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setDeleteTarget(u)}
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
            <Head title="User Accounts — EduPlatform" />

            <PageHeader
                title="User Accounts Directory"
                eyebrow="Identity Governance"
                description="Manage university accounts, role authorizations, and educational access tiers."
                actions={
                    <Button href="/users/create" variant="primary">
                        + Add New Account
                    </Button>
                }
            />

            <DataTable
                columns={columns}
                data={users?.data || users || []}
                emptyTitle="No users found"
                emptyDescription="No platform accounts registered."
            />

            {users?.links && <Pagination pagination={users} className="mt-4" />}

            <ConfirmDialog
                isOpen={Boolean(deleteTarget)}
                onClose={() => setDeleteTarget(null)}
                onConfirm={handleDelete}
                title="Delete User Account?"
                description={`Are you sure you want to delete ${deleteTarget?.name} (${deleteTarget?.email})? All associated permissions will be revoked.`}
                confirmText="Yes, delete"
                cancelText="Cancel"
                variant="danger"
            />
        </AppLayout>
    );
}
