import React, { useState, useRef, useEffect, Suspense, lazy } from 'react';
import { useNavigate, useLocation, Routes, Route } from 'react-router-dom';
import { Canvas } from '@react-three/fiber';
import { OrbitControls, Box, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath, InlineMath } from 'react-katex';
import { Menu, X, ChevronRight, ChevronDown, BookOpen, Activity, AlertCircle, Info, Calculator, Layers, Loader2 } from 'lucide-react';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

// Lazy loaded simulation components
const Unit1Lesson1Sec1 = lazy(() => import('./simulations/unit1/lesson1/Sec1'));
const Unit1Lesson1Sec2 = lazy(() => import('./simulations/unit1/lesson1/Sec2'));
const Unit1Lesson1Sec3 = lazy(() => import('./simulations/unit1/lesson1/Sec3'));
const Unit1Lesson2Sec2 = lazy(() => import('./simulations/unit1/lesson2/Sec2'));
const Unit1Lesson3Sec1 = lazy(() => import('./simulations/unit1/lesson3/Sec1'));
const Unit1Lesson3Sec2 = lazy(() => import('./simulations/unit1/lesson3/Sec2'));
const Unit1Lesson4Sec1 = lazy(() => import('./simulations/unit1/lesson4/Sec1'));
const Unit1Lesson4Sec2 = lazy(() => import('./simulations/unit1/lesson4/Sec2'));
const Unit1Lesson4Sec3 = lazy(() => import('./simulations/unit1/lesson4/Sec3'));
const Unit1Lesson5Sec1 = lazy(() => import('./simulations/unit1/lesson5/Sec1'));
const Unit1Lesson5Sec2 = lazy(() => import('./simulations/unit1/lesson5/Sec2'));

function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

// --------------------------------------------------------
// DATA STRUCTURE
// --------------------------------------------------------
const UNIT_DATA = {
  title: "Unit 1: Numbers and Operations on Them",
  miniChapters: [
    {
      id: "mc1",
      title: "Mini Chapter 1: Core Instructional Unit",
      lessons: [
        {
          id: "l1",
          title: "Lesson 1: Powers of Powers",
          sections: [
            { id: "s1.1", title: "Section 1.1: Remember the Laws of Exponents in Real Numbers", path: "/unit/1/lesson/1/section/1" },
            { id: "s1.2", title: "Section 1.2: Power of a Power Rule", path: "/unit/1/lesson/1/section/2" },
            { id: "s1.3", title: "Section 1.3: Power of a Product and Quotient Rules", path: "/unit/1/lesson/1/section/3" },
            { id: "p1", title: "Practice: Exercise 1 — Powers of Powers", path: "/unit/1/lesson/1/practice" },
            { id: "e1", title: "Evaluation: Multiple Choice Questions", path: "/unit/1/lesson/1/evaluation" },
          ]
        },
        {
          id: "l2",
          title: "Lesson 2: Exponential Equations",
          sections: [
            { id: "s2.1", title: "Section 2.1: Exponential Equations", path: "/unit/1/lesson/2/section/1" },
            { id: "s2.2", title: "Section 2.2: Solving Exponential Equations", path: "/unit/1/lesson/2/section/2" },
            { id: "p2", title: "Practice: Exercise 2 — Exponential Equations", path: "/unit/1/lesson/2/practice" },
            { id: "e2", title: "Evaluation: Multiple Choice Questions", path: "/unit/1/lesson/2/evaluation" },
          ]
        }
      ]
    },
    {
      id: "mc2",
      title: "Mini Chapter 2: Assessments and Final Revision",
      lessons: [
        {
          id: "l3",
          title: "Lesson 3: Unit 1 Summary",
          sections: [
            { id: "m3.1", title: "Module 3.1: Laws of Exponents in Real Numbers", path: "/unit/1/lesson/3/section/1" },
            { id: "m3.2", title: "Module 3.2: The Exponential Equation", path: "/unit/1/lesson/3/section/2" },
          ]
        },
        {
          id: "l4",
          title: "Lesson 4: Assessment of Unit One from the School Book",
          sections: [
            { id: "m4.1", title: "Section 1: Multiple Choice Evaluation", path: "/unit/1/lesson/4/section/1" },
            { id: "m4.2", title: "Section 2: Targeted Completion Problems", path: "/unit/1/lesson/4/section/2" },
            { id: "m4.3", title: "Section 3: Comprehensive Free-Response Problem Solving", path: "/unit/1/lesson/4/section/3" }
          ]
        },
        {
          id: "l5",
          title: "Lesson 5: Model Exam on Unit One",
          sections: [
            { id: "s5.1", title: "Section 1: Objective & Speed-Based Problem Solving", path: "/unit/1/lesson/5/section/1" },
            { id: "s5.2", title: "Section 2: Free-Response, System Equating & Geometric Applications", path: "/unit/1/lesson/5/section/2" },
          ]
        }
      ]
    }
  ]
};

