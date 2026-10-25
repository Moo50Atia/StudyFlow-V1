import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import FormField from '../../components/FormField';
import SelectField from '../../components/SelectField';
import Button from '../../components/Button';
import ErrorSummary from '../../components/ErrorSummary';

export default function QuestionsForm({ question = null, lectures = [] }) {
    const isEdit = Boolean(question?.id);

    const { data, setData, post, processing, errors } = useForm({
        lecture_id: question?.lecture_id || (lectures[0]?.id || ''),
        question_text: question?.question_text || '',
        idea_text: question?.idea_text || '',
        hint: question?.hint || '',
        solution_explanation: question?.solution_explanation || '',
        dynamic_view_link: question?.dynamic_view_link || '',
        question_image: null,
        solution_images: [],
        _method: isEdit ? 'put' : 'post',
    });

    const submit = (e) => {
        e.preventDefault();
        const url = isEdit ? `/questions/${question.id}` : '/questions';
        post(url, {
            forceFormData: true,
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Question Bank', url: '/questions' },
        { label: isEdit ? `Edit Question #${question.id}` : 'New Question' },
    ];

    const lectureOptions = lectures.map((lec) => ({
        value: lec.id,
        label: `${lec.title} (${lec.subject?.name || 'Subject'})`,
    }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${isEdit ? 'Edit Question' : 'Create Question'} — EduPlatform`} />

            <PageHeader
                title={isEdit ? `Edit Question #${question.id}` : 'Create New Practice Question'}
                eyebrow="Assessment Authoring"
                description="Author formative questions, diagram prompts, hints, and step-by-step solutions."
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
                        label="Question Prompt"
                        name="question_text"
                        type="textarea"
                        rows={4}
                        value={data.question_text}
                        onChange={(e) => setData('question_text', e.target.value)}
                        error={errors.question_text}
                        placeholder="State the problem clearly..."
                    />

                    {/* Question Image */}
                    <div className="space-y-1">
                        <label className="block text-sm font-semibold text-slate-800 dark:text-slate-200">
                            Prompt Diagram / Image (Optional)
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
                        {question?.question_image && (
                            <p className="text-xs text-slate-500">Current image saved</p>
                        )}
                    </div>

                    <FormField
                        label="Underlying Idea / Concept"
                        name="idea_text"
                        type="textarea"
                        rows={2}
                        value={data.idea_text}
                        onChange={(e) => setData('idea_text', e.target.value)}
                        error={errors.idea_text}
                        placeholder="Core physical intuition tested by this problem."
                    />

                    <FormField
                        label="Hint (Gradual Scaffolding)"
                        name="hint"
                        value={data.hint}
                        onChange={(e) => setData('hint', e.target.value)}
                        error={errors.hint}
                        placeholder="Consider the conservation of energy before and after the collision..."
                    />

                    <FormField
                        label="Full Solution Explanation"
                        name="solution_explanation"
                        type="textarea"
                        rows={4}
                        value={data.solution_explanation}
                        onChange={(e) => setData('solution_explanation', e.target.value)}
                        error={errors.solution_explanation}
                        placeholder="Step 1: Set up the coordinate system..."
                    />

                    {/* Solution Images */}
                    <div className="space-y-1">
                        <label className="block text-sm font-semibold text-slate-800 dark:text-slate-200">
                            Worked Solution Images / Diagrams (Max 10)
                        </label>
                        <input
                            type="file"
                            accept="image/*"
                            multiple
                            onChange={(e) => setData('solution_images', Array.from(e.target.files))}
                            className="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-teal-50 file:text-teal-700 dark:file:bg-teal-950/60 dark:file:text-teal-400 hover:file:bg-teal-100 cursor-pointer"
                        />
                        {errors.solution_images && (
                            <p className="text-xs text-rose-600 dark:text-rose-400">{errors.solution_images}</p>
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
                        <Button href="/questions" variant="secondary">
                            Cancel
                        </Button>
                        <Button type="submit" variant="primary" isLoading={processing}>
                            {isEdit ? 'Save Changes' : 'Create Question'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
