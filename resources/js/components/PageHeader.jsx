import React from 'react';

export default function PageHeader({
  eyebrow = null,
  title,
  description = null,
  actions = null,
  className = '',
}) {
  return (
    <div className={`mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between ${className}`}>
      <div className="space-y-1">
        {eyebrow && (
          <p className="text-xs font-bold uppercase tracking-wider text-teal-800 dark:text-teal-400">
            {eyebrow}
          </p>
        )}
        <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100 sm:text-3xl">
          {title}
        </h1>
        {description && (
          <p className="text-base text-slate-600 dark:text-slate-400">
            {description}
          </p>
        )}
      </div>
      {actions && (
        <div className="flex flex-wrap items-center gap-3 shrink-0">
          {actions}
        </div>
      )}
    </div>
  );
}
