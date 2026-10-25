import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../components/AppLayout';
import PageHeader from '../components/PageHeader';
import Button from '../components/Button';
import LessonContent from '../components/LessonContent';
import EmptyState from '../components/EmptyState';

export default function LearningContentShow({
    screen,
    lecture,
    section,
    nextLecture,
    nextSection,
}) {
    const isLecture = Boolean(lecture && !section);
    const content = section || lecture || {};
    const title = content.title || content.section_identity || 'Curriculum Content';

    const subject = section?.lecture?.subject || lecture?.subject;
    const parentLecture = section?.lecture || lecture;

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        ...(subject ? [{ label: subject.name, url: `/subjects/${subject.id}` }] : []),
        ...(isLecture
            ? [{ label: title }]
            : [
                  ...(parentLecture ? [{ label: parentLecture.title, url: `/lectures/${parentLecture.id}` }] : []),
                  { label: title },
              ]),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${title} — EduPlatform`} />

            {/* LECTURE OVERVIEW MODE */}
            {isLecture && (
                <div className="space-y-8 max-w-4xl mx-auto">
                    <PageHeader
                        title={title}
                        eyebrow={subject?.name || 'Lecture Overview'}
                        description={content.description || content.summary || 'Curriculum module outline and concept sections.'}
                        actions={
                            nextLecture ? (
                                <Button href={`/lectures/${nextLecture.id}`} variant="primary" size="sm">
                                    Next Lecture →
                                </Button>
                            ) : null
                        }
                    />

                    {/* Section Sequence Cards */}
                    <section className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 sm:p-8 shadow-sm">
                        <div className="flex items-center justify-between mb-6">
                            <h2 className="text-xl font-bold text-slate-900 dark:text-slate-100">
                                Concept Sections ({lecture.sections?.length || 0})
                            </h2>
                            <span className="text-xs text-slate-500 dark:text-slate-400">
                                Sequential reading order
                            </span>
                        </div>

                        {lecture.sections?.length > 0 ? (
                            <ol className="divide-y divide-slate-100 dark:divide-slate-800 space-y-3">
                                {lecture.sections.map((sec, idx) => (
                                    <li key={sec.id} className="pt-4 first:pt-0 flex items-center justify-between gap-4">
                                        <div className="flex items-center gap-3.5 min-w-0">
                                            <span className="w-8 h-8 rounded-xl bg-teal-100 dark:bg-teal-950/60 text-teal-800 dark:text-teal-300 font-bold text-xs flex items-center justify-center flex-shrink-0">
                                                {idx + 1}
                                            </span>
                                            <div className="min-w-0">
                                                <Link
                                                    href={`/sections/${sec.id}`}
                                                    className="font-bold text-base text-slate-900 dark:text-slate-100 hover:text-teal-700 dark:hover:text-teal-400 transition-colors block truncate"
                                                >
                                                    {sec.title}
                                                </Link>
                                                {sec.quick_summary && (
                                                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5 truncate max-w-md">
                                                        {sec.quick_summary}
                                                    </p>
                                                )}
                                            </div>
                                        </div>

                                        <Button href={`/sections/${sec.id}`} variant="ghost" size="sm">
                                            Read Section →
                                        </Button>
                                    </li>
                                ))}
                            </ol>
                        ) : (
                            <EmptyState
                                title="No sections defined in this lecture"
                                description="Course sections will appear once published by faculty."
                            />
                        )}
                    </section>

                    {/* Next Lecture Footer */}
                    {nextLecture && (
                        <div className="flex justify-end pt-4">
                            <Button href={`/lectures/${nextLecture.id}`} variant="primary">
                                Continue to: {nextLecture.title} →
                            </Button>
                        </div>
                    )}
                </div>
            )}

            {/* INDIVIDUAL SECTION READING MODE */}
            {!isLecture && (
                <div className="max-w-4xl mx-auto">
                    <LessonContent
                        section={{
                            title: section.title,
                            concept: section.core_concept,
                            egyptian_explain: section.egyptian_explain,
                            formula: section.formulas,
                            example: section.real_life,
                            order_index: section.order_index,
                        }}
                        sceneUrl={section.dynamic_view_link || null}
                        sceneTitle={`Interactive Simulation: ${section.title}`}
                        prevSection={null}
                        nextSection={
                            nextSection
                                ? { url: `/sections/${nextSection.id}`, title: nextSection.title }
                                : nextLecture
                                ? { url: `/lectures/${nextLecture.id}`, title: `Next Lecture: ${nextLecture.title}` }
                                : null
                        }
                    />
                </div>
            )}
        </AppLayout>
    );
}
