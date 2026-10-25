import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import ThemeToggle from './ThemeToggle';

export default function AuthLayout({ title, description, children }) {
    const { flash } = usePage().props;

    return (
        <div className="min-h-screen bg-[#f6f8f6] dark:bg-[#0a1210] flex flex-col justify-center py-12 sm:px-6 lg:px-8 relative">
            {/* Top Right Theme Toggle & Home Link */}
            <div className="absolute top-4 right-4 flex items-center gap-2">
                <Link
                    href="/"
                    className="p-2 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200"
                    aria-label="Return to homepage"
                >
                    Home
                </Link>
                <ThemeToggle />
            </div>

            <div className="sm:mx-auto sm:w-full sm:max-w-md px-4">
                {/* Brand Logo */}
                <div className="flex justify-center">
                    <Link href="/" className="flex items-center gap-2.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-600 rounded-lg">
                        <span className="w-10 h-10 rounded-2xl bg-teal-700 dark:bg-teal-500 text-white dark:text-slate-950 font-black text-xl flex items-center justify-center shadow-md">
                            E
                        </span>
                        <span className="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">
                            EduPlatform<span className="text-teal-600 dark:text-teal-400">.</span>
                        </span>
                    </Link>
                </div>

                {title && (
                    <h1 className="mt-6 text-center text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">
                        {title}
                    </h1>
                )}
                {description && (
                    <p className="mt-2 text-center text-sm text-slate-600 dark:text-slate-400">
                        {description}
                    </p>
                )}
            </div>

            <div className="mt-8 sm:mx-auto sm:w-full sm:max-w-md px-4">
                <div className="bg-white dark:bg-[#121e1a] py-8 px-6 sm:px-10 rounded-3xl border border-slate-200 dark:border-[#263932] shadow-xl">
                    {/* Status Alert */}
                    {flash?.status && (
                        <div
                            role="status"
                            className="mb-6 p-4 rounded-xl bg-teal-50 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-900 text-teal-800 dark:text-teal-300 text-sm font-medium"
                        >
                            {flash.status}
                        </div>
                    )}
                    {flash?.error && (
                        <div
                            role="alert"
                            className="mb-6 p-4 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 text-rose-800 dark:text-rose-300 text-sm font-medium"
                        >
                            {flash.error}
                        </div>
                    )}

                    {children}
                </div>
            </div>
        </div>
    );
}
