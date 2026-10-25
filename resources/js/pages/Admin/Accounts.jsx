import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import Pagination from '../../components/Pagination';
import StatusBadge from '../../components/StatusBadge';

export default function Accounts({ accounts }) {
    const rows = accounts?.data || [];

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Operations Console', url: '/v3/admin/workspace' },
        { label: 'Account Access Management' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Account Access — EduPlatform" />

            <PageHeader
                title="Account Access & V3 Entitlements"
                eyebrow="Governance & Security"
                description="Manage sovereign V3 engine authorization, role levels, and account suspension with optimistic concurrency checks."
                actions={
                    <Button href="/users/create" variant="primary" size="sm">
                        + Enroll New Account
                    </Button>
                }
            />

            <div className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] overflow-hidden shadow-sm">
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 dark:divide-slate-800 text-left text-sm">
                        <thead className="bg-slate-50 dark:bg-[#182823] text-xs font-semibold uppercase tracking-wider text-slate-700 dark:text-slate-300">
                            <tr>
                                <th scope="col" className="px-6 py-4">Account User</th>
                                <th scope="col" className="px-4 py-4">Role</th>
                                <th scope="col" className="px-4 py-4">V3 State</th>
                                <th scope="col" className="px-4 py-4">Version</th>
                                <th scope="col" className="px-6 py-4">Update Access Tier</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800/60 bg-white dark:bg-[#121e1a]">
                            {rows.map((account) => (
                                <AccountRow key={account.id} account={account} />
                            ))}
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan="5" className="p-8 text-center text-slate-500">
                                        No platform accounts found.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {accounts?.links && <Pagination pagination={accounts} />}
            </div>
        </AppLayout>
    );
}

function AccountRow({ account }) {
    const form = useForm({
        role: account.role,
        is_active: account.v3_is_active ? '1' : '0',
        expected_version: account.v3_version,
        expected_role: account.role,
        reason_code: account.v3_is_active ? 'change_role' : 'grant_access',
    });

    const handleUpdate = (e) => {
        e.preventDefault();
        form.patch(`/v3/admin/accounts/${account.id}/access`);
    };

    return (
        <tr className="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors">
            <td className="px-6 py-4">
                <p className="font-bold text-slate-900 dark:text-slate-100">{account.name}</p>
                <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{account.email}</p>
            </td>

            <td className="px-4 py-4 capitalize font-medium text-slate-800 dark:text-slate-200">
                {account.role}
            </td>

            <td className="px-4 py-4">
                <StatusBadge status={account.v3_is_active ? 'active' : 'inactive'} />
            </td>

            <td className="px-4 py-4 font-mono text-xs text-slate-500">
                v{account.v3_version}
            </td>

            <td className="px-6 py-4">
                <form onSubmit={handleUpdate} className="flex flex-wrap items-center gap-2">
                    <select
                        aria-label={`Role for ${account.name}`}
                        value={form.data.role}
                        onChange={(e) => form.setData('role', e.target.value)}
                        className="rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-[#182823] text-xs py-1.5 px-2 text-slate-800 dark:text-slate-200"
                    >
                        <option value="admin">Admin</option>
                        <option value="teacher">Teacher</option>
                        <option value="student">Student</option>
                    </select>

                    <select
                        aria-label={`Access status for ${account.name}`}
                        value={form.data.is_active}
                        onChange={(e) => {
                            const active = e.target.value;
                            form.setData({
                                ...form.data,
                                is_active: active,
                                reason_code:
                                    active === '1'
                                        ? account.v3_is_active
                                            ? 'change_role'
                                            : 'restore_access'
                                        : 'suspend_access',
                            });
                        }}
                        className="rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-[#182823] text-xs py-1.5 px-2 text-slate-800 dark:text-slate-200"
                    >
                        <option value="1">Active</option>
                        <option value="0">Suspended</option>
                    </select>

                    <select
                        aria-label={`Reason for ${account.name}`}
                        value={form.data.reason_code}
                        onChange={(e) => form.setData('reason_code', e.target.value)}
                        className="rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-[#182823] text-xs py-1.5 px-2 text-slate-800 dark:text-slate-200"
                    >
                        <option value="grant_access">Grant Access</option>
                        <option value="suspend_access">Suspend Access</option>
                        <option value="restore_access">Restore Access</option>
                        <option value="change_role">Change Role</option>
                    </select>

                    <Button
                        type="submit"
                        variant="primary"
                        size="sm"
                        isLoading={form.processing}
                    >
                        Save
                    </Button>

                    {Object.values(form.errors).map((error, i) => (
                        <span key={i} role="alert" className="w-full text-xs text-rose-600 dark:text-rose-400 mt-1">
                            {error}
                        </span>
                    ))}
                </form>
            </td>
        </tr>
    );
}
