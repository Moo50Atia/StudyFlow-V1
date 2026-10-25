import React from 'react';

export default function SelectField({
  id,
  name,
  label,
  value,
  onChange,
  options = [],
  placeholder = 'Select an option',
  error = null,
  helperText = null,
  required = false,
  disabled = false,
  className = '',
  ...props
}) {
  const inputId = id || name;
  const errorId = error ? `${inputId}-error` : undefined;
  const helperId = helperText ? `${inputId}-helper` : undefined;

  const baseClasses = 'w-full rounded-xl border bg-white px-3.5 py-2.5 text-base text-slate-900 transition focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-2 dark:bg-slate-900 dark:text-slate-100 disabled:opacity-50 disabled:cursor-not-allowed';
  const borderClasses = error
    ? 'border-rose-400 focus-visible:outline-rose-600 dark:border-rose-700 dark:focus-visible:outline-rose-400'
    : 'border-slate-300 focus-visible:outline-teal-700 dark:border-slate-700 dark:focus-visible:outline-teal-400';

  return (
    <div className={`space-y-1.5 ${className}`}>
      {label && (
        <label
          htmlFor={inputId}
          className="block text-sm font-semibold text-slate-800 dark:text-slate-200"
        >
          {label} {required && <span className="text-rose-600 dark:text-rose-400" aria-hidden="true">*</span>}
        </label>
      )}

      <select
        id={inputId}
        name={name}
        value={value ?? ''}
        onChange={onChange}
        required={required}
        disabled={disabled}
        aria-invalid={Boolean(error)}
        aria-describedby={[errorId, helperId].filter(Boolean).join(' ') || undefined}
        className={`${baseClasses} ${borderClasses}`}
        {...props}
      >
        {placeholder && (
          <option value="" disabled>
            {placeholder}
          </option>
        )}
        {options.map((opt) => {
          const val = typeof opt === 'object' ? opt.value : opt;
          const lbl = typeof opt === 'object' ? opt.label : opt;
          return (
            <option key={val} value={val}>
              {lbl}
            </option>
          );
        })}
      </select>

      {helperText && !error && (
        <p id={helperId} className="text-sm text-slate-500 dark:text-slate-400">
          {helperText}
        </p>
      )}

      {error && (
        <p id={errorId} role="alert" className="text-sm font-medium text-rose-600 dark:text-rose-400">
          {error}
        </p>
      )}
    </div>
  );
}
