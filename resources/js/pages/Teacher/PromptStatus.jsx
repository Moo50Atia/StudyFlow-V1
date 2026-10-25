import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import StatusBadge from '../../components/StatusBadge';
import DataTable from '../../components/DataTable';

export default function PromptStatus({ case: caseModel, data = {} }) {
    const caseId = caseModel?.id || data?.case_id;
    const sections = data?.sections || [];

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: `Case #${caseId}`, url: `/v3/teacher/cases/${caseId}` },
        { label: 'Prompt Qualification Status' },
    ];

    const columns = [
        {
            key: 'ordinal',
            label: 'Sec #',
            render: (s) => (
                <span className="font-bold text-xs">
                    {s.ordinal_in_work ?? s.ordinal ?? '—'}
                </span>
            ),
        },
        {
            key: 'identity',
            label: 'Section Identity',
            render: (s) => (
                <div>
                    <span className="font-semibold text-sm text-slate-900 dark:text-slate-100 block">
                        {s.section_identity || `Section ${s.section_id}`}
                    </span>
                    <span className="font-mono text-xs text-slate-400">
                        {s.section_id}
                    </span>
                </div>
            ),
        },
        {
            key: 'prompt',
            label: 'Prompt Version',
            render: (s) => (
                <span className="font-mono text-xs text-slate-600 dark:text-slate-300">
                    {s.prompt_version_id ? String(s.prompt_version_id).slice(0, 8) + '...' : 'Pending'}
                </span>
            ),
        },
        {
            key: 'qualification',
            label: 'Gate Qualification',
            render: (s) => (
                <StatusBadge status={s.qualification_status || s.status || 'pending'} />
            ),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Prompt Qualification: Case #${caseId} — EduPlatform`} />

            <PageHeader
                title={`Prompt Qualification Status (Case #${caseId})`}
                eyebrow="Pedagogical Prompt Verification"
                description="Status of deterministic 3D generation prompts and pedagogical qualification gates."
                actions={
                    <Button href={`/v3/teacher/cases/${caseId}`} variant="secondary" size="sm">
                        ← Case Overview
                    </Button>
                }
            />

            <div className="space-y-6">
                <DataTable
                    columns={columns}
                    data={sections}
                    emptyTitle="No prompt sections defined"
                    emptyDescription="Prompts will appear once the lesson structuring stage identifies curriculum concepts."
                />
            </div>
        </AppLayout>
    );
}
