import React from 'react';
import EmptyState from './EmptyState';

export default function DataTable({
    columns = [],
    data = [],
    keyField = 'id',
    emptyTitle = 'No records found',
    emptyDescription = 'There are no items matching this criteria.',
    emptyAction = null,
    className = '',
}) {
    if (!data || data.length === 0) {
        return (
            <div className={`bg-white dark:bg-[#121e1a] rounded-2xl border border-slate-200 dark:border-[#263932] overflow-hidden ${className}`}>
                <EmptyState
                    title={emptyTitle}
                    description={emptyDescription}
                    action={emptyAction}
                />
            </div>
        );
    }

    return (
        <div className={`bg-white dark:bg-[#121e1a] rounded-2xl border border-slate-200 dark:border-[#263932] overflow-hidden shadow-sm ${className}`}>
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-slate-200 dark:divide-slate-800 text-left text-sm">
                    <thead className="bg-slate-50 dark:bg-[#182823]">
                        <tr>
                            {columns.map((col, idx) => (
                                <th
                                    key={col.key || idx}
                                    scope="col"
                                    className={`px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-slate-700 dark:text-slate-300 ${
                                        col.align === 'right' ? 'text-right' : col.align === 'center' ? 'text-center' : 'text-left'
                                    } ${col.className || ''}`}
                                >
                                    {col.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800/60 bg-white dark:bg-[#121e1a]">
                        {data.map((row, rowIdx) => {
                            const rowKey = row[keyField] ?? rowIdx;
                            return (
                                <tr
                                    key={rowKey}
                                    className="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors"
                                >
                                    {columns.map((col, colIdx) => {
                                        const value = col.render ? col.render(row, rowIdx) : row[col.key];
                                        const isFirst = colIdx === 0;
                                        return (
                                            <td
                                                key={col.key || colIdx}
                                                className={`px-4 py-3.5 text-slate-800 dark:text-slate-200 ${
                                                    isFirst ? 'font-medium' : ''
                                                } ${
                                                    col.align === 'right' ? 'text-right' : col.align === 'center' ? 'text-center' : 'text-left'
                                                } ${col.cellClassName || ''}`}
                                            >
                                                {value}
                                            </td>
                                        );
                                    })}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
