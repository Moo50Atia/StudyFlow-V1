import React from 'react';
import StatusBadge from './StatusBadge';
import { Link } from '@inertiajs/react';

export default function PdfStatusList({
    files = [],
    onRemove = null,
    className = '',
}) {
    if (!files || files.length === 0) {
        return (
            <div className="p-6 text-center text-sm text-slate-500 dark:text-slate-400 border border-dashed border-slate-300 dark:border-slate-800 rounded-2xl">
                No documents uploaded yet.
            </div>
        );
    }

    return (
        <div className={`space-y-3 ${className}`}>
            {files.map((file, idx) => {
                const fileName = file.filename || file.name || file.original_name || `Document ${idx + 1}`;
                const fileStatus = file.status || 'pending';
                const errorMessage = file.error_message || file.error || null;
                const linkUrl = file.show_url || file.url || (file.id ? `/v3/teacher/pdf-uploads/${file.id}` : null);

                return (
                    <div
                        key={file.id || idx}
                        className="bg-white dark:bg-[#121e1a] rounded-2xl border border-slate-200 dark:border-[#263932] p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-sm hover:border-slate-300 dark:hover:border-slate-700 transition-colors"
                    >
                        <div className="flex items-start gap-3 min-w-0">
                            <div className="w-10 h-10 rounded-xl bg-teal-50 dark:bg-teal-950/60 text-teal-700 dark:text-teal-400 flex items-center justify-center flex-shrink-0">
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </div>

                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="font-semibold text-sm text-slate-900 dark:text-slate-100 truncate">
                                        {fileName}
                                    </span>
                                    <StatusBadge status={fileStatus} />
                                </div>

                                <div className="text-xs text-slate-500 dark:text-slate-400 mt-1 flex items-center gap-3 flex-wrap">
                                    {file.size && <span>{(file.size / 1024 / 1024).toFixed(2)} MB</span>}
                                    {file.stage && <span>Stage: {file.stage}</span>}
                                    {file.created_at && <span>Uploaded: {new Date(file.created_at).toLocaleDateString()}</span>}
                                </div>

                                {errorMessage && (
                                    <p className="mt-2 text-xs text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/40 p-2 rounded-lg border border-rose-200 dark:border-rose-900/60">
                                        {errorMessage}
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="flex items-center gap-2 self-end sm:self-auto flex-shrink-0">
                            {linkUrl && (
                                <Link
                                    href={linkUrl}
                                    className="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold rounded-lg text-teal-700 dark:text-teal-400 hover:bg-teal-50 dark:hover:bg-teal-950/60 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-600"
                                >
                                    Details
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
                                    </svg>
                                </Link>
                            )}

                            {onRemove && (
                                <button
                                    type="button"
                                    onClick={() => onRemove(file, idx)}
                                    aria-label={`Remove ${fileName}`}
                                    className="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors"
                                >
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
