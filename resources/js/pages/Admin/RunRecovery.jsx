import React, { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import StatusBadge from '../../components/StatusBadge';

export default function RunRecovery({ initial }) {
    const [data, setData] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const endpoint = `/v3/admin/local-worker/tasks/${initial.task_id}/reconciliation`;
    const finished = ['queued', 'leased', 'running', 'completed'].includes(data.status);
    const refresh = async (signal) => {
        const response = await fetch(endpoint, { headers: { Accept: 'application/json' }, signal });
        if (!response.ok) throw new Error('Recovery status is unavailable. Refresh the page or check your owner session.');
        setData(await response.json());
    };
    useEffect(() => {
        const controller = new AbortController();
        const tick = () => refresh(controller.signal).catch((e) => { if (e.name !== 'AbortError') setError(e.message); });
        tick();
        const timer = setInterval(tick, 5000);
        return () => { clearInterval(timer); controller.abort(); };
    }, [endpoint]);
    const act = async (continueRun) => {
        setBusy(true); setError(''); setMessage('');
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const payload = continueRun ? {
                request_id: crypto.randomUUID(), effect_id: data.recovery.effect_id,
                evidence_artifact_id: data.recovery.evidence_artifact_id, acknowledge_existing_terminal_run: true,
            } : {};
            const response = await fetch(endpoint + (continueRun ? '/resolve-terminal' : ''), {
                method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error?.message || ({
                device_unavailable: 'The original worker is unavailable. Start it and try again.',
                task_not_reconcilable: 'The previous worker lease has not cleared yet. Wait a moment and check again.',
                expired_assignment_required: 'Waiting for the previous worker lease to expire. Try again shortly.',
            }[result.error?.code]) || 'Recovery could not be authorized. Refresh and check the current status.');
            setMessage(continueRun ? 'Continue authorized. The worker will resume this saved run.' : 'Worker check requested. Keep this page open while it reads the saved run.');
            await refresh();
        } catch (e) { setError(e.message); }
        finally { setBusy(false); }
    };
    return (
        <AppLayout>
            <Head title="Recover processing run" />
            <PageHeader title="Continue your saved run" description={`Run ${data.run_id}`} />
            <section className="max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-900 space-y-5">
                <div className="flex flex-wrap items-center gap-3"><span className="font-semibold">Processing status</span><StatusBadge status={data.status} /></div>
                <p className="text-sm text-slate-600 dark:text-slate-300">After refreshing NotebookLM authentication on the laptop, check the original worker. Once it confirms the saved run, continue processing from that run.</p>
                {error && <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950 dark:text-rose-200">{error}</p>}
                {message && <p role="status" className="text-sm text-teal-700 dark:text-teal-300">{message}</p>}
                {finished ? (
                    <div role="status" className="space-y-3"><p className="font-semibold text-teal-700 dark:text-teal-300">The recovery hold has been released.</p><Link className="underline" href="/v3/admin/subject-runs">View run progress →</Link></div>
                ) : (
                    <>
                        {data.recovery && <p role="status" className="text-sm">Saved terminal run: <strong>{data.recovery.terminal_status}</strong>. Authentication will be checked again when processing resumes.</p>}
                        {!data.can_check && !data.recovery?.can_continue && <p role="status" className="text-sm text-slate-500">{data.assignment_status === 'reconciliation_only' ? 'Waiting for the original worker to report its saved run…' : 'Waiting for the previous worker lease to clear. This page refreshes automatically.'}</p>}
                        <div className="flex flex-wrap gap-3">
                            <Button variant="outline" disabled={busy || !data.can_check} onClick={() => act(false)}>1. Check worker</Button>
                            <Button disabled={busy || !data.recovery?.can_continue} onClick={() => act(true)}>2. Continue processing</Button>
                        </div>
                    </>
                )}
            </section>
        </AppLayout>
    );
}