// --------------------------------------------------------
// 3D CANVAS COMPONENTS (PLACEHOLDERS FOR FUTURE PROMPTS)
// --------------------------------------------------------
function InteractiveScene({ activeSection }: { activeSection: string | null }) {
  const groupRef = useRef<THREE.Group>(null);
  
  useEffect(() => {
    if (groupRef.current) {
      // Basic entry animation for the scene when section changes
      gsap.fromTo(
        groupRef.current.scale,
        { x: 0, y: 0, z: 0 },
        { x: 1, y: 1, z: 1, duration: 1, ease: 'elastic.out(1, 0.5)' }
      );
      gsap.fromTo(
        groupRef.current.rotation,
        { x: Math.PI / 4, y: -Math.PI / 4, z: 0 },
        { x: 0, y: 0, z: 0, duration: 1.5, ease: 'power3.out' }
      );
    }
  }, [activeSection]);

  return (
    <group ref={groupRef}>
      {/* 
        This is a placeholder architecture.
        Future prompts will inject dynamic geometric representations here 
        based on activeSection (e.g. visualizing 2^3 vs (2^2)^3).
      */}
      <Box args={[1, 1, 1]} position={[-1.5, 0, 0]}>
        <meshStandardMaterial color="#3b82f6" wireframe />
      </Box>
      
      <Sphere args={[0.7, 32, 32]} position={[1.5, 0, 0]}>
        <meshStandardMaterial color="#8b5cf6" wireframe />
      </Sphere>
      
      <gridHelper args={[10, 10, '#ffffff', '#475569']} position={[0, -1.5, 0]} />
      <axesHelper args={[3]} />
    </group>
  );
}

// --------------------------------------------------------
// ROUTER & COMPONENT MAPPING
// --------------------------------------------------------
function DynamicComponentRenderer({ activeSection }: { activeSection: string | null }) {
  const fallbackUI = (
    <div role="status" className="flex-1 flex flex-col items-center justify-center gap-4 p-8 text-slate-300">
      <p>This activity has no exported scene yet.</p>
      <a className="text-blue-300 underline" href="/unit/1/lesson/1/section/1">Open the first interactive lesson</a>
    </div>
  );

  return (
    <Suspense fallback={
      <div className="flex-1 flex items-center justify-center text-slate-400 flex-col gap-4">
        <Loader2 className="w-8 h-8 animate-spin" />
        <p>Loading interactive simulation...</p>
      </div>
    }>
      <Routes>
        <Route path="/" element={
          <div className="flex-1 flex items-center justify-center text-slate-500 flex-col gap-4">
            <Layers className="w-12 h-12 opacity-50" />
            <p>Select a lesson section from the architecture sidebar to begin.</p>
          </div>
        } />
        <Route path="/unit/1/lesson/1/section/1" element={<Unit1Lesson1Sec1 />} />
        <Route path="/unit/1/lesson/1/section/2" element={<Unit1Lesson1Sec2 />} />
        <Route path="/unit/1/lesson/1/section/3" element={<Unit1Lesson1Sec3 />} />
        <Route path="/unit/1/lesson/2/section/1" element={<Unit1Lesson2Sec2 />} />
        <Route path="/unit/1/lesson/2/section/2" element={<Unit1Lesson2Sec2 />} />
        <Route path="/unit/1/lesson/3/section/1" element={<Unit1Lesson3Sec1 />} />
        <Route path="/unit/1/lesson/3/section/2" element={<Unit1Lesson3Sec2 />} />
        <Route path="/unit/1/lesson/4/section/1" element={<Unit1Lesson4Sec1 />} />
        <Route path="/unit/1/lesson/4/section/2" element={<Unit1Lesson4Sec2 />} />
        <Route path="/unit/1/lesson/4/section/3" element={<Unit1Lesson4Sec3 />} />
        <Route path="/unit/1/lesson/5/section/1" element={<Unit1Lesson5Sec1 />} />
        <Route path="/unit/1/lesson/5/section/2" element={<Unit1Lesson5Sec2 />} />
        <Route path="*" element={fallbackUI} />
      </Routes>
    </Suspense>
  );
}

