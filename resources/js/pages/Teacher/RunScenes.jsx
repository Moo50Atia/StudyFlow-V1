import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import StatusBadge from '../../components/StatusBadge';
import FormField from '../../components/FormField';
import SelectField from '../../components/SelectField';

export default function RunScenes({
    run,
    currentSelection = {},
    catalog = {},
    scenes = [],
    paidScenesEnabled = false,
}) {
    const selection = useForm({
        mode: run.mode === 'api_generate' ? 'selected_api' : 'export_manual',
        provider_id: currentSelection.provider?.id || Object.keys(catalog)[0] || '',
        model: currentSelection.provider?.requested_model || '',
        thinking_budget: currentSelection.provider?.gemini_thinking_budget || 24576,
    });

    const handleSaveMode = (e) => {
        e.preventDefault();
        selection.post(`/v3/teacher/subject-runs/${run.id}/generation-mode`);
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: `Run #${run.id}`, url: `/v3/teacher/subject-runs/${run.id}` },
        { label: 'Scene Workbench' },
    ];

    const csrf = typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]')?.content || '' : '';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Scene Workbench — Run #${run.id} — EduPlatform`} />

            <PageHeader
                title={`3D Scene Workbench (Run #${run.id})`}
                eyebrow="Interactive Simulation Synthesis"
                description="Manage deterministic Three.js scene generation, qualification pipelines, and manual artifact packages."
                actions={
                    <div className="flex items-center gap-2">
                        <Button href={`/v3/teacher/subject-runs/${run.id}`} variant="secondary" size="sm">
                            ← Back to Run
                        </Button>
                        <a
                            href={`/v3/teacher/subject-runs/${run.id}/prompt-export`}
                            className="px-3.5 py-1.5 text-xs font-semibold rounded-xl bg-teal-700 dark:bg-teal-500 text-white dark:text-slate-950 hover:bg-teal-800 transition-colors shadow-sm"
                        >
                            Export Prompts
                        </a>
                    </div>
                }
            />

            {/* Provider and Mode Configuration Form (if paid scenes enabled) */}
            {paidScenesEnabled && (
                <div className="mb-8 bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                    <h2 className="text-base font-bold text-slate-900 dark:text-slate-100 mb-1">
                        Synthesis Provider Configuration
                    </h2>
                    <p className="text-xs text-slate-500 dark:text-slate-400 mb-6">
                        Configure LLM provider routing and deterministic Three.js generation modes.
                    </p>

                    <form onSubmit={handleSaveMode} className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div className="space-y-4">
                            <label className="flex items-center gap-2.5 p-3 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/80 dark:border-[#263932] cursor-pointer">
                                <input
                                    type="radio"
                                    value="export_manual"
                                    checked={selection.data.mode === 'export_manual'}
                                    onChange={(e) => selection.setData('mode', e.target.value)}
                                    className="text-teal-700 dark:text-teal-400"
                                />
                                <div>
                                    <span className="font-semibold text-sm text-slate-900 dark:text-slate-100 block">
                                        Export / Manual Authoring (Default)
                                    </span>
                                    <span className="text-xs text-slate-500 dark:text-slate-400">
                                        No remote cloud API calls; export prompts or upload zip bundles.
                                    </span>
                                </div>
                            </label>

                            <label className="flex items-center gap-2.5 p-3 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/80 dark:border-[#263932] cursor-pointer">
                                <input
                                    type="radio"
                                    value="selected_api"
                                    checked={selection.data.mode === 'selected_api'}
                                    onChange={(e) => selection.setData('mode', e.target.value)}
                                    className="text-teal-700 dark:text-teal-400"
                                />
                                <div>
                                    <span className="font-semibold text-sm text-slate-900 dark:text-slate-100 block">
                                        Automated Provider API Generation
                                    </span>
                                    <span className="text-xs text-slate-500 dark:text-slate-400">
                                        Direct code synthesis using an approved remote foundation model.
                                    </span>
                                </div>
                            </label>
                        </div>

                        <div className="space-y-4">
                            <SelectField
                                label="Approved Foundation Provider"
                                name="provider_id"
                                value={selection.data.provider_id}
                                onChange={(e) => selection.setData('provider_id', e.target.value)}
                                options={Object.entries(catalog).map(([id, meta]) => ({
                                    value: id,
                                    label: `${id} (${meta.default_model || 'Standard'})`,
                                }))}
                            />

                            <FormField
                                label="Model Override"
                                name="model"
                                value={selection.data.model}
                                onChange={(e) => selection.setData('model', e.target.value)}
                                placeholder="e.g. gemini-2.5-pro"
                            />

                            <div className="flex justify-end">
                                <Button type="submit" variant="primary" size="sm" isLoading={selection.processing}>
                                    Save Provider Settings
                                </Button>
                            </div>
                        </div>
                    </form>
                </div>
            )}

            {/* Prompt Sections & Candidate Cards */}
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100">
                        Synthesized Scenes & Qualification ({scenes.length})
                    </h2>
                </div>

                {scenes.length > 0 ? (
                    <div className="space-y-4">
                        {scenes.map((scene, i) => {
                            const s = scene.section || {};
                            const prompt = scene.prompt;
                            const state = scene.candidate_state?.status;
                            const isQualified = scene.prompt_gate || scene.prompt_qualification?.passed;

                            return (
                                <article
                                    key={s.id || i}
                                    className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm space-y-4"
                                >
                                    <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                                        <div>
                                            <span className="text-xs font-bold uppercase tracking-wider text-teal-700 dark:text-teal-400">
                                                PDF {scene.item?.ordinal} • Section {s.ordinal}
                                            </span>
                                            <h3 className="text-base font-bold text-slate-900 dark:text-slate-100 mt-0.5">
                                                {scene.item?.display_title} — {s.section_identity}
                                            </h3>
                                            <p className="font-mono text-xs text-slate-400 mt-0.5">
                                                {s.id}
                                            </p>
                                        </div>

                                        <div className="flex items-center gap-2 flex-wrap">
                                            <StatusBadge
                                                status={
                                                    scene.prompt_gate
                                                        ? 'demo_waived'
                                                        : scene.prompt_qualification?.passed
                                                        ? 'jev_qualified'
                                                        : 'qualification_pending'
                                                }
                                            />
                                            <StatusBadge status={state || 'no_candidate'} />
                                        </div>
                                    </div>

                                    {/* Collapsible Prompt Preview */}
                                    {prompt && (
                                        <details className="rounded-2xl bg-slate-50 dark:bg-[#182823] p-4 text-xs">
                                            <summary className="cursor-pointer font-semibold text-slate-700 dark:text-slate-300">
                                                View System Prompt • {prompt.id?.slice(0, 8)}...
                                            </summary>
                                            <p className="mt-2 text-slate-600 dark:text-slate-300 whitespace-pre-wrap leading-relaxed">
                                                {prompt.prompt_text}
                                            </p>
                                        </details>
                                    )}

                                    {/* Candidate Meta Info */}
                                    {scene.candidate && (
                                        <div className="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-3">
                                            <span>Origin: {scene.candidate.origin === 'manual_import' ? 'Manual Import' : 'Automated Synthesis'}</span>
                                            <span>• Files: {scene.candidate.file_count}</span>
                                            <span>• Size: {Math.round(scene.candidate.total_bytes / 1024)} KB</span>
                                        </div>
                                    )}

                                    {/* Action Bar */}
                                    <div className="pt-3 border-t border-slate-100 dark:border-slate-800 flex flex-wrap items-center justify-between gap-3">
                                        <div className="flex items-center gap-2">
                                            {scene.review && ['review_ready', 'demo_review_ready'].includes(state) && (
                                                <Button
                                                    href={`/v3/teacher/cases/${s.case_id}/reviews/${scene.review.id}`}
                                                    variant="primary"
                                                    size="sm"
                                                >
                                                    Open Review Viewport →
                                                </Button>
                                            )}

                                            {paidScenesEnabled &&
                                                ['selected_api', 'api_generate'].includes(run.mode) &&
                                                (!scene.candidate || state === 'repair_pending') && (
                                                    <form
                                                        method="post"
                                                        action={`/v3/teacher/subject-runs/${run.id}/scenes/${s.id}/authoring-request`}
                                                    >
                                                        <input type="hidden" name="_token" value={csrf} />
                                                        <Button type="submit" variant="secondary" size="sm">
                                                            {scene.candidate ? 'Regenerate Scene (API)' : 'Request Scene (API)'}
                                                        </Button>
                                                    </form>
                                                )}
                                        </div>

                                        {/* Manual Static Package Upload */}
                                        <details className="text-xs">
                                            <summary className="cursor-pointer font-semibold text-teal-700 dark:text-teal-400 hover:underline">
                                                Upload Static Scene Bundle (.html, .css, .js)
                                            </summary>
                                            <form
                                                method="post"
                                                action={`/v3/teacher/subject-runs/${run.id}/scenes/${s.id}/imports`}
                                                encType="multipart/form-data"
                                                className="mt-3 p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] space-y-3 border border-slate-200 dark:border-[#263932]"
                                            >
                                                <input type="hidden" name="_token" value={csrf} />
                                                <input type="hidden" name="prompt_version_id" value={prompt?.id || ''} />
                                                <p className="text-slate-600 dark:text-slate-400">
                                                    Package must contain <code>index.html</code>, <code>styles.css</code>, and <code>script.js</code>.
                                                </p>
                                                <input
                                                    type="file"
                                                    name="files[]"
                                                    multiple
                                                    required
                                                    className="block w-full text-xs text-slate-500"
                                                />
                                                <Button type="submit" variant="primary" size="sm">
                                                    Import Static Package
                                                </Button>
                                            </form>
                                        </details>
                                    </div>
                                </article>
                            );
                        })}
                    </div>
                ) : (
                    <div className="p-8 text-center text-sm text-slate-500 bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932]">
                        No prompt sections found for this curriculum run yet.
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
