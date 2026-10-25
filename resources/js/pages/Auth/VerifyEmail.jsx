import React from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import Button from '../../components/Button';

export default function VerifyEmail({ status }) {
    const { post, processing } = useForm({});

    const submit = (e) => {
        e.preventDefault();
        post('/email/verification-notification');
    };

    const handleLogout = (e) => {
        e.preventDefault();
        router.post('/logout');
    };

    const verificationLinkSent = status === 'verification-link-sent';

    return (
        <AuthLayout
            title="Verify your email address"
            description="Thanks for signing up! Before getting started, please verify your email address by clicking the link we just sent to you."
        >
            <Head title="Verify Email — EduPlatform" />

            {verificationLinkSent && (
                <div
                    role="status"
                    className="mb-6 p-4 rounded-xl bg-teal-50 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-900 text-teal-800 dark:text-teal-300 text-sm font-medium"
                >
                    A new verification link has been sent to the email address you provided during registration.
                </div>
            )}

            <form onSubmit={submit} className="space-y-4">
                <Button
                    type="submit"
                    variant="primary"
                    className="w-full justify-center"
                    isLoading={processing}
                >
                    Resend Verification Email
                </Button>

                <div className="text-center pt-2">
                    <button
                        type="button"
                        onClick={handleLogout}
                        className="text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-rose-600 dark:hover:text-rose-400 underline transition-colors"
                    >
                        Sign Out
                    </button>
                </div>
            </form>
        </AuthLayout>
    );
}
