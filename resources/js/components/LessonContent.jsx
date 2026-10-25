import React from 'react';
import Button from './Button';

export default function LessonContent({
    section = {},
    sceneUrl = null,
    sceneTitle = null,
    prevSection = null,
    nextSection = null,
    className = '',
}) {
    const title = section.title || section.name || 'Lesson Section';
    const concept = section.concept || section.content || section.body || '';
    const egyptianExplain = section.egyptian_explain || section.arabic_explanation || section.explanation_ar || '';
    const formula = section.formula || section.latex || section.code || '';
    const example = section.example || section.examples || '';

    return (
        <article className={`space-y-8 max-w-4xl mx-auto ${className}`}>
            {/* Section Header */}
            <header className="border-b border-slate-200 dark:border-slate-800 pb-5">
                <div className="flex items-center gap-2 mb-2 text-xs font-bold uppercase tracking-wider text-teal-700 dark:text-teal-400">
                    <span>Curriculum Section</span>
                    {section.order_index !== undefined && <span>• Step {section.order_index + 1}</span>}
                </div>
                <h1 className="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-slate-100 tracking-tight">
                    {title}
                </h1>
            </header>

            {/* Core Concept (English / Universal) */}
            {concept && (
                <section aria-labelledby="heading-concept" className="prose dark:prose-invert max-w-none">
                    <h2 id="heading-concept" className="text-lg font-bold text-slate-900 dark:text-slate-100 mb-3 flex items-center gap-2">
                        <span className="w-2.5 h-2.5 rounded-full bg-teal-600 dark:bg-teal-400" />
                        Core Concept
                    </h2>
                    <div className="text-base sm:text-lg text-slate-700 dark:text-slate-200 leading-relaxed max-w-[75ch] whitespace-pre-line">
                        {concept}
                    </div>
                </section>
            )}

            {/* Egyptian Arabic Explanation (Bilingual Pedagogical Callout) */}
            {egyptianExplain && (
                <section
                    aria-labelledby="heading-arabic"
                    className="p-5 sm:p-6 rounded-2xl bg-amber-50/70 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-900/40"
                >
                    <div className="flex items-center justify-between gap-2 mb-3">
                        <span className="text-xs font-bold uppercase tracking-wider text-amber-800 dark:text-amber-300">
                            شرح بالعامية المصرية (Pedagogical Insight)
                        </span>
                        <span className="text-xs text-amber-700 dark:text-amber-400 font-medium">
                            Egyptian Arabic
                        </span>
                    </div>
                    <div
                        dir="rtl"
                        lang="ar"
                        className="text-base sm:text-lg text-slate-800 dark:text-amber-100/90 leading-loose font-normal font-sans"
                        style={{ fontFamily: "'Noto Sans Arabic', 'Cairo', system-ui, sans-serif" }}
                    >
                        {egyptianExplain}
                    </div>
                </section>
            )}

            {/* Formulas, Math & Code */}
            {formula && (
                <section aria-labelledby="heading-formula">
                    <h2 id="heading-formula" className="text-sm font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-2">
                        Mathematical Formula / Code Structure
                    </h2>
                    <div
                        dir="ltr"
                        className="bg-slate-900 dark:bg-black/90 text-teal-300 p-4 sm:p-5 rounded-2xl font-mono text-sm sm:text-base overflow-x-auto shadow-inner border border-slate-800"
                    >
                        <code>{formula}</code>
                    </div>
                </section>
            )}

            {/* Example Section */}
            {example && (
                <section aria-labelledby="heading-example" className="p-5 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200 dark:border-[#263932]">
                    <h2 id="heading-example" className="text-base font-bold text-slate-900 dark:text-slate-100 mb-2">
                        Worked Example
                    </h2>
                    <div className="text-sm sm:text-base text-slate-700 dark:text-slate-300 leading-relaxed whitespace-pre-line">
                        {example}
                    </div>
                </section>
            )}

            {/* Interactive 3D Scene Callout */}
            {sceneUrl && (
                <section aria-label="Interactive visual simulation" className="p-6 rounded-3xl bg-gradient-to-br from-teal-900 to-slate-900 text-white shadow-lg flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <span className="text-xs font-bold uppercase tracking-wider text-teal-300">
                            Interactive 3D Simulation Available
                        </span>
                        <h3 className="text-xl font-bold text-white mt-1">
                            {sceneTitle || 'Explore this concept in 3D'}
                        </h3>
                        <p className="text-sm text-teal-100 mt-1 max-w-lg">
                            Manipulate variables in real-time to build deep spatial intuition.
                        </p>
                    </div>

                    <Button
                        href={sceneUrl}
                        variant="primary"
                        className="bg-teal-400 text-slate-950 hover:bg-teal-300 focus-visible:outline-white self-start sm:self-auto"
                    >
                        Launch Interactive Scene →
                    </Button>
                </section>
            )}

            {/* Stepper Navigation */}
            {(prevSection || nextSection) && (
                <nav aria-label="Section navigation" className="pt-6 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between gap-4">
                    {prevSection ? (
                        <Button href={prevSection.url || `#`} variant="secondary">
                            ← Previous Section
                        </Button>
                    ) : <span />}

                    {nextSection && (
                        <Button href={nextSection.url || `#`} variant="primary">
                            Next Section →
                        </Button>
                    )}
                </nav>
            )}
        </article>
    );
}
