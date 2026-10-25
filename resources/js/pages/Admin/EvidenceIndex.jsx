import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import StatusBadge from '../../components/StatusBadge';

function generateUuid() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

function formatDurationMs(ms) {
    if (ms == null) return '—';
    const seconds = Math.floor(ms / 1000);
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    const remSec = seconds % 60;
    if (minutes < 60) return `${minutes}m ${remSec}s`;
    const hours = Math.floor(minutes / 60);
    const remMin = minutes % 60;
    return `${hours}h ${remMin}m`;
}

export default function EvidenceIndex({ report, from, to }) {
    const [fromDate, setFromDate] = useState(from || '');
    const [toDate, setToDate] = useState(to || '');
    const [isExporting, setIsExporting] = useState(false);
    const [exportDownloadUrl, setExportDownloadUrl] = useState(null);
    const [exportError, setExportError] = useState(null);

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Operations Console', url: '/v3/admin/workspace' },
        { label: 'Evidence & Audit Metrics' },
    ];

    const handleApplyDateFilter = (e) => {
        e.preventDefault();
        if (!fromDate || !toDate) return;
        router.get('/v3/admin/evidence', { from: fromDate, to: toDate }, { preserveState: true });
    };

    const handleTriggerExport = async () => {
        setIsExporting(true);
        setExportError(null);
        setExportDownloadUrl(null);

        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const res = await fetch('/v3/admin/evidence-exports', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({
                    schema_version: 1,
                    idempotency_key: generateUuid(),
                    from: fromDate,
                    to: toDate,
                }),
            });

            const body = await res.json();
            if (res.ok && body.data?.download_url) {
                setExportDownloadUrl(body.data.download_url);
                // Trigger auto download
                window.location.href = body.data.download_url;
            } else {
                setExportError(body.error?.message || 'Failed to generate evidence export.');
            }
        } catch {
            setExportError('Export service unreachable. Please retry.');
        } finally {
            setIsExporting(false);
        }
    };

    if (!report) {
        return (
            <AppLayout>
                <div className="mx-auto max-w-4xl p-8 text-center text-slate-500">
                    No evidence report generated for the selected window.
                </div>
            </AppLayout>
        );
    }

    const {
        case_count,
        outcomes = {},
        current_approved_scene_count = 0,
        historical_approval_count = 0,
        technical_retry_count = 0,
        prompt_revision_count = 0,
        scene_repair_count = 0,
        teacher_change_count = 0,
        real_qualified_count = 0,
        demo_reviewed_count = 0,
        generated_scene_count = 0,
        imported_scene_count = 0,
        real_approved_scene_count = 0,
        demo_approved_scene_count = 0,
        case_elapsed_ms = {},
        review_wait_ms = {},
        operation_observed_ms = {},
        usage = {},
        cost = {},
        gap_count = 0,
    } = report;

    return (
        <AppLayout>
            <Head title="Audit & Compliance Evidence - Operations" />

            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    title="Audit & Compliance Evidence Metrics"
                    subtitle="Cryptographic verification digests, SLA duration percentiles, provenance breakdown, and institutional compliance exports."
                    breadcrumbs={breadcrumbs}
                    actions={
                        <div className="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                onClick={handleTriggerExport}
                                disabled={isExporting}
                                className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white shadow-xs hover:bg-teal-700 focus:outline-hidden focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 disabled:opacity-50 dark:bg-teal-500 dark:hover:bg-teal-600"
                            >
                                <span>{isExporting ? '⏳' : '📥'}</span>
                                <span>{isExporting ? 'Generating Bundle...' : 'Export Evidence Snapshot'}</span>
                            </button>
                            <Link
                                href="/v3/admin/workspace"
                                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-xs hover:bg-slate-50 focus:outline-hidden focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700/80"
                            >
                                <span>←</span>
                                <span>Operations Console</span>
                            </Link>
                        </div>
                    }
                />

                {/* Export notification / error */}
                {exportDownloadUrl && (
                    <div className="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/60 dark:bg-emerald-950/40">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2 text-sm font-semibold text-emerald-900 dark:text-emerald-200">
                                <span>✅</span>
                                <span>Evidence export created successfully. Download started automatically.</span>
                            </div>
                            <a
                                href={exportDownloadUrl}
                                className="text-xs font-semibold text-emerald-700 underline dark:text-emerald-300"
                            >
                                Click here if download did not start
                            </a>
                        </div>
                    </div>
                )}

                {exportError && (
                    <div className="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-900/60 dark:bg-rose-950/40">
                        <div className="flex items-center gap-2 text-sm font-medium text-rose-800 dark:text-rose-200">
                            <span>⚠️</span>
                            <span>{exportError}</span>
                        </div>
                    </div>
                )}

                {/* Date Filter Bar */}
                <form
                    onSubmit={handleApplyDateFilter}
                    className="mt-8 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-xs sm:flex-row sm:items-center sm:justify-between dark:border-slate-800 dark:bg-slate-900"
                >
                    <div className="flex flex-wrap items-center gap-4 text-xs">
                        <div className="flex items-center gap-2">
                            <label className="font-medium text-slate-700 dark:text-slate-300">From:</label>
                            <input
                                type="text"
                                placeholder="YYYY-MM-DDTHH:MM:SS"
                                value={fromDate}
                                onChange={(e) => setFromDate(e.target.value)}
                                className="w-52 rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-900 focus:border-teal-500 focus:outline-hidden dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
                            />
                        </div>
                        <div className="flex items-center gap-2">
                            <label className="font-medium text-slate-700 dark:text-slate-300">To:</label>
                            <input
                                type="text"
                                placeholder="YYYY-MM-DDTHH:MM:SS"
                                value={toDate}
                                onChange={(e) => setToDate(e.target.value)}
                                className="w-52 rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-900 focus:border-teal-500 focus:outline-hidden dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
                            />
                        </div>
                        <button
                            type="submit"
                            className="rounded-lg bg-slate-900 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-slate-800 dark:bg-slate-700 dark:hover:bg-slate-600"
                        >
                            Filter Period
                        </button>
                    </div>

                    <div className="text-xs text-slate-500 dark:text-slate-400">
                        Period window: <span className="font-semibold text-slate-900 dark:text-white">{case_count}</span> cases analyzed
                    </div>
                </form>

                {/* Primary Metric Grid */}
                <div className="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <div className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            Total Cases
                        </div>
                        <div className="mt-2 text-3xl font-extrabold text-slate-900 dark:text-white">
                            {case_count}
                        </div>
                        <div className="mt-1 text-xs text-slate-500">In audit window</div>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <div className="text-xs font-semibold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">
                            Approved Scenes
                        </div>
                        <div className="mt-2 text-3xl font-extrabold text-emerald-600 dark:text-emerald-400">
                            {current_approved_scene_count}
                        </div>
                        <div className="mt-1 text-xs text-slate-500">Historical: {historical_approval_count}</div>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <div className="text-xs font-semibold uppercase tracking-wider text-teal-600 dark:text-teal-400">
                            AI Qualified
                        </div>
                        <div className="mt-2 text-3xl font-extrabold text-teal-600 dark:text-teal-400">
                            {real_qualified_count}
                        </div>
                        <div className="mt-1 text-xs text-slate-500">Demo waived: {demo_reviewed_count}</div>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <div className="text-xs font-semibold uppercase tracking-wider text-amber-600 dark:text-amber-400">
                            Technical Retries
                        </div>
                        <div className="mt-2 text-3xl font-extrabold text-amber-600 dark:text-amber-400">
                            {technical_retry_count}
                        </div>
                        <div className="mt-1 text-xs text-slate-500">Repairs: {scene_repair_count}</div>
                    </div>
                </div>

                {/* Outcomes and Provenance Sections */}
                <div className="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {/* Pipeline Outcomes */}
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            Workflow Outcomes Breakdown
                        </h3>
                        <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            {Object.entries(outcomes).map(([key, val]) => (
                                <div key={key} className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
                                    <div className="text-xs capitalize text-slate-500 dark:text-slate-400">
                                        {key.replace('_', ' ')}
                                    </div>
                                    <div className="mt-1 text-xl font-bold text-slate-900 dark:text-white">
                                        {val}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Scene Provenance */}
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            Scene Provenance & Gates
                        </h3>
                        <dl className="mt-4 space-y-3 divide-y divide-slate-100 text-xs dark:divide-slate-800">
                            <div className="flex justify-between pt-1">
                                <dt className="text-slate-500 dark:text-slate-400">AI Generated Scenes</dt>
                                <dd className="font-semibold text-slate-900 dark:text-slate-100">{generated_scene_count}</dd>
                            </div>
                            <div className="flex justify-between pt-3">
                                <dt className="text-slate-500 dark:text-slate-400">Manual Imported Scenes</dt>
                                <dd className="font-semibold text-slate-900 dark:text-slate-100">{imported_scene_count}</dd>
                            </div>
                            <div className="flex justify-between pt-3">
                                <dt className="text-slate-500 dark:text-slate-400">Real JEV Approved</dt>
                                <dd className="font-semibold text-emerald-600 dark:text-emerald-400">{real_approved_scene_count}</dd>
                            </div>
                            <div className="flex justify-between pt-3">
                                <dt className="text-slate-500 dark:text-slate-400">Owner Waived Demo Approved</dt>
                                <dd className="font-semibold text-amber-600 dark:text-amber-400">{demo_approved_scene_count}</dd>
                            </div>
                            <div className="flex justify-between pt-3">
                                <dt className="text-slate-500 dark:text-slate-400">Teacher Revisions & Corrections</dt>
                                <dd className="font-semibold text-slate-900 dark:text-slate-100">{teacher_change_count}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                {/* Durations & SLA Timing */}
                <div className="mt-8 rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        Observed Durations & SLA Timings
                    </h3>

                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div className="rounded-lg bg-slate-50 p-4 dark:bg-slate-800/60">
                            <div className="text-xs text-slate-500 dark:text-slate-400">Case Elapsed Total</div>
                            <div className="mt-1 text-2xl font-bold text-slate-900 dark:text-white">
                                {formatDurationMs(case_elapsed_ms.sum)}
                            </div>
                            <div className="mt-1 text-xs text-slate-500">
                                {case_elapsed_ms.known_count ?? 0} cases known ({case_elapsed_ms.ongoing_count ?? 0} ongoing)
                            </div>
                        </div>

                        <div className="rounded-lg bg-slate-50 p-4 dark:bg-slate-800/60">
                            <div className="text-xs text-slate-500 dark:text-slate-400">Teacher Review Wait Duration</div>
                            <div className="mt-1 text-2xl font-bold text-slate-900 dark:text-white">
                                {formatDurationMs(review_wait_ms.sum)}
                            </div>
                            <div className="mt-1 text-xs text-slate-500">
                                {review_wait_ms.known_count ?? 0} items evaluated
                            </div>
                        </div>

                        <div className="rounded-lg bg-slate-50 p-4 dark:bg-slate-800/60">
                            <div className="text-xs text-slate-500 dark:text-slate-400">Pipeline Coding Observed Time</div>
                            <div className="mt-1 text-2xl font-bold text-slate-900 dark:text-white">
                                {formatDurationMs(operation_observed_ms.sum)}
                            </div>
                            <div className="mt-1 text-xs text-slate-500">
                                {operation_observed_ms.known_count ?? 0} operations timed
                            </div>
                        </div>
                    </div>
                </div>

                {/* Token Usage & Unit Economics */}
                <div className="mt-8 rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        Token Utilization & Unit Economics
                    </h3>

                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-4">
                        <div className="rounded-lg bg-slate-50 p-4 dark:bg-slate-800/60">
                            <div className="text-xs text-slate-500 dark:text-slate-400">Input Tokens</div>
                            <div className="mt-1 text-xl font-bold text-slate-900 dark:text-white">
                                {Number(usage.known_input_tokens ?? 0).toLocaleString()}
                            </div>
                        </div>
                        <div className="rounded-lg bg-slate-50 p-4 dark:bg-slate-800/60">
                            <div className="text-xs text-slate-500 dark:text-slate-400">Output Tokens</div>
                            <div className="mt-1 text-xl font-bold text-slate-900 dark:text-white">
                                {Number(usage.known_output_tokens ?? 0).toLocaleString()}
                            </div>
                        </div>
                        <div className="rounded-lg bg-slate-50 p-4 dark:bg-slate-800/60">
                            <div className="text-xs text-slate-500 dark:text-slate-400">Cost Status</div>
                            <div className="mt-1 text-xl font-bold capitalize text-slate-900 dark:text-white">
                                {cost.status ?? 'unknown'}
                            </div>
                        </div>
                        <div className="rounded-lg bg-slate-50 p-4 dark:bg-slate-800/60">
                            <div className="text-xs text-slate-500 dark:text-slate-400">Cost per Approved Scene</div>
                            <div className="mt-1 text-xl font-bold text-slate-900 dark:text-white">
                                {cost.per_current_approved_scene_minor ? `${cost.currency || '$'}${cost.per_current_approved_scene_minor}` : (cost.per_scene_reason ? `(${cost.per_scene_reason})` : '—')}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
