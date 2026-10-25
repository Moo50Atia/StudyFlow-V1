import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import FormField from '../../components/FormField';
import Button from '../../components/Button';
import ErrorSummary from '../../components/ErrorSummary';

export default function SubjectsForm({ subject = null }) {
    const isEdit = Boolean(subject?.id);

    const { data, setData, post, put, processing, errors } = useForm({
        name: subject?.name || '',
        description: subject?.description || '',
    });

    const submit = (e) => {
        e.preventDefault();
        if (isEdit) {
            put(`/subjects/${subject.id}`);
        } else {
            post('/subjects');
        }
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Subjects', url: '/subjects' },
        { label: isEdit ? `Edit ${subject.name}` : 'New Subject' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${isEdit ? 'Edit Subject' : 'Create Subject'} — EduPlatform`} />

            <PageHeader
                title={isEdit ? `Edit Subject: ${subject.name}` : 'Create New Subject'}
                eyebrow="Curriculum Management"
                description={
                    isEdit
                        ? 'Update the title and syllabus description for this course.'
                        : 'Define a new academic subject to organize course lectures and curriculum documents.'
                }
            />

            <div className="max-w-2xl bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                <ErrorSummary errors={errors} className="mb-6" />

                <form onSubmit={submit} className="space-y-6">
                    <FormField
                        label="Subject Name"
                        name="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        error={errors.name}
                        required
                        autoFocus
                        placeholder="e.g. Physics 101: Classical Mechanics"
                    />

                    <FormField
                        label="Course Description"
                        name="description"
                        type="textarea"
                        rows={4}
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        error={errors.description}
                        placeholder="Comprehensive introduction to Newtonian dynamics, energy conservation, and rotational motion."
                    />

                    <div className="pt-2 flex items-center justify-end gap-3">
                        <Button href="/subjects" variant="secondary">
                            Cancel
                        </Button>
                        <Button type="submit" variant="primary" isLoading={processing}>
                            {isEdit ? 'Save Changes' : 'Create Subject'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
