import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import FormField from '../../components/FormField';
import Button from '../../components/Button';
import ErrorSummary from '../../components/ErrorSummary';

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/forgot-password');
    };

    return (
        <AuthLayout
            title="Reset your password"
            description="Enter your email address and we will send you a password reset link."
        >
            <Head title="Forgot Password — EduPlatform" />

            <ErrorSummary errors={errors} className="mb-6" />

            <form onSubmit={submit} className="space-y-5">
                <FormField
                    label="Email address"
                    name="email"
                    type="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    error={errors.email}
                    required
                    autoComplete="username"
                    autoFocus
                    placeholder="name@university.edu"
                />

                <div className="pt-2">
                    <Button
                        type="submit"
                        variant="primary"
                        className="w-full justify-center"
                        isLoading={processing}
                    >
                        Email Password Reset Link
                    </Button>
                </div>

                <div className="text-center text-sm text-slate-600 dark:text-slate-400 pt-4 border-t border-slate-100 dark:border-slate-800">
                    Remember your password?{' '}
                    <Link
                        href="/login"
                        className="font-semibold text-teal-700 dark:text-teal-400 hover:underline"
                    >
                        Return to sign in
                    </Link>
                </div>
            </form>
        </AuthLayout>
    );
}
