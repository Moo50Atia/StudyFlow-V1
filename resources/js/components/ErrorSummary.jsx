import React from 'react';

export default function ErrorSummary({ errors = {}, title = 'There were errors with your submission' }) {
  const errorList = Object.entries(errors)
    .filter(([_, val]) => Boolean(val))
    .map(([key, val]) => ({ key, message: Array.isArray(val) ? val[0] : String(val) }));

  if (!errorList.length) return null;

  return (
    <div
      role="alert"
      className="mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-900/60 dark:bg-rose-950/40"
    >
      <div className="flex items-start gap-3">
        <svg className="h-5 w-5 text-rose-600 dark:text-rose-400 shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
          <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
        </svg>
        <div className="space-y-1">
          <h4 className="text-sm font-bold text-rose-900 dark:text-rose-200">{title}</h4>
          <ul className="list-disc pl-5 text-sm text-rose-800 dark:text-rose-300 space-y-1">
            {errorList.map(({ key, message }) => (
              <li key={key}>{message}</li>
            ))}
          </ul>
        </div>
      </div>
    </div>
  );
}
