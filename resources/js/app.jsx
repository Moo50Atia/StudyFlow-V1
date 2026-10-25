import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import './bootstrap';
import '../css/app.css';
import './v3-review-preview';

function ThemeToggle() {
  const [dark, setDark] = React.useState(() => document.documentElement.classList.contains('dark'));
  const toggle = () => {
    const next = !document.documentElement.classList.contains('dark');
    document.documentElement.classList.toggle('dark', next);
    try { localStorage.setItem('edu-theme', next ? 'dark' : 'light'); } catch (_) {}
    setDark(next);
  };
  return <button type="button" onClick={toggle} aria-label={`Switch to ${dark ? 'light' : 'dark'} mode`} aria-pressed={dark} className="fixed bottom-4 right-4 z-50 inline-flex min-h-11 items-center gap-2 rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 shadow-lg transition hover:shadow-xl focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-teal-600 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
    <span aria-hidden="true">{dark ? '☀' : '☾'}</span><span>{dark ? 'Light mode' : 'Dark mode'}</span>
  </button>;
}

const pages = import.meta.glob('./pages/**/*.jsx');
createInertiaApp({
  resolve: async name => {
    const page = pages[`./pages/${name}.jsx`];
    if (!page) return (await pages['./pages/Legacy.jsx']()).default;
    return (await page()).default;
  },
  setup({ el, App, props }) { createRoot(el).render(<><App {...props} /><ThemeToggle /></>); },
});
