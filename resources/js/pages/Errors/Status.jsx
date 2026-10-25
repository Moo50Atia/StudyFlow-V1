import React from 'react';
import { Head, Link } from '@inertiajs/react';
import ThemeToggle from '../../components/ThemeToggle';

export default function Status({ message, screen, status: propStatus }) {
    // Derive HTTP status code from screen name (e.g., 'v3.errors.404') or prop
    const statusCode =
        propStatus ||
        (screen && typeof screen === 'string' && screen.match(/\d+/)?.[0]) ||
        '404';

    const getErrorDetails = (code) => {
        switch (String(code)) {
            case '403':
                return {
                    title: 'Access Restricted',
                    defaultMessage:
                        message ||
                        'You do not have the required role or institutional clearance to access this educational resource.',
                    badge: '403 Forbidden',
                    actionLabel: 'Return to Dashboard',
                    actionUrl: '/dashboard',
                    icon: '🔒',
                };
            case '409':
                return {
                    title: 'Concurrency Conflict Detected',
                    defaultMessage:
                        message ||
                        'The state of this resource has changed concurrently. Please reload to fetch the latest version before retrying your action.',
                    badge: '409 Conflict',
                    actionLabel: 'Reload Page',
                    isReload: true,
                    icon: '⚡',
                };
            case '503':
                return {
                    title: 'Service Temporarily Unavailable',
                    defaultMessage:
                        message ||
                        'The processing pipeline or bridge service is temporarily unavailable. Please wait a moment and retry.',
                    badge: '503 Unavailable',
                    actionLabel: 'Retry Connection',
                    isReload: true,
                    icon: '⏳',
                };
            case '404':
            default:
                return {
                    title: 'Resource Unavailable',
                    defaultMessage:
                        message ||
                        'The requested curriculum item, scene, or case does not exist or is no longer accessible.',
                    badge: `${code} Not Found`,
                    actionLabel: 'Back to Safe Harbor',
                    actionUrl: '/dashboard',
                    icon: '🔍',
                };
        }
    };

    const details = getErrorDetails(statusCode);

    return (
        <div className="flex min-h-screen flex-col bg-slate-50 text-slate-900 transition-colors dark:bg-slate-950 dark:text-slate-100">
            <Head title={`${details.badge} - EduPlatform`} />

            {/* Simple Top Navigation */}
            <header className="border-b border-slate-200/80 bg-white/70 px-6 py-4 backdrop-blur-md dark:border-slate-800/80 dark:bg-slate-900/70">
                <div className="mx-auto flex max-w-5xl items-center justify-between">
                    <Link href="/" className="flex items-center gap-2.5">
                        <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-teal-600 text-lg font-bold text-white shadow-sm dark:bg-teal-500">
                            E
                        </span>
                        <span className="text-lg font-bold tracking-tight text-slate-900 dark:text-white">
                            EduPlatform
                        </span>
                    </Link>

                    <div className="flex items-center gap-3">
                        <ThemeToggle />
                        <Link
                            href="/dashboard"
                            className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            Dashboard
                        </Link>
                    </div>
                </div>
            </header>

            {/* Error Body */}
            <main className="flex flex-1 items-center justify-center px-4 py-16 sm:px-6 lg:px-8">
                <div className="w-full max-w-lg text-center">
                    <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-2xl bg-slate-100 text-3xl shadow-inner dark:bg-slate-800">
                        {details.icon}
                    </div>

                    <div className="mt-6 inline-flex items-center rounded-full bg-slate-200/80 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                        {details.badge}
                    </div>

                    <h1 className="mt-4 text-2xl font-bold tracking-tight sm:text-3xl">
                        {details.title}
                    </h1>

                    <p className="mt-3 text-sm text-slate-600 dark:text-slate-400">
                        {details.defaultMessage}
                    </p>

                    <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        {details.isReload ? (
                            <button
                                type="button"
                                onClick={() => window.location.reload()}
                                className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 focus:outline-hidden focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 sm:w-auto dark:bg-teal-500 dark:hover:bg-teal-600"
                            >
                                <span>🔄</span>
                                <span>{details.actionLabel}</span>
                            </button>
                        ) : (
                            <Link
                                href={details.actionUrl}
                                className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 focus:outline-hidden focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 sm:w-auto dark:bg-teal-500 dark:hover:bg-teal-600"
                            >
                                <span>🏠</span>
                                <span>{details.actionLabel}</span>
                            </Link>
                        )}

                        <button
                            type="button"
                            onClick={() => window.history.back()}
                            className="inline-flex w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 shadow-xs transition hover:bg-slate-50 focus:outline-hidden focus:ring-2 focus:ring-slate-400 sm:w-auto dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                        >
                            Go Back
                        </button>
                    </div>

                    <div className="mt-12 text-xs text-slate-400 dark:text-slate-500">
                        Secured Educational Platform • Incident reference code:{' '}
                        <span className="font-mono">{statusCode}</span>
                    </div>
                </div>
            </main>
        </div>
    );
}