// --------------------------------------------------------
// MAIN APPLICATION
// --------------------------------------------------------
export default function App() {
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const location = useLocation();
  const navigate = useNavigate();
  
  let activeSection: string | null = null;
  for (const mc of UNIT_DATA.miniChapters) {
    for (const l of mc.lessons) {
      const sec = l.sections.find(s => s.path === location.pathname);
      if (sec) {
        activeSection = sec.id;
        break;
      }
    }
    if (activeSection) break;
  }

  const [expandedLessons, setExpandedLessons] = useState<Record<string, boolean>>({
    'l1': true,
    'l2': true,
    'l3': true
  });
  
  const toggleLesson = (lessonId: string) => {
    setExpandedLessons(prev => ({ ...prev, [lessonId]: !prev[lessonId] }));
  };

  const getSectionTitle = (id: string | null) => {
    if (!id) return "Select a Topic";
    for (const mc of UNIT_DATA.miniChapters) {
      for (const l of mc.lessons) {
        const sec = l.sections.find(s => s.id === id);
        if (sec) return sec.title;
      }
    }
    return "Interactive Visualizer";
  };

  return (
    <div className="w-screen h-screen relative overflow-hidden flex bg-slate-900 text-slate-100 font-sans">
      
      {/* ----------------------------------------------------- */}
      {/* SIDEBAR NAVIGATION */}
      {/* ----------------------------------------------------- */}
      <div 
        className={cn(
          "absolute lg:relative inset-y-0 left-0 z-40 w-80 shrink-0 bg-slate-900/95 backdrop-blur-xl border-r border-slate-700/50 transform transition-transform duration-300 ease-in-out flex flex-col shadow-2xl",
          sidebarOpen ? "translate-x-0" : "-translate-x-full"
        )}
      >
        <div className="p-4 border-b border-slate-700/50 flex items-center justify-between sticky top-0 bg-slate-900/90 z-10">
          <h2 className="text-lg font-bold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent flex items-center gap-2">
            <BookOpen className="w-5 h-5 text-blue-400" />
            Course Architecture
          </h2>
          <button 
            onClick={() => setSidebarOpen(false)}
            className="p-1 hover:bg-slate-800 rounded-lg transition-colors text-slate-400 hover:text-white lg:hidden"
          >
            <X className="w-5 h-5" />
          </button>
        </div>
        
        <div className="flex-1 overflow-y-auto p-4 space-y-6 scrollbar-thin scrollbar-thumb-slate-700">
          <div className="mb-2 px-2 py-1 bg-blue-500/10 border border-blue-500/20 rounded-md">
            <span className="text-xs font-semibold text-blue-400 uppercase tracking-wider">{UNIT_DATA.title}</span>
          </div>
          
          {UNIT_DATA.miniChapters.map((mc) => (
            <div key={mc.id} className="space-y-3">
              <h3 className="text-sm font-semibold text-slate-300 uppercase tracking-wide flex items-center gap-2">
                <Layers className="w-4 h-4 text-slate-500" />
                {mc.title}
              </h3>
              <div className="space-y-1 ml-2 border-l border-slate-800 pl-2">
                {mc.lessons.map((lesson) => (
                  <div key={lesson.id} className="space-y-1">
                    <button
                      onClick={() => toggleLesson(lesson.id)}
                      className="w-full text-left px-2 py-1.5 rounded-lg hover:bg-slate-800/50 transition-colors flex items-center justify-between group"
                    >
                      <span className="text-sm font-medium text-slate-200 group-hover:text-white transition-colors">{lesson.title}</span>
                      {lesson.sections.length > 0 && (
                        expandedLessons[lesson.id] ? 
                          <ChevronDown className="w-4 h-4 text-slate-500 group-hover:text-slate-300" /> : 
                          <ChevronRight className="w-4 h-4 text-slate-500 group-hover:text-slate-300" />
                      )}
                    </button>
                    
                    {expandedLessons[lesson.id] && lesson.sections.length > 0 && (
                      <div className="pl-4 space-y-0.5">
                        {lesson.sections.map((section) => (
                          <button
                            key={section.id}
                            onClick={() => {
                              navigate(section.path);
                              if (window.innerWidth < 1024) setSidebarOpen(false);
                            }}
                            className={cn(
                              "w-full text-left px-3 py-2 rounded-md text-xs transition-all duration-200 border-l-2",
                              activeSection === section.id 
                                ? "bg-blue-500/10 border-blue-500 text-blue-300 font-medium"
                                : "border-transparent text-slate-400 hover:text-slate-200 hover:bg-slate-800/30 hover:border-slate-600"
                            )}
                          >
                            {section.title}
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* ----------------------------------------------------- */}
      {/* MAIN VIEWPORT (R3F Canvas & Overlay UI) */}
      {/* ----------------------------------------------------- */}
      <div className="flex-1 relative flex flex-col min-w-0">
        
        {/* TOP HEADER */}
        <header className="absolute top-0 inset-x-0 h-16 bg-slate-900/60 backdrop-blur-md border-b border-slate-700/50 z-20 flex items-center px-4 gap-4 pointer-events-auto">
          {!sidebarOpen && (
            <button 
              onClick={() => setSidebarOpen(true)}
              className="p-2 hover:bg-slate-800 rounded-lg transition-colors text-slate-300"
            >
              <Menu className="w-5 h-5" />
            </button>
          )}
          <div className="flex-1 truncate">
            <h1 className="text-lg font-medium text-slate-100 truncate">
              {getSectionTitle(activeSection)}
            </h1>
          </div>
        </header>

        <DynamicComponentRenderer key={location.pathname} activeSection={activeSection} />
      </div>
    </div>
  );
}
