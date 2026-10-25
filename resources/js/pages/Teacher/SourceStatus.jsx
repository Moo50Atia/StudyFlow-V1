import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import StatusBadge from '../../components/StatusBadge';

export default function SourceStatus({ case: caseModel, sourceStatus = {} }) {
    const caseId = caseModel?.id || sourceStatus?.case_id;
    const status = sourceStatus?.status || 'unknown';
    const gaps = sourceStatus?.gaps || [];

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: `Case #${caseId}`, url: `/v3/teacher/cases/${caseId}` },
        { label: 'Source Document Status' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Source Status: Case #${caseId} — EduPlatform`} />

            <PageHeader
                title={`Source Document Status (Case #${caseId})`}
                eyebrow="PDF Extraction Health"
                description="Verify text extraction fidelity, OCR coverage, and formatting gap detection across the ingested document."
                actions={
                    <Button href={`/v3/teacher/cases/${caseId}`} variant="secondary" size="sm">
                        ← Case Overview
                    </Button>
                }
            />

            <div className="max-w-3xl space-y-6">
                {/* Metric Summary Card */}
                <div className="bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                    <div className="flex items-center justify-between mb-6">
                        <h2 className="text-base font-bold text-slate-900 dark:text-slate-100">
                            Extraction Metrics
                        </h2>
                        <StatusBadge status={status} />
                    </div>

                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        <div className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823]">
                            <span className="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400 block">
                                Total Pages
                            </span>
                            <span className="text-2xl font-black text-slate-900 dark:text-slate-100 mt-1 block">
                                {sourceStatus?.page_count ?? '—'}
                            </span>
                        </div>

                        <div className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823]">
                            <span className="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400 block">
                                OCR Processed
                            </span>
                            <span className="text-2xl font-black text-teal-700 dark:text-teal-400 mt-1 block">
                                {sourceStatus?.ocr_page_count ?? '—'}
                            </span>
                        </div>

                        <div className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] col-span-2 sm:col-span-1">
                            <span className="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400 block">
                                Detected Gaps
                            </span>
                            <span className="text-2xl font-black text-amber-600 dark:text-amber-400 mt-1 block">
                                {gaps.length}
                            </span>
                        </div>
                    </div>

                    {sourceStatus?.error_code && (
                        <div className="mt-6 p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 text-rose-900 dark:text-rose-200 text-xs">
                            <strong>Extraction Warning:</strong> Error code {sourceStatus.error_code}. Certain non-standard typography or scanned graphics could not be parsed with 100% confidence.
                        </div>
                    )}
                </div>

                {/* Gap List */}
                <div className="bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                    <h3 className="text-base font-bold text-slate-900 dark:text-slate-100 mb-2">
                        Formatting Gaps & Scanned Elements
                    </h3>
                    <p className="text-xs text-slate-500 dark:text-slate-400 mb-4">
                        Sections where visual layout or low resolution required fallbacks.
                    </p>

                    {gaps.length > 0 ? (
                        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                            {gaps.map((gap, i) => (
                                <li key={i} className="py-3 text-xs flex items-center justify-between">
                                    <span className="font-semibold text-slate-800 dark:text-slate-200">
                                        Page {gap.page || '—'}: {gap.reason || 'Formatting gap'}
                                    </span>
                                    <span className="text-slate-500">
                                        {gap.severity || 'low'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-emerald-600 dark:text-emerald-400 py-4 flex items-center gap-2">
                            <span>✓</span> Full text coverage without unparsed gaps.
                        </p>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
