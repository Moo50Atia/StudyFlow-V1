import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';

export default function CaseConflict({
    code = 'action_conflict',
    message = 'The requested operation conflicts with the current pipeline state.',
}) {
    const handleReload = () => {
        window.location.reload();
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Educator Studio', url: '/v3/teacher/workspace' },
        { label: 'State Conflict' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Action Conflict (409) — EduPlatform" />

            <div className="max-w-2xl mx-auto py-12">
                <div className="bg-amber-50/80 dark:bg-amber-950/30 rounded-3xl border border-amber-200 dark:border-amber-900/60 p-8 sm:p-10 shadow-sm text-center">
                    <div className="w-14 h-14 rounded-2xl bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 mx-auto mb-4 flex items-center justify-center">
                        <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>

                    <span className="text-xs font-bold uppercase tracking-wider text-amber-800 dark:text-amber-400">
                        HTTP 409 • Concurrency Conflict
                    </span>

                    <h1 className="text-2xl font-extrabold text-slate-900 dark:text-slate-100 mt-2 mb-3">
                        State Changed Concurrently
                    </h1>

                    <p className="text-sm text-slate-600 dark:text-slate-300 leading-relaxed mb-4">
                        {message}
                    </p>

                    <div className="inline-block p-2 rounded-xl bg-white/70 dark:bg-[#121e1a]/70 border border-amber-200/60 dark:border-amber-800 text-xs font-mono text-amber-900 dark:text-amber-300 mb-8">
                        Reason Code: {code}
                    </div>

                    <div className="flex flex-wrap items-center justify-center gap-3">
                        <Button
                            type="button"
                            variant="primary"
                            onClick={handleReload}
                        >
                            Refresh & Sync State
                        </Button>
                        <Button
                            href="/v3/teacher/workspace"
                            variant="secondary"
                        >
                            Return to Studio
                        </Button>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
