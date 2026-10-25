import React from 'react';
import { Link } from '@inertiajs/react';

export default function Pagination({ pagination, links: directLinks, from, to, total, className = '' }) {
    const links = directLinks || pagination?.links;
    const pageFrom = from ?? pagination?.from;
    const pageTo = to ?? pagination?.to;
    const pageTotal = total ?? pagination?.total;

    if (!links || links.length <= 3) {
        return null;
    }

    const cleanLabel = (label) => {
        if (!label) return '';
        return label
            .replace('&laquo; Previous', 'Previous')
            .replace('Next &raquo;', 'Next')
            .replace('&laquo;', '')
            .replace('&raquo;', '')
            .trim();
    };

    return (
        <nav
            aria-label="Pagination navigation"
            className={`flex flex-col sm:flex-row items-center justify-between gap-4 py-3 px-4 border-t border-slate-200 dark:border-slate-800 ${className}`}
        >
            <div className="text-sm text-slate-600 dark:text-slate-400">
                {pageTotal !== undefined && pageFrom !== undefined && pageTo !== undefined ? (
                    <span>
                        Showing <span className="font-medium text-slate-900 dark:text-slate-100">{pageFrom}</span> to{' '}
                        <span className="font-medium text-slate-900 dark:text-slate-100">{pageTo}</span> of{' '}
                        <span className="font-medium text-slate-900 dark:text-slate-100">{pageTotal}</span> results
                    </span>
                ) : null}
            </div>

            <div className="inline-flex flex-wrap items-center gap-1">
                {links.map((link, idx) => {
                    const label = cleanLabel(link.label);
                    const isPrev = idx === 0;
                    const isNext = idx === links.length - 1;

                    if (!link.url) {
                        return (
                            <span
                                key={idx}
                                aria-disabled="true"
                                className="inline-flex items-center justify-center min-h-[40px] min-w-[40px] px-3 py-1.5 text-sm font-medium rounded-lg text-slate-400 dark:text-slate-600 bg-transparent cursor-not-allowed select-none"
                            >
                                {label}
                            </span>
                        );
                    }

                    return (
                        <Link
                            key={idx}
                            href={link.url}
                            aria-current={link.active ? 'page' : undefined}
                            className={`inline-flex items-center justify-center min-h-[40px] min-w-[40px] px-3 py-1.5 text-sm font-medium rounded-lg transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-600 dark:focus-visible:outline-teal-400 ${
                                link.active
                                    ? 'bg-teal-700 text-white dark:bg-teal-500 dark:text-slate-950 font-semibold shadow-sm'
                                    : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'
                            }`}
                        >
                            {label}
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
}
