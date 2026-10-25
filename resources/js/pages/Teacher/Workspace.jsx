import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import WorkflowRail from '../../components/WorkflowRail';
import ErrorSummary from '../../components/ErrorSummary';

export default function Workspace({ teacher }) {
    const form = useForm({
        pdf: null,
    });

    const handleUpload = (e) => {
        e.preventDefault();
        form.post('/v3/teacher/pdf-uploads', {
            forceFormData: true,
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Educator Studio' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Educator Studio — EduPlatform" />

            <PageHeader
                title={`Welcome back, ${teacher?.name || 'Educator'}`}
                eyebrow="Pedagogical Workspace"
                description="Ingest curriculum PDFs, supervise 3D scene generation, review bilingual lessons, and publish to students."
                actions={
                    <div className="flex items-center gap-2">
                        <Button href="/v3/teacher/pdf-uploads" variant="secondary" size="sm">
                            Past Uploads
                        </Button>
                        <Button href="/subjects" variant="primary" size="sm">
                            + New Curriculum Run
                        </Button>
                    </div>
                }
            />

            {/* Visual Workflow Rail Banner */}
            <div className="mb-8 bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                <div className="flex items-center justify-between mb-2">
                    <span className="text-xs font-bold uppercase tracking-wider text-teal-700 dark:text-teal-400">
                        The Sovereign Pedagogical Rail
                    </span>
                    <span className="text-xs text-slate-500 dark:text-slate-400 font-medium">
                        Standard Ingestion Pipeline
                    </span>
                </div>
                <WorkflowRail currentStep={1} status="active" />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
                {/* PDF Ingestion Card (7 cols) */}
                <div className="lg:col-span-7">
                    <section className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 sm:p-8 shadow-sm h-full flex flex-col justify-between">
                        <div>
                            <div className="flex items-center gap-2 mb-2">
                                <span className="w-2.5 h-2.5 rounded-full bg-teal-500 animate-pulse" />
                                <h2 className="text-xl font-bold text-slate-900 dark:text-slate-100">
                                    Quick Courseware Upload
                                </h2>
                            </div>
                            <p className="text-sm text-slate-600 dark:text-slate-400 mb-6 leading-relaxed">
                                Upload a course syllabus, chapter notes, or textbook excerpt. The document is pre-screened and validated for structural fidelity before case processing begins.
                            </p>

                            <ErrorSummary errors={form.errors} className="mb-6" />

                            <form onSubmit={handleUpload} className="space-y-4">
                                <div className="border-2 border-dashed border-slate-300 dark:border-slate-700 rounded-2xl p-6 text-center hover:border-teal-500 dark:hover:border-teal-400 transition-colors">
                                    <svg className="w-10 h-10 text-teal-600 dark:text-teal-400 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                    </svg>
                                    <label className="cursor-pointer">
                                        <span className="text-sm font-semibold text-teal-700 dark:text-teal-400 hover:underline">
                                            Choose PDF document
                                        </span>
                                        <input
                                            type="file"
                                            accept=".pdf,application/pdf"
                                            required
                                            onChange={(e) => form.setData('pdf', e.target.files?.[0] || null)}
                                            className="sr-only"
                                        />
                                    </label>
                                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                        PDF files up to 50MB supported
                                    </p>
                                    {form.data.pdf && (
                                        <p className="mt-3 text-xs font-semibold text-teal-800 dark:text-teal-300 bg-teal-50 dark:bg-teal-950/60 p-2 rounded-xl border border-teal-200 dark:border-teal-800">
                                            Selected: {form.data.pdf.name} ({(form.data.pdf.size / 1024 / 1024).toFixed(2)} MB)
                                        </p>
                                    )}
                                </div>

                                <div className="pt-2">
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        className="w-full justify-center"
                                        isLoading={form.processing}
                                        disabled={!form.data.pdf}
                                    >
                                        Upload & Validate Document
                                    </Button>
                                </div>
                            </form>
                        </div>

                        <div className="mt-6 pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs text-slate-500">
                            <span>Sovereign Storage Protocol</span>
                            <Link href="/v3/teacher/pdf-uploads" className="text-teal-700 dark:text-teal-400 hover:underline font-semibold">
                                View Upload History →
                            </Link>
                        </div>
                    </section>
                </div>

                {/* Studio Tools & Fast Paths (5 cols) */}
                <div className="lg:col-span-5 space-y-6">
                    {/* Multi-PDF Run CTA Card */}
                    <div className="p-6 rounded-3xl bg-[#121e1a] border border-[#263932] text-white shadow-xl">
                        <span className="text-xs font-bold uppercase tracking-wider text-teal-300">
                            Multi-Document Pipeline
                        </span>
                        <h3 className="text-lg font-bold text-white mt-1 mb-2">
                            Batch Subject Ingestion Run
                        </h3>
                        <p className="text-xs text-teal-100/80 leading-relaxed mb-4">
                            Queue an entire course sequence with multi-PDF ordering, parallel concept extraction, and automated Three.js scene generation.
                        </p>
                        <Button href="/subjects" variant="primary" size="sm" className="bg-teal-400 text-slate-950 hover:bg-teal-300">
                            Launch Subject Run →
                        </Button>
                    </div>

                    {/* Manual Curriculum Authoring Card */}
                    <div className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                        <h3 className="text-base font-bold text-slate-900 dark:text-slate-100 mb-1">
                            Manual Teaching Tools
                        </h3>
                        <p className="text-xs text-slate-500 dark:text-slate-400 mb-4">
                            Author custom curriculum lectures and bilingual sections without PDF ingestion.
                        </p>

                        <div className="space-y-2">
                            <Link
                                href="/teacher-study-flow/start"
                                className="p-3 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/60 dark:border-[#263932] flex items-center justify-between hover:border-teal-500/50 transition-colors text-sm font-semibold text-slate-800 dark:text-slate-200"
                            >
                                <span>Curriculum Flow Wizard</span>
                                <span className="text-teal-700 dark:text-teal-400">Launch →</span>
                            </Link>

                            <Link
                                href="/v3/teacher/reviews"
                                className="p-3 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/60 dark:border-[#263932] flex items-center justify-between hover:border-teal-500/50 transition-colors text-sm font-semibold text-slate-800 dark:text-slate-200"
                            >
                                <span>Scene Review & Qualification</span>
                                <span className="text-teal-700 dark:text-teal-400">Review →</span>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
