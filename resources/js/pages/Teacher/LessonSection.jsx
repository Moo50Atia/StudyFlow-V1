import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';

const FIELDS_METADATA = [
    { key: 'core_understanding', label: 'Core Educational Understanding', isArabic: false },
    { key: 'visual_first_explanation', label: 'Visual-First Pedagogical Narrative', isArabic: false },
    { key: 'visual_scene_script', label: 'Interactive 3D Scene Script', isArabic: false, isCode: true },
    { key: 'dynamic_view_blueprint', label: 'Dynamic View Simulation Blueprint', isArabic: false, isCode: true },
    { key: 'visual_learning_outcome', label: 'Expected Cognitive Learning Outcome', isArabic: false },
    { key: 'concept_check', label: 'Formative Concept Check Question', isArabic: false },
    { key: 'conceptual_summary', label: 'Summary & Key Takeaways', isArabic: false },
    { key: 'colloquial_egyptian_explanation', label: 'الشرح بالعامية المصرية (Pedagogical Insight)', isArabic: true },
    { key: 'laws_if_any', label: 'القوانين والمعادلات الرياضية (Formulas & Laws)', isArabic: true },
    { key: 'real_life_example', label: 'مثال واقعي وتطبيقي (Real-Life Analogy)', isArabic: true },
];

export default function LessonSection({ section = {}, case: caseInfo }) {
    const data = section.fields ? section : { fields: section };
    const fields = data.fields || {};
    const title = fields.section_identity || 'Saved Lesson Section';

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: `Case #${caseInfo?.id}`, url: `/v3/teacher/cases/${caseInfo?.id}` },
        { label: 'Lesson Map', url: `/v3/teacher/cases/${caseInfo?.id}/lesson-map` },
        { label: title },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${title} — EduPlatform`} />

            <PageHeader
                title={title}
                eyebrow={`Saved Lesson • ${data.ordinal_in_work || 'Section'}`}
                description="Inspect structured bilingual pedagogical fields and 3D simulation blueprints extracted from source material."
                actions={
                    <Button href={`/v3/teacher/cases/${caseInfo?.id}/lesson-map`} variant="secondary" size="sm">
                        ← Back to Lesson Map
                    </Button>
                }
            />

            <div className="max-w-4xl space-y-6">
                {FIELDS_METADATA.map(({ key, label, isArabic, isCode }) => {
                    const value = fields[key];
                    if (!value) return null;

                    return (
                        <article
                            key={key}
                            className={`p-6 sm:p-8 rounded-3xl border shadow-sm ${
                                isArabic
                                    ? 'bg-amber-50/60 dark:bg-amber-950/20 border-amber-200 dark:border-amber-900/40'
                                    : isCode
                                    ? 'bg-slate-900 border-slate-800 text-teal-300'
                                    : 'bg-white dark:bg-[#121e1a] border-slate-200 dark:border-[#263932]'
                            }`}
                        >
                            <h2
                                className={`text-xs font-bold uppercase tracking-wider mb-3 ${
                                    isArabic
                                        ? 'text-amber-900 dark:text-amber-300'
                                        : isCode
                                        ? 'text-slate-400'
                                        : 'text-teal-700 dark:text-teal-400'
                                }`}
                            >
                                {label}
                            </h2>

                            <div
                                dir={isArabic ? 'rtl' : isCode ? 'ltr' : 'auto'}
                                lang={isArabic ? 'ar' : undefined}
                                className={`whitespace-pre-line leading-relaxed ${
                                    isArabic
                                        ? 'text-base sm:text-lg text-slate-900 dark:text-amber-100/90 font-normal'
                                        : isCode
                                        ? 'font-mono text-xs sm:text-sm text-teal-300 overflow-x-auto'
                                        : 'text-base text-slate-800 dark:text-slate-200'
                                }`}
                                style={isArabic ? { fontFamily: "'Noto Sans Arabic', 'Cairo', system-ui, sans-serif" } : {}}
                            >
                                {value}
                            </div>
                        </article>
                    );
                })}
            </div>
        </AppLayout>
    );
}
