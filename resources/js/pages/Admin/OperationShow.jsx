import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import StatusBadge from '../../components/StatusBadge';

function formatDuration(seconds) {
    if (seconds == null) return '—';
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    const remSec = seconds % 60;
    if (minutes < 60) return `${minutes}m ${remSec}s`;
    const hours = Math.floor(minutes / 60);
    const remMin = minutes % 60;
    return `${hours}h ${remMin}m`;
}

export default function OperationShow({ admin, summary, actionTargets = [] }) {
    if (!summary || !summary.case) {
        return (
            <AppLayout>
                <div className="mx-auto max-w-4xl p-8 text-center text-slate-500">
                    Operation record not found.
                </div>
            </AppLayout>
        );
    }

    const {
        case: caseData,
        age_seconds,
        idle_seconds,
        control_mode,
        dormant_review_count,
        uncertain_effect_count,
    } = summary;

    const caseId = caseData.case_id;

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Operations Console', url: '/v3/admin/workspace' },
        { label: 'Operations & Health', url: '/v3/admin/operations' },
        { label: `Case ${caseId.slice(0, 8)}...` },
    ];

    return (
        <AppLayout>
            <Head title={`Operation: ${caseId.slice(0, 8)} - Admin`} />

            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    title="Case Operational Detail"
                    subtitle={`Real-time bridge status, recovery targets, and execution scalars for ${caseId}`}
                    breadcrumbs={breadcrumbs}
                    actions={
                        <div className="flex flex-wrap items-center gap-3">
                            <Link
                                href={`/v3/admin/cases/${caseId}`}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-xs hover:bg-slate-50 focus:outline-hidden focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700/80"
                            >
                                <span>📂</span>
                                <span>Case Summary</span>
                            </Link>
                            <Link
                                href="/v3/admin/operations"
                                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-xs hover:bg-slate-50 focus:outline-hidden focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700/80"
                            >
                                <span>←</span>
                                <span>Operations Queue</span>
                            </Link>
                        </div>
                    }
                />

                {/* Error Banner if present */}
                {caseData.error_code && (
                    <div className="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-900/60 dark:bg-rose-950/40">
                        <div className="flex items-center gap-3">
                            <span className="text-xl">⚠️</span>
                            <div>
                                <h4 className="text-sm font-semibold text-rose-900 dark:text-rose-200">
                                    Operational Error Code
                                </h4>
                                <p className="mt-0.5 font-mono text-xs text-rose-700 dark:text-rose-300">
                                    {caseData.error_code}
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                {/* Main 2-column Grid */}
                <div className="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {/* Column 1: Case Operational Status */}
                    <div className="space-y-6">
                        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                            <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                Operational Status & Lifecycle
                            </h3>

                            <div className="mt-4 flex flex-wrap items-center gap-3">
                                <StatusBadge status={caseData.status_code} size="lg" />
                                <span className="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                    Stage: {caseData.stage_code}
                                </span>
                                <span className="rounded-md bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-500/30">
                                    Control Mode: {control_mode}
                                </span>
                            </div>

                            <dl className="mt-6 grid grid-cols-2 gap-4 text-xs">
                                <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
                                    <dt className="text-slate-500 dark:text-slate-400">Pipeline Age</dt>
                                    <dd className="mt-1 font-semibold text-slate-900 dark:text-white">
                                        {formatDuration(age_seconds)} ({age_seconds}s)
                                    </dd>
                                </div>
                                <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
                                    <dt className="text-slate-500 dark:text-slate-400">Idle Duration</dt>
                                    <dd className="mt-1 font-semibold text-slate-900 dark:text-white">
                                        {formatDuration(idle_seconds)} ({idle_seconds}s)
                                    </dd>
                                </div>
                                <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
                                    <dt className="text-slate-500 dark:text-slate-400">Coding Attempts</dt>
                                    <dd className="mt-1 font-semibold text-slate-900 dark:text-white">
                                        {caseData.attempt_count ?? 0}
                                    </dd>
                                </div>
                                <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
                                    <dt className="text-slate-500 dark:text-slate-400">Approved Scenes</dt>
                                    <dd className="mt-1 font-semibold text-emerald-600 dark:text-emerald-400">
                                        {caseData.approved_scene_count ?? 0} / {caseData.scene_count ?? 0}
                                    </dd>
                                </div>
                                <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
                                    <dt className="text-slate-500 dark:text-slate-400">Dormant Reviews</dt>
                                    <dd className="mt-1 font-semibold text-slate-900 dark:text-white">
                                        {dormant_review_count ?? 0}
                                    </dd>
                                </div>
                                <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
                                    <dt className="text-slate-500 dark:text-slate-400">Uncertain Effects</dt>
                                    <dd className="mt-1 font-semibold text-amber-600 dark:text-amber-400">
                                        {uncertain_effect_count ?? 0}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        {/* Timestamps Card */}
                        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                            <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                Audit Metadata
                            </h3>
                            <dl className="mt-4 space-y-2.5 text-xs">
                                <div className="flex justify-between">
                                    <dt className="text-slate-500 dark:text-slate-400">Case Identifier</dt>
                                    <dd className="font-mono text-slate-900 dark:text-slate-100">{caseId}</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-slate-500 dark:text-slate-400">Created At</dt>
                                    <dd className="text-slate-900 dark:text-slate-100">
                                        {caseData.created_at ? new Date(caseData.created_at).toLocaleString() : '—'}
                                    </dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-slate-500 dark:text-slate-400">Updated At</dt>
                                    <dd className="text-slate-900 dark:text-slate-100">
                                        {caseData.updated_at ? new Date(caseData.updated_at).toLocaleString() : '—'}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    {/* Column 2: Recoverable Action Targets */}
                    <div className="space-y-6">
                        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                            <div className="flex items-center justify-between">
                                <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                    Recoverable Action Targets
                                </h3>
                                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                    {actionTargets.length}
                                </span>
                            </div>

                            {actionTargets.length === 0 ? (
                                <div className="mt-6 rounded-lg border border-dashed border-slate-200 p-8 text-center text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                                    No recoverable action targets currently available for this case. Normal pipeline flow has no stalled effects.
                                </div>
                            ) : (
                                <div className="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                                    {actionTargets.map((target, idx) => (
                                        <div key={target.ref || idx} className="py-3">
                                            <div className="flex items-center justify-between">
                                                <span className="text-xs font-semibold text-slate-900 dark:text-slate-100">
                                                    {target.kind}
                                                </span>
                                                <span className="rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950/60 dark:text-amber-300">
                                                    {target.state}
                                                </span>
                                            </div>
                                            <div className="mt-1 font-mono text-xs text-slate-500 dark:text-slate-400 truncate">
                                                ref: {target.ref}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Inspector Portals Navigation */}
                        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                            <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                Quick Navigation
                            </h3>
                            <div className="mt-4 space-y-2">
                                <Link
                                    href={`/v3/admin/operations/${caseId}`}
                                    className="flex items-center justify-between rounded-lg border border-slate-200 p-3 text-xs font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-800 dark:text-slate-300 dark:hover:bg-slate-800/60"
                                >
                                    <span>Operational status</span>
                                    <span>→</span>
                                </Link>
                                <Link
                                    href="/v3/admin/evidence"
                                    className="flex items-center justify-between rounded-lg border border-slate-200 p-3 text-xs font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-800 dark:text-slate-300 dark:hover:bg-slate-800/60"
                                >
                                    <span>Audit metrics</span>
                                    <span>→</span>
                                </Link>
                                <Link
                                    href="/v3/admin/subject-runs"
                                    className="flex items-center justify-between rounded-lg border border-slate-200 p-3 text-xs font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-800 dark:text-slate-300 dark:hover:bg-slate-800/60"
                                >
                                    <span>Subject runs</span>
                                    <span>→</span>
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
