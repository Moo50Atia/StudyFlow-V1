import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import FormField from '../../components/FormField';
import SelectField from '../../components/SelectField';
import Button from '../../components/Button';
import ErrorSummary from '../../components/ErrorSummary';

export default function UsersForm({ user = null }) {
    const isEdit = Boolean(user?.id);

    const { data, setData, post, put, processing, errors } = useForm({
        name: user?.name || '',
        email: user?.email || '',
        password: '',
        role: user?.role || 'student',
        expected_version: user?.account_access?.version ?? user?.version ?? 0,
        expected_role: user?.role || 'student',
    });

    const submit = (e) => {
        e.preventDefault();
        if (isEdit) {
            put(`/users/${user.id}`);
        } else {
            post('/users');
        }
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'User Accounts', url: '/users' },
        { label: isEdit ? `Edit: ${user.name}` : 'New Account' },
    ];

    const roleOptions = [
        { value: 'student', label: 'Student (Curriculum Consumer)' },
        { value: 'teacher', label: 'Teacher (Educator / Ingestion Studio)' },
        { value: 'admin', label: 'Administrator (Operations & Governance)' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${isEdit ? 'Edit Account' : 'Create Account'} — EduPlatform`} />

            <PageHeader
                title={isEdit ? `Edit Account: ${user.name}` : 'Create New Account'}
                eyebrow="Identity Governance"
                description={
                    isEdit
                        ? 'Update account credentials, contact email, and system authorization tier.'
                        : 'Enroll a new student, educator, or operations administrator.'
                }
            />

            <div className="max-w-2xl bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                <ErrorSummary errors={errors} className="mb-6" />

                <form onSubmit={submit} className="space-y-6">
                    <FormField
                        label="Full Name"
                        name="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        error={errors.name}
                        required
                        autoFocus
                        placeholder="Dr. Ahmed Hassan"
                    />

                    <FormField
                        label="Email Address"
                        name="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        error={errors.email}
                        required
                        placeholder="ahmed.hassan@university.edu"
                    />

                    <SelectField
                        label="System Role & Authorization Tier"
                        name="role"
                        value={data.role}
                        onChange={(e) => setData('role', e.target.value)}
                        options={roleOptions}
                        error={errors.role}
                        required
                    />

                    <FormField
                        label={isEdit ? 'Reset Password (Leave blank to keep existing)' : 'Password'}
                        name="password"
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        error={errors.password}
                        required={!isEdit}
                        helper="Must be at least 8 characters"
                        placeholder="••••••••"
                    />

                    <div className="pt-2 flex items-center justify-end gap-3">
                        <Button href="/users" variant="secondary">
                            Cancel
                        </Button>
                        <Button type="submit" variant="primary" isLoading={processing}>
                            {isEdit ? 'Save Changes' : 'Create Account'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
