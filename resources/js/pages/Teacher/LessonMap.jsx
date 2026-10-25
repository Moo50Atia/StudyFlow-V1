import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import WorkflowRail from '../../components/WorkflowRail';

export default function LessonMap({ map = {}, case: caseInfo, workItems = [], pending = false }) {
    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: `Case #${caseInfo?.id}`, url: `/v3/teacher/cases/${caseInfo?.id}` },
        { label: 'Curriculum Lesson Map' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Lesson Map: Case #${caseInfo?.id} — EduPlatform`} />

            <PageHeader
                title={`Curriculum Lesson Map (Case #${caseInfo?.id})`}
                eyebrow="Extracted Lesson Hierarchy"
                description="Review structured lessons and bilingual concept sections before 3D scene review and publication."
                actions={
                    <Button href={`/v3/teacher/cases/${caseInfo?.id}`} variant="secondary" size="sm">
                        ← Back to Case
                    </Button>
                }
            />

            <div className="mb-8 bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                <WorkflowRail currentStep={3} status={pending ? 'pending' : 'active'} />
            </div>

            <div className="max-w-4xl space-y-6">
                {workItems.length > 0 ? (
                    <ol className="space-y-6">
                        {workItems.map((node, i) => (
                            <li
                                key={node.work_node_ref || i}
                                className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 sm:p-8 shadow-sm"
                            >
                                <div className="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800">
                                    <div>
                                        <span className="text-xs font-bold uppercase tracking-wider text-teal-700 dark:text-teal-400">
                                            Lesson Node {node.ordinal || i + 1}
                                        </span>
                                        <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100 mt-0.5">
                                            {node.path || `Lesson ${i + 1}`}
                                        </h2>
                                    </div>
                                    <span className="px-3 py-1 rounded-full text-xs font-semibold bg-teal-50 dark:bg-teal-950/60 text-teal-800 dark:text-teal-300">
                                        {node.section_count || 0} Saved Sections
                                    </span>
                                </div>

                                <ul className="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                                    {(node.sections || []).map((section, j) => (
                                        <li
                                            key={section.id || j}
                                            className="py-3 flex items-center justify-between gap-3 text-sm"
                                        >
                                            <span className="font-medium text-slate-800 dark:text-slate-200">
                                                {section.identity || `Section ${j + 1}`}
                                            </span>
                                            {section.id && (
                                                <Button
                                                    href={`/v3/teacher/cases/${caseInfo?.id}/sections/${section.id}`}
                                                    variant="ghost"
                                                    size="sm"
                                                >
                                                    Open Lesson Section →
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ol>
                ) : (
                    <div className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-10 text-center text-slate-500">
                        {pending ? 'No lesson map has been saved yet. Check case progress for the current processing status.' : 'No lesson nodes discovered in this case yet.'}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
