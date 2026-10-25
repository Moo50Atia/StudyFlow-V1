import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import ConfirmDialog from '../../components/ConfirmDialog';

export default function UsersShow({ user }) {
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);

    const handleDelete = () => {
        router.delete(`/users/${user.id}`, {
            onSuccess: () => router.visit('/users'),
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'User Accounts', url: '/users' },
        { label: user.name },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${user.name} — Account Details — EduPlatform`} />

            <PageHeader
                title={user.name}
                eyebrow={`Role: ${user.role?.toUpperCase()}`}
                description={`Account email: ${user.email} • Enrolled on ${new Date(user.created_at).toLocaleDateString()}`}
                actions={
                    <div className="flex items-center gap-2">
                        <Button href={`/users/${user.id}/edit`} variant="secondary" size="sm">
                            Edit Account
                        </Button>
                        <Button
                            type="button"
                            variant="danger"
                            size="sm"
                            onClick={() => setIsDeleteDialogOpen(true)}
                        >
                            Delete Account
                        </Button>
                    </div>
                }
            />

            <div className="max-w-3xl space-y-6">
                {/* Account Details Card */}
                <div className="bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                    <h2 className="text-base font-bold text-slate-900 dark:text-slate-100 mb-4">
                        Account Information
                    </h2>

                    <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt className="text-slate-500 dark:text-slate-400">Display Name</dt>
                            <dd className="font-semibold text-slate-900 dark:text-slate-100 mt-0.5">{user.name}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500 dark:text-slate-400">Email Address</dt>
                            <dd className="font-semibold text-slate-900 dark:text-slate-100 mt-0.5">{user.email}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500 dark:text-slate-400">Authorization Tier</dt>
                            <dd className="font-semibold uppercase tracking-wider text-teal-700 dark:text-teal-400 mt-0.5">{user.role}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500 dark:text-slate-400">Email Verified</dt>
                            <dd className="font-semibold text-slate-900 dark:text-slate-100 mt-0.5">
                                {user.email_verified_at ? 'Verified' : 'Unverified'}
                            </dd>
                        </div>
                    </dl>
                </div>

                {/* Teacher Permissions Scope */}
                {user.role === 'teacher' && (
                    <div className="bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                        <div className="flex items-center justify-between mb-4">
                            <h2 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                Assigned Curriculum Scopes
                            </h2>
                            <Button href="/teacher_permissions/create" variant="ghost" size="sm">
                                + Grant Scope
                            </Button>
                        </div>

                        {user.teacher_permissions?.length > 0 ? (
                            <ul className="divide-y divide-slate-100 dark:divide-slate-800 text-sm">
                                {user.teacher_permissions.map((perm) => (
                                    <li key={perm.id} className="py-3 flex items-center justify-between">
                                        <div>
                                            <span className="font-semibold text-slate-800 dark:text-slate-200">
                                                {perm.subject?.name || 'All Subjects'}
                                            </span>
                                            {perm.lecture && (
                                                <span className="text-xs text-slate-500 dark:text-slate-400 ml-2">
                                                    (Lecture: {perm.lecture.title})
                                                </span>
                                            )}
                                        </div>
                                        <span className="text-xs text-teal-700 dark:text-teal-400 font-medium">
                                            Active Scope
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-slate-500 py-2">
                                No specific subject permissions assigned yet.
                            </p>
                        )}
                    </div>
                )}
            </div>

            <ConfirmDialog
                isOpen={isDeleteDialogOpen}
                onClose={() => setIsDeleteDialogOpen(false)}
                onConfirm={handleDelete}
                title="Delete User Account?"
                description={`Are you sure you want to delete ${user.name}? This action cannot be undone.`}
                confirmText="Yes, delete"
                cancelText="Cancel"
                variant="danger"
            />
        </AppLayout>
    );
}
