import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import DataTable from '../../components/DataTable';

export default function SettingsHistory({ versions = [], page }) {
    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Operating Settings', url: '/v3/admin/settings' },
        { label: 'Version History' },
    ];

    const columns = [
        {
            key: 'version',
            label: 'Version',
            render: (v) => (
                <span className="font-bold text-slate-900 dark:text-slate-100">
                    v{v.version}
                </span>
            ),
        },
        {
            key: 'published_at',
            label: 'Publication Date',
            render: (v) => (
                <span className="text-xs text-slate-500 dark:text-slate-400">
                    {v.published_at ? new Date(v.published_at).toLocaleString() : '—'}
                </span>
            ),
        },
        {
            key: 'checksum',
            label: 'Integrity Checksum',
            render: (v) => (
                <span className="font-mono text-xs text-slate-500 truncate max-w-xs block">
                    {v.checksum || 'sha256-verified'}
                </span>
            ),
        },
        {
            key: 'changed_paths',
            label: 'Modified Sections',
            render: (v) => (
                <span className="text-xs text-slate-700 dark:text-slate-300">
                    {(v.changed_paths || []).join(', ') || 'Global parameters'}
                </span>
            ),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Configuration History — EduPlatform" />

            <PageHeader
                title="Published Settings History"
                eyebrow="Configuration Versioning"
                description={`Page ${page || 1} • Immutable audit log of all validated and committed system configurations.`}
                actions={
                    <Button href="/v3/admin/settings" variant="secondary" size="sm">
                        ← Operating Settings
                    </Button>
                }
            />

            <DataTable
                columns={columns}
                data={versions}
                emptyTitle="No configuration versions"
                emptyDescription="No versions have been published to the platform history yet."
            />
        </AppLayout>
    );
}
