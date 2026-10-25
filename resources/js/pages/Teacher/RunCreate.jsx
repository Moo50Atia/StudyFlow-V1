import React, { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import FormField from '../../components/FormField';
import WorkflowRail from '../../components/WorkflowRail';
import ErrorSummary from '../../components/ErrorSummary';

export default function RunCreate({ subject, errors = {} }) {
    const [files, setFiles] = useState([]);
    const [titles, setTitles] = useState([]);

    const form = useForm({
        submission_key: typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `run-${Date.now()}`,
        files: [],
        titles: [],
        mode: 'export_manual',
        qualification_mode: 'real_jev',
        execution_mode: 'local_worker_v1',
        output_scope: 'prompts_and_scenes',
    });

    const handleFileChange = (e) => {
        const next = Array.from(e.target.files || []).slice(0, 20);
        setFiles(next);
        const autoTitles = next.map((f) => f.name.replace(/\.pdf$/i, ''));
        setTitles(autoTitles);
        form.setData((prev) => ({
            ...prev,
            files: next,
            titles: autoTitles,
        }));
    };

    const handleTitleChange = (idx, value) => {
        const next = [...titles];
        next[idx] = value;
        setTitles(next);
        form.setData('titles', next);
    };

    const handleRemoveFile = (idx) => {
        const nextFiles = files.filter((_, i) => i !== idx);
        const nextTitles = titles.filter((_, i) => i !== idx);
        setFiles(nextFiles);
        setTitles(nextTitles);
        form.setData((prev) => ({
            ...prev,
            files: nextFiles,
            titles: nextTitles,
        }));
    };

    const submit = (e) => {
        e.preventDefault();
        form.post(`/v3/teacher/subjects/${subject.id}/runs`, {
            forceFormData: true,
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: subject.name, url: `/subjects/${subject.id}` },
        { label: 'Create Curriculum Run' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`New Ingestion Run: ${subject.name} — EduPlatform`} />

            <PageHeader
                title={`Curriculum Ingestion Run: ${subject.name}`}
                eyebrow="Step 1: Multi-Document Intake"
                description="Upload up to 20 chapter or lecture PDFs. The sovereign pipeline extracts concepts, creates bilingual sections, and initiates 3D scene generation."
                actions={
                    <Button href={`/subjects/${subject.id}`} variant="secondary" size="sm">
                        ← Back to Subject
                    </Button>
                }
            />

            <div className="mb-8 bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                <WorkflowRail currentStep={1} status="active" />
            </div>

            <div className="max-w-3xl bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                <ErrorSummary errors={errors} className="mb-6" />

                <form onSubmit={submit} className="space-y-6">
                    {/* File Drop / Selection Area */}
                    <div className="border-2 border-dashed border-slate-300 dark:border-slate-700 rounded-2xl p-6 text-center hover:border-teal-500 transition-colors">
                        <svg className="w-10 h-10 text-teal-600 dark:text-teal-400 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        <label className="cursor-pointer">
                            <span className="text-sm font-semibold text-teal-700 dark:text-teal-400 hover:underline">
                                Choose PDF courseware files
                            </span>
                            <input
                                type="file"
                                accept="application/pdf,.pdf"
                                multiple
                                required
                                onChange={handleFileChange}
                                className="sr-only"
                            />
                        </label>
                        <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">
                            Select one or more PDF files (maximum 20 per run)
                        </p>
                    </div>

                    {/* Ordered List of Chosen Files */}
                    {files.length > 0 && (
                        <div className="space-y-3">
                            <h3 className="text-sm font-bold uppercase tracking-wider text-slate-800 dark:text-slate-200">
                                Sequential Lesson Ordering ({files.length} documents)
                            </h3>
                            <div className="space-y-3">
                                {files.map((file, idx) => (
                                    <div
                                        key={`${file.name}-${idx}`}
                                        className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/80 dark:border-[#263932] flex items-start gap-3"
                                    >
                                        <span className="w-8 h-8 rounded-xl bg-teal-100 dark:bg-teal-950/60 text-teal-800 dark:text-teal-300 flex items-center justify-center font-bold text-xs flex-shrink-0 mt-1">
                                            {idx + 1}
                                        </span>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-xs font-mono text-slate-500 dark:text-slate-400 truncate">
                                                {file.name} ({(file.size / 1024 / 1024).toFixed(2)} MB)
                                            </p>
                                            <div className="mt-1.5">
                                                <input
                                                    type="text"
                                                    value={titles[idx] || ''}
                                                    onChange={(e) => handleTitleChange(idx, e.target.value)}
                                                    required
                                                    placeholder="Curriculum Lesson Title"
                                                    className="w-full text-sm font-semibold rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-[#121e1a] text-slate-900 dark:text-slate-100 px-3 py-1.5 focus:ring-2 focus:ring-teal-500"
                                                />
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => handleRemoveFile(idx)}
                                            className="text-slate-400 hover:text-rose-600 p-1 rounded-lg"
                                            aria-label={`Remove ${file.name}`}
                                        >
                                            ✕
                                        </button>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Processing Topology and Output Scope */}
                    <div className="p-5 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/80 dark:border-[#263932] space-y-4">
                        <div>
                            <h3 className="text-sm font-bold text-slate-800 dark:text-slate-200">
                                Processing Environment
                            </h3>
                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                Select where curriculum documents are analyzed and authoring outputs generated.
                            </p>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2.5">
                                <label className={`p-3.5 rounded-xl border cursor-pointer transition-all ${
                                    form.data.execution_mode === 'local_worker_v1'
                                        ? 'border-teal-500 bg-teal-50/40 dark:bg-teal-950/20 ring-1 ring-teal-500'
                                        : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-[#121e1a]'
                                }`}>
                                    <input
                                        type="radio"
                                        name="execution_mode"
                                        value="local_worker_v1"
                                        checked={form.data.execution_mode === 'local_worker_v1'}
                                        onChange={(e) => form.setData('execution_mode', e.target.value)}
                                        className="sr-only"
                                    />
                                    <div className="font-semibold text-xs text-slate-900 dark:text-slate-100 flex items-center justify-between">
                                        <span>Outbound Local Worker</span>
                                        {form.data.execution_mode === 'local_worker_v1' && <span className="text-teal-600 font-bold">✓</span>}
                                    </div>
                                    <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
                                        Tasks claimed via outbound HTTPS by enrolled institution worker. Provider credentials remain local.
                                    </p>
                                </label>

                                <label className={`p-3.5 rounded-xl border cursor-pointer transition-all ${
                                    form.data.execution_mode === 'same_host'
                                        ? 'border-teal-500 bg-teal-50/40 dark:bg-teal-950/20 ring-1 ring-teal-500'
                                        : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-[#121e1a]'
                                }`}>
                                    <input
                                        type="radio"
                                        name="execution_mode"
                                        value="same_host"
                                        checked={form.data.execution_mode === 'same_host'}
                                        onChange={(e) => form.setData('execution_mode', e.target.value)}
                                        className="sr-only"
                                    />
                                    <div className="font-semibold text-xs text-slate-900 dark:text-slate-100 flex items-center justify-between">
                                        <span>Standard Server Worker</span>
                                        {form.data.execution_mode === 'same_host' && <span className="text-teal-600 font-bold">✓</span>}
                                    </div>
                                    <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
                                        Background worker executed directly within server environment.
                                    </p>
                                </label>
                            </div>
                        </div>

                        <div>
                            <h3 className="text-sm font-bold text-slate-800 dark:text-slate-200">
                                Output Delivery Scope
                            </h3>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                                <label className={`p-3 rounded-xl border cursor-pointer text-xs font-medium transition-all ${
                                    form.data.output_scope === 'prompts_only'
                                        ? 'border-teal-500 bg-teal-50/40 dark:bg-teal-950/20 ring-1 ring-teal-500 text-slate-900 dark:text-slate-100'
                                        : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-[#121e1a] text-slate-600 dark:text-slate-400'
                                }`}>
                                    <input
                                        type="radio"
                                        name="output_scope"
                                        value="prompts_only"
                                        checked={form.data.output_scope === 'prompts_only'}
                                        onChange={(e) => form.setData('output_scope', e.target.value)}
                                        className="sr-only"
                                    />
                                    <span className="font-semibold block">Prompts Only</span>
                                    <span className="text-[11px] text-slate-500 mt-0.5 block">Authoring completes at prompt export.</span>
                                </label>

                                <label className={`p-3 rounded-xl border cursor-pointer text-xs font-medium transition-all ${
                                    form.data.output_scope === 'prompts_and_scenes'
                                        ? 'border-teal-500 bg-teal-50/40 dark:bg-teal-950/20 ring-1 ring-teal-500 text-slate-900 dark:text-slate-100'
                                        : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-[#121e1a] text-slate-600 dark:text-slate-400'
                                }`}>
                                    <input
                                        type="radio"
                                        name="output_scope"
                                        value="prompts_and_scenes"
                                        checked={form.data.output_scope === 'prompts_and_scenes'}
                                        onChange={(e) => form.setData('output_scope', e.target.value)}
                                        className="sr-only"
                                    />
                                    <span className="font-semibold block">Prompts & Scenes</span>
                                    <span className="text-[11px] text-slate-500 mt-0.5 block">Includes scene package return stage.</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    {/* Explanatory Process Card */}
                    <div className="p-4 rounded-2xl bg-teal-50/60 dark:bg-teal-950/30 border border-teal-200 dark:border-teal-900/60 text-sm text-teal-950 dark:text-teal-200">
                        <strong className="block mb-1 font-bold text-teal-900 dark:text-teal-100">
                            Automated Verification & Structuring
                        </strong>
                        Each document is processed asynchronously. Completed lessons are persisted immediately and queued for educator qualification before student publication.
                    </div>

                    {/* Progress Bar during submit */}
                    {form.progress && (
                        <div className="space-y-1">
                            <div className="flex justify-between text-xs font-semibold text-teal-800 dark:text-teal-300">
                                <span>Uploading curriculum bundle...</span>
                                <span>{form.progress.percentage}%</span>
                            </div>
                            <div className="w-full bg-slate-200 dark:bg-slate-700 h-2 rounded-full overflow-hidden">
                                <div
                                    className="bg-teal-600 h-full rounded-full transition-all"
                                    style={{ width: `${form.progress.percentage}%` }}
                                />
                            </div>
                        </div>
                    )}

                    <div className="pt-2 flex items-center justify-end gap-3">
                        <Button href={`/subjects/${subject.id}`} variant="secondary">
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            disabled={form.processing || files.length === 0}
                            isLoading={form.processing}
                        >
                            Start Ingestion Run →
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
