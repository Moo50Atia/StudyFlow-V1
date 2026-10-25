import React, { useState } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import ThemeToggle from './ThemeToggle';
import Breadcrumbs from './Breadcrumbs';

export default function AppLayout({ title, breadcrumbs = [], actions = null, children }) {
    const { auth, flash } = usePage().props;
    const user = auth?.user;
    const isOwner = Boolean(auth?.is_owner);
    const userRole = (user?.role || '').toLowerCase();
    const isTeacher = userRole === 'teacher';
    const isAdmin = userRole === 'admin' || isOwner;
    const isStudent = userRole === 'student' || (!isTeacher && !isAdmin);

    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

    const handleLogout = (e) => {
        e.preventDefault();
        router.post('/logout');
    };

    // Role badge configuration
    let roleLabel = 'Student';
    let roleBadgeClass = 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';

    if (isOwner) {
        roleLabel = 'Platform Owner';
        roleBadgeClass = 'bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300 border border-purple-200 dark:border-purple-800';
    } else if (userRole === 'admin') {
        roleLabel = 'Administrator';
        roleBadgeClass = 'bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300 border border-blue-200 dark:border-blue-800';
    } else if (isTeacher) {
        roleLabel = 'Educator';
        roleBadgeClass = 'bg-teal-100 text-teal-800 dark:bg-teal-950/60 dark:text-teal-300 border border-teal-200 dark:border-teal-800';
    }

    return (
        <div className="min-h-screen flex flex-col bg-[#f6f8f6] dark:bg-[#0a1210] text-slate-800 dark:text-slate-200 transition-colors">
            {/* Skip Link for Accessibility */}
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 z-50 px-4 py-2 bg-teal-700 text-white rounded-xl shadow-lg font-medium outline-none ring-2 ring-white"
            >
                Skip to main content
            </a>

            {/* Navigation Header */}
            <header className="sticky top-0 z-40 bg-white/90 dark:bg-[#121e1a]/90 backdrop-blur-md border-b border-slate-200 dark:border-[#263932]">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between h-16">
                        {/* Left: Brand and Primary Nav */}
                        <div className="flex items-center gap-6">
                            <Link
                                href="/dashboard"
                                className="flex items-center gap-2.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-600 rounded-lg"
                            >
                                <span className="w-8 h-8 rounded-xl bg-teal-700 dark:bg-teal-500 text-white dark:text-slate-950 font-black text-lg flex items-center justify-center shadow-sm">
                                    E
                                </span>
                                <span className="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">
                                    EduPlatform<span className="text-teal-600 dark:text-teal-400">.</span>
                                </span>
                            </Link>

                            <nav aria-label="Main navigation" className="hidden md:flex items-center gap-1 text-sm font-medium">
                                <Link
                                    href="/dashboard"
                                    className="px-3 py-2 rounded-xl text-slate-700 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-300 dark:hover:text-white dark:hover:bg-slate-800 transition-colors"
                                >
                                    Dashboard
                                </Link>

                                <Link
                                    href="/subjects"
                                    className="px-3 py-2 rounded-xl text-slate-700 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-300 dark:hover:text-white dark:hover:bg-slate-800 transition-colors"
                                >
                                    Subjects
                                </Link>

                                {isTeacher && (
                                    <>
                                        <Link
                                            href="/v3/teacher/workspace"
                                            className="px-3 py-2 rounded-xl text-slate-700 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-300 dark:hover:text-white dark:hover:bg-slate-800 transition-colors"
                                        >
                                            Teacher Studio
                                        </Link>
                                        <Link
                                            href="/v3/teacher/reviews"
                                            className="px-3 py-2 rounded-xl text-slate-700 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-300 dark:hover:text-white dark:hover:bg-slate-800 transition-colors"
                                        >
                                            Scene Reviews
                                        </Link>
                                    </>
                                )}

                                {isOwner && (
                                    <>
                                        <Link
                                            href="/questions"
                                            className="px-3 py-2 rounded-xl text-slate-700 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-300 dark:hover:text-white dark:hover:bg-slate-800 transition-colors"
                                        >
                                            Question Bank
                                        </Link>
                                        <Link
                                            href="/users"
                                            className="px-3 py-2 rounded-xl text-slate-700 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-300 dark:hover:text-white dark:hover:bg-slate-800 transition-colors"
                                        >
                                            Accounts
                                        </Link>
                                    </>
                                )}

                                {isAdmin && (
                                    <Link
                                        href="/v3/admin/workspace"
                                        className="px-3 py-2 rounded-xl text-slate-700 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-300 dark:hover:text-white dark:hover:bg-slate-800 transition-colors"
                                    >
                                        Operations
                                    </Link>
                                )}
                            </nav>
                        </div>

                        {/* Right: Role, Theme, Profile, Logout */}
                        <div className="hidden md:flex items-center gap-3">
                            <span className={`px-2.5 py-1 text-xs font-semibold rounded-full ${roleBadgeClass}`}>
                                {roleLabel}
                            </span>

                            <ThemeToggle />

                            <Link
                                href="/profile"
                                className="px-3 py-2 text-sm font-medium text-slate-700 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            >
                                {user?.name || 'Profile'}
                            </Link>

                            <button
                                type="button"
                                onClick={handleLogout}
                                className="px-3 py-2 text-sm font-medium text-rose-600 hover:text-rose-700 dark:text-rose-400 dark:hover:text-rose-300 rounded-xl hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors"
                            >
                                Sign Out
                            </button>
                        </div>

                        {/* Mobile Menu Button */}
                        <div className="flex md:hidden items-center gap-2">
                            <ThemeToggle />
                            <button
                                type="button"
                                onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                                aria-label="Toggle mobile menu"
                                className="p-2 rounded-xl text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800"
                            >
                                <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    {mobileMenuOpen ? (
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    ) : (
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                                    )}
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                {/* Mobile Drawer */}
                {mobileMenuOpen && (
                    <div className="md:hidden border-t border-slate-200 dark:border-slate-800 bg-white dark:bg-[#121e1a] px-4 pt-2 pb-6 space-y-2">
                        <div className="py-2 flex items-center justify-between border-b border-slate-100 dark:border-slate-800">
                            <span className="text-sm font-medium text-slate-900 dark:text-slate-100">
                                {user?.name || 'Signed in'}
                            </span>
                            <span className={`px-2 py-0.5 text-xs font-semibold rounded-full ${roleBadgeClass}`}>
                                {roleLabel}
                            </span>
                        </div>

                        <Link
                            href="/dashboard"
                            className="block px-3 py-2 text-base font-medium rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800"
                        >
                            Dashboard
                        </Link>
                        <Link
                            href="/subjects"
                            className="block px-3 py-2 text-base font-medium rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800"
                        >
                            Subjects
                        </Link>
                        {isTeacher && (
                            <>
                                <Link
                                    href="/v3/teacher/workspace"
                                    className="block px-3 py-2 text-base font-medium rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800"
                                >
                                    Teacher Studio
                                </Link>
                                <Link
                                    href="/v3/teacher/reviews"
                                    className="block px-3 py-2 text-base font-medium rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800"
                                >
                                    Scene Reviews
                                </Link>
                            </>
                        )}
                        {isOwner && (
                            <>
                                <Link
                                    href="/questions"
                                    className="block px-3 py-2 text-base font-medium rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800"
                                >
                                    Question Bank
                                </Link>
                                <Link
                                    href="/users"
                                    className="block px-3 py-2 text-base font-medium rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800"
                                >
                                    Accounts
                                </Link>
                            </>
                        )}
                        {isAdmin && (
                            <Link
                                href="/v3/admin/workspace"
                                className="block px-3 py-2 text-base font-medium rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800"
                            >
                                Operations
                            </Link>
                        )}
                        <Link
                            href="/profile"
                            className="block px-3 py-2 text-base font-medium rounded-xl text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800"
                        >
                            Profile Settings
                        </Link>
                        <button
                            type="button"
                            onClick={handleLogout}
                            className="w-full text-left px-3 py-2 text-base font-medium rounded-xl text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40"
                        >
                            Sign Out
                        </button>
                    </div>
                )}
            </header>

            {/* Breadcrumb strip if present */}
            {breadcrumbs.length > 0 && (
                <div className="bg-slate-50/80 dark:bg-[#121e1a]/40 border-b border-slate-200/60 dark:border-[#263932]/60">
                    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2.5">
                        <Breadcrumbs items={breadcrumbs} />
                    </div>
                </div>
            )}

            {/* Flash Banners */}
            {flash?.status && (
                <div className="bg-teal-50 dark:bg-teal-950/40 border-b border-teal-200 dark:border-teal-900 py-3 px-4 text-center text-sm font-medium text-teal-800 dark:text-teal-300">
                    {flash.status}
                </div>
            )}
            {flash?.success && (
                <div className="bg-emerald-50 dark:bg-emerald-950/40 border-b border-emerald-200 dark:border-emerald-900 py-3 px-4 text-center text-sm font-medium text-emerald-800 dark:text-emerald-300">
                    {flash.success}
                </div>
            )}
            {flash?.error && (
                <div className="bg-rose-50 dark:bg-rose-950/40 border-b border-rose-200 dark:border-rose-900 py-3 px-4 text-center text-sm font-medium text-rose-800 dark:text-rose-300" role="alert">
                    {flash.error}
                </div>
            )}

            {/* Main Content Area */}
            <main id="main-content" className="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
                {children}
            </main>

            {/* Global Footer */}
            <footer className="border-t border-slate-200 dark:border-slate-800 bg-white/40 dark:bg-[#121e1a]/40 py-6 mt-auto">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500 dark:text-slate-400">
                    <div>
                        EduPlatform • Sovereign Interactive Educational Engine
                    </div>
                    <div>
                        Logged in as <span className="font-semibold text-slate-700 dark:text-slate-300">{user?.email}</span> ({roleLabel})
                    </div>
                </div>
            </footer>
        </div>
    );
}
