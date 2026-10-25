import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';

export default function Workspace({ admin }) {
    const [serverStatus, setServerStatus] = useState('');
    const [workerStatus, setWorkerStatus] = useState('');
    const [serverBusy, setServerBusy] = useState(false);
    const [workerBusy, setWorkerBusy] = useState(false);
    const [command, setCommand] = useState('');

    const checkServerNotebookLM = async () => {
        setServerBusy(true);
        setServerStatus('Checking the NotebookLM session configured on this Laravel server...');
        setCommand('');
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const response = await fetch('/v3/admin/notebooklm/health-check', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
            });
            const body = await response.json();
            if (body.data?.healthy) {
                setServerStatus(body.data.message || 'The Laravel server NotebookLM session is healthy.');
            } else if (body.data?.state === 'authentication_required') {
                setServerStatus(body.data.message || 'The Laravel server session requires authentication.');
                setCommand(body.data.terminal_command || '');
            } else {
                setServerStatus(body.error?.message || 'The Laravel server NotebookLM health check reported an issue.');
            }
        } catch {
            setServerStatus('The Laravel server health check is unreachable. Inspect the bridge service.');
        } finally {
            setServerBusy(false);
        }
    };

    const checkWorkerNotebookLM = async () => {
        setWorkerBusy(true);
        setWorkerStatus('Requesting a read-only NotebookLM check from the enrolled local worker...');
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const response = await fetch('/v3/admin/notebooklm/health-check', {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ target: 'local_worker' }),
            });
            const body = await response.json();
            if (!response.ok || !body.data?.probe_id) {
                setWorkerStatus(body.data?.message || body.error?.message || 'No active NotebookLM worker is available.');
                return;
            }
            const probeUrl = `/v3/admin/notebooklm/health-check/${body.data.probe_id}`;
            for (let attempt = 0; attempt < 30; attempt += 1) {
                await new Promise((resolve) => window.setTimeout(resolve, 2000));
                const result = await fetch(probeUrl, { headers: { Accept: 'application/json' } });
                const status = await result.json();
                if (!result.ok) throw new Error(status.error?.message || 'Worker health result could not be read.');
                setWorkerStatus(status.data?.message || 'Waiting for the local worker...');
                if (status.data?.state !== 'pending') return;
            }
            setWorkerStatus('The local worker has not responded yet. Confirm it is running and check again.');
        } catch (error) {
            setWorkerStatus(error.message || 'The local worker health check is unreachable.');
        } finally {
            setWorkerBusy(false);
        }
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Operations Console' },
    ];

    const adminCards = [
        {
            title: 'Account Access & Roles',
            description: 'Audit roles, grant or suspend V3 access status using versioned updates.',
            url: '/v3/admin/accounts',
            icon: '👥',
        },
        {
            title: 'Curriculum Cases',
            description: 'Monitor throughput, state milestones, and closed scalars safely.',
            url: '/v3/admin/cases',
            icon: '📂',
        },
        {
            title: 'Operations & Health',
            description: 'Inspect recoverable targets, bridge states, and system incidents.',
            url: '/v3/admin/operations',
            icon: '⚙️',
        },
        {
            title: 'Operating Settings',
            description: 'Manage verified system configuration, models, and template versions.',
            url: '/v3/admin/settings',
            icon: '🔧',
        },
        {
            title: 'Subject Runs Stream',
            description: 'Inspect global subject runs and async background queue progress.',
            url: '/v3/admin/subject-runs',
            icon: '🚀',
        },
        {
            title: 'Audit & Compliance Evidence',
            description: 'Audit cryptographic digests and export institutional evidence bundles.',
            url: '/v3/admin/evidence',
            icon: '🛡️',
        },
        {
            title: 'Processing Devices',
            description: 'Enroll and monitor outbound processing workers, manage pairing codes and revocation.',
            url: '/v3/admin/devices',
            icon: '💻',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Operations Console — EduPlatform" />

            <PageHeader
                title="Operations Console"
                eyebrow="System Administration"
                description={`Logged in as ${admin?.name || 'Administrator'} • Centralized operational monitoring, background queues, and system configurations.`}
            />

            {/* NotebookLM Session Probe Card */}
            <section className="mb-8 bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 sm:p-8 shadow-sm">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="w-2.5 h-2.5 rounded-full bg-teal-500 animate-pulse" />
                            <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100">
                                NotebookLM Session Health
                            </h2>
                        </div>
                        <p className="text-sm text-slate-600 dark:text-slate-400 mt-1 max-w-2xl">
                            Check each NotebookLM runtime independently. Both checks are read-only and do not dispatch generation requests.
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-3 self-start sm:self-auto">
                        <Button type="button" variant="secondary" onClick={checkServerNotebookLM} disabled={serverBusy} loading={serverBusy}>
                            Check Server Session
                        </Button>
                        <Button type="button" variant="primary" onClick={checkWorkerNotebookLM} disabled={workerBusy} loading={workerBusy}>
                            Check Local Worker Session
                        </Button>
                    </div>
                </div>

                {serverStatus && (
                    <div
                        role="status"
                        aria-live="polite"
                        className="mt-4 p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200 dark:border-[#263932] text-sm text-slate-800 dark:text-slate-200 font-medium"
                    >
                        {serverStatus}
                    </div>
                )}

                {workerStatus && (
                    <div role="status" aria-live="polite" className="mt-3 p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200 dark:border-[#263932] text-sm text-slate-800 dark:text-slate-200 font-medium">
                        <strong className="mr-1">Local worker:</strong>{workerStatus}
                    </div>
                )}

                {command && (
                    <div className="mt-4 space-y-2">
                        <span className="text-xs font-bold uppercase tracking-wider text-slate-500">
                            Authentication Required — Run in Sovereign Terminal:
                        </span>
                        <pre className="p-4 rounded-2xl bg-slate-900 text-teal-300 font-mono text-xs overflow-x-auto shadow-inner">
                            {command}
                        </pre>
                    </div>
                )}
            </section>

            {/* Operations Hub Navigation Grid */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {adminCards.map((card) => (
                    <Link
                        key={card.title}
                        href={card.url}
                        className="p-6 rounded-3xl bg-white dark:bg-[#121e1a] border border-slate-200 dark:border-[#263932] hover:border-teal-500 hover:shadow-md transition-all flex flex-col justify-between group"
                    >
                        <div>
                            <span className="text-2xl mb-3 block">{card.icon}</span>
                            <h3 className="font-bold text-base text-slate-900 dark:text-slate-100 group-hover:text-teal-700 dark:group-hover:text-teal-400 transition-colors">
                                {card.title}
                            </h3>
                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                                {card.description}
                            </p>
                        </div>

                        <div className="mt-6 pt-3 border-t border-slate-100 dark:border-slate-800 text-xs font-semibold text-teal-700 dark:text-teal-400 flex items-center justify-between">
                            <span>Open Console</span>
                            <span>→</span>
                        </div>
                    </Link>
                ))}
            </div>
        </AppLayout>
    );
}
