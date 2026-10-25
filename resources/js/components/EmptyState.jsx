import React from 'react';
import Button from './Button';

export default function EmptyState({
  title = 'No items found',
  description = 'Get started by creating your first entry.',
  actionLabel = null,
  actionHref = null,
  onAction = null,
  icon = null,
  className = '',
}) {
  return (
    <div className={`rounded-2xl border-2 border-dashed border-slate-200 bg-white p-12 text-center dark:border-slate-800 dark:bg-slate-900/50 ${className}`}>
      <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-teal-50 text-teal-700 dark:bg-teal-950/60 dark:text-teal-400">
        {icon || (
          <svg className="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
          </svg>
        )}
      </div>
      <h3 className="mt-4 text-lg font-bold text-slate-900 dark:text-slate-100">{title}</h3>
      <p className="mx-auto mt-2 max-w-sm text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
        {description}
      </p>
      {(actionLabel && (actionHref || onAction)) && (
        <div className="mt-6">
          <Button
            href={actionHref}
            onClick={onAction}
            variant="primary"
          >
            {actionLabel}
          </Button>
        </div>
      )}
    </div>
  );
}
