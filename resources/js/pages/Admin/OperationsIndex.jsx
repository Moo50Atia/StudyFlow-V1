import React, { useState, useMemo } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import StatusBadge from '../../components/StatusBadge';
import EmptyState from '../../components/EmptyState';

export default function OperationsIndex({ admin, cases = [], meta = {} }) {
    const [search, setSearch] = useState('');
    const [stageFilter, setStageFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [healthData, setHealthData] = useState(null);
    const [probingHealth, setProbingHealth] = useState(false);

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Operations Console', url: '/v3/admin/workspace' },
        { label: 'Operations & Health' },
    ];

    const handleProbeHealth = async () => {
        setProbingHealth(true);
        try {
            const res = await fetch('/v3/admin/operations/health', {
                headers: { Accept: 'application/json' },
            });
            const json = await res.json();
            if (json.data) {
                setHealthData(json.data);
            }
        } catch {
            setHealthData({ error: 'Failed to probe service health.' });
        } finally {
            setProbingHealth(false);
        }
    };

    const filteredCases = useMemo(() => {
        return cases.map((summary) => summary.case).filter((c) => {
            const matchesStage = !stageFilter || c.stage_code === stageFilter;
            const matchesStatus = !statusFilter || c.status_code === statusFilter;
            const matchesSearch =
                !search ||
                c.case_id?.toLowerCase().includes(search.toLowerCase()) ||
                c.stage_code?.toLowerCase().includes(search.toLowerCase()) ||
                c.status_code?.toLowerCase().includes(search.toLowerCase()) ||
                (c.error_code && c.error_code.toLowerCase().includes(search.toLowerCase()));
            return matchesStage && matchesStatus && matchesSearch;
        });
    }, [cases, stageFilter, statusFilter, search]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Operations & Health - Admin" />

            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    title="Service Operations & Pipeline Health"
                    description="Monitor real-time workflow engine bridges, delivery outbox backlogs, and target recovery states."
                    actions={
                        <div className="flex items-center gap-3">
                            <button
                                type="button"
                                onClick={handleProbeHealth}
                                disabled={probingHealth}
                                className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-3.5 py-2 text-sm font-medium text-white shadow-xs hover:bg-teal-700 focus:outline-hidden focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 disabled:opacity-50 dark:bg-teal-500 dark:hover:bg-teal-600"
                            >
                                <span>{probingHealth ? '⏳' : '🩺'}</span>
                                <span>{probingHealth ? 'Probing Bridge...' : 'Probe System Health'}</span>
                            </button>
                            <Link
                                href="/v3/admin/cases"
                                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-xs hover:bg-slate-50 focus:outline-hidden focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700/80"
                            >
                                <span>📂</span>
                                <span>Curriculum Cases</span>
                            </Link>
                        </div>
                    }
                />

                {/* Health Status Box (if probed or default banner) */}
                {healthData && (
                    <div className="mt-8 rounded-xl border border-slate-200 bg-white p-6 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex items-center justify-between">
                            <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                Live Infrastructure Health Report
                            </h3>
                            <span className="text-xs text-slate-400">
                                Checked: {healthData.checked_at ? new Date(healthData.checked_at).toLocaleTimeString() : 'now'}
                            </span>
                        </div>

                        {healthData.error ? (
                            <div className="mt-3 text-sm text-rose-600 dark:text-rose-400 font-medium">
                                {healthData.error}
                            </div>
                        ) : (
                            <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                                <div className="rounded-lg bg-slate-50 p-3.5 dark:bg-slate-800/60">
                                    <div className="text-xs text-slate-500 dark:text-slate-400">Temporal Bridge</div>
                                    <div className="mt-1 flex items-center gap-2">
                                        <span className={`inline-block h-2 w-2 rounded-full ${healthData.temporal_bridge === 'reachable' ? 'bg-emerald-500' : 'bg-rose-500'}`} />
                                        <span className="text-sm font-bold capitalize text-slate-900 dark:text-white">
                                            {healthData.temporal_bridge}
                                        </span>
                                    </div>
                                </div>
                                <div className="rounded-lg bg-slate-50 p-3.5 dark:bg-slate-800/60">
                                    <div className="text-xs text-slate-500 dark:text-slate-400">Workflow Start Backlog</div>
                                    <div className="mt-1 text-lg font-bold text-slate-900 dark:text-white">
                                        {healthData.workflow_start_backlog ?? 0}
                                    </div>
                                </div>
                                <div className="rounded-lg bg-slate-50 p-3.5 dark:bg-slate-800/60">
                                    <div className="text-xs text-slate-500 dark:text-slate-400">Review Delivery Backlog</div>
                                    <div className="mt-1 text-lg font-bold text-slate-900 dark:text-white">
                                        {healthData.review_delivery_backlog ?? 0}
                                    </div>
                                </div>
                                <div className="rounded-lg bg-slate-50 p-3.5 dark:bg-slate-800/60">
                                    <div className="text-xs text-slate-500 dark:text-slate-400">Provider Health</div>
                                    <div className="mt-1 text-sm font-semibold capitalize text-slate-700 dark:text-slate-300">
                                        {healthData.provider_health ?? 'ok'}
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {/* Filters Row */}
                <div className="mt-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="relative">
                            <input
                                type="text"
                                placeholder="Search by case ID or error..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-64 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-teal-500 focus:outline-hidden focus:ring-1 focus:ring-teal-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
                            />
                            {search && (
                                <button
                                    type="button"
                                    onClick={() => setSearch('')}
                                    className="absolute right-2.5 top-2.5 text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
                                >
                                    ✕
                                </button>
                            )}
                        </div>

                        <select
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-teal-500 focus:outline-hidden focus:ring-1 focus:ring-teal-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
                        >
                            <option value="">All Statuses</option>
                            <option value="queued">Queued</option>
                            <option value="running">Running</option>
                            <option value="awaiting_review">Awaiting Review</option>
                            <option value="blocked">Blocked</option>
                            <option value="failed">Failed</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="approved">Approved</option>
                        </select>

                        <select
                            value={stageFilter}
                            onChange={(e) => setStageFilter(e.target.value)}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-teal-500 focus:outline-hidden focus:ring-1 focus:ring-teal-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
                        >
                            <option value="">All Stages</option>
                            <option value="intake">Intake</option>
                            <option value="extraction">Extraction</option>
                            <option value="mapping">Mapping</option>
                            <option value="sections">Sections</option>
                            <option value="prompting">Prompting</option>
                            <option value="scene_authoring">Scene Authoring</option>
                            <option value="scene_qualification">Scene Qualification</option>
                            <option value="teacher_review">Teacher Review</option>
                            <option value="complete">Complete</option>
                        </select>
                    </div>

                    <div className="text-sm text-slate-600 dark:text-slate-400">
                        Showing <span className="font-semibold text-slate-900 dark:text-white">{filteredCases.length}</span> operations
                    </div>
                </div>

                {/* Operations Cases List */}
                {filteredCases.length === 0 ? (
                    <div className="mt-6">
                        <EmptyState
                            icon="⚙️"
                            title="No operations found"
                            description={
                                search || statusFilter || stageFilter
                                    ? 'No operational cases matched the applied criteria.'
                                    : 'There are currently no active operations registered in the pipeline.'
                            }
                            action={
                                search || statusFilter || stageFilter ? (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setSearch('');
                                            setStatusFilter('');
                                            setStageFilter('');
                                        }}
                                        className="text-sm font-medium text-teal-600 hover:text-teal-700 dark:text-teal-400"
                                    >
                                        Clear filters
                                    </button>
                                ) : null
                            }
                        />
                    </div>
                ) : (
                    <div className="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800">
                                <thead className="bg-slate-50 dark:bg-slate-800/60">
                                    <tr>
                                        <th className="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            Case Reference
                                        </th>
                                        <th className="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            Operational Stage & State
                                        </th>
                                        <th className="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            Coding Retries
                                        </th>
                                        <th className="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            Approved Scenes
                                        </th>
                                        <th className="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            Last Updated
                                        </th>
                                        <th className="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                                    {filteredCases.map((c) => (
                                        <tr
                                            key={c.case_id}
                                            className="transition-colors hover:bg-slate-50/70 dark:hover:bg-slate-800/40"
                                        >
                                            <td className="whitespace-nowrap px-6 py-4">
                                                <Link
                                                    href={`/v3/admin/operations/${c.case_id}`}
                                                    className="font-mono text-xs font-semibold text-teal-600 hover:text-teal-700 hover:underline dark:text-teal-400"
                                                >
                                                    {c.case_id}
                                                </Link>
                                                {c.error_code && (
                                                    <div className="mt-1 font-mono text-xs text-rose-600 dark:text-rose-400">
                                                        ⚠️ {c.error_code}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4">
                                                <div className="flex flex-col gap-1 items-start">
                                                    <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                                        {c.stage_code}
                                                    </span>
                                                    <StatusBadge status={c.status_code} />
                                                </div>
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-xs font-medium text-slate-900 dark:text-slate-100">
                                                {c.attempt_count ?? 0}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-xs">
                                                <span className="font-semibold text-emerald-600 dark:text-emerald-400">
                                                    {c.approved_scene_count ?? 0}
                                                </span>
                                                <span className="text-slate-400 mx-1">/</span>
                                                <span className="text-slate-600 dark:text-slate-300">{c.scene_count ?? 0}</span>
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-xs text-slate-600 dark:text-slate-300">
                                                {c.updated_at ? new Date(c.updated_at).toLocaleString() : '—'}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-right">
                                                <Link
                                                    href={`/v3/admin/operations/${c.case_id}`}
                                                    className="inline-flex items-center gap-1 rounded-md bg-teal-50 px-2.5 py-1 text-xs font-semibold text-teal-700 hover:bg-teal-100 dark:bg-teal-950/60 dark:text-teal-300"
                                                >
                                                    <span>Inspect</span>
                                                    <span>→</span>
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
