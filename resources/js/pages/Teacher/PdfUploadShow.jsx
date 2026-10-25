import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import StatusBadge from '../../components/StatusBadge';

export default function PdfUploadShow({ summary = {}, attempt }) {
    const state = summary.state || attempt?.state || 'unknown';
    const attemptId = summary.attempt_id || attempt?.id;

    const form = useForm({
        document_id: summary.document_id || '',
        action: summary.latest_case_id ? 'process_again' : 'process',
        idempotency_key: summary.nextIdempotencyKey || (typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `key-${Date.now()}`),
    });

    const isBusy = ['receiving', 'quarantined', 'validating', 'retry_pending', 'promoting'].includes(state);
    const isAccepted = state === 'accepted';
    const isRejected = state === 'rejected' || state === 'failed';

    const handleProcess = (e) => {
        e.preventDefault();
        form.post('/v3/teacher/cases');
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'PDF Ingestion', url: '/v3/teacher/pdf-uploads' },
        { label: `Attempt #${String(attemptId || '').slice(0, 8)}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PDF Ingestion Status — EduPlatform`} />

            <PageHeader
                title={`Ingestion: ${String(attemptId || '').slice(0, 13)}...`}
                eyebrow="Verification Status"
                description="Structural integrity, MIME screening, and sovereign storage validation."
                actions={
                    <Button href={`/v3/teacher/pdf-uploads/${attemptId}`} variant="secondary" size="sm">
                        Refresh Status
                    </Button>
                }
            />

            <div className="max-w-3xl space-y-8">
                {/* Status Hero Card */}
                <div className="bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                    <div className="flex items-center justify-between gap-4 mb-4">
                        <div className="flex items-center gap-3">
                            <h2 className="text-xl font-extrabold text-slate-900 dark:text-slate-100">
                                Current State
                            </h2>
                            <StatusBadge status={state} />
                        </div>
                    </div>

                    {isBusy && (
                        <div className="p-4 rounded-2xl bg-indigo-50/70 dark:bg-indigo-950/30 border border-indigo-200 dark:border-indigo-900/60 mb-6 flex items-start gap-3">
                            <div className="w-5 h-5 rounded-full border-2 border-indigo-600 border-t-transparent animate-spin flex-shrink-0 mt-0.5" />
                            <div>
                                <p className="text-sm font-bold text-indigo-900 dark:text-indigo-300">
                                    Inspection in Progress
                                </p>
                                <p className="text-xs text-indigo-700 dark:text-indigo-400 mt-0.5">
                                    The document is undergoing automated anti-malware and PDF structure verification. Refresh the page to check status.
                                </p>
                            </div>
                        </div>
                    )}

                    {summary.reason_code && (
                        <div className="p-4 rounded-2xl bg-rose-50/70 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900/60 mb-6">
                            <p className="text-sm font-bold text-rose-900 dark:text-rose-300">
                                Ingestion Alert: {summary.reason_code}
                            </p>
                            <p className="text-xs text-rose-700 dark:text-rose-400 mt-0.5">
                                The file could not be verified. Please inspect the source document formatting and re-upload.
                            </p>
                        </div>
                    )}

                    {/* Metadata Attributes */}
                    <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm border-t border-slate-100 dark:border-slate-800 pt-6">
                        <div className="p-3.5 rounded-2xl bg-slate-50 dark:bg-[#182823]">
                            <dt className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                Attempt ID
                            </dt>
                            <dd className="font-mono text-xs text-slate-800 dark:text-slate-200 mt-1 break-all">
                                {attemptId || '—'}
                            </dd>
                        </div>

                        <div className="p-3.5 rounded-2xl bg-slate-50 dark:bg-[#182823]">
                            <dt className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                Document Identity
                            </dt>
                            <dd className="font-mono text-xs text-slate-800 dark:text-slate-200 mt-1 break-all">
                                {summary.document_id || '—'}
                            </dd>
                        </div>

                        <div className="p-3.5 rounded-2xl bg-slate-50 dark:bg-[#182823]">
                            <dt className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                Upload Timestamp
                            </dt>
                            <dd className="text-xs text-slate-800 dark:text-slate-200 mt-1">
                                {summary.created_at ? new Date(summary.created_at).toLocaleString() : '—'}
                            </dd>
                        </div>

                        <div className="p-3.5 rounded-2xl bg-slate-50 dark:bg-[#182823]">
                            <dt className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                Verification Completed
                            </dt>
                            <dd className="text-xs text-slate-800 dark:text-slate-200 mt-1">
                                {summary.finished_at ? new Date(summary.finished_at).toLocaleString() : 'Pending'}
                            </dd>
                        </div>
                    </dl>
                </div>

                {/* Promotion to Processing Case */}
                {isAccepted && (
                    <div className="bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-teal-200 dark:border-teal-900/60 shadow-sm">
                        <span className="text-xs font-bold uppercase tracking-wider text-teal-700 dark:text-teal-400">
                            Verified Document Ready
                        </span>
                        <h3 className="text-xl font-bold text-slate-900 dark:text-slate-100 mt-1 mb-2">
                            Curriculum Pipeline Case
                        </h3>
                        <p className="text-sm text-slate-600 dark:text-slate-400 mb-6">
                            This document has passed sovereign screening and is authorized for multi-stage concept structuring and 3D scene synthesis.
                        </p>

                        {summary.latest_case_id ? (
                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200 dark:border-[#263932]">
                                <div>
                                    <span className="text-xs text-slate-500 dark:text-slate-400">Active Case:</span>
                                    <p className="font-bold text-sm text-slate-900 dark:text-slate-100">
                                        Case #{summary.latest_case_id}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button href={`/v3/teacher/cases/${summary.latest_case_id}`} variant="primary" size="sm">
                                        Open Case Details →
                                    </Button>
                                    <Button type="button" variant="secondary" size="sm" onClick={handleProcess} isLoading={form.processing}>
                                        Process Again
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <form onSubmit={handleProcess}>
                                <Button type="submit" variant="primary" size="lg" isLoading={form.processing}>
                                    Start Processing Case →
                                </Button>
                            </form>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
