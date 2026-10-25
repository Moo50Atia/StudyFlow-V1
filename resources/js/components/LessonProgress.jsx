import React from 'react';
import StatusBadge from './StatusBadge';

export default function LessonProgress({
    completedCount = 0,
    totalCount = null,
    phase = '',
    status = 'running',
    pollIntervalSeconds = 5,
    isPolling = false,
    className = '',
}) {
    const hasTotal = typeof totalCount === 'number' && totalCount > 0;
    const percentage = hasTotal ? Math.min(100, Math.max(0, Math.round((completedCount / totalCount) * 100))) : null;

    const isTerminal = status === 'completed' || status === 'failed' || status === 'cancelled';
    const isFailed = status === 'failed';
    const isPaused = status === 'paused';

    return (
        <div className={`bg-white dark:bg-[#121e1a] rounded-2xl border border-slate-200 dark:border-[#263932] p-5 shadow-sm ${className}`}>
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                <div>
                    <div className="flex items-center gap-2">
                        <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                            Generation Progress
                        </h3>
                        <StatusBadge status={status} />
                    </div>
                    {phase && (
                        <p className="text-sm text-slate-600 dark:text-slate-400 mt-0.5">
                            Current phase: <span className="font-semibold text-slate-800 dark:text-slate-200">{phase}</span>
                        </p>
                    )}
                </div>

                <div className="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-1.5 self-start sm:self-auto">
                    {isPolling && !isTerminal && (
                        <span className="inline-flex items-center gap-1">
                            <span className="w-2 h-2 rounded-full bg-teal-500 animate-ping" />
                            Live sync (every {pollIntervalSeconds}s)
                        </span>
                    )}
                </div>
            </div>

            {/* Progress Bar Container */}
            <div className="w-full bg-slate-100 dark:bg-slate-800/80 rounded-full h-3 overflow-hidden relative">
                {hasTotal ? (
                    <div
                        className={`h-full rounded-full transition-all duration-500 ease-out ${
                            isFailed
                                ? 'bg-rose-500'
                                : isPaused
                                ? 'bg-amber-500'
                                : 'bg-teal-600 dark:bg-teal-400'
                        }`}
                        style={{ width: `${percentage}%` }}
                        role="progressbar"
                        aria-valuenow={completedCount}
                        aria-valuemin={0}
                        aria-valuemax={totalCount}
                        aria-label="Lesson generation progress"
                    />
                ) : (
                    <div
                        className="h-full rounded-full bg-teal-600 dark:bg-teal-400 w-1/3 animate-[indeterminate_1.5s_infinite_ease-in-out]"
                        role="progressbar"
                        aria-label="Indeterminate lesson generation progress"
                    />
                )}
            </div>

            {/* Metrics and Live Announcement */}
            <div className="mt-3 flex items-center justify-between text-sm">
                <span className="font-medium text-slate-700 dark:text-slate-300">
                    {hasTotal ? (
                        <>
                            <span className="font-bold text-slate-900 dark:text-slate-100">{completedCount}</span> of{' '}
                            <span className="font-bold text-slate-900 dark:text-slate-100">{totalCount}</span> lessons persisted
                        </>
                    ) : (
                        <span>
                            <span className="font-bold text-slate-900 dark:text-slate-100">{completedCount}</span> lessons persisted (total calculating...)
                        </span>
                    )}
                </span>

                <span className="font-bold text-slate-900 dark:text-slate-100">
                    {hasTotal ? `${percentage}%` : 'In progress'}
                </span>
            </div>

            {/* Polite Screen Reader Live Region */}
            <div className="sr-only" aria-live="polite" aria-atomic="true">
                {hasTotal
                    ? `Progress update: ${completedCount} of ${totalCount} lessons completed. Status: ${status}.`
                    : `Progress update: ${completedCount} lessons completed so far. Status: ${status}.`}
            </div>
        </div>
    );
}
