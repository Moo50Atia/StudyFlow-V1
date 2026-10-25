import React, { useEffect, useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import StatusBadge from '../../components/StatusBadge';
import FormField from '../../components/FormField';
import SelectField from '../../components/SelectField';

const CONTEXT_FIELDS = [
    ['core_understanding', 'Core Educational Understanding'],
    ['visual_first_explanation', 'Visual-First Explanation'],
    ['visual_scene_script', 'Visual Scene Script'],
    ['dynamic_view_blueprint', 'Dynamic View Blueprint'],
    ['visual_learning_outcome', 'Visual Learning Outcome'],
    ['concept_check', 'Formative Concept Check'],
    ['conceptual_summary', 'Conceptual Summary'],
    ['colloquial_egyptian_explanation', 'الشرح بالعامية المصرية (Pedagogical Insight)'],
    ['laws_if_any', 'القوانين والمعادلات الرياضية (Laws)'],
    ['real_life_example', 'مثال من الحياة اليومية (Real-Life Analogy)'],
];

export default function ReviewShow({ data = {}, case: caseInfo, errors = {} }) {
    const previewRef = useRef(null);
    const [firstFrame, setFirstFrame] = useState(false);
    const [message, setMessage] = useState('');
    const [busy, setBusy] = useState(false);
    const [changesKind, setChangesKind] = useState('visual_interaction');
    const [changesNotes, setChangesNotes] = useState('');

    const pending = ['pending', 'dormant'].includes(data.review_state);
    const isDecided = data.review_state === 'decided';
    const fields = data.fields || {};
    const title = fields.section_identity || 'Review Scene Candidate';

    // Strictly preserve preview session protocol with window.V3ReviewPreview
    useEffect(() => {
        if (!pending || !previewRef.current || !window.V3ReviewPreview) return;
        const instance = new window.V3ReviewPreview({
            caseId: caseInfo.id,
            reviewId: data.review_ref,
            expectedReviewVersion: Number(data.review_state_version),
            csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || '',
            containerEl: previewRef.current,
            onFirstFrame: () => setFirstFrame(true),
        });
        return () => instance.closeSession?.();
    }, [caseInfo?.id, data.review_ref, data.review_state_version, pending]);

    const decide = async (body) => {
        setBusy(true);
        setMessage('Submitting educator qualification decision...');
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const idempotencyKey = typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `dec-${Date.now()}`;
            const response = await fetch(`/v3/teacher/cases/${caseInfo.id}/reviews/${data.review_ref}/decisions`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({
                    schema_version: 1,
                    idempotency_key: idempotencyKey,
                    expected_review_version: Number(data.review_state_version),
                    candidate_ref: data.candidate_ref,
                    candidate_version: Number(data.candidate_version),
                    ...body,
                }),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(payload?.error?.message || `Qualification request failed (${response.status})`);
            }
            setMessage('Decision saved successfully.');
            window.location.reload();
        } catch (e) {
            setMessage(e.message || 'Qualification submission failed.');
            setBusy(false);
        }
    };

    const submitCorrection = async (e) => {
        e.preventDefault();
        const formEl = e.currentTarget;
        setBusy(true);
        setMessage('Submitting post-approval correction...');
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const idempotencyKey = typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `cor-${Date.now()}`;
            const response = await fetch(`/v3/teacher/cases/${caseInfo.id}/approved-scenes/${data.approval_ref}/corrections`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({
                    schema_version: 1,
                    idempotency_key: idempotencyKey,
                    expected_section_version: Number(data.section_state_version),
                    feedback_kind: formEl.elements.feedback_kind.value,
                    notes: formEl.elements.notes.value,
                }),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(payload?.error?.message || `Correction request failed (${response.status})`);
            }
            setMessage('Correction accepted and queued for repair.');
        } catch (e) {
            setMessage(e.message || 'Correction submission failed.');
        } finally {
            setBusy(false);
        }
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: `Case #${caseInfo?.id}`, url: `/v3/teacher/cases/${caseInfo?.id}` },
        { label: 'Review Queue', url: `/v3/teacher/cases/${caseInfo?.id}/reviews` },
        { label: title },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Review: ${title} — EduPlatform`} />

            <PageHeader
                title={title}
                eyebrow="Educator Qualification Workbench"
                description={`Review Reference: ${data.review_ref} • Candidate Version v${data.candidate_version}`}
                actions={
                    <div className="flex items-center gap-2">
                        <Button href={`/v3/teacher/cases/${caseInfo?.id}/reviews`} variant="secondary" size="sm">
                            ← Back to Queue
                        </Button>
                        <StatusBadge status={data.review_state || 'pending'} />
                    </div>
                }
            />

            {data.eligibility_basis && (
                <div className="mb-6 p-4 rounded-2xl bg-teal-50 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-900 text-teal-900 dark:text-teal-200 text-xs flex items-center justify-between">
                    <span>Qualification Basis: <strong className="font-semibold">{data.eligibility_basis}</strong></span>
                    {data.qualification_ref && <span className="font-mono text-slate-500">Gate: {data.qualification_ref}</span>}
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
                {/* Left: Viewport Preview & Decision Controls (7 cols) */}
                <div className="lg:col-span-7 space-y-6">
                    {/* Viewport Frame Container */}
                    <div className="bg-slate-950 rounded-3xl border border-slate-800 p-4 shadow-2xl">
                        <div className="flex items-center justify-between pb-3 mb-2 border-b border-slate-800 text-xs text-slate-300">
                            <span className="flex items-center gap-2">
                                <span className={`w-2 h-2 rounded-full ${firstFrame ? 'bg-emerald-400' : 'bg-amber-400 animate-pulse'}`} />
                                {firstFrame ? 'First frame received • Interactive' : 'Awaiting preview container session...'}
                            </span>
                            <span className="font-mono text-slate-500">WebGL / Three.js</span>
                        </div>

                        {/* Preview DOM node attached by V3ReviewPreview */}
                        <div
                            ref={previewRef}
                            className="relative aspect-video w-full rounded-2xl overflow-hidden bg-black flex items-center justify-center min-h-[380px]"
                        >
                            {!firstFrame && (
                                <div className="text-center text-xs text-slate-500">
                                    <div className="w-8 h-8 border-2 border-teal-500 border-t-transparent rounded-full animate-spin mx-auto mb-2" />
                                    Starting sandboxed Three.js preview...
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Decision Action Card (if Pending) */}
                    {pending && (
                        <div className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm space-y-5">
                            <div className="flex items-center justify-between">
                                <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                    Educator Qualification Decision
                                </h3>
                                <span className="text-xs text-slate-500 dark:text-slate-400">
                                    Approval requires inspecting first frame
                                </span>
                            </div>

                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    type="button"
                                    variant="primary"
                                    onClick={() => decide({ decision: 'approve' })}
                                    disabled={!firstFrame || busy}
                                    isLoading={busy}
                                    className="bg-emerald-600 hover:bg-emerald-700 text-white"
                                >
                                    Approve & Qualify for Students ✓
                                </Button>
                            </div>

                            {/* Request Changes Form */}
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    decide({
                                        decision: 'request_changes',
                                        feedback_kind: changesKind,
                                        notes: changesNotes,
                                    });
                                }}
                                className="pt-4 border-t border-slate-100 dark:border-slate-800 space-y-4"
                            >
                                <h4 className="text-sm font-bold text-slate-800 dark:text-slate-200">
                                    Or Request Technical / Pedagogical Changes
                                </h4>

                                <SelectField
                                    label="Feedback Category"
                                    name="feedback_kind"
                                    value={changesKind}
                                    onChange={(e) => setChangesKind(e.target.value)}
                                    options={[
                                        { value: 'visual_interaction', label: 'Visual or 3D Interaction Issue' },
                                        { value: 'factual_source', label: 'Factual / Curriculum Inaccuracy' },
                                        { value: 'mixed', label: 'Mixed Correction' },
                                    ]}
                                />

                                <FormField
                                    label="Required Changes (Visible to repair pipeline)"
                                    name="notes"
                                    type="textarea"
                                    rows={3}
                                    value={changesNotes}
                                    onChange={(e) => setChangesNotes(e.target.value)}
                                    required
                                    placeholder="Specify exactly what needs adjustment..."
                                />

                                <Button
                                    type="submit"
                                    variant="secondary"
                                    size="sm"
                                    disabled={busy || !changesNotes.trim()}
                                >
                                    Submit Change Request
                                </Button>
                            </form>

                            {message && (
                                <p role="status" aria-live="polite" className="text-xs font-semibold text-teal-800 dark:text-teal-300">
                                    {message}
                                </p>
                            )}
                        </div>
                    )}

                    {/* Post-Approval Correction Form */}
                    {isDecided && data.approval_ref && (
                        <div className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm space-y-4">
                            <h3 className="text-base font-bold text-slate-900 dark:text-slate-100">
                                Request Post-Approval Correction
                            </h3>
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                This scene is currently approved. Submitting a correction will quarantine it for repair.
                            </p>

                            <form onSubmit={submitCorrection} className="space-y-4">
                                <SelectField
                                    label="Correction Category"
                                    name="feedback_kind"
                                    options={[
                                        { value: 'visual_interaction', label: 'Visual or Interaction Flaw' },
                                        { value: 'factual_source', label: 'Curriculum Fact Correction' },
                                        { value: 'mixed', label: 'Mixed Correction' },
                                    ]}
                                />

                                <FormField
                                    label="Detailed Correction Notes"
                                    name="notes"
                                    type="textarea"
                                    rows={3}
                                    required
                                    placeholder="Describe the necessary update..."
                                />

                                <Button type="submit" variant="danger" size="sm" isLoading={busy}>
                                    Submit Post-Approval Correction
                                </Button>

                                {message && (
                                    <p role="status" aria-live="polite" className="text-xs font-semibold text-teal-800 dark:text-teal-300">
                                        {message}
                                    </p>
                                )}
                            </form>
                        </div>
                    )}
                </div>

                {/* Right: Lesson Context & Prompt (5 cols) */}
                <div className="lg:col-span-5 space-y-6">
                    {/* Prompt Details Card */}
                    {data.prompt && (
                        <div className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                            <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100 mb-2">
                                Synthesis Prompt
                            </h3>
                            <pre className="p-4 rounded-2xl bg-slate-50 dark:bg-[#182823] font-mono text-xs text-slate-700 dark:text-slate-300 whitespace-pre-wrap leading-relaxed max-h-60 overflow-y-auto">
                                {data.prompt}
                            </pre>
                        </div>
                    )}

                    {/* Collapsible Lesson Context Cards */}
                    <div className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 shadow-sm">
                        <h3 className="text-base font-bold text-slate-900 dark:text-slate-100 mb-4">
                            Curriculum Lesson Context
                        </h3>

                        <div className="space-y-3">
                            {CONTEXT_FIELDS.map(([key, label]) => {
                                const val = fields[key];
                                if (!val) return null;
                                const isArabic = key.includes('egyptian') || key.includes('laws');

                                return (
                                    <details
                                        key={key}
                                        className="p-3.5 rounded-2xl bg-slate-50 dark:bg-[#182823] border border-slate-200/60 dark:border-[#263932] text-xs"
                                    >
                                        <summary className="cursor-pointer font-bold text-slate-800 dark:text-slate-200">
                                            {label}
                                        </summary>
                                        <div
                                            dir={isArabic ? 'rtl' : 'auto'}
                                            className={`mt-2 leading-relaxed whitespace-pre-line ${
                                                isArabic
                                                    ? 'text-sm font-sans text-amber-950 dark:text-amber-100'
                                                    : 'text-slate-600 dark:text-slate-300'
                                            }`}
                                        >
                                            {val}
                                        </div>
                                    </details>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
