import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import FormField from '../../components/FormField';
import SelectField from '../../components/SelectField';
import Button from '../../components/Button';
import ErrorSummary from '../../components/ErrorSummary';

export default function SectionsForm({ section = null, lectures = [] }) {
    const isEdit = Boolean(section?.id);

    const { data, setData, post, put, processing, errors } = useForm({
        lecture_id: section?.lecture_id || (lectures[0]?.id || ''),
        title: section?.title || '',
        quick_summary: section?.quick_summary || '',
        core_concept: section?.core_concept || '',
        egyptian_explain: section?.egyptian_explain || '',
        formulas: section?.formulas || '',
        real_life: section?.real_life || '',
        dynamic_view_link: section?.dynamic_view_link || '',
    });

    const submit = (e) => {
        e.preventDefault();
        if (isEdit) {
            put(`/sections/${section.id}`);
        } else {
            post('/sections');
        }
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Sections', url: '/sections' },
        { label: isEdit ? `Edit: ${section.title}` : 'New Section' },
    ];

    const lectureOptions = lectures.map((lec) => ({
        value: lec.id,
        label: `${lec.title} (${lec.subject?.name || 'Subject'})`,
    }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${isEdit ? 'Edit Section' : 'Create Section'} — EduPlatform`} />

            <PageHeader
                title={isEdit ? `Edit Section: ${section.title}` : 'Create New Section'}
                eyebrow="Pedagogical Authoring"
                description={
                    isEdit
                        ? 'Modify core concept explanations, Egyptian Arabic pedagogical notes, or mathematical formulas.'
                        : 'Define a distinct learning concept within a lecture sequence.'
                }
            />

            <div className="max-w-3xl bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                <ErrorSummary errors={errors} className="mb-6" />

                <form onSubmit={submit} className="space-y-6">
                    <SelectField
                        label="Parent Lecture"
                        name="lecture_id"
                        value={data.lecture_id}
                        onChange={(e) => setData('lecture_id', e.target.value)}
                        options={lectureOptions}
                        error={errors.lecture_id}
                        required
                        disabled={isEdit}
                        helper={isEdit ? 'Parent lecture cannot be changed after creation' : undefined}
                    />

                    <FormField
                        label="Section Title"
                        name="title"
                        value={data.title}
                        onChange={(e) => setData('title', e.target.value)}
                        error={errors.title}
                        required
                        placeholder="e.g. Torque and Moment of Inertia"
                    />

                    <FormField
                        label="Quick Summary (One sentence takeaway)"
                        name="quick_summary"
                        type="textarea"
                        rows={2}
                        value={data.quick_summary}
                        onChange={(e) => setData('quick_summary', e.target.value)}
                        error={errors.quick_summary}
                        placeholder="Torque causes angular acceleration inversely proportional to rotational inertia."
                    />

                    <FormField
                        label="Core Educational Concept (English / Standard)"
                        name="core_concept"
                        type="textarea"
                        rows={4}
                        value={data.core_concept}
                        onChange={(e) => setData('core_concept', e.target.value)}
                        error={errors.core_concept}
                        placeholder="Explain the theoretical foundation with clarity..."
                    />

                    {/* Egyptian Arabic Callout Field */}
                    <div className="p-5 rounded-2xl bg-amber-50/60 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-900/40 space-y-2">
                        <label className="block text-sm font-semibold text-amber-900 dark:text-amber-300">
                            شرح بالعامية المصرية (Egyptian Arabic Pedagogical Note)
                        </label>
                        <textarea
                            dir="rtl"
                            lang="ar"
                            rows={4}
                            value={data.egyptian_explain}
                            onChange={(e) => setData('egyptian_explain', e.target.value)}
                            placeholder="اشرح الفكرة ببساطة وبطريقة بديهية للطلاب..."
                            className="w-full rounded-xl border border-amber-300 dark:border-amber-800 bg-white dark:bg-[#121e1a] p-3 text-slate-900 dark:text-amber-100 text-base leading-relaxed focus:ring-2 focus:ring-amber-500"
                            style={{ fontFamily: "'Noto Sans Arabic', 'Cairo', system-ui, sans-serif" }}
                        />
                        {errors.egyptian_explain && (
                            <p className="text-xs text-rose-600 dark:text-rose-400">{errors.egyptian_explain}</p>
                        )}
                    </div>

                    {/* Formulas / Math / Code */}
                    <div className="space-y-2">
                        <label className="block text-sm font-semibold text-slate-800 dark:text-slate-200">
                            Mathematical Formulas / Code Blocks (LTR Monospace)
                        </label>
                        <textarea
                            dir="ltr"
                            rows={3}
                            value={data.formulas}
                            onChange={(e) => setData('formulas', e.target.value)}
                            placeholder="\tau = I \cdot \alpha \quad \text{or} \quad L = I \cdot \omega"
                            className="w-full font-mono text-sm rounded-xl border border-slate-300 dark:border-[#395249] bg-slate-900 text-teal-300 p-3 shadow-inner focus:ring-2 focus:ring-teal-500"
                        />
                        {errors.formulas && (
                            <p className="text-xs text-rose-600 dark:text-rose-400">{errors.formulas}</p>
                        )}
                    </div>

                    <FormField
                        label="Real-Life Analogy / Application"
                        name="real_life"
                        type="textarea"
                        rows={3}
                        value={data.real_life}
                        onChange={(e) => setData('real_life', e.target.value)}
                        error={errors.real_life}
                        placeholder="Like a figure skater pulling in their arms to spin faster."
                    />

                    <FormField
                        label="Interactive Scene / Dynamic View URL (Optional)"
                        name="dynamic_view_link"
                        type="url"
                        value={data.dynamic_view_link}
                        onChange={(e) => setData('dynamic_view_link', e.target.value)}
                        error={errors.dynamic_view_link}
                        placeholder="https://..."
                    />

                    <div className="pt-2 flex items-center justify-end gap-3">
                        <Button href="/sections" variant="secondary">
                            Cancel
                        </Button>
                        <Button type="submit" variant="primary" isLoading={processing}>
                            {isEdit ? 'Save Changes' : 'Create Section'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
