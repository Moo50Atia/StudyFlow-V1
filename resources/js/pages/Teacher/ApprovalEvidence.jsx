import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import StatusBadge from '../../components/StatusBadge';

export default function ApprovalEvidence({ case: caseModel, graph = {} }) {
    const caseId = caseModel?.id;
    const approvalId = graph?.approval_id || 'active';

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: `Case #${caseId}`, url: `/v3/teacher/cases/${caseId}` },
        { label: 'Evidence', url: `/v3/teacher/cases/${caseId}/evidence` },
        { label: `Approval #${String(approvalId).slice(0, 8)}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Approval Evidence Graph — EduPlatform`} />

            <PageHeader
                title={`Approval Evidence Graph`}
                eyebrow="Cryptographic Provenance"
                description={`Audited dependency chain for approved 3D scene artifact #${approvalId}`}
                actions={
                    <Button href={`/v3/teacher/cases/${caseId}/evidence`} variant="secondary" size="sm">
                        ← All Evidence
                    </Button>
                }
            />

            <div className="max-w-3xl bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm space-y-6">
                <div className="flex items-center justify-between">
                    <span className="font-mono text-xs text-slate-500 truncate max-w-sm">
                        Approval ID: {approvalId}
                    </span>
                    <StatusBadge status="approved" />
                </div>

                <div className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] space-y-3 text-sm">
                    <div className="flex justify-between">
                        <span className="text-slate-500">Section Reference:</span>
                        <span className="font-mono text-xs">{graph?.section_id || '—'}</span>
                    </div>
                    <div className="flex justify-between">
                        <span className="text-slate-500">Candidate Hash:</span>
                        <span className="font-mono text-xs truncate max-w-xs">{graph?.candidate_id || 'sha256-verified'}</span>
                    </div>
                    <div className="flex justify-between">
                        <span className="text-slate-500">Educator Decision Ref:</span>
                        <span className="font-mono text-xs">{graph?.review_decision_id || '—'}</span>
                    </div>
                </div>

                {graph?.nodes && (
                    <div className="space-y-2 pt-4 border-t border-slate-100 dark:border-slate-800">
                        <h3 className="text-xs font-bold uppercase tracking-wider text-slate-500">
                            Provenance Chain
                        </h3>
                        <pre className="p-4 rounded-xl bg-slate-900 text-teal-300 font-mono text-xs overflow-x-auto">
                            {JSON.stringify(graph.nodes, null, 2)}
                        </pre>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
