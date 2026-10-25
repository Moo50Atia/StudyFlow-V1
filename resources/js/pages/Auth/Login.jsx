import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import FormField from '../../components/FormField';
import Button from '../../components/Button';
import ErrorSummary from '../../components/ErrorSummary';

export default function Login({ status, canResetPassword = true }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post('/login', {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout
            title="Sign in to your account"
            description="Access your curriculum workspace, interactive lessons, and teaching tools."
        >
            <Head title="Sign In — EduPlatform" />

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

                <FormField
                    label="Password"
                    name="password"
                    type="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    error={errors.password}
                    required
                    autoComplete="current-password"
                    placeholder="••••••••"
                />

                <div className="flex items-center justify-between text-sm">
                    <label className="flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            name="remember"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                            className="w-4 h-4 rounded text-teal-700 dark:text-teal-500 border-slate-300 dark:border-slate-700 focus:ring-teal-600 focus:ring-offset-0"
                        />
                        <span className="text-slate-700 dark:text-slate-300">Remember me</span>
                    </label>

                    {canResetPassword && (
                        <Link
                            href="/forgot-password"
                            className="font-medium text-teal-700 dark:text-teal-400 hover:text-teal-800 dark:hover:text-teal-300 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-600 rounded"
                        >
                            Forgot password?
                        </Link>
                    )}
                </div>

                <div className="pt-2">
                    <Button
                        type="submit"
                        variant="primary"
                        className="w-full justify-center"
                        isLoading={processing}
                    >
                        Sign In
                    </Button>
                </div>

                <div className="text-center text-sm text-slate-600 dark:text-slate-400 pt-4 border-t border-slate-100 dark:border-slate-800">
                    Don't have an account?{' '}
                    <Link
                        href="/register"
                        className="font-semibold text-teal-700 dark:text-teal-400 hover:underline"
                    >
                        Create an account
                    </Link>
                </div>
            </form>
        </AuthLayout>
    );
}
