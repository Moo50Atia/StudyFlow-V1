import React, { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import AppLayout from '../components/AppLayout';
import PageHeader from '../components/PageHeader';
import FormField from '../components/FormField';
import Button from '../components/Button';
import ErrorSummary from '../components/ErrorSummary';
import ConfirmDialog from '../components/ConfirmDialog';

export default function Profile({ user }) {
    const { flash } = usePage().props;
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);

    // Profile Info Form
    const infoForm = useForm({
        name: user?.name || '',
        email: user?.email || '',
    });

    // Password Update Form
    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    // Account Delete Form
    const deleteForm = useForm({
        password: '',
    });

    const handleUpdateProfile = (e) => {
        e.preventDefault();
        infoForm.patch('/profile');
    };

    const handleUpdatePassword = (e) => {
        e.preventDefault();
        passwordForm.put('/password', {
            onSuccess: () => passwordForm.reset(),
        });
    };

    const handleDeleteAccount = () => {
        deleteForm.delete('/profile', {
            onSuccess: () => setIsDeleteDialogOpen(false),
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Profile Settings' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Profile Settings — EduPlatform" />

            <PageHeader
                title="Profile Settings"
                eyebrow="Account Management"
                description="Manage your identity, update your security credentials, and handle account options."
            />

            {flash?.status === 'profile-updated' && (
                <div
                    role="status"
                    className="mb-6 p-4 rounded-2xl bg-teal-50 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-900 text-teal-800 dark:text-teal-300 text-sm font-semibold flex items-center gap-2"
                >
                    <svg className="w-5 h-5 text-teal-600 dark:text-teal-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    Profile information saved successfully.
                </div>
            )}

            {flash?.status === 'password-updated' && (
                <div
                    role="status"
                    className="mb-6 p-4 rounded-2xl bg-teal-50 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-900 text-teal-800 dark:text-teal-300 text-sm font-semibold flex items-center gap-2"
                >
                    <svg className="w-5 h-5 text-teal-600 dark:text-teal-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    Password updated successfully.
                </div>
            )}

            <div className="space-y-8 max-w-3xl">
                {/* 1. Personal Information */}
                <section className="bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                    <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100 mb-1">
                        Personal Information
                    </h2>
                    <p className="text-sm text-slate-600 dark:text-slate-400 mb-6">
                        Update your account's display name and primary email address.
                    </p>

                    <ErrorSummary errors={infoForm.errors} className="mb-6" />

                    <form onSubmit={handleUpdateProfile} className="space-y-5">
                        <FormField
                            label="Display Name"
                            name="name"
                            type="text"
                            value={infoForm.data.name}
                            onChange={(e) => infoForm.setData('name', e.target.value)}
                            error={infoForm.errors.name}
                            required
                        />

                        <FormField
                            label="Email Address"
                            name="email"
                            type="email"
                            value={infoForm.data.email}
                            onChange={(e) => infoForm.setData('email', e.target.value)}
                            error={infoForm.errors.email}
                            required
                        />

                        <div className="pt-2">
                            <Button
                                type="submit"
                                variant="primary"
                                isLoading={infoForm.processing}
                            >
                                Save Changes
                            </Button>
                        </div>
                    </form>
                </section>

                {/* 2. Change Password */}
                <section className="bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                    <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100 mb-1">
                        Change Password
                    </h2>
                    <p className="text-sm text-slate-600 dark:text-slate-400 mb-6">
                        Ensure your account uses a long, random password to stay secure.
                    </p>

                    <ErrorSummary errors={passwordForm.errors} className="mb-6" />

                    <form onSubmit={handleUpdatePassword} className="space-y-5">
                        <FormField
                            label="Current Password"
                            name="current_password"
                            type="password"
                            value={passwordForm.data.current_password}
                            onChange={(e) => passwordForm.setData('current_password', e.target.value)}
                            error={passwordForm.errors.current_password}
                            required
                            autoComplete="current-password"
                        />

                        <FormField
                            label="New Password"
                            name="password"
                            type="password"
                            value={passwordForm.data.password}
                            onChange={(e) => passwordForm.setData('password', e.target.value)}
                            error={passwordForm.errors.password}
                            required
                            autoComplete="new-password"
                            helper="Must be at least 8 characters"
                        />

                        <FormField
                            label="Confirm New Password"
                            name="password_confirmation"
                            type="password"
                            value={passwordForm.data.password_confirmation}
                            onChange={(e) => passwordForm.setData('password_confirmation', e.target.value)}
                            error={passwordForm.errors.password_confirmation}
                            required
                            autoComplete="new-password"
                        />

                        <div className="pt-2">
                            <Button
                                type="submit"
                                variant="secondary"
                                isLoading={passwordForm.processing}
                            >
                                Update Password
                            </Button>
                        </div>
                    </form>
                </section>

                {/* 3. Delete Account (Destructive Zone) */}
                <section className="bg-rose-50/40 dark:bg-rose-950/20 p-6 sm:p-8 rounded-3xl border border-rose-200 dark:border-rose-900/60 shadow-sm">
                    <h2 className="text-lg font-bold text-rose-900 dark:text-rose-300 mb-1">
                        Delete Account
                    </h2>
                    <p className="text-sm text-slate-600 dark:text-slate-400 mb-6 max-w-xl">
                        Once your account is deleted, all associated resources and personal data will be permanently removed.
                    </p>

                    <Button
                        type="button"
                        variant="danger"
                        onClick={() => setIsDeleteDialogOpen(true)}
                    >
                        Delete My Account
                    </Button>
                </section>
            </div>

            {/* Confirm Account Deletion Dialog */}
            <ConfirmDialog
                isOpen={isDeleteDialogOpen}
                onClose={() => setIsDeleteDialogOpen(false)}
                onConfirm={handleDeleteAccount}
                title="Permanently delete account?"
                description="Please enter your current password to confirm you want to permanently delete your account."
                confirmText="Yes, delete my account"
                cancelText="Keep account"
                variant="danger"
                isLoading={deleteForm.processing}
            />
        </AppLayout>
    );
}
