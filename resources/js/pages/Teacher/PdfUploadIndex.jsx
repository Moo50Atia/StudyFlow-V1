import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import DataTable from '../../components/DataTable';
import Pagination from '../../components/Pagination';
import StatusBadge from '../../components/StatusBadge';
import ErrorSummary from '../../components/ErrorSummary';

export default function PdfUploadIndex({ attempts }) {
    const form = useForm({
        pdf: null,
    });

    const submit = (e) => {
        e.preventDefault();
        form.post('/v3/teacher/pdf-uploads', {
            forceFormData: true,
        });
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Educator Studio', url: '/v3/teacher/workspace' },
        { label: 'PDF Ingestion History' },
    ];

    const attemptsData = attempts?.data || [];

    const columns = [
        {
            key: 'id',
            label: 'Attempt Reference',
            render: (item) => {
                const row = item.data || item;
                const id = row.id || row.attempt_id;
                return (
                    <div>
                        <Link
                            href={`/v3/teacher/pdf-uploads/${id}`}
                            className="font-bold text-teal-800 dark:text-teal-400 hover:underline font-mono text-xs sm:text-sm"
                        >
                            {String(id || '').slice(0, 13)}...
                        </Link>
                        {row.original_name && (
                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5 truncate max-w-xs">
                                {row.original_name}
                            </p>
                        )}
                    </div>
                );
            },
        },
        {
            key: 'state',
            label: 'Verification State',
            render: (item) => {
                const row = item.data || item;
                return <StatusBadge status={row.state || 'processing'} />;
            },
        },
        {
            key: 'created_at',
            label: 'Date Uploaded',
            render: (item) => {
                const row = item.data || item;
                return (
                    <span className="text-xs text-slate-500 dark:text-slate-400">
                        {row.created_at ? new Date(row.created_at).toLocaleString() : '—'}
                    </span>
                );
            },
        },
        {
            key: 'actions',
            label: 'Actions',
            align: 'right',
            render: (item) => {
                const row = item.data || item;
                const id = row.id || row.attempt_id;
                return (
                    <Button href={`/v3/teacher/pdf-uploads/${id}`} variant="ghost" size="sm">
                        Inspect Status →
                    </Button>
                );
            },
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="PDF Ingestion History — EduPlatform" />

            <PageHeader
                title="Curriculum PDF Ingestion"
                eyebrow="Document Pre-Screening"
                description="Upload academic syllabus files and track automated structural inspection and quarantine verification."
            />

            {/* Upload Box */}
            <div className="mb-8 bg-white dark:bg-[#121e1a] p-6 sm:p-8 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-sm">
                <h2 className="text-base font-bold text-slate-900 dark:text-slate-100 mb-2">
                    Submit New Courseware Document
                </h2>
                <p className="text-sm text-slate-600 dark:text-slate-400 mb-6">
                    Files are screened in sovereign storage before being promoted to extraction cases.
                </p>

                <ErrorSummary errors={form.errors} className="mb-6" />

                <form onSubmit={submit} className="flex flex-col sm:flex-row items-stretch sm:items-center gap-4">
                    <div className="flex-1">
                        <input
                            type="file"
                            name="pdf"
                            accept="application/pdf,.pdf"
                            required
                            onChange={(e) => form.setData('pdf', e.target.files?.[0] || null)}
                            className="block w-full text-sm text-slate-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-teal-50 file:text-teal-700 dark:file:bg-teal-950/60 dark:file:text-teal-400 hover:file:bg-teal-100 cursor-pointer border border-slate-200 dark:border-slate-800 rounded-xl"
                        />
                    </div>
                    <Button
                        type="submit"
                        variant="primary"
                        isLoading={form.processing}
                        disabled={!form.data.pdf}
                    >
                        Upload PDF Document
                    </Button>
                </form>
            </div>

            {/* History Table */}
            <div className="space-y-4">
                <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100">
                    Recent Upload Ingestions
                </h2>

                <DataTable
                    columns={columns}
                    data={attemptsData}
                    emptyTitle="No PDF uploads recorded"
                    emptyDescription="Upload your first syllabus or courseware document using the box above."
                />

                {attempts?.links && <Pagination pagination={attempts} />}
            </div>
        </AppLayout>
    );
}
