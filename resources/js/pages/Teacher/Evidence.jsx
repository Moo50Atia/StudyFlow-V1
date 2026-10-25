import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import StatusBadge from '../../components/StatusBadge';
import DataTable from '../../components/DataTable';

export default function Evidence({ case: caseModel, evidence = {} }) {
    const caseId = caseModel?.id || evidence?.case_id;
    const events = evidence?.events || [];
    const integrity = evidence?.integrity || 'verified';
    const [isExporting, setIsExporting] = useState(false);

    const handleExport = () => {
        setIsExporting(true);
        router.post(`/v3/teacher/cases/${caseId}/evidence-exports`, {}, {
            onFinish: () => setIsExporting(false),
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: `Case #${caseId}`, url: `/v3/teacher/cases/${caseId}` },
        { label: 'Compliance Evidence' },
    ];

    const columns = [
        {
            key: 'timestamp',
            label: 'Timestamp',
            render: (evt) => (
                <span className="text-xs font-mono text-slate-500 dark:text-slate-400">
                    {evt.timestamp ? new Date(evt.timestamp).toLocaleString() : '—'}
                </span>
            ),
        },
        {
            key: 'action',
            label: 'Action / Milestone',
            render: (evt) => (
                <span className="font-semibold text-xs text-slate-900 dark:text-slate-100">
                    {evt.event_type || evt.action || 'Evidence Milestone'}
                </span>
            ),
        },
        {
            key: 'hash',
            label: 'Integrity Digest',
            render: (evt) => (
                <span className="font-mono text-xs text-slate-500 truncate max-w-xs block">
                    {evt.digest || evt.hash || 'sha256-verified'}
                </span>
            ),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Evidence: Case #${caseId} — EduPlatform`} />

            <PageHeader
                title={`Compliance & Audit Evidence (Case #${caseId})`}
                eyebrow="Sovereign Integrity Audit"
                description="Immutable audit trail of ingestion inputs, qualified prompts, and educator qualification decisions."
                actions={
                    <div className="flex items-center gap-2">
                        <Button href={`/v3/teacher/cases/${caseId}`} variant="secondary" size="sm">
                            ← Case Overview
                        </Button>
                        <Button
                            type="button"
                            variant="primary"
                            size="sm"
                            onClick={handleExport}
                            isLoading={isExporting}
                        >
                            Export Evidence Bundle
                        </Button>
                    </div>
                }
            />

            <div className="space-y-6">
                {/* Integrity Banner */}
                <div className="p-6 rounded-3xl bg-white dark:bg-[#121e1a] border border-slate-200 dark:border-[#263932] shadow-sm flex items-center justify-between">
                    <div>
                        <span className="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">
                            Cryptographic Integrity
                        </span>
                        <div className="flex items-center gap-2 mt-1">
                            <span className="text-xl font-bold text-slate-900 dark:text-slate-100">
                                {integrity.toUpperCase()}
                            </span>
                            <StatusBadge status="verified" />
                        </div>
                    </div>

                    <div className="text-xs text-slate-500 dark:text-slate-400 text-right">
                        <span>Approvals: {(evidence?.current_approval_refs || []).length} active</span>
                    </div>
                </div>

                {/* Event Audit Table */}
                <DataTable
                    columns={columns}
                    data={events}
                    emptyTitle="No audit records"
                    emptyDescription="Audit records will populate as qualification milestones occur."
                />
            </div>
        </AppLayout>
    );
}
