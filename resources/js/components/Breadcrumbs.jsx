import React from 'react';
import { Link } from '@inertiajs/react';

export default function Breadcrumbs({ items = [] }) {
  if (!items.length) return null;

  return (
    <nav aria-label="Breadcrumb" className="mb-4">
      <ol className="flex flex-wrap items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
        <li>
          <Link href="/dashboard" className="transition hover:text-teal-700 dark:hover:text-teal-400">
            Workspace
          </Link>
        </li>
        {items.map((item, index) => {
          const isLast = index === items.length - 1;
          return (
            <li key={index} className="flex items-center gap-2">
              <svg className="h-4 w-4 text-slate-400 dark:text-slate-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fillRule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clipRule="evenodd" />
              </svg>
              {isLast || !item.href ? (
                <span className="font-semibold text-slate-900 dark:text-slate-100" aria-current="page">
                  {item.label}
                </span>
              ) : (
                <Link href={item.href} className="transition hover:text-teal-700 dark:hover:text-teal-400">
                  {item.label}
                </Link>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
