import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import ConfirmDialog from '../../components/ConfirmDialog';

export default function TeacherPermissionsShow({ teacherPermission }) {
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);

    const handleDelete = () => {
        router.delete(`/teacher_permissions/${teacherPermission.id}`, {
            onSuccess: () => router.visit('/teacher_permissions'),
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Teacher Scopes', url: '/teacher_permissions' },
        { label: `Scope #${teacherPermission.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Teacher Scope #${teacherPermission.id} — EduPlatform`} />

            <PageHeader
                title={`Scope Assignment #${teacherPermission.id}`}
                eyebrow="Access Control"
                description={`Educator: ${teacherPermission.teacher?.name} (${teacherPermission.teacher?.email})`}
                actions={
                    <div className="flex items-center gap-2">
                        <Button href={`/teacher_permissions/${teacherPermission.id}/edit`} variant="secondary" size="sm">
                            Edit Scope
                        </Button>
                        <Button
                            type="button"
                            variant="danger"
                            size="sm"
                            onClick={() => setIsDeleteDialogOpen(true)}
                        >
                            Revoke Scope
                        </Button>
                    </div>
                }
            />

            <div className="max-w-2xl bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                <h2 className="text-base font-bold text-slate-900 dark:text-slate-100 mb-4">
                    Scope Details
                </h2>

                <dl className="space-y-4 text-sm">
                    <div>
                        <dt className="text-slate-500 dark:text-slate-400">Educator</dt>
                        <dd className="font-semibold text-slate-900 dark:text-slate-100 mt-0.5">
                            {teacherPermission.teacher?.name} ({teacherPermission.teacher?.email})
                        </dd>
                    </div>

                    <div>
                        <dt className="text-slate-500 dark:text-slate-400">Subject Scope</dt>
                        <dd className="font-semibold text-teal-800 dark:text-teal-400 mt-0.5">
                            {teacherPermission.subject?.name || 'All Subjects'}
                        </dd>
                    </div>

                    <div>
                        <dt className="text-slate-500 dark:text-slate-400">Lecture Scope</dt>
                        <dd className="font-semibold text-slate-800 dark:text-slate-200 mt-0.5">
                            {teacherPermission.lecture?.title || 'Entire Subject (All Current and Future Lectures)'}
                        </dd>
                    </div>

                    <div>
                        <dt className="text-slate-500 dark:text-slate-400">Granted On</dt>
                        <dd className="font-semibold text-slate-800 dark:text-slate-200 mt-0.5">
                            {new Date(teacherPermission.created_at).toLocaleDateString()}
                        </dd>
                    </div>
                </dl>
            </div>

            <ConfirmDialog
                isOpen={isDeleteDialogOpen}
                onClose={() => setIsDeleteDialogOpen(false)}
                onConfirm={handleDelete}
                title="Revoke Permission Scope?"
                description="Are you sure you want to revoke this educator's scope?"
                confirmText="Yes, revoke"
                cancelText="Cancel"
                variant="danger"
            />
        </AppLayout>
    );
}
