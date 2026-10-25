import React from 'react';

const DEFAULT_STEPS = [
    { id: 'intake', number: 1, title: 'PDF Intake', description: 'Upload & validate document' },
    { id: 'extraction', number: 2, title: 'Extraction', description: 'Structured text & concepts' },
    { id: 'lessons', number: 3, title: 'Lesson Mapping', description: 'Curriculum & bilingual sections' },
    { id: 'scenes', number: 4, title: '3D Scenes', description: 'Interactive concept generation' },
    { id: 'review', number: 5, title: 'Review & Publish', description: 'Teacher approval & release' },
];

export default function WorkflowRail({ currentStep = 1, status = 'active', steps = DEFAULT_STEPS, className = '' }) {
    // Determine active index (0-based)
    let activeIndex = 0;
    if (typeof currentStep === 'number') {
        activeIndex = Math.max(0, Math.min(steps.length - 1, currentStep - 1));
    } else if (typeof currentStep === 'string') {
        const found = steps.findIndex((s) => s.id === currentStep.toLowerCase());
        if (found !== -1) activeIndex = found;
    }

    const isFailed = status === 'failed' || status === 'error';

    return (
        <nav aria-label="Pipeline progress" className={`w-full py-4 px-2 ${className}`}>
            <ol className="grid grid-cols-2 md:grid-cols-5 gap-3 lg:gap-4">
                {steps.map((step, idx) => {
                    const isCompleted = idx < activeIndex || (idx === activeIndex && status === 'completed');
                    const isCurrent = idx === activeIndex && status !== 'completed';
                    const isUpcoming = idx > activeIndex;

                    return (
                        <li
                            key={step.id || idx}
                            className={`relative flex flex-col p-3 rounded-2xl border transition-all ${
                                isCurrent
                                    ? isFailed
                                        ? 'bg-rose-50/80 border-rose-300 dark:bg-rose-950/40 dark:border-rose-900 shadow-sm'
                                        : 'bg-teal-50/80 border-teal-300 dark:bg-teal-950/40 dark:border-teal-800 shadow-sm'
                                    : isCompleted
                                    ? 'bg-emerald-50/40 border-emerald-200 dark:bg-emerald-950/20 dark:border-emerald-900/60'
                                    : 'bg-white/60 border-slate-200 dark:bg-[#121e1a]/60 dark:border-[#263932] opacity-75'
                            }`}
                            aria-current={isCurrent ? 'step' : undefined}
                        >
                            <div className="flex items-center gap-2 mb-1.5">
                                <span
                                    className={`inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold ${
                                        isCompleted
                                            ? 'bg-emerald-600 text-white dark:bg-emerald-500 dark:text-slate-950'
                                            : isCurrent
                                            ? isFailed
                                                ? 'bg-rose-600 text-white dark:bg-rose-500'
                                                : 'bg-teal-700 text-white dark:bg-teal-400 dark:text-slate-950 animate-pulse'
                                            : 'bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-400'
                                    }`}
                                >
                                    {isCompleted ? (
                                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3} aria-hidden="true">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                    ) : (
                                        step.number || idx + 1
                                    )}
                                </span>
                                <span className={`text-xs font-semibold uppercase tracking-wider ${
                                    isCurrent ? (isFailed ? 'text-rose-700 dark:text-rose-400' : 'text-teal-800 dark:text-teal-300') : isCompleted ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400'
                                }`}>
                                    Step {step.number || idx + 1}
                                </span>
                            </div>

                            <div className="font-semibold text-sm text-slate-900 dark:text-slate-100">
                                {step.title}
                            </div>
                            {step.description && (
                                <div className="text-xs text-slate-600 dark:text-slate-400 mt-0.5 line-clamp-1">
                                    {step.description}
                                </div>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
