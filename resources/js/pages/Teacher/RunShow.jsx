import React, { useEffect, useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import WorkflowRail from '../../components/WorkflowRail';
import LessonProgress from '../../components/LessonProgress';
import StatusBadge from '../../components/StatusBadge';
import ConfirmDialog from '../../components/ConfirmDialog';

const TERMINAL_STATES = new Set(['completed', 'partial', 'failed', 'cancelled']);

export default function RunShow({
    run,
    initialProgress,
    progressUrl,
    commandUrl,
    scenesUrl,
    scenePullUrl,
    subjectUrl,
    pollInterval = 5000,
}) {
    const [progress, setProgress] = useState(initialProgress);
    const [error, setError] = useState('');
    const [isCancelling, setIsCancelling] = useState(false);
    const [isControlLoading, setIsControlLoading] = useState(false);
    const [lessonPage, setLessonPage] = useState(1);
    const [pullStatus, setPullStatus] = useState('');
    const pullRequestId = useRef(null);
    const pullRequestEpoch = useRef(null);
    const inFlight = useRef(false);

    const pollSeconds = Math.round(Math.max(1000, Number(pollInterval) || 5000) / 1000);

    // Active polling effect with unmount cleanup and overlap prevention
    useEffect(() => {
        if (TERMINAL_STATES.has(progress?.state)) return;

        const timer = window.setInterval(async () => {
            if (inFlight.current) return;
            inFlight.current = true;
            try {
                const response = await fetch(progressUrl, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                if (response.ok) {
                    const data = await response.json();
                    setProgress(data);
                }
            } catch (_) {
                setError('Progress polling encountered a temporary network issue. Showing last known state.');
            } finally {
                inFlight.current = false;
            }
        }, Math.max(1000, Number(pollInterval) || 5000));

        return () => window.clearInterval(timer);
    }, [progress?.state, progressUrl, pollInterval]);

    const counts = progress?.counts || {};
    const total = counts.lessons_total;
    const saved = Number(counts.lessons_saved || 0);
    const isTerminal = TERMINAL_STATES.has(progress?.state);
    const isActive = !isTerminal;
    const pendingControl = ['requested', 'accepted'].includes(progress?.control?.state);
    const failures = (progress?.failures || []).filter((f) => f.visibility === 'teacher');
    const items = progress?.items || [];
    const lessons = progress?.completed_lessons || [];
    const lessonsPerPage = 10;
    const lessonPageCount = Math.max(1, Math.ceil(lessons.length / lessonsPerPage));
    const visibleLessons = lessons.slice((lessonPage - 1) * lessonsPerPage, lessonPage * lessonsPerPage);

    useEffect(() => {
        setLessonPage((page) => Math.min(page, lessonPageCount));
    }, [lessonPageCount]);

    const csrf = typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]')?.content || '' : '';

    useEffect(() => {
        if (pullRequestId.current && pullRequestEpoch.current !== null
            && progress?.workflow?.scene_return_status === 'awaiting_pull'
            && progress?.workflow?.scene_return_epoch > pullRequestEpoch.current) {
            pullRequestId.current = null;
            pullRequestEpoch.current = null;
            setPullStatus('');
            setError('Scene files are not complete yet. Check the local worker for missing scene IDs, then request Pull again.');
        }
    }, [progress]);

    const executeCommand = async (action) => {
        setError('');
        setIsControlLoading(true);
        try {
            const commandId = typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `cmd-${Date.now()}`;
            const response = await fetch(commandUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    command_id: commandId,
                    action,
                    expected_version: run.state_version,
                }),
            });

            if (!response.ok) {
                throw new Error(
                    response.status === 409
                        ? 'The run state changed concurrently. Refresh the page before retrying.'
                        : 'The pipeline command could not be processed.'
                );
            }

            // Immediately refresh progress
            const update = await fetch(progressUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            if (update.ok) {
                const data = await update.json();
                setProgress(data);
            }
        } catch (e) {
            setError(e.message || 'Pipeline command execution failed.');
        } finally {
            setIsControlLoading(false);
            setIsCancelling(false);
        }
    };

    const requestScenePull = async () => {
        if (pullStatus) return;
        if (!pullRequestId.current) pullRequestId.current = crypto.randomUUID();
        setPullStatus('Requesting…');
        setError('');
        try {
            const response = await fetch(scenePullUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                body: JSON.stringify({ request_id: pullRequestId.current }),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error?.message || 'Scene Pull could not be requested.');
            pullRequestEpoch.current = data.return_task_epoch;
            setPullStatus('Pull requested — waiting for your local worker');
        } catch (e) {
            setError(e.message);
            setPullStatus('');
        }
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: run.subject?.name || 'Subject', url: subjectUrl },
        { label: `Run #${run.id}` },
    ];

    // Determine current pipeline step
    let currentStep = 2; // extraction
    if (progress?.state === 'completed') currentStep = 5;
    else if (progress?.workflow?.phase === 'scene_generation') currentStep = 4;
    else if (progress?.workflow?.phase === 'lesson_structuring') currentStep = 3;
    const usesLocalWorker = run.execution_mode === 'local_worker_v1';
    const localSteps = [
        { id: 'upload', title: 'Material uploaded' },
        { id: 'prompts', title: 'Prepare prompts' },
        { id: 'manual', title: 'Generate scene files' },
        { id: 'transfer', title: 'Receive scene files' },
        { id: 'complete', title: progress?.output_scope === 'prompts_only' ? 'Prompts exported' : 'Files received' },
    ];
    if (usesLocalWorker) {
        currentStep = progress?.state === 'completed' ? 5 : progress?.state === 'awaiting_generation' ? 3
            : progress?.workflow?.task_kind === 'return_scenes' ? 4 : 2;
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Pipeline Run #${run.id} Progress — EduPlatform`} />

            <PageHeader
                title={`Pipeline Run #${run.id}`}
                eyebrow={run.subject?.name || 'Curriculum Ingestion'}
                description={usesLocalWorker ? 'Track prompt preparation, manual scene generation and file transfer. Review and publication follow separately.'
                    : 'Live progress tracking across multi-document parsing, concept structuring, and Three.js synthesis.'}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {scenePullUrl && progress?.state === 'awaiting_generation' && (
                            <button type="button" onClick={requestScenePull} disabled={Boolean(pullStatus)}
                                className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                                {pullStatus || 'Pull Generated Scenes'}
                            </button>
                        )}
                        {scenesUrl && (
                            <Button href={scenesUrl} variant="primary" size="sm">
                                Scene Workbench →
                            </Button>
                        )}
                        <a
                            href={`/v3/teacher/subject-runs/${run.id}/prompt-export`}
                            download={`run-${run.id}-prompts.json`}
                            className="px-3 py-1.5 text-xs font-semibold rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-[#121e1a] text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
                        >
                            Export Prompts (JSON)
                        </a>
                        <a
                            href={`/v3/teacher/subject-runs/${run.id}/prompt-export?format=text`}
                            download={`run-${run.id}-prompts.txt`}
                            className="px-3 py-1.5 text-xs font-semibold rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-[#121e1a] text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
                        >
                            Export Prompts (Text)
                        </a>
                    </div>
                }
            />

            {/* Workflow Rail */}
            <div className="mb-8 bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                <WorkflowRail currentStep={currentStep} status={progress?.state || 'running'} steps={usesLocalWorker ? localSteps : undefined} />
            </div>

            {/* Error / Alert banner */}
            {error && (
                <div role="alert" className="mb-6 p-4 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-800 text-amber-900 dark:text-amber-200 text-sm font-medium">
                    {error}
                </div>
            )}

            {failures.length > 0 && (
                <div role="alert" className="mb-6 p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-300 dark:border-rose-800 text-rose-900 dark:text-rose-200 text-sm space-y-1">
                    <strong className="block font-bold">Documents requiring attention:</strong>
                    {failures.map((f, i) => (
                        <p key={i} className="text-xs">
                            {f.code}: {f.message || 'Verification issue. Please inspect source file formatting.'}
                        </p>
                    ))}
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
                {/* Left: Progress Monitor & Controls (8 cols) */}
                <div className="lg:col-span-8 space-y-8">
                    {/* Local Worker Architecture Banner */}
                    {run.execution_mode === 'local_worker_v1' && (
                        <div className="p-4 rounded-2xl bg-teal-50/60 dark:bg-teal-950/30 border border-teal-200 dark:border-teal-900/60 flex items-center justify-between gap-4">
                            <div className="flex items-center gap-3">
                                <span className="w-9 h-9 rounded-xl bg-teal-100 dark:bg-teal-900/60 text-teal-800 dark:text-teal-200 flex items-center justify-center font-bold text-sm">
                                    ⚙
                                </span>
                                <div>
                                    <div className="flex items-center gap-2">
                                        <h4 className="text-sm font-bold text-teal-950 dark:text-teal-100">
                                            Managed Outbound Worker Execution
                                        </h4>
                                        <span className="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-teal-200 dark:bg-teal-800 text-teal-900 dark:text-teal-100">
                                            local_worker_v1
                                        </span>
                                    </div>
                                    <p className="text-xs text-teal-800/80 dark:text-teal-300/80 mt-0.5">
                                        Tasks are claimed through outbound HTTPS by the enrolled processing device. Provider credentials remain private to that machine.
                                    </p>
                                </div>
                            </div>
                            <div className="text-right flex-shrink-0">
                                <span className="text-xs font-semibold text-slate-500 dark:text-slate-400 block">
                                    Delivery Scope
                                </span>
                                <span className="text-xs font-bold text-slate-800 dark:text-slate-200 capitalize">
                                    {(run.output_scope || 'prompts_only').replace(/_/g, ' ')}
                                </span>
                            </div>
                        </div>
                    )}

                    {/* Live Progress Card */}
                    <LessonProgress
                        completedCount={saved}
                        totalCount={total}
                        phase={progress?.workflow?.phase || ''}
                        status={progress?.state || 'running'}
                        pollIntervalSeconds={pollSeconds}
                        isPolling={isActive}
                    />

                    {/* Run Controls Toolbar */}
                    <div className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                    Pipeline Execution Controls
                                </h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    {pendingControl
                                        ? 'A pipeline command is currently being dispatched...'
                                        : isTerminal
                                        ? `Execution concluded as ${progress?.state}`
                                        : 'Active pipeline running in background worker'}
                                </p>
                            </div>

                            <div className="flex items-center gap-2 flex-wrap">
                                {progress?.retry_available && !pendingControl && (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        onClick={() => executeCommand('retry')}
                                        isLoading={isControlLoading}
                                    >
                                        Retry Run
                                    </Button>
                                )}

                                {isActive && !pendingControl && progress?.state === 'running' && (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        onClick={() => executeCommand('pause')}
                                        isLoading={isControlLoading}
                                    >
                                        Pause
                                    </Button>
                                )}

                                {isActive && !pendingControl && progress?.state === 'paused' && (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        onClick={() => executeCommand('resume')}
                                        isLoading={isControlLoading}
                                    >
                                        Resume
                                    </Button>
                                )}

                                {isActive && !pendingControl && ['running', 'paused', 'staging', 'queued', 'awaiting_generation'].includes(progress?.state) && (
                                    <Button
                                        type="button"
                                        variant="danger"
                                        size="sm"
                                        onClick={() => setIsCancelling(true)}
                                        isLoading={isControlLoading}
                                    >
                                        Cancel Run
                                    </Button>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Completed Lessons Stream */}
                    <section className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 sm:p-8 shadow-sm">
                        <h3 className="text-lg font-bold text-slate-900 dark:text-slate-100 mb-1">
                            Persisted Lessons ({lessons.length})
                        </h3>
                        <p className="text-xs text-slate-500 dark:text-slate-400 mb-6">
                            Lessons are listed in PDF order, ten at a time.
                        </p>

                        {lessons.length > 0 ? (
                            <>
                                <ol start={(lessonPage - 1) * lessonsPerPage + 1} className="space-y-3">
                                    {visibleLessons.map((lesson, index) => (
                                        <li key={lesson.id} className="rounded-2xl border border-slate-200 dark:border-[#263932] bg-slate-50 dark:bg-[#182823] p-4">
                                            <div className="flex items-start gap-3">
                                                <span className="w-8 h-8 shrink-0 rounded-xl bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 flex items-center justify-center font-bold text-xs">
                                                    {(lessonPage - 1) * lessonsPerPage + index + 1}
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <h4 className="font-semibold text-sm leading-5 text-slate-900 dark:text-slate-100">
                                                        {lesson.title || `Lesson ${lesson.ordinal}`}
                                                    </h4>
                                                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                                        PDF part {lesson.pdf_ordinal} · Lesson {lesson.ordinal}
                                                    </p>
                                                </div>
                                                <span aria-label="Persisted" className="text-emerald-700 dark:text-emerald-400 font-bold">✓</span>
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                                {lessonPageCount > 1 && (
                                    <nav aria-label="Persisted lessons pages" className="mt-5 flex items-center justify-between gap-3">
                                        <Button type="button" variant="secondary" size="sm" disabled={lessonPage <= 1} onClick={() => setLessonPage((page) => Math.max(1, page - 1))}>
                                            Previous
                                        </Button>
                                        <span aria-live="polite" className="text-xs text-slate-500 dark:text-slate-400">
                                            Page {lessonPage} of {lessonPageCount} · {Math.min(lessons.length, (lessonPage - 1) * lessonsPerPage + 1)}–{Math.min(lessons.length, lessonPage * lessonsPerPage)} of {lessons.length}
                                        </span>
                                        <Button type="button" variant="secondary" size="sm" disabled={lessonPage >= lessonPageCount} onClick={() => setLessonPage((page) => Math.min(lessonPageCount, page + 1))}>
                                            Next
                                        </Button>
                                    </nav>
                                )}
                            </>
                        ) : (
                            <p className="text-sm text-slate-500 dark:text-slate-400 py-6 text-center">
                                Lessons will populate here as extraction and structuring milestones complete.
                            </p>
                        )}
                    </section>
                </div>

                {/* Right: PDF Source Items (4 cols) */}
                <div className="lg:col-span-4">
                    <section className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                        <h3 className="text-lg font-bold text-slate-900 dark:text-slate-100 mb-1">
                            Source Documents ({items.length})
                        </h3>
                        <p className="text-xs text-slate-500 dark:text-slate-400 mb-4">
                            Per-document status in sequential order.
                        </p>

                        {items.length > 0 ? (
                            <div className="space-y-3">
                                {items.map((item) => {
                                    const meta = run.items?.find((x) => x.id === item.id) || {};
                                    const title = meta.display_title || meta.original_filename || `Document ${item.ordinal}`;
                                    const savedCount = Number(item.counts?.saved_lessons || 0);

                                    return (
                                        <div
                                            key={item.id}
                                            className="p-3.5 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/60 dark:border-[#263932] flex items-center justify-between gap-3"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="w-5 h-5 rounded-lg bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 font-bold text-[11px] flex items-center justify-center">
                                                        {item.ordinal}
                                                    </span>
                                                    <span className="font-semibold text-xs text-slate-900 dark:text-slate-100 truncate">
                                                        {title}
                                                    </span>
                                                </div>
                                                <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
                                                    {savedCount} lessons saved
                                                </p>
                                            </div>

                                            <StatusBadge status={item.state || 'pending'} />
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <p className="text-sm text-slate-500 dark:text-slate-400 py-4 text-center">
                                Source PDF items will display once accepted.
                            </p>
                        )}
                    </section>
                </div>
            </div>

            {/* Cancel Confirmation Dialog */}
            <ConfirmDialog
                isOpen={isCancelling}
                onClose={() => setIsCancelling(false)}
                onConfirm={() => executeCommand('cancel')}
                title="Cancel Pipeline Run?"
                description="Are you sure you want to cancel this curriculum run? Processing of remaining documents will stop immediately."
                confirmText="Yes, cancel run"
                cancelText="Keep running"
                variant="danger"
                isLoading={isControlLoading}
            />
        </AppLayout>
    );
}
