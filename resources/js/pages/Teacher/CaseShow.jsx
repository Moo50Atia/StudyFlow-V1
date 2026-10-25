import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import StatusBadge from '../../components/StatusBadge';
import WorkflowRail from '../../components/WorkflowRail';

export default function CaseShow({ case: caseModel, receipt = {} }) {
    const caseId = receipt.case_id || caseModel?.id;
    const state = receipt.state || caseModel?.state || 'unknown';

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Educator Studio', url: '/v3/teacher/workspace' },
        { label: `Case #${caseId}` },
    ];

    const pipelineLinks = [
        {
            title: 'Case Progress & Events',
            description: 'Monitor asynchronous pipeline transitions and milestone logs.',
            url: `/v3/teacher/cases/${caseId}/progress`,
            icon: '⚡',
        },
        {
            title: 'Source Document Status',
            description: 'Inspect page counts, OCR coverage, and formatting gaps.',
            url: `/v3/teacher/cases/${caseId}/source-status`,
            icon: '📄',
        },
        {
            title: 'Lesson Map & Hierarchy',
            description: 'View extracted chapter outline and bilingual sections.',
            url: `/v3/teacher/cases/${caseId}/lesson-map`,
            icon: '🗺️',
        },
        {
            title: 'Prompt Qualification Status',
            description: 'Review pedagogical prompt versions and JEV qualification gates.',
            url: `/v3/teacher/cases/${caseId}/prompt-status`,
            icon: '🎯',
        },
        {
            title: 'Interactive Scene Reviews',
            description: 'Inspect 3D simulations and submit educator qualification decisions.',
            url: `/v3/teacher/cases/${caseId}/reviews`,
            icon: '🧪',
        },
        {
            title: 'Compliance Evidence',
            description: 'Review sovereign audit trails, approval hashes, and exports.',
            url: `/v3/teacher/cases/${caseId}/evidence`,
            icon: '🛡️',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Case #${caseId} Overview — EduPlatform`} />

            <PageHeader
                title={`Processing Case #${caseId}`}
                eyebrow="Sovereign Processing Case"
                description="Comprehensive multi-stage curriculum pipeline overview, status checkpoints, and pedagogical review gates."
                actions={
                    <Button href={`/v3/teacher/cases/${caseId}/progress`} variant="primary" size="sm">
                        View Live Progress →
                    </Button>
                }
            />

            {/* Workflow Progress Banner */}
            <div className="mb-8 bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                <WorkflowRail currentStep={2} status={state} />
            </div>

            {/* Overview Attributes Card */}
            <div className="bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm mb-8">
                <div className="flex items-center justify-between mb-4">
                    <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100">
                        Case Checkpoint Metadata
                    </h2>
                    <StatusBadge status={state} />
                </div>

                <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                    <div className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823]">
                        <dt className="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">
                            Document Ref
                        </dt>
                        <dd className="font-mono text-xs text-slate-800 dark:text-slate-200 mt-1 truncate">
                            {receipt.document_id || caseModel?.document_id || '—'}
                        </dd>
                    </div>

                    <div className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823]">
                        <dt className="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">
                            Settings Version
                        </dt>
                        <dd className="font-mono text-xs text-slate-800 dark:text-slate-200 mt-1">
                            v{receipt.settings_version_id || caseModel?.settings_version_id || '1'}
                        </dd>
                    </div>

                    <div className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823]">
                        <dt className="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">
                            Current State
                        </dt>
                        <dd className="font-semibold text-xs text-slate-800 dark:text-slate-200 mt-1 uppercase">
                            {state}
                        </dd>
                    </div>

                    <div className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823]">
                        <dt className="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">
                            Initiated At
                        </dt>
                        <dd className="text-xs text-slate-800 dark:text-slate-200 mt-1">
                            {receipt.created_at ? new Date(receipt.created_at).toLocaleString() : 'Recently'}
                        </dd>
                    </div>
                </dl>
            </div>

            {/* Pipeline Stage Portals */}
            <div className="space-y-4">
                <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100">
                    Pipeline Stages & Inspector Hubs
                </h2>

                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {pipelineLinks.map((link) => (
                        <Link
                            key={link.title}
                            href={link.url}
                            className="p-5 rounded-3xl bg-white dark:bg-[#121e1a] border border-slate-200 dark:border-[#263932] hover:border-teal-500 hover:shadow-md transition-all flex flex-col justify-between group"
                        >
                            <div>
                                <span className="text-2xl mb-2 block">{link.icon}</span>
                                <h3 className="font-bold text-slate-900 dark:text-slate-100 group-hover:text-teal-700 dark:group-hover:text-teal-400 transition-colors">
                                    {link.title}
                                </h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                                    {link.description}
                                </p>
                            </div>

                            <div className="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800 text-xs font-semibold text-teal-700 dark:text-teal-400 flex items-center justify-between">
                                <span>Inspect Stage</span>
                                <span>→</span>
                            </div>
                        </Link>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
