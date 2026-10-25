import React, { useState, useMemo } from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import StatusBadge from '../../components/StatusBadge';
import EmptyState from '../../components/EmptyState';
import Pagination from '../../components/Pagination';

export default function CasesIndex({ admin, cases = [], paginator = null }) {
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [stageFilter, setStageFilter] = useState('all');

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Operations Console', url: '/v3/admin/workspace' },
        { label: 'Curriculum Cases' },
    ];

    const uniqueStatuses = useMemo(() => {
        const set = new Set(cases.map((c) => c.status_code).filter(Boolean));
        return ['all', ...Array.from(set)];
    }, [cases]);

    const uniqueStages = useMemo(() => {
        const set = new Set(cases.map((c) => c.stage_code).filter(Boolean));
        return ['all', ...Array.from(set)];
    }, [cases]);

    const filteredCases = useMemo(() => {
        return cases.filter((c) => {
            const matchesStatus = statusFilter === 'all' || c.status_code === statusFilter;
            const matchesStage = stageFilter === 'all' || c.stage_code === stageFilter;
            const matchesSearch =
                !search ||
                c.case_id?.toLowerCase().includes(search.toLowerCase()) ||
                c.stage_code?.toLowerCase().includes(search.toLowerCase()) ||
                c.status_code?.toLowerCase().includes(search.toLowerCase()) ||
                (c.error_code && c.error_code.toLowerCase().includes(search.toLowerCase()));
            return matchesStatus && matchesStage && matchesSearch;
        });
    }, [cases, statusFilter, stageFilter, search]);

    // Quick metric counters
    const metrics = useMemo(() => {
        const total = paginator?.total ?? cases.length;
        const active = cases.filter((c) => ['running', 'queued'].includes(c.status_code)).length;
        const review = cases.filter((c) => c.status_code === 'awaiting_review').length;
        const approved = cases.filter((c) => ['approved', 'complete'].includes(c.status_code)).length;
        const blocked = cases.filter((c) => ['blocked', 'failed'].includes(c.status_code)).length;
        return { total, active, review, approved, blocked };
    }, [cases, paginator]);

    return (
        <AppLayout>
            <Head title="Curriculum Cases - Operations" />

            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    title="Curriculum Processing Cases"
                    subtitle="Monitor automated textbook ingestion pipelines, stage milestones, review readiness, and workflow scalars."
                    breadcrumbs={breadcrumbs}
                    actions={
                        <div className="flex items-center gap-3">
                            <Link
                                href="/v3/admin/operations"
                                className="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-xs hover:bg-slate-50 focus:outline-hidden focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700/80"
                            >
                                <span>⚙️</span>
                                <span>Operations & Health</span>
                            </Link>
                            <Link
                                href="/v3/admin/subject-runs"
                                className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-3.5 py-2 text-sm font-medium text-white shadow-xs hover:bg-teal-700 focus:outline-hidden focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 dark:bg-teal-500 dark:hover:bg-teal-600"
                            >
                                <span>🚀</span>
                                <span>Subject Runs Stream</span>
                            </Link>
                        </div>
                    }
                />

                {/* KPI Metrics row */}
                <div className="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-5">
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <div className="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Total Cases</div>
                        <div className="mt-2 text-2xl font-bold text-slate-900 dark:text-white">{metrics.total}</div>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <div className="text-xs font-medium uppercase tracking-wider text-teal-600 dark:text-teal-400">Active / Queued</div>
                        <div className="mt-2 text-2xl font-bold text-teal-600 dark:text-teal-400">{metrics.active}</div>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <div className="text-xs font-medium uppercase tracking-wider text-amber-600 dark:text-amber-400">Awaiting Review</div>
                        <div className="mt-2 text-2xl font-bold text-amber-600 dark:text-amber-400">{metrics.review}</div>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <div className="text-xs font-medium uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Approved</div>
                        <div className="mt-2 text-2xl font-bold text-emerald-600 dark:text-emerald-400">{metrics.approved}</div>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-xs dark:border-slate-800 dark:bg-slate-900">
                        <div className="text-xs font-medium uppercase tracking-wider text-rose-600 dark:text-rose-400">Blocked / Failed</div>
                        <div className="mt-2 text-2xl font-bold text-rose-600 dark:text-rose-400">{metrics.blocked}</div>
                    </div>
                </div>

                {/* Filter and Search Bar */}
                <div className="mt-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-xs sm:flex-row sm:items-center sm:justify-between dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="relative">
                            <input
                                type="text"
                                placeholder="Search by case ID or stage..."
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
                            {uniqueStatuses.map((st) => (
                                <option key={st} value={st}>
                                    {st === 'all' ? 'All Statuses' : `Status: ${st}`}
                                </option>
                            ))}
                        </select>

                        {uniqueStages.length > 2 && (
                            <select
                                value={stageFilter}
                                onChange={(e) => setStageFilter(e.target.value)}
                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-teal-500 focus:outline-hidden focus:ring-1 focus:ring-teal-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
                            >
                                {uniqueStages.map((sg) => (
                                    <option key={sg} value={sg}>
                                        {sg === 'all' ? 'All Stages' : `Stage: ${sg}`}
                                    </option>
                                ))}
                            </select>
                        )}
                    </div>

                    <div className="text-xs text-slate-500 dark:text-slate-400">
                        Showing {filteredCases.length} loaded records
                    </div>
                </div>

                {/* Cases Table */}
                {filteredCases.length === 0 ? (
                    <div className="mt-6">
                        <EmptyState
                            icon="📂"
                            title="No cases found"
                            description={
                                search || statusFilter !== 'all' || stageFilter !== 'all'
                                    ? 'No processing cases match the active filter criteria.'
                                    : 'There are currently no processing cases recorded in the system.'
                            }
                            action={
                                search || statusFilter !== 'all' || stageFilter !== 'all' ? (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setSearch('');
                                            setStatusFilter('all');
                                            setStageFilter('all');
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
                                            Stage & Status
                                        </th>
                                        <th className="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            Attempts
                                        </th>
                                        <th className="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            Scenes (Apprv/Tot)
                                        </th>
                                        <th className="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            Created
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
                                                    href={`/v3/admin/cases/${c.case_id}`}
                                                    className="font-mono text-xs font-semibold text-teal-600 hover:text-teal-700 hover:underline dark:text-teal-400"
                                                >
                                                    {c.case_id}
                                                </Link>
                                                {c.error_code && (
                                                    <div className="mt-1 font-mono text-xs text-rose-600 dark:text-rose-400">
                                                        err: {c.error_code}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4">
                                                <div className="flex flex-col gap-1 items-start">
                                                    <span className="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                                        {c.stage_code}
                                                    </span>
                                                    <StatusBadge status={c.status_code} />
                                                </div>
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-xs font-medium text-slate-900 dark:text-slate-100">
                                                <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                                    {c.attempt_count ?? 0}
                                                </span>
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-xs font-medium text-slate-900 dark:text-slate-100">
                                                <span className="text-emerald-600 dark:text-emerald-400 font-semibold">
                                                    {c.approved_scene_count ?? 0}
                                                </span>
                                                <span className="text-slate-400 mx-1">/</span>
                                                <span>{c.scene_count ?? 0}</span>
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-xs text-slate-600 dark:text-slate-300">
                                                {c.created_at ? new Date(c.created_at).toLocaleString() : '—'}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-right">
                                                <div className="flex items-center justify-end gap-2 text-xs">
                                                    <Link
                                                        href={`/v3/admin/cases/${c.case_id}`}
                                                        className="font-medium text-teal-600 hover:text-teal-700 dark:text-teal-400"
                                                    >
                                                        Summary
                                                    </Link>
                                                    <span className="text-slate-300 dark:text-slate-700">|</span>
                                                    <Link
                                                        href={`/v3/admin/operations/${c.case_id}`}
                                                        className="font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-200"
                                                    >
                                                        Operations
                                                    </Link>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination footer */}
                        {paginator && (
                            <div className="border-t border-slate-200 p-4 dark:border-slate-800">
                                <Pagination links={paginator.links} />
                            </div>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
