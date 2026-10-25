import React from 'react';

export default function StatusBadge({ status = 'neutral', label, children, className = '' }) {
  const text = label || children || status;
  const s = String(status).toLowerCase();

  const configs = {
    success: {
      bg: 'bg-emerald-50 dark:bg-emerald-950/50 text-emerald-800 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800',
      icon: (
        <svg className="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
          <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
        </svg>
      ),
    },
    warning: {
      bg: 'bg-amber-50 dark:bg-amber-950/50 text-amber-900 dark:text-amber-300 border-amber-200 dark:border-amber-800',
      icon: (
        <svg className="h-3.5 w-3.5 text-amber-600 dark:text-amber-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
          <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
        </svg>
      ),
    },
    error: {
      bg: 'bg-rose-50 dark:bg-rose-950/50 text-rose-800 dark:text-rose-300 border-rose-200 dark:border-rose-800',
      icon: (
        <svg className="h-3.5 w-3.5 text-rose-600 dark:text-rose-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
          <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
        </svg>
      ),
    },
    info: {
      bg: 'bg-indigo-50 dark:bg-indigo-950/50 text-indigo-800 dark:text-indigo-300 border-indigo-200 dark:border-indigo-800',
      icon: (
        <span className="relative flex h-2 w-2">
          <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
          <span className="relative inline-flex rounded-full h-2 w-2 bg-indigo-500"></span>
        </span>
      ),
    },
    neutral: {
      bg: 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700',
      icon: (
        <span className="h-1.5 w-1.5 rounded-full bg-slate-400 dark:bg-slate-500" aria-hidden="true"></span>
      ),
    },
  };

  let type = 'neutral';
  if (['success', 'approved', 'complete', 'completed', 'passed', 'published', 'active'].includes(s)) type = 'success';
  else if (['warning', 'pending', 'awaiting_review', 'paused', 'delayed', 'needs_attention'].includes(s)) type = 'warning';
  else if (['error', 'failed', 'rejected', 'conflict', 'cancelled', 'blocked', 'inactive'].includes(s)) type = 'error';
  else if (['info', 'running', 'processing', 'extracting', 'generating'].includes(s)) type = 'info';

  const cfg = configs[type];

  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm font-semibold leading-none ${cfg.bg} ${className}`}>
      {cfg.icon}
      <span>{text}</span>
    </span>
  );
}
