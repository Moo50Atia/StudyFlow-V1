import React, { useState, Suspense, useEffect } from 'react';
import { Outlet, NavLink, useMatch } from 'react-router-dom';
import { curriculumManifest } from '../../data/curriculumManifest';
import { ChevronDown, ChevronRight, Search, Menu, X, Moon, Sun, BookOpen } from 'lucide-react';

export const Shell: React.FC = () => {
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [searchQuery, setSearchQuery] = useState('');
  const [expandedUnits, setExpandedUnits] = useState<number[]>([1]);
  const [expandedLessons, setExpandedLessons] = useState<string[]>(['1-2']);
  const [isDark, setIsDark] = useState(true);

  const match = useMatch('/unit/:unitId/lesson/:lessonId/section/:sectionId');
  const unitIdParam = match?.params.unitId;
  const lessonIdParam = match?.params.lessonId;
  const sectionIdParam = match?.params.sectionId;

  useEffect(() => {
    if (isDark) {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }
  }, [isDark]);

  const toggleUnit = (unitId: number) => {
    setExpandedUnits(prev => prev.includes(unitId) ? prev.filter(id => id !== unitId) : [...prev, unitId]);
  };

  const toggleLesson = (lessonId: string) => {
    setExpandedLessons(prev => prev.includes(lessonId) ? prev.filter(id => id !== lessonId) : [...prev, lessonId]);
  };

  // Search filtering
  const filteredManifest = curriculumManifest.map(unit => ({
    ...unit,
    lessons: unit.lessons.map(lesson => ({
      ...lesson,
      sections: lesson.sections.filter(sec => 
        sec.titleEn.toLowerCase().includes(searchQuery.toLowerCase()) || 
        sec.description.toLowerCase().includes(searchQuery.toLowerCase())
      )
    })).filter(lesson => lesson.sections.length > 0)
  })).filter(unit => unit.lessons.length > 0);

  // Current Breadcrumbs via Route Params
  let breadcrumbs = 'Dashboard';
  if (unitIdParam && lessonIdParam && sectionIdParam) {
    const unit = curriculumManifest.find(u => u.unitNumber === parseInt(unitIdParam, 10));
    const lesson = unit?.lessons.find(l => l.lessonNumber === parseInt(lessonIdParam, 10));
    const section = lesson?.sections.find(s => s.sectionNumber === parseInt(sectionIdParam, 10));
    if (unit && lesson && section) {
      breadcrumbs = `${unit.titleEn} / ${lesson.titleEn} / ${section.titleEn}`;
    }
  }

  return (
    <div className="flex h-screen w-full bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-200 overflow-hidden font-sans transition-colors duration-300">
      {/* Sidebar */}
      <aside className={`transition-all duration-300 ease-in-out border-r border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md flex flex-col ${sidebarOpen ? 'w-80' : 'w-0 opacity-0 overflow-hidden'}`}>
        <div className="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
          <div className="flex items-center space-x-3">
            <div className="p-2 bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 rounded-lg">
              <BookOpen size={24} />
            </div>
            <h1 className="font-semibold text-lg tracking-wide">MathSim 1st Prep</h1>
          </div>
        </div>
        
        <div className="p-4 border-b border-slate-200 dark:border-slate-800">
          <div className="relative">
            <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500" />
            <input 
              type="text" 
              placeholder="Filter topics..." 
              value={searchQuery}
              onChange={(e) => {
                setSearchQuery(e.target.value);
                if (e.target.value && expandedUnits.length === 0) {
                  setExpandedUnits(curriculumManifest.map(u => u.unitNumber));
                }
              }}
              className="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-700 rounded-lg py-2 pl-9 pr-4 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all dark:text-white"
            />
          </div>
        </div>

        <div className="flex-1 overflow-y-auto p-4 space-y-2 custom-scrollbar">
          {(searchQuery ? filteredManifest : curriculumManifest).map(unit => (
            <div key={unit.id} className="mb-2">
              <button 
                onClick={() => toggleUnit(unit.unitNumber)}
                className="flex items-center w-full justify-between p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
              >
                <div className="flex items-center space-x-3">
                  <div className={`w-3 h-3 rounded-full ${unit.color}`} />
                  <span className="font-medium text-slate-700 dark:text-slate-300">{unit.titleEn}</span>
                </div>
                {expandedUnits.includes(unit.unitNumber) ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
              </button>

              {expandedUnits.includes(unit.unitNumber) && (
                <div className="ml-4 mt-1 space-y-1 border-l border-slate-200 dark:border-slate-800 pl-2">
                  {unit.lessons.map(lesson => {
                    const lessonKey = `${unit.unitNumber}-${lesson.lessonNumber}`;
                    const isLessonExpanded = expandedLessons.includes(lessonKey) || searchQuery !== '';
                    return (
                      <div key={lesson.id} className="mb-1">
                        <button 
                          onClick={() => toggleLesson(lessonKey)}
                          className="flex items-center w-full justify-between p-2 text-sm rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800/50 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 transition-colors"
                        >
                          <span className="text-left truncate">{lesson.titleEn}</span>
                          {isLessonExpanded ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                        </button>

                        {isLessonExpanded && (
                          <div className="ml-3 mt-1 space-y-1">
                            {lesson.sections.map(section => (
                              <NavLink
                                key={section.id}
                                to={section.path}
                                className={({ isActive }) => 
                                  `block p-2 text-xs rounded-md transition-all ${
                                    isActive 
                                      ? 'bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-500/20 font-medium' 
                                      : 'text-slate-500 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800/40'
                                  }`
                                }
                              >
                                <div className="flex items-center space-x-2">
                                  <span className="shrink-0 font-mono opacity-50">S{section.sectionNumber}</span>
                                  <span className="truncate">{section.titleEn}</span>
                                </div>
                              </NavLink>
                            ))}
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              )}
            </div>
          ))}
        </div>
      </aside>

      {/* Main Content Area */}
      <main className="flex-1 flex flex-col min-w-0 relative bg-slate-50 dark:bg-slate-950 transition-colors duration-300">
        {/* Header */}
        <header className="h-16 border-b border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md flex items-center justify-between px-4 z-20 absolute top-0 left-0 right-0">
          <div className="flex items-center space-x-4">
            <button 
              onClick={() => setSidebarOpen(!sidebarOpen)}
              className="p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-colors"
            >
              {sidebarOpen ? <X size={20} /> : <Menu size={20} />}
            </button>
            <div className="hidden md:flex items-center space-x-2 text-sm text-slate-500 dark:text-slate-400">
              <span>{breadcrumbs}</span>
            </div>
          </div>
          <div className="flex items-center space-x-2">
            <button 
              onClick={() => setIsDark(!isDark)}
              className="p-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 dark:text-slate-400 transition-colors"
            >
              {isDark ? <Sun size={18} /> : <Moon size={18} />}
            </button>
          </div>
        </header>

        {/* Stage Viewport */}
        <div className="flex-1 pt-16 relative w-full h-full min-h-0 overflow-hidden">
          <Suspense fallback={
            <div className="flex h-full w-full items-center justify-center bg-slate-50 dark:bg-slate-950">
              <div className="w-8 h-8 border-4 border-indigo-500/30 border-t-indigo-500 rounded-full animate-spin" />
            </div>
          }>
            <Outlet />
          </Suspense>
        </div>
      </main>
    </div>
  );
};
