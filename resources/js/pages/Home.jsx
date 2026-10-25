import React from 'react';
import { Head, Link } from '@inertiajs/react';
import PublicLayout from '../components/PublicLayout';
import Button from '../components/Button';

export default function Home({ auth }) {
    const user = auth?.user;

    const pipelineSteps = [
        {
            number: '01',
            title: 'Sovereign PDF Ingestion',
            description: 'Upload academic syllabi, course notes, or full textbooks. The engine extracts concepts with high structural fidelity.',
            badge: 'Ingest',
        },
        {
            number: '02',
            title: 'Bilingual Pedagogical Structuring',
            description: 'Every lesson pairs standard core concepts with localized Egyptian Arabic pedagogical intuition and structured math/code blocks.',
            badge: 'Pedagogy',
        },
        {
            number: '03',
            title: 'Interactive 3D Simulation',
            description: 'Automated synthesis of deterministic Three.js scenes allowing learners to manipulate spatial variables in real time.',
            badge: 'Synthesis',
        },
        {
            number: '04',
            title: 'Educator Review & Governance',
            description: 'Rigorous educator qualification loop ensures only verified, safe educational scenes are published to students.',
            badge: 'Quality Control',
        },
    ];

    const audiences = [
        {
            role: 'For Higher Ed Educators',
            title: 'Turn static textbooks into active spatial classrooms',
            points: [
                'Automated multi-PDF curriculum ingestion and lesson maps',
                'Side-by-side interactive 3D scene preview and qualification',
                'Full control over published content and bilingual explanations',
            ],
            cta: user ? 'Open Studio' : 'Start as Educator',
            href: user ? '/dashboard' : '/register',
        },
        {
            role: 'For University Students',
            title: 'Grasp complex engineering and science intuitively',
            points: [
                'Bilingual explanations that break down difficult terminology',
                'Spatial 3D models with orbit, pan, and real-time variable tweaking',
                'Calm, distraction-free reading experience optimized for focus',
            ],
            cta: user ? 'View Lessons' : 'Access Courses',
            href: user ? '/dashboard' : '/register',
        },
    ];

    return (
        <PublicLayout>
            <Head title="EduPlatform — Sovereign Interactive Learning Studio" />

            {/* Hero Section */}
            <section className="relative overflow-hidden pt-12 pb-20 lg:pt-20 lg:pb-32">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
                        {/* Hero Text */}
                        <div className="lg:col-span-7 space-y-6">
                            <div className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-teal-50 dark:bg-teal-950/60 border border-teal-200 dark:border-teal-800 text-teal-800 dark:text-teal-300 text-xs sm:text-sm font-semibold">
                                <span className="w-2 h-2 rounded-full bg-teal-600 dark:bg-teal-400" />
                                Next-Generation Curriculum Intelligence
                            </div>

                            <h1 className="text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100 leading-[1.15]">
                                Turn academic texts into <span className="text-teal-700 dark:text-teal-400">interactive 3D understanding.</span>
                            </h1>

                            <p className="text-lg sm:text-xl text-slate-600 dark:text-slate-300 max-w-2xl leading-relaxed">
                                Move seamlessly from source documents to verified interactive lessons. Grounded in university pedagogy, bilingual Egyptian Arabic explanations, and real-time 3D simulation.
                            </p>

                            <div className="flex flex-wrap items-center gap-4 pt-2">
                                <Button
                                    href={user ? '/dashboard' : '/register'}
                                    variant="primary"
                                    size="lg"
                                    className="shadow-lg shadow-teal-900/10"
                                >
                                    {user ? 'Go to Workspace →' : 'Get Started Free →'}
                                </Button>
                                <Button
                                    href="#pipeline"
                                    variant="secondary"
                                    size="lg"
                                >
                                    Explore the Pipeline
                                </Button>
                            </div>

                            <div className="pt-8 border-t border-slate-200 dark:border-slate-800/80 grid grid-cols-3 gap-6 text-slate-700 dark:text-slate-300">
                                <div>
                                    <div className="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-slate-100">100%</div>
                                    <div className="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">Sovereign Data</div>
                                </div>
                                <div>
                                    <div className="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-slate-100">3D</div>
                                    <div className="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">Interactive Scenes</div>
                                </div>
                                <div>
                                    <div className="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-slate-100">AR/EN</div>
                                    <div className="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">Bilingual Clarity</div>
                                </div>
                            </div>
                        </div>

                        {/* Hero Interactive Graphic / Workflow Preview Card */}
                        <div className="lg:col-span-5">
                            <div className="relative rounded-3xl bg-[#121e1a] border border-[#263932] p-6 sm:p-8 shadow-2xl text-white">
                                <div className="flex items-center justify-between pb-4 border-b border-[#263932]">
                                    <div className="flex items-center gap-2">
                                        <span className="w-3 h-3 rounded-full bg-teal-400 animate-pulse" />
                                        <span className="text-xs font-bold uppercase tracking-wider text-teal-300">
                                            Curriculum Workflow Rail
                                        </span>
                                    </div>
                                    <span className="px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-teal-900/60 text-teal-200 border border-teal-800">
                                        Active
                                    </span>
                                </div>

                                <div className="mt-6 space-y-4">
                                    <div className="p-3.5 rounded-2xl bg-[#182823] border border-teal-800/40 flex items-center justify-between">
                                        <div className="flex items-center gap-3">
                                            <div className="w-8 h-8 rounded-xl bg-teal-500/20 text-teal-300 flex items-center justify-center font-bold text-xs">
                                                01
                                            </div>
                                            <div>
                                                <div className="text-sm font-semibold text-slate-100">Lecture_04_Rotational_Dynamics.pdf</div>
                                                <div className="text-xs text-slate-400">PDF Ingestion & Extraction</div>
                                            </div>
                                        </div>
                                        <span className="text-emerald-400 text-xs font-semibold">Done ✓</span>
                                    </div>

                                    <div className="p-3.5 rounded-2xl bg-[#182823] border border-teal-800/40 flex items-center justify-between">
                                        <div className="flex items-center gap-3">
                                            <div className="w-8 h-8 rounded-xl bg-teal-500/20 text-teal-300 flex items-center justify-center font-bold text-xs">
                                                02
                                            </div>
                                            <div>
                                                <div className="text-sm font-semibold text-slate-100">Bilingual Lesson Structuring</div>
                                                <div className="text-xs text-slate-400">Concepts + Egyptian Arabic Context</div>
                                            </div>
                                        </div>
                                        <span className="text-emerald-400 text-xs font-semibold">Done ✓</span>
                                    </div>

                                    <div className="p-3.5 rounded-2xl bg-teal-950/60 border border-teal-500/40 flex items-center justify-between shadow-md">
                                        <div className="flex items-center gap-3">
                                            <div className="w-8 h-8 rounded-xl bg-teal-400 text-slate-950 flex items-center justify-center font-bold text-xs">
                                                03
                                            </div>
                                            <div>
                                                <div className="text-sm font-semibold text-teal-100">Momentum_Conservation_Scene.js</div>
                                                <div className="text-xs text-teal-300">Three.js Interactive Viewport</div>
                                            </div>
                                        </div>
                                        <span className="text-teal-300 text-xs font-bold animate-pulse">Qualified ↗</span>
                                    </div>

                                    <div className="p-3.5 rounded-2xl bg-[#182823]/60 border border-[#263932] flex items-center justify-between opacity-70">
                                        <div className="flex items-center gap-3">
                                            <div className="w-8 h-8 rounded-xl bg-slate-800 text-slate-400 flex items-center justify-center font-bold text-xs">
                                                04
                                            </div>
                                            <div>
                                                <div className="text-sm font-semibold text-slate-300">Student Publication</div>
                                                <div className="text-xs text-slate-400">Released to enrolled cohort</div>
                                            </div>
                                        </div>
                                        <span className="text-slate-400 text-xs">Ready</span>
                                    </div>
                                </div>

                                <div className="mt-6 pt-4 border-t border-[#263932] flex items-center justify-between text-xs text-slate-400">
                                    <span>Teacher Review Status: Approved</span>
                                    <span className="font-semibold text-teal-300">100% Deterministic</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {/* Pipeline Section */}
            <section id="pipeline" className="py-20 bg-white dark:bg-[#121e1a] border-y border-slate-200 dark:border-[#263932]">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="text-center max-w-3xl mx-auto mb-16">
                        <span className="text-xs font-bold uppercase tracking-wider text-teal-700 dark:text-teal-400">
                            The Sovereign Pipeline
                        </span>
                        <h2 className="text-3xl sm:text-4xl font-extrabold text-slate-900 dark:text-slate-100 mt-2 tracking-tight">
                            From complex academic corpus to spatial intuition
                        </h2>
                        <p className="text-base sm:text-lg text-slate-600 dark:text-slate-300 mt-4 leading-relaxed">
                            A structured, multi-stage processing pipeline built specifically for university-level technical courses.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        {pipelineSteps.map((step) => (
                            <div
                                key={step.number}
                                className="bg-[#f6f8f6] dark:bg-[#182823] p-6 rounded-3xl border border-slate-200 dark:border-[#263932] flex flex-col justify-between"
                            >
                                <div>
                                    <div className="flex items-center justify-between mb-4">
                                        <span className="text-2xl font-black text-teal-700 dark:text-teal-400">
                                            {step.number}
                                        </span>
                                        <span className="px-2 py-0.5 rounded-full text-xs font-medium bg-teal-100 dark:bg-teal-950/60 text-teal-800 dark:text-teal-300">
                                            {step.badge}
                                        </span>
                                    </div>
                                    <h3 className="text-lg font-bold text-slate-900 dark:text-slate-100 mb-2">
                                        {step.title}
                                    </h3>
                                    <p className="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                                        {step.description}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* Audience Dual Cards */}
            <section className="py-20">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        {audiences.map((aud) => (
                            <div
                                key={aud.role}
                                className="p-8 sm:p-10 rounded-3xl bg-white dark:bg-[#121e1a] border border-slate-200 dark:border-[#263932] shadow-sm flex flex-col justify-between"
                            >
                                <div>
                                    <span className="text-xs font-bold uppercase tracking-wider text-teal-700 dark:text-teal-400">
                                        {aud.role}
                                    </span>
                                    <h3 className="text-2xl font-extrabold text-slate-900 dark:text-slate-100 mt-2 mb-4">
                                        {aud.title}
                                    </h3>
                                    <ul className="space-y-3 mb-8">
                                        {aud.points.map((pt, idx) => (
                                            <li key={idx} className="flex items-start gap-2.5 text-sm sm:text-base text-slate-600 dark:text-slate-300">
                                                <svg className="w-5 h-5 text-teal-600 dark:text-teal-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                                </svg>
                                                <span>{pt}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>

                                <Button href={aud.href} variant="primary" size="lg">
                                    {aud.cta} →
                                </Button>
                            </div>
                        ))}
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
