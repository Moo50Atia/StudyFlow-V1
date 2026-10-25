import React, { useState, useMemo } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import StatusBadge from '../../components/StatusBadge';
import EmptyState from '../../components/EmptyState';
import ConfirmDialog from '../../components/ConfirmDialog';

function generateUuid() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

export default function RunIndex({ runs = [], canPullScenes = false }) {
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [activeAction, setActiveAction] = useState(null); // { run, action }
    const [isSubmitting, setIsSubmitting] = useState(false);

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Operations Console', url: '/v3/admin/workspace' },
        { label: 'Subject Runs Stream' },
    ];

    const uniqueStatuses = useMemo(() => {
        const set = new Set(runs.map((r) => r.state).filter(Boolean));
        return ['all', ...Array.from(set)];
    }, [runs]);

    const filteredRuns = useMemo(() => {
        return runs.filter((r) => {
            const matchesStatus = statusFilter === 'all' || r.state === statusFilter;
            const matchesSearch =
                !search ||
                r.run_id?.toLowerCase().includes(search.toLowerCase()) ||
                r.origin?.toLowerCase().includes(search.toLowerCase()) ||
                r.mode?.toLowerCase().includes(search.toLowerCase()) ||
                r.state?.toLowerCase().includes(search.toLowerCase());
            return matchesStatus && matchesSearch;
        });
    }, [runs, statusFilter, search]);

    const handleExecuteCommand = () => {
        if (!activeAction) return;
        const { run, action } = activeAction;
        setIsSubmitting(true);

        const payload = {
            command_id: generateUuid(),
            action: action,
            expected_version: run.state_version ?? 0,
        };

        router.post(`/v3/admin/subject-runs/${run.run_id}/commands`, payload, {
            preserveScroll: true,
            onFinish: () => {
                setIsSubmitting(false);
                setActiveAction(null);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Subject Runs Stream - Operations" />

            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    title="Global Subject Runs Stream"
                    description="Monitor asynchronous textbook compilation runs, active background workers, and dispatch administrative lifecycle controls."
                    actions={
                        <Link
                            href="/v3/admin/workspace"
                            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-xs hover:bg-slate-50 focus:outline-hidden focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700/80"
                        >
                            <span>←</span>
                            <span>Operations Console</span>
                        </Link>
                    }
                />

                {/* Filter and stats row */}
                <div className="mt-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="relative">
                            <input
                                type="text"
                                placeholder="Search by run ID, origin, or mode..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-72 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-teal-500 focus:outline-hidden focus:ring-1 focus:ring-teal-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
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
                            {uniqueStatuses.map((st) => (
                                <option key={st} value={st}>
                                    {st === 'all' ? 'All Run States' : `State: ${st}`}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="text-sm text-slate-600 dark:text-slate-400">
                        Showing <span className="font-semibold text-slate-900 dark:text-white">{filteredRuns.length}</span> of{' '}
                        <span className="font-semibold text-slate-900 dark:text-white">{runs.length}</span> runs
                    </div>
                </div>

                {/* Runs list or Empty State */}
                {filteredRuns.length === 0 ? (
                    <div className="mt-6">
                        <EmptyState
                            icon="🚀"
                            title="No subject runs found"
                            description={
                                search || statusFilter !== 'all'
                                    ? 'No runs match the filter parameters. Clear your filters to view all runs.'
                                    : 'No asynchronous subject runs have been dispatched yet.'
                            }
                            action={
                                search || statusFilter !== 'all' ? (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setSearch('');
                                            setStatusFilter('all');
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
                                            Run Reference
                                        </th>
                                        <th className="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            State
                                        </th>
                                        <th className="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            Pipeline Mode
                                        </th>
                                        <th className="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            PDFs
                                        </th>
                                        <th className="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            Updated
                                        </th>
                                        <th className="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            Controls & Portals
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                                    {filteredRuns.map((r) => {
                                        const isRunning = r.state === 'running';
                                        const isPaused = r.state === 'paused';
                                        const isTerminal = ['completed', 'cancelled', 'failed'].includes(r.state);

                                        return (
                                            <tr
                                                key={r.run_id}
                                                className="transition-colors hover:bg-slate-50/70 dark:hover:bg-slate-800/40"
                                            >
                                                <td className="whitespace-nowrap px-6 py-4">
                                                    <Link
                                                        href={`/v3/admin/subject-runs/${r.run_id}`}
                                                        className="font-mono text-xs font-semibold text-teal-600 hover:text-teal-700 hover:underline dark:text-teal-400"
                                                    >
                                                        {r.run_id}
                                                    </Link>
                                                    <div className="mt-1 flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                                                        <span>Origin: {r.origin || 'system'}</span>
                                                        <span>•</span>
                                                        <span>v{r.state_version ?? 0}</span>
                                                    </div>
                                                    {r.failures && r.failures.length > 0 && (
                                                        <div className="mt-1 font-mono text-xs text-rose-600 dark:text-rose-400">
                                                            {typeof r.failures[0] === 'string' ? r.failures[0] : r.failures[0]?.message || r.failures[0]?.code || 'Processing needs attention.'}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="whitespace-nowrap px-6 py-4">
                                                    <StatusBadge status={r.state} />
                                                </td>
                                                <td className="whitespace-nowrap px-6 py-4 text-xs">
                                                    <div className="font-medium text-slate-900 dark:text-slate-100">
                                                        {r.mode || 'standard'}
                                                    </div>
                                                    <div className="text-slate-500 dark:text-slate-400">
                                                        Gate: {r.qualification_mode || 'default'}
                                                    </div>
                                                </td>
                                                <td className="whitespace-nowrap px-6 py-4 text-xs font-semibold text-slate-900 dark:text-slate-100">
                                                    {r.total_pdfs ?? 1}
                                                </td>
                                                <td className="whitespace-nowrap px-6 py-4 text-xs text-slate-600 dark:text-slate-300">
                                                    {r.last_updated_at ? new Date(r.last_updated_at).toLocaleTimeString() : '—'}
                                                </td>
                                                <td className="whitespace-nowrap px-6 py-4 text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        {canPullScenes && r.execution_mode === 'local_worker_v1' && r.state === 'awaiting_generation' && (
                                                            <button type="button" disabled={isSubmitting}
                                                                onClick={() => {
                                                                    setIsSubmitting(true);
                                                                    router.post(`/v3/admin/subject-runs/${r.run_id}/pull-scenes`,
                                                                        { request_id: generateUuid() },
                                                                        { preserveScroll: true, onFinish: () => setIsSubmitting(false) });
                                                                }}
                                                                className="rounded-md bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60">
                                                                Pull Generated Scenes
                                                            </button>
                                                        )}
                                                        {canPullScenes && r.recovery_task_id && (
                                                            <Link href={`/v3/admin/local-worker/tasks/${r.recovery_task_id}/reconciliation`} className="rounded-md bg-teal-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-teal-800">Recover run</Link>
                                                        )}
                                                        {/* Control actions */}
                                                        {isRunning && (
                                                            <button
                                                                type="button"
                                                                onClick={() => setActiveAction({ run: r, action: 'pause' })}
                                                                className="rounded-md border border-amber-300 bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/60 dark:text-amber-300"
                                                            >
                                                                Pause
                                                            </button>
                                                        )}
                                                        {isPaused && (
                                                            <button
                                                                type="button"
                                                                onClick={() => setActiveAction({ run: r, action: 'resume' })}
                                                                className="rounded-md border border-teal-300 bg-teal-50 px-2 py-1 text-xs font-medium text-teal-800 hover:bg-teal-100 dark:border-teal-800 dark:bg-teal-950/60 dark:text-teal-300"
                                                            >
                                                                Resume
                                                            </button>
                                                        )}
                                                        {!isTerminal && (
                                                            <button
                                                                type="button"
                                                                onClick={() => setActiveAction({ run: r, action: 'cancel' })}
                                                                className="rounded-md border border-rose-300 bg-rose-50 px-2 py-1 text-xs font-medium text-rose-800 hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/60 dark:text-rose-300"
                                                            >
                                                                Cancel
                                                            </button>
                                                        )}
                                                        {r.retry_available && (
                                                            <button
                                                                type="button"
                                                                onClick={() => setActiveAction({ run: r, action: 'retry' })}
                                                                className="rounded-md border border-sky-300 bg-sky-50 px-2 py-1 text-xs font-medium text-sky-800 hover:bg-sky-100 dark:border-sky-800 dark:bg-sky-950/60 dark:text-sky-300"
                                                            >
                                                                Retry
                                                            </button>
                                                        )}

                                                        <span className="text-slate-300 dark:text-slate-700">|</span>

                                                        <Link
                                                            href={`/v3/admin/subject-runs/${r.run_id}`}
                                                            className="text-xs font-medium text-teal-600 hover:text-teal-700 dark:text-teal-400"
                                                        >
                                                            View
                                                        </Link>
                                                        <Link
                                                            href={`/v3/admin/subject-runs/${r.run_id}#items`}
                                                            className="text-xs font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-200"
                                                        >
                                                            PDF status
                                                        </Link>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>

            {/* Confirm Dialog */}
            <ConfirmDialog
                isOpen={!!activeAction}
                onClose={() => setActiveAction(null)}
                onConfirm={handleExecuteCommand}
                title={`Confirm Run ${activeAction?.action ? activeAction.action.toUpperCase() : ''}`}
                description={`Are you sure you want to request a "${activeAction?.action}" command for run ${activeAction?.run?.run_id}? This administrative action will update the execution control outbox.`}
                confirmText={isSubmitting ? 'Dispatching...' : `Execute ${activeAction?.action ?? ''}`}
                variant={activeAction?.action === 'cancel' ? 'danger' : 'primary'}
                isLoading={isSubmitting}
            />
        </AppLayout>
    );
}
