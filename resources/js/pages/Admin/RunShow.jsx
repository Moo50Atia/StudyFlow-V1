import React from 'react';
import { Head, Link, usePoll } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import StatusBadge from '../../components/StatusBadge';
import Button from '../../components/Button';
import LessonProgress from '../../components/LessonProgress';

export default function RunShow({ run, canManageWorker = false }) {
    usePoll(5000, { only: ['run'] });
    const snapshot = run.workflow?.worker_progress;
    const breadcrumbs = [{ label: 'Dashboard', url: '/dashboard' }, { label: 'Subject runs', url: '/v3/admin/subject-runs' }, { label: run.run_id }];
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Run progress — Operations" />
            <PageHeader title="Run progress" description={`Run ${run.run_id}`} actions={<Button href="/v3/admin/subject-runs" variant="outline">All runs</Button>} />
            <section className="rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-900 space-y-5">
                <div className="flex flex-wrap items-center gap-3"><StatusBadge status={run.state} /><span className="text-sm text-slate-500">{run.execution_mode === 'local_worker_v1' ? 'Local worker' : 'Server processing'}</span></div>
                {canManageWorker && run.recovery_task_id && <div className="space-y-3"><p className="text-sm text-slate-600 dark:text-slate-300">This run is held. Check the original worker and authorize continuation from its saved checkpoint.</p><Button href={`/v3/admin/local-worker/tasks/${run.recovery_task_id}/reconciliation`}>Recover run</Button></div>}
                {run.failures?.length > 0 && <ul className="text-sm text-amber-800 dark:text-amber-200 space-y-1">{run.failures.map((failure, i) => <li key={i}>{failure.message || failure.code || 'Processing needs attention.'}</li>)}</ul>}
                <div className="space-y-4" aria-live="polite">
                    <p className="text-sm font-semibold">Stage: {(run.workflow?.phase || run.state).replaceAll('_', ' ')}</p>
                    <LessonProgress completedCount={run.counts.lessons_saved} totalCount={run.counts.lessons_total} phase={run.workflow?.phase} isPolling={true} status={run.state} />
                    {snapshot && <p className="text-sm text-slate-600 dark:text-slate-300">
                        Worker saved {snapshot.lessons_saved} lessons · PDF {snapshot.current_pdf_ordinal || '—'} of {snapshot.total_pdfs || '—'}.
                        {' '}Last worker update: {new Date(snapshot.received_at).toLocaleTimeString()}.
                        {!run.workflow.progress_fresh && ' Showing last known worker progress.'}
                    </p>}
                    <p className="text-xs text-slate-500">Worker counts update during processing. Lessons are imported into the platform at the PDF handoff; the table below shows imported counts.</p>
                </div>
                <div id="items" className="overflow-x-auto">
                    <table className="w-full text-left text-sm"><caption className="text-left font-semibold mb-3">PDF processing</caption><thead><tr className="border-b dark:border-slate-700"><th className="p-3">PDF</th><th className="p-3">Status</th><th className="p-3">Imported lessons</th><th className="p-3">Prompts</th><th className="p-3">Details</th></tr></thead>
                        <tbody>{run.items.map((item) => <tr key={item.id} className="border-b dark:border-slate-700"><td className="p-3">{item.pdf_ordinal}</td><td className="p-3"><StatusBadge status={item.state} /></td><td className="p-3">{item.counts.saved_lessons || 0}</td><td className="p-3">{item.counts.prompts || 0}</td><td className="p-3">{item.safe_code === 'ok' ? '—' : item.safe_code}</td></tr>)}</tbody>
                    </table>
                </div>
                <p className="text-xs text-slate-500">Updates automatically every five seconds.</p>
            </section>
        </AppLayout>
    );
}
