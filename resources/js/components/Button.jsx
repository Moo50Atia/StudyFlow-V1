import React from 'react';
import { Link } from '@inertiajs/react';

export default function Button({
  children,
  variant = 'primary',
  size = 'md',
  loading = false,
  disabled = false,
  href,
  type = 'button',
  className = '',
  icon = null,
  ...props
}) {
  const base = 'inline-flex items-center justify-center font-semibold transition-all duration-150 focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-2 disabled:opacity-50 disabled:cursor-not-allowed select-none';

  const sizes = {
    sm: 'min-h-[36px] px-3 py-1.5 text-sm rounded-lg gap-1.5',
    md: 'min-h-[44px] px-4 py-2 text-sm rounded-xl gap-2',
    lg: 'min-h-[48px] px-6 py-3 text-base rounded-2xl gap-2.5',
  };

  const variants = {
    primary: 'bg-teal-700 text-white hover:bg-teal-800 dark:bg-teal-500 dark:text-slate-950 dark:hover:bg-teal-400 focus-visible:outline-teal-700 dark:focus-visible:outline-teal-400 shadow-sm active:scale-[0.98]',
    secondary: 'bg-slate-100 text-slate-800 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 focus-visible:outline-slate-500 active:scale-[0.98]',
    outline: 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 focus-visible:outline-teal-600 active:scale-[0.98]',
    danger: 'bg-rose-600 text-white hover:bg-rose-700 dark:bg-rose-500 dark:hover:bg-rose-400 focus-visible:outline-rose-600 shadow-sm active:scale-[0.98]',
    ghost: 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 focus-visible:outline-teal-600',
  };

  const classes = `${base} ${sizes[size] || sizes.md} ${variants[variant] || variants.primary} ${className}`;

  const content = (
    <>
      {loading && (
        <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-current" fill="none" viewBox="0 0 24 24" aria-hidden="true">
          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
        </svg>
      )}
      {!loading && icon && <span className="shrink-0" aria-hidden="true">{icon}</span>}
      <span>{children}</span>
    </>
  );

  if (href && !disabled) {
    return (
      <Link href={href} className={classes} {...props}>
        {content}
      </Link>
    );
  }

  return (
    <button
      type={type}
      disabled={disabled || loading}
      aria-busy={loading}
      className={classes}
      {...props}
    >
      {content}
    </button>
  );
}
