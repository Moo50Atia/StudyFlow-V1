import React from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import FormField from '../../components/FormField';
import SelectField from '../../components/SelectField';
import Button from '../../components/Button';
import ErrorSummary from '../../components/ErrorSummary';

export default function LecturesForm({ lecture = null, subjects = [] }) {
    const { auth } = usePage().props;
    const isOwner = Boolean(auth?.is_owner);
    const isEdit = Boolean(lecture?.id);

    const { data, setData, post, processing, errors } = useForm({
        title: lecture?.title || '',
        description: lecture?.description || '',
        subject_id: lecture?.subject_id || (subjects[0]?.id || ''),
        pdf_path: null,
        mindmap_path: null,
        _method: isEdit ? 'put' : 'post',
    });

    const submit = (e) => {
        e.preventDefault();
        const url = isEdit ? `/lectures/${lecture.id}` : '/lectures';
        post(url, {
            forceFormData: true,
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Lectures', url: '/lectures' },
        { label: isEdit ? `Edit: ${lecture.title}` : 'New Lecture' },
    ];

    const subjectOptions = subjects.map((sub) => ({
        value: sub.id,
        label: sub.name,
    }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${isEdit ? 'Edit Lecture' : 'Create Lecture'} — EduPlatform`} />

            <PageHeader
                title={isEdit ? `Edit Lecture: ${lecture.title}` : 'Create New Lecture'}
                eyebrow="Curriculum Management"
                description={
                    isEdit
                        ? 'Update lecture syllabus metadata, linked documents, or owner assets.'
                        : 'Author a new structured lecture linked to an existing subject.'
                }
            />

            <div className="max-w-2xl bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                <ErrorSummary errors={errors} className="mb-6" />

                <form onSubmit={submit} className="space-y-6">
                    <SelectField
                        label="Assigned Subject"
                        name="subject_id"
                        value={data.subject_id}
                        onChange={(e) => setData('subject_id', e.target.value)}
                        options={subjectOptions}
                        error={errors.subject_id}
                        required
                    />

                    <FormField
                        label="Lecture Title"
                        name="title"
                        value={data.title}
                        onChange={(e) => setData('title', e.target.value)}
                        error={errors.title}
                        required
                        placeholder="e.g. Conservation of Angular Momentum"
                    />

                    <FormField
                        label="Overview & Learning Objectives"
                        name="description"
                        type="textarea"
                        rows={4}
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        error={errors.description}
                        placeholder="Key concepts covered: moment of inertia, rotational kinetic energy, and torque balance."
                    />

                    {/* PDF Document Upload */}
                    <div className="space-y-1">
                        <label className="block text-sm font-semibold text-slate-800 dark:text-slate-200">
                            Courseware PDF (Optional)
                        </label>
                        <input
                            type="file"
                            accept=".pdf"
                            onChange={(e) => setData('pdf_path', e.target.files[0])}
                            className="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-teal-50 file:text-teal-700 dark:file:bg-teal-950/60 dark:file:text-teal-400 hover:file:bg-teal-100 cursor-pointer"
                        />
                        {errors.pdf_path && (
                            <p className="text-xs text-rose-600 dark:text-rose-400">{errors.pdf_path}</p>
                        )}
                        {lecture?.pdf_path && (
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                Current PDF: <span className="font-mono">{lecture.pdf_path}</span>
                            </p>
                        )}
                    </div>

                    {/* Owner Only: Mind Map Upload */}
                    {isOwner && (
                        <div className="space-y-1 p-4 rounded-2xl bg-purple-50/50 dark:bg-purple-950/20 border border-purple-200/60 dark:border-purple-900/40">
                            <label className="block text-sm font-semibold text-purple-900 dark:text-purple-300">
                                Mind Map File (Owner Authorization)
                            </label>
                            <input
                                type="file"
                                onChange={(e) => setData('mindmap_path', e.target.files[0])}
                                className="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-purple-100 file:text-purple-800 dark:file:bg-purple-900 dark:file:text-purple-300 hover:file:bg-purple-200 cursor-pointer"
                            />
                            {errors.mindmap_path && (
                                <p className="text-xs text-rose-600 dark:text-rose-400">{errors.mindmap_path}</p>
                            )}
                        </div>
                    )}

                    <div className="pt-2 flex items-center justify-end gap-3">
                        <Button href="/lectures" variant="secondary">
                            Cancel
                        </Button>
                        <Button type="submit" variant="primary" isLoading={processing}>
                            {isEdit ? 'Save Changes' : 'Create Lecture'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
