import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import FormField from '../../components/FormField';
import Button from '../../components/Button';
import ErrorSummary from '../../components/ErrorSummary';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/confirm-password', {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout
            title="Confirm your password"
            description="This is a secure area of the application. Please confirm your password before continuing."
        >
            <Head title="Confirm Password — EduPlatform" />

            <ErrorSummary errors={errors} className="mb-6" />

            <form onSubmit={submit} className="space-y-5">
                <FormField
                    label="Password"
                    name="password"
                    type="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    error={errors.password}
                    required
                    autoComplete="current-password"
                    autoFocus
                    placeholder="••••••••"
                />

                <div className="pt-2">
                    <Button
                        type="submit"
                        variant="primary"
                        className="w-full justify-center"
                        isLoading={processing}
                    >
                        Confirm Password
                    </Button>
                </div>
            </form>
        </AuthLayout>
    );
}
