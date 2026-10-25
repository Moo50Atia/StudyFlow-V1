import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import FormField from '../../components/FormField';
import Button from '../../components/Button';
import ErrorSummary from '../../components/ErrorSummary';

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/register', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout
            title="Create an account"
            description="Join EduPlatform to start learning or teaching with interactive 3D simulations."
        >
            <Head title="Create Account — EduPlatform" />

            <ErrorSummary errors={errors} className="mb-6" />

            <form onSubmit={submit} className="space-y-5">
                <FormField
                    label="Full Name"
                    name="name"
                    type="text"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    error={errors.name}
                    required
                    autoComplete="name"
                    autoFocus
                    placeholder="Prof. Jane Doe"
                />

                <FormField
                    label="Email address"
                    name="email"
                    type="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    error={errors.email}
                    required
                    autoComplete="email"
                    placeholder="jane@university.edu"
                />

                <FormField
                    label="Password"
                    name="password"
                    type="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    error={errors.password}
                    required
                    autoComplete="new-password"
                    helper="Must be at least 8 characters"
                    placeholder="••••••••"
                />

                <FormField
                    label="Confirm Password"
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
                        Create Account
                    </Button>
                </div>

                <div className="text-center text-sm text-slate-600 dark:text-slate-400 pt-4 border-t border-slate-100 dark:border-slate-800">
                    Already registered?{' '}
                    <Link
                        href="/login"
                        className="font-semibold text-teal-700 dark:text-teal-400 hover:underline"
                    >
                        Sign in
                    </Link>
                </div>
            </form>
        </AuthLayout>
    );
}
