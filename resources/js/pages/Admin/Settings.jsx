import React, { useEffect, useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import PageHeader from '../../components/PageHeader';
import Button from '../../components/Button';
import ErrorSummary from '../../components/ErrorSummary';

export default function Settings({ activeVersion, settings = {} }) {
    const { errors = {}, flash = {} } = usePage().props;
    const form = useForm({
        expected_active_version: activeVersion?.version ?? 0,
        ...settings,
    });

    const [json, setJson] = useState(JSON.stringify(settings, null, 2));
    const [parseError, setParseError] = useState('');

    useEffect(() => {
        setJson(JSON.stringify(settings, null, 2));
    }, [settings]);

    const publish = (event) => {
        event.preventDefault();
        try {
            const payload = JSON.parse(json);
            if (!payload || Array.isArray(payload) || typeof payload !== 'object') {
                throw new Error('Settings payload must be a JSON object.');
            }
            setParseError('');
            form.transform(() => ({
                ...payload,
                expected_active_version: activeVersion?.version ?? 0,
            })).post('/v3/admin/settings');
        } catch (error) {
            setParseError(error.message || 'Settings must be valid JSON format.');
        }
    };

    const breadcrumbs = [
        { label: 'Dashboard', url: '/dashboard' },
        { label: 'Operations Console', url: '/v3/admin/workspace' },
        { label: 'Operating Settings' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Operating Settings — EduPlatform" />

            <PageHeader
                title="Operating Settings & Configuration"
                eyebrow="Platform Governance"
                description="Edit validated operational limits, pipeline qualification rules, and AI providers. Server validates all schemas before committing a version."
                actions={
                    <div className="flex items-center gap-2">
                        <Button href="/v3/admin/settings/history" variant="secondary" size="sm">
                            Version History
                        </Button>
                        <Button href="/v3/admin/settings/templates" variant="secondary" size="sm">
                            Approved Templates
                        </Button>
                    </div>
                }
            />

            <div className="max-w-4xl space-y-6">
                {/* Active Version Banner */}
                <div className="p-5 rounded-3xl bg-white dark:bg-[#121e1a] border border-slate-200 dark:border-[#263932] shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-sm">
                    <div>
                        <span className="text-xs uppercase font-semibold text-slate-500 dark:text-slate-400">
                            Current Active Version
                        </span>
                        <div className="font-bold text-slate-900 dark:text-slate-100 text-base mt-0.5">
                            Version {activeVersion?.version ?? 'None Published'}
                        </div>
                    </div>
                    {activeVersion?.checksum && (
                        <div className="font-mono text-xs text-slate-500 dark:text-slate-400 break-all">
                            SHA: {activeVersion.checksum}
                        </div>
                    )}
                </div>

                {flash?.status && (
                    <div role="status" className="p-4 rounded-2xl bg-teal-50 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-900 text-teal-800 dark:text-teal-300 text-sm font-semibold">
                        {flash.status}
                    </div>
                )}

                <ErrorSummary errors={errors} className="mb-4" />

                {/* Editor Container */}
                <div className="bg-white dark:bg-[#121e1a] rounded-3xl border border-slate-200 dark:border-[#263932] p-6 sm:p-8 shadow-sm">
                    <form onSubmit={publish} className="space-y-4">
                        <label htmlFor="settings-json" className="block text-sm font-bold text-slate-900 dark:text-slate-100">
                            Settings Payload (Strict JSON)
                        </label>
                        <p className="text-xs text-slate-500 dark:text-slate-400">
                            Required fields: limits, qualification rules, provider models, and approved template hashes.
                        </p>

                        <textarea
                            id="settings-json"
                            spellCheck="false"
                            value={json}
                            onChange={(e) => setJson(e.target.value)}
                            rows={20}
                            className="w-full rounded-2xl border border-slate-300 dark:border-slate-800 bg-slate-950 text-teal-300 font-mono text-xs sm:text-sm p-4 shadow-inner focus:ring-2 focus:ring-teal-500 leading-relaxed"
                        />

                        {parseError && (
                            <p role="alert" className="text-xs text-rose-600 dark:text-rose-400 font-semibold">
                                {parseError}
                            </p>
                        )}

                        <div className="pt-2 flex justify-end">
                            <Button
                                type="submit"
                                variant="primary"
                                isLoading={form.processing}
                            >
                                Validate & Publish Configuration Version →
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
