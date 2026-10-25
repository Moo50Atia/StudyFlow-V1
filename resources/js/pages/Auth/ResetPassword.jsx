import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import FormField from '../../components/FormField';
import Button from '../../components/Button';
import ErrorSummary from '../../components/ErrorSummary';

export default function ResetPassword({ token, email }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token: token,
        email: email || '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/reset-password', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout
            title="Set new password"
            description="Create a secure new password for your EduPlatform account."
        >
            <Head title="Reset Password — EduPlatform" />

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
                    placeholder="name@university.edu"
                />

                <FormField
                    label="New Password"
                    name="password"
                    type="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    error={errors.password}
                    required
                    autoComplete="new-password"
                    autoFocus
                    placeholder="••••••••"
                />

                <FormField
                    label="Confirm New Password"
                    name="password_confirmation"
                    type="password"
                    value={data.password_confirmation}
                    onChange={(e) => setData('password_confirmation', e.target.value)}
                    error={errors.password_confirmation}
                    required
                    autoComplete="new-password"
                    placeholder="••••••••"
                />

                <div className="pt-2">
                    <Button
                        type="submit"
                        variant="primary"
                        className="w-full justify-center"
                        isLoading={processing}
                    >
                        Reset Password
                    </Button>
                </div>
            </form>
        </AuthLayout>
    );
}
