import React, { useState, useMemo } from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import StatusBadge from '../../components/StatusBadge';
import EmptyState from '../../components/EmptyState';

export default function SettingsTemplates({ templates = [] }) {
    const [search, setSearch] = useState('');
    const [selectedKind, setSelectedKind] = useState('all');

    const kinds = useMemo(() => {
        const unique = new Set(templates.map((t) => t.kind).filter(Boolean));
        return ['all', ...Array.from(unique)];
    }, [templates]);

    const filtered = useMemo(() => {
        return templates.filter((t) => {
            const matchesKind = selectedKind === 'all' || t.kind === selectedKind;
            const matchesSearch =
                !search ||
                t.id?.toLowerCase().includes(search.toLowerCase()) ||
                t.kind?.toLowerCase().includes(search.toLowerCase()) ||
                t.checksum?.toLowerCase().includes(search.toLowerCase());
            return matchesKind && matchesSearch;
        });
    }, [templates, search, selectedKind]);

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Operations Console', url: '/v3/admin/workspace' },
        { label: 'Operating Settings', url: '/v3/admin/settings' },
        { label: 'Approved Templates' },
    ];

    return (
        <AppLayout>
            <Head title="Approved Templates - Settings" />

            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    title="Approved Settings Templates"
                    subtitle="Immutable catalog of verified pipeline prompt and scene templates registered for platform operation."
                    breadcrumbs={breadcrumbs}
                    actions={
                        <Link
                            href="/v3/admin/settings"
                            className="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-xs hover:bg-slate-50 focus:outline-hidden focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700/80"
                        >
                            <span>←</span>
                            <span>Back to Settings</span>
                        </Link>
                    }
                />

                {/* Filter and stats row */}
                <div className="mt-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="relative">
                            <input
                                type="text"
                                placeholder="Search by template ID, kind, or checksum..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-72 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-teal-500 focus:outline-hidden focus:ring-1 focus:ring-teal-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500"
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

                        {kinds.length > 2 && (
                            <select
                                value={selectedKind}
                                onChange={(e) => setSelectedKind(e.target.value)}
                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-teal-500 focus:outline-hidden focus:ring-1 focus:ring-teal-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
                            >
                                {kinds.map((k) => (
                                    <option key={k} value={k}>
                                        {k === 'all' ? 'All Template Kinds' : `Kind: ${k}`}
                                    </option>
                                ))}
                            </select>
                        )}
                    </div>

                    <div className="text-sm text-slate-600 dark:text-slate-400">
                        Showing <span className="font-semibold text-slate-900 dark:text-white">{filtered.length}</span> of{' '}
                        <span className="font-semibold text-slate-900 dark:text-white">{templates.length}</span> templates
                    </div>
                </div>

                {/* Table or Empty state */}
                {filtered.length === 0 ? (
                    <div className="mt-6">
                        <EmptyState
                            icon="📋"
                            title="No templates found"
                            description={
                                search || selectedKind !== 'all'
                                    ? 'No approved templates matched your filter query. Clear search to see all registered templates.'
                                    : 'No verified templates are currently registered in the database.'
                            }
                            action={
                                search || selectedKind !== 'all' ? (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setSearch('');
                                            setSelectedKind('all');
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
                                            Template Kind
                                        </th>
                                        <th className="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            Identifier
                                        </th>
                                        <th className="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            SHA-256 Digest
                                        </th>
                                        <th className="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            Registered At
                                        </th>
                                        <th className="px-6 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200 dark:divide-slate-800">
                                    {filtered.map((item) => (
                                        <tr
                                            key={item.id}
                                            className="transition-colors hover:bg-slate-50/70 dark:hover:bg-slate-800/40"
                                        >
                                            <td className="whitespace-nowrap px-6 py-4">
                                                <span className="inline-flex items-center rounded-md bg-teal-50 px-2.5 py-1 text-xs font-medium text-teal-700 ring-1 ring-inset ring-teal-600/20 dark:bg-teal-950/60 dark:text-teal-300 dark:ring-teal-500/30">
                                                    {item.kind}
                                                </span>
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 font-mono text-xs font-medium text-slate-900 dark:text-slate-100">
                                                {item.id}
                                            </td>
                                            <td className="px-6 py-4 font-mono text-xs text-slate-500 dark:text-slate-400">
                                                <span className="truncate block max-w-xs" title={item.checksum}>
                                                    {item.checksum}
                                                </span>
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-xs text-slate-600 dark:text-slate-300">
                                                {item.registered_at
                                                    ? new Date(item.registered_at).toLocaleString()
                                                    : '—'}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4">
                                                <StatusBadge status="verified" label="Approved" />
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
