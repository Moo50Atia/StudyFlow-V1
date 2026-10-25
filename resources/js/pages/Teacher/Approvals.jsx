import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import StatusBadge from '../../components/StatusBadge';
import DataTable from '../../components/DataTable';

export default function Approvals({ approvals = {}, case: caseInfo }) {
    const items = approvals.data || [];
    const caseId = caseInfo?.id;

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        ...(caseId ? [{ label: `Case #${caseId}`, url: `/v3/teacher/cases/${caseId}` }] : []),
        { label: 'Approved Scenes' },
    ];

    const columns = [
        {
            key: 'ref',
            label: 'Approved Scene',
            render: (item, idx) => (
                <div>
                    <span className="font-bold text-slate-900 dark:text-slate-100">
                        Interactive Scene #{idx + 1}
                    </span>
                    <p className="font-mono text-xs text-slate-400 mt-0.5">
                        {item.approval_ref?.slice(0, 13)}...
                    </p>
                </div>
            ),
        },
        {
            key: 'version',
            label: 'Candidate Version',
            render: (item) => (
                <span className="font-mono text-xs text-slate-600 dark:text-slate-300">
                    v{item.candidate_version || '1'}
                </span>
            ),
        },
        {
            key: 'approved_at',
            label: 'Approval Date',
            render: (item) => (
                <span className="text-xs text-slate-500 dark:text-slate-400">
                    {item.approved_at ? new Date(item.approved_at).toLocaleString() : 'Approved'}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            render: () => <StatusBadge status="approved" />,
        },
        {
            key: 'actions',
            label: 'Actions',
            align: 'right',
            render: (item) => (
                <Button
                    href={`/v3/teacher/cases/${caseId}/reviews/${item.review_ref}`}
                    variant="secondary"
                    size="sm"
                >
                    Request Correction
                </Button>
            ),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Approved Scenes — EduPlatform" />

            <PageHeader
                title="Approved Curriculum Scenes"
                eyebrow="Qualified Educational Content"
                description="List of verified 3D interactive scenes published or ready for student cohorts."
                actions={
                    caseId ? (
                        <div className="flex items-center gap-2">
                            <Button href={`/v3/teacher/cases/${caseId}`} variant="secondary" size="sm">
                                ← Back to Case
                            </Button>
                            <Button href={`/v3/teacher/cases/${caseId}/reviews`} variant="primary" size="sm">
                                Review Queue
                            </Button>
                        </div>
                    ) : null
                }
            />

            <div className="space-y-6">
                <DataTable
                    columns={columns}
                    data={items}
                    emptyTitle="No approved scenes yet"
                    emptyDescription="Approved scenes will appear here once qualified in the review viewport."
                />
            </div>
        </AppLayout>
    );
}
