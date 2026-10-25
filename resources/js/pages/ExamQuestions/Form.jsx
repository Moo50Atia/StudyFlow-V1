import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import FormField from '../../components/FormField';
import SelectField from '../../components/SelectField';
import Button from '../../components/Button';
import ErrorSummary from '../../components/ErrorSummary';

export default function ExamQuestionsForm({ examQuestion = null, lectures = [] }) {
    const isEdit = Boolean(examQuestion?.id);

    const { data, setData, post, processing, errors } = useForm({
        lecture_id: examQuestion?.lecture_id || (lectures[0]?.id || ''),
        idea: examQuestion?.idea || '',
        explanation: examQuestion?.explanation || '',
        dynamic_view_link: examQuestion?.dynamic_view_link || '',
        question_image: null,
        solution_image: null,
        _method: isEdit ? 'put' : 'post',
    });

    const submit = (e) => {
        e.preventDefault();
        const url = isEdit ? `/exam_questions/${examQuestion.id}` : '/exam_questions';
        post(url, {
            forceFormData: true,
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Exams Bank', url: '/exam_questions' },
        { label: isEdit ? `Edit Exam #${examQuestion.id}` : 'New Exam Question' },
    ];

    const lectureOptions = lectures.map((lec) => ({
        value: lec.id,
        label: `${lec.title} (${lec.subject?.name || 'Subject'})`,
    }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${isEdit ? 'Edit Exam Question' : 'Create Exam Question'} — EduPlatform`} />

            <PageHeader
                title={isEdit ? `Edit Exam Question #${examQuestion.id}` : 'Create Exam Question'}
                eyebrow="Assessment Authoring"
                description="Upload official exam paper excerpts and authoritative solution diagrams."
            />

            <div className="max-w-3xl bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                <ErrorSummary errors={errors} className="mb-6" />

                <form onSubmit={submit} className="space-y-6">
                    <SelectField
                        label="Associated Lecture"
                        name="lecture_id"
                        value={data.lecture_id}
                        onChange={(e) => setData('lecture_id', e.target.value)}
                        options={lectureOptions}
                        error={errors.lecture_id}
                        required
                    />

                    <FormField
                        label="Concept / Syllabus Objective Tested"
                        name="idea"
                        value={data.idea}
                        onChange={(e) => setData('idea', e.target.value)}
                        error={errors.idea}
                        placeholder="e.g. 2024 Midterm Problem 3 — Conservation of angular momentum"
                    />

                    {/* Question Paper Image */}
                    <div className="space-y-1">
                        <label className="block text-sm font-semibold text-slate-800 dark:text-slate-200">
                            Exam Paper Image / Prompt Document
                        </label>
                        <input
                            type="file"
                            accept="image/*"
                            onChange={(e) => setData('question_image', e.target.files[0])}
                            className="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-teal-50 file:text-teal-700 dark:file:bg-teal-950/60 dark:file:text-teal-400 hover:file:bg-teal-100 cursor-pointer"
                        />
                        {errors.question_image && (
                            <p className="text-xs text-rose-600 dark:text-rose-400">{errors.question_image}</p>
                        )}
                        {examQuestion?.question_image && (
                            <p className="text-xs text-slate-500">Current exam paper saved</p>
                        )}
                    </div>

                    <FormField
                        label="Authoritative Solution Explanation"
                        name="explanation"
                        type="textarea"
                        rows={4}
                        value={data.explanation}
                        onChange={(e) => setData('explanation', e.target.value)}
                        error={errors.explanation}
                        placeholder="Model answer steps and scoring rubric..."
                    />

                    {/* Solution Image */}
                    <div className="space-y-1">
                        <label className="block text-sm font-semibold text-slate-800 dark:text-slate-200">
                            Official Answer Key / Solution Diagram
                        </label>
                        <input
                            type="file"
                            accept="image/*"
                            onChange={(e) => setData('solution_image', e.target.files[0])}
                            className="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-teal-50 file:text-teal-700 dark:file:bg-teal-950/60 dark:file:text-teal-400 hover:file:bg-teal-100 cursor-pointer"
                        />
                        {errors.solution_image && (
                            <p className="text-xs text-rose-600 dark:text-rose-400">{errors.solution_image}</p>
                        )}
                        {examQuestion?.solution_image && (
                            <p className="text-xs text-slate-500">Current answer key saved</p>
                        )}
                    </div>

                    <FormField
                        label="Interactive 3D Simulation URL (Optional)"
                        name="dynamic_view_link"
                        type="url"
                        value={data.dynamic_view_link}
                        onChange={(e) => setData('dynamic_view_link', e.target.value)}
                        error={errors.dynamic_view_link}
                        placeholder="https://..."
                    />

                    <div className="pt-2 flex items-center justify-end gap-3">
                        <Button href="/exam_questions" variant="secondary">
                            Cancel
                        </Button>
                        <Button type="submit" variant="primary" isLoading={processing}>
                            {isEdit ? 'Save Changes' : 'Create Exam Question'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
