import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import ThemeToggle from './ThemeToggle';
import Button from './Button';

export default function PublicLayout({ children }) {
    const { auth } = usePage().props;
    const user = auth?.user;

    return (
        <div className="min-h-screen flex flex-col bg-[#f6f8f6] dark:bg-[#0a1210] text-slate-800 dark:text-slate-200 transition-colors">
            {/* Skip to Main Link for Accessibility */}
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 z-50 px-4 py-2 bg-teal-700 text-white rounded-xl shadow-lg font-medium outline-none ring-2 ring-white"
            >
                Skip to main content
            </a>

            {/* Header */}
            <header className="sticky top-0 z-40 bg-white/80 dark:bg-[#121e1a]/80 backdrop-blur-md border-b border-slate-200/80 dark:border-[#263932]/80">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Link href="/" className="flex items-center gap-2.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-600 rounded-lg">
                            <span className="w-8 h-8 rounded-xl bg-teal-700 dark:bg-teal-500 text-white dark:text-slate-950 font-black text-lg flex items-center justify-center shadow-sm">
                                E
                            </span>
                            <span className="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">
                                EduPlatform<span className="text-teal-600 dark:text-teal-400">.</span>
                            </span>
                        </Link>
                    </div>

                    <nav aria-label="Public navigation" className="flex items-center gap-3">
                        <ThemeToggle />

                        {user ? (
                            <Button href="/dashboard" variant="primary" size="sm">
                                Dashboard
                            </Button>
                        ) : (
                            <div className="flex items-center gap-2">
                                <Link
                                    href="/login"
                                    className="px-3.5 py-2 text-sm font-semibold rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-600 min-h-[44px] inline-flex items-center"
                                >
                                    Sign In
                                </Link>
                                <Button href="/register" variant="primary" size="sm">
                                    Get Started
                                </Button>
                            </div>
                        )}
                    </nav>
                </div>
            </header>

            {/* Main Content */}
            <main id="main-content" className="flex-1 flex flex-col">
                {children}
            </main>

            {/* Footer */}
            <footer className="border-t border-slate-200 dark:border-slate-800 bg-white/40 dark:bg-[#121e1a]/40 py-8 mt-16">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-sm text-slate-500 dark:text-slate-400">
                    <div className="flex items-center gap-2">
                        <span className="font-semibold text-slate-800 dark:text-slate-200">EduPlatform Studio</span>
                        <span>• Sovereign 3D Educational Engine</span>
                    </div>
                    <div>
                        © {new Date().getFullYear()} EduPlatform. All rights reserved.
                    </div>
                </div>
            </footer>
        </div>
    );
}
