import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import StatusBadge from '../../components/StatusBadge';

export default function CaseProgress({ case: caseModel, progress = {} }) {
    const caseId = caseModel?.id || progress?.case_id;
    const state = progress?.state || caseModel?.state || 'unknown';
    const events = progress?.timeline || progress?.events || [];

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: `Case #${caseId}`, url: `/v3/teacher/cases/${caseId}` },
        { label: 'Progress & Events' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Case #${caseId} Progress — EduPlatform`} />

            <PageHeader
                title={`Pipeline Progress: Case #${caseId}`}
                eyebrow="Milestone Event Log"
                description="Audited execution timeline recording every state transition, concept checkpoint, and processing milestone."
                actions={
                    <div className="flex items-center gap-2">
                        <Button href={`/v3/teacher/cases/${caseId}`} variant="secondary" size="sm">
                            ← Case Overview
                        </Button>
                        <Button href={`/v3/teacher/cases/${caseId}/progress`} variant="primary" size="sm">
                            Refresh Events
                        </Button>
                    </div>
                }
            />

            <div className="max-w-4xl space-y-8">
                {/* Status Bar */}
                <div className="p-6 bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm flex items-center justify-between">
                    <div>
                        <span className="text-xs uppercase font-semibold text-slate-500 dark:text-slate-400">
                            Current Pipeline State
                        </span>
                        <div className="flex items-center gap-2 mt-1">
                            <span className="text-xl font-extrabold text-slate-900 dark:text-slate-100">
                                {state.toUpperCase()}
                            </span>
                            <StatusBadge status={state} />
                        </div>
                    </div>

                    <div className="text-xs text-slate-500 dark:text-slate-400 text-right">
                        <span>Total Events Recorded: {events.length}</span>
                    </div>
                </div>

                {/* Timeline Stream */}
                <div className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 sm:p-8 shadow-sm">
                    <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100 mb-6">
                        Chronological Event Timeline
                    </h2>

                    {events.length > 0 ? (
                        <ol className="relative border-l-2 border-slate-200 dark:border-slate-800 ml-4 space-y-6">
                            {events.map((evt, idx) => {
                                const eventTime = evt.timestamp || evt.created_at;
                                return (
                                    <li key={evt.id || idx} className="relative pl-6">
                                        {/* Dot */}
                                        <span className="absolute -left-2 top-1.5 w-4 h-4 rounded-full bg-teal-600 dark:bg-teal-400 border-2 border-white dark:border-[#121e1a]" />

                                        <div className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/60 dark:border-[#263932]">
                                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-1 mb-1">
                                                <span className="font-bold text-sm text-slate-900 dark:text-slate-100">
                                                    {evt.event_type || 'State Transition'}
                                                </span>
                                                <span className="text-xs text-slate-500 dark:text-slate-400 font-mono">
                                                    {eventTime ? new Date(eventTime).toLocaleString() : '—'}
                                                </span>
                                            </div>

                                            {(evt.state_before || evt.state_after) && (
                                                <div className="text-xs text-slate-600 dark:text-slate-300 flex items-center gap-2 mt-1">
                                                    <span className="font-mono bg-slate-200 dark:bg-slate-700 px-1.5 py-0.5 rounded">
                                                        {evt.state_before || 'init'}
                                                    </span>
                                                    <span>→</span>
                                                    <span className="font-mono bg-teal-100 dark:bg-teal-950/60 text-teal-800 dark:text-teal-300 px-1.5 py-0.5 rounded font-semibold">
                                                        {evt.state_after}
                                                    </span>
                                                </div>
                                            )}

                                            {evt.message && (
                                                <p className="text-xs text-slate-600 dark:text-slate-400 mt-2">
                                                    {evt.message}
                                                </p>
                                            )}
                                        </div>
                                    </li>
                                );
                            })}
                        </ol>
                    ) : (
                        <p className="text-sm text-slate-500 py-6 text-center">
                            No transition events recorded for this case yet.
                        </p>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
