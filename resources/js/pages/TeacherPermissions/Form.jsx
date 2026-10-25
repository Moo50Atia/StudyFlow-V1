import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import SelectField from '../../components/SelectField';
import Button from '../../components/Button';
import ErrorSummary from '../../components/ErrorSummary';

export default function TeacherPermissionsForm({
    teacherPermission = null,
    teachers = [],
    subjects = [],
    lectures = [],
}) {
    const isEdit = Boolean(teacherPermission?.id);

    const { data, setData, post, put, processing, errors } = useForm({
        teacher_id: teacherPermission?.teacher_id || (teachers[0]?.id || ''),
        subject_id: teacherPermission?.subject_id || (subjects[0]?.id || ''),
        lecture_id: teacherPermission?.lecture_id || '',
    });

    const submit = (e) => {
        e.preventDefault();
        if (isEdit) {
            put(`/teacher_permissions/${teacherPermission.id}`);
        } else {
            post('/teacher_permissions');
        }
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Teacher Scopes', url: '/teacher_permissions' },
        { label: isEdit ? 'Edit Scope' : 'Grant Scope' },
    ];

    const teacherOptions = teachers.map((t) => ({
        value: t.id,
        label: `${t.name} (${t.email})`,
    }));

    const subjectOptions = subjects.map((s) => ({
        value: s.id,
        label: s.name,
    }));

    const filteredLectures = data.subject_id
        ? lectures.filter((l) => String(l.subject_id) === String(data.subject_id))
        : lectures;

    const lectureOptions = [
        { value: '', label: 'Entire Subject (All Lectures)' },
        ...filteredLectures.map((l) => ({
            value: l.id,
            label: l.title,
        })),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${isEdit ? 'Edit Teacher Scope' : 'Grant Teacher Scope'} — EduPlatform`} />

            <PageHeader
                title={isEdit ? 'Edit Teacher Permission Scope' : 'Grant New Teacher Scope'}
                eyebrow="Access Control"
                description="Assign curriculum authoring, editing, and review permissions to faculty."
            />

            <div className="max-w-2xl bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                <ErrorSummary errors={errors} className="mb-6" />

                <form onSubmit={submit} className="space-y-6">
                    <SelectField
                        label="Educator"
                        name="teacher_id"
                        value={data.teacher_id}
                        onChange={(e) => setData('teacher_id', e.target.value)}
                        options={teacherOptions}
                        error={errors.teacher_id}
                        required
                    />

                    <SelectField
                        label="Permitted Subject"
                        name="subject_id"
                        value={data.subject_id}
                        onChange={(e) => {
                            setData((prev) => ({
                                ...prev,
                                subject_id: e.target.value,
                                lecture_id: '',
                            }));
                        }}
                        options={subjectOptions}
                        error={errors.subject_id}
                        required
                    />

                    <SelectField
                        label="Specific Lecture Scope (Optional)"
                        name="lecture_id"
                        value={data.lecture_id}
                        onChange={(e) => setData('lecture_id', e.target.value)}
                        options={lectureOptions}
                        error={errors.lecture_id}
                        helper="Leave as 'Entire Subject' to grant access across all lectures in this subject."
                    />

                    <div className="pt-2 flex items-center justify-end gap-3">
                        <Button href="/teacher_permissions" variant="secondary">
                            Cancel
                        </Button>
                        <Button type="submit" variant="primary" isLoading={processing}>
                            {isEdit ? 'Save Changes' : 'Grant Permission'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
