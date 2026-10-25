import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import StatusBadge from '../../components/StatusBadge';
import DataTable from '../../components/DataTable';

export default function ReviewIndex({ reviews = {}, case: caseInfo, reminders = [] }) {
    const items = reviews.data || [];
    const caseId = caseInfo?.id;

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        ...(caseId ? [{ label: `Case #${caseId}`, url: `/v3/teacher/cases/${caseId}` }] : []),
        { label: 'Scene Reviews' },
    ];

    const columns = [
        {
            key: 'ref',
            label: 'Review Item',
            render: (item, idx) => (
                <div>
                    <Link
                        href={`/v3/teacher/cases/${item.case_id || caseId}/reviews/${item.review_ref}`}
                        className="font-bold text-teal-800 dark:text-teal-400 hover:underline"
                    >
                        Scene Candidate #{idx + 1}
                    </Link>
                    <p className="font-mono text-xs text-slate-400 mt-0.5">
                        {item.review_ref?.slice(0, 13)}...
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
            key: 'status',
            label: 'Review Readiness',
            render: (item) => (
                <StatusBadge status={item.status || 'review_ready'} />
            ),
        },
        {
            key: 'actions',
            label: 'Actions',
            align: 'right',
            render: (item) => (
                <Button
                    href={`/v3/teacher/cases/${item.case_id || caseId}/reviews/${item.review_ref}`}
                    variant="primary"
                    size="sm"
                >
                    Open Viewport →
                </Button>
            ),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="3D Scene Reviews — EduPlatform" />

            <PageHeader
                title="Scene Review & Qualification Queue"
                eyebrow="Pedagogical Quality Gate"
                description="Inspect interactive 3D simulations in an isolated viewport before approving publication to student cohorts."
                actions={
                    caseId ? (
                        <div className="flex items-center gap-2">
                            <Button href={`/v3/teacher/cases/${caseId}`} variant="secondary" size="sm">
                                ← Back to Case
                            </Button>
                            <Button href={`/v3/teacher/cases/${caseId}/approved-scenes`} variant="ghost" size="sm">
                                Approved Scenes
                            </Button>
                        </div>
                    ) : null
                }
            />

            {reminders.length > 0 && (
                <div role="status" className="mb-6 p-4 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-800 text-amber-900 dark:text-amber-200 text-sm font-semibold flex items-center gap-2">
                    <span className="w-2.5 h-2.5 rounded-full bg-amber-500 animate-ping" />
                    New scene candidates are waiting for your pedagogical qualification decision.
                </div>
            )}

            <div className="space-y-6">
                <DataTable
                    columns={columns}
                    data={items}
                    emptyTitle="No scenes awaiting review"
                    emptyDescription="All scene candidates have been qualified or are still in synthesis."
                />
            </div>
        </AppLayout>
    );
}
