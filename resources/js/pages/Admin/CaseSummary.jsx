import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import StatusBadge from '../../components/StatusBadge';

export default function CaseSummary({ admin, case: caseItem }) {
    if (!caseItem) {
        return (
            <AppLayout>
                <div className="mx-auto max-w-4xl p-8 text-center text-slate-500">
                    Case record not found.
                </div>
            </AppLayout>
        );
    }

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Operations Console', url: '/v3/admin/workspace' },
        { label: 'Curriculum Cases', url: '/v3/admin/cases' },
        { label: `Case ${caseItem.case_id.slice(0, 8)}...` },
    ];

    const approvalRate =
        caseItem.scene_count > 0
            ? Math.round((caseItem.approved_scene_count / caseItem.scene_count) * 100)
            : 0;

    return (
        <AppLayout>
            <Head title={`Case ${caseItem.case_id.slice(0, 8)} - Summary`} />

            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    title={`Case Operational Summary`}
                    subtitle={`Immutable workflow scalars and state projection for case ${caseItem.case_id}`}
                    breadcrumbs={breadcrumbs}
                    actions={
                        <div className="flex flex-wrap items-center gap-3">
                            <Link
                                href={`/v3/admin/operations/${caseItem.case_id}`}
                                className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-3.5 py-2 text-sm font-medium text-white shadow-xs hover:bg-teal-700 focus:outline-hidden focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 dark:bg-teal-500 dark:hover:bg-teal-600"
                            >
                                <span>⚙️</span>
                                <span>Operations Console</span>
                            </Link>
                            <Link
                                href="/v3/admin/cases"
                                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-xs hover:bg-slate-50 focus:outline-hidden focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700/80"
                            >
                                <span>←</span>
                                <span>All Cases</span>
                            </Link>
                        </div>
                    }
                />

                {/* Error Banner if present */}
                {caseItem.error_code && (
                    <div className="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-900/60 dark:bg-rose-950/40">
                        <div className="flex items-center gap-3">
                            <span className="text-xl">⚠️</span>
                            <div>
                                <h4 className="text-sm font-semibold text-rose-900 dark:text-rose-200">
                                    Operational Error Detected
                                </h4>
                                <p className="mt-0.5 font-mono text-xs text-rose-700 dark:text-rose-300">
                                    Error Code: {caseItem.error_code}
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                {/* Primary Overview Cards */}
                <div className="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Status & Lifecycle Card */}
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            Workflow State
                        </h3>
                        <div className="mt-4 flex items-center gap-3">
                            <StatusBadge status={caseItem.status_code} size="lg" />
                            <span className="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                Stage: {caseItem.stage_code}
                            </span>
                        </div>

                        <dl className="mt-6 space-y-3 divide-y divide-slate-100 text-xs dark:divide-slate-800">
                            <div className="flex justify-between pt-2">
                                <dt className="text-slate-500 dark:text-slate-400">Case ID</dt>
                                <dd className="font-mono text-slate-900 dark:text-slate-100">
                                    {caseItem.case_id}
                                </dd>
                            </div>
                            <div className="flex justify-between pt-3">
                                <dt className="text-slate-500 dark:text-slate-400">Created At</dt>
                                <dd className="text-slate-900 dark:text-slate-100">
                                    {caseItem.created_at ? new Date(caseItem.created_at).toLocaleString() : '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between pt-3">
                                <dt className="text-slate-500 dark:text-slate-400">Last Updated</dt>
                                <dd className="text-slate-900 dark:text-slate-100">
                                    {caseItem.updated_at ? new Date(caseItem.updated_at).toLocaleString() : '—'}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    {/* Scene Metrics Card */}
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            Pedagogical Scenes
                        </h3>
                        <div className="mt-4 flex items-baseline gap-2">
                            <span className="text-3xl font-extrabold text-emerald-600 dark:text-emerald-400">
                                {caseItem.approved_scene_count}
                            </span>
                            <span className="text-sm text-slate-500 dark:text-slate-400">
                                of {caseItem.scene_count} approved ({approvalRate}%)
                            </span>
                        </div>

                        {/* Progress Bar */}
                        <div className="mt-4 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div
                                className="h-full bg-emerald-500 transition-all duration-300"
                                style={{ width: `${approvalRate}%` }}
                            />
                        </div>

                        <dl className="mt-6 space-y-3 divide-y divide-slate-100 text-xs dark:divide-slate-800">
                            <div className="flex justify-between pt-2">
                                <dt className="text-slate-500 dark:text-slate-400">Total Scene Candidates</dt>
                                <dd className="font-semibold text-slate-900 dark:text-slate-100">{caseItem.scene_count}</dd>
                            </div>
                            <div className="flex justify-between pt-3">
                                <dt className="text-slate-500 dark:text-slate-400">Approved Scenes</dt>
                                <dd className="font-semibold text-emerald-600 dark:text-emerald-400">
                                    {caseItem.approved_scene_count}
                                </dd>
                            </div>
                            <div className="flex justify-between pt-3">
                                <dt className="text-slate-500 dark:text-slate-400">Pending / Unapproved</dt>
                                <dd className="font-semibold text-slate-700 dark:text-slate-300">
                                    {Math.max(0, caseItem.scene_count - caseItem.approved_scene_count)}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    {/* Operational Executions Card */}
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            Execution Retries & Portals
                        </h3>
                        <div className="mt-4 flex items-baseline gap-2">
                            <span className="text-3xl font-extrabold text-slate-900 dark:text-white">
                                {caseItem.attempt_count}
                            </span>
                            <span className="text-sm text-slate-500 dark:text-slate-400">
                                pipeline coding attempts
                            </span>
                        </div>

                        <div className="mt-6 space-y-2.5">
                            <Link
                                href={`/v3/admin/operations/${caseItem.case_id}`}
                                className="flex items-center justify-between rounded-lg border border-slate-200 p-2.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-800 dark:text-slate-300 dark:hover:bg-slate-800/60"
                            >
                                <span>Operational status</span>
                                <span>→</span>
                            </Link>
                            <Link
                                href="/v3/admin/evidence"
                                className="flex items-center justify-between rounded-lg border border-slate-200 p-2.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-800 dark:text-slate-300 dark:hover:bg-slate-800/60"
                            >
                                <span>Audit metrics</span>
                                <span>→</span>
                            </Link>
                            <Link
                                href="/v3/admin/subject-runs"
                                className="flex items-center justify-between rounded-lg border border-slate-200 p-2.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-800 dark:text-slate-300 dark:hover:bg-slate-800/60"
                            >
                                <span>Subject runs</span>
                                <span>→</span>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
