import React from 'react';
import { useParams } from 'react-router-dom';
import { curriculumManifest } from '../../data/curriculumManifest';
import { Beaker, Construction, Info } from 'lucide-react';

export const PlaceholderSimulation: React.FC = () => {
  const { unitId, lessonId, sectionId } = useParams();

  const unit = curriculumManifest.find(u => u.unitNumber === Number(unitId));
  const lesson = unit?.lessons.find(l => l.lessonNumber === Number(lessonId));
  const section = lesson?.sections.find(s => s.sectionNumber === Number(sectionId));

  return (
    <div className="flex flex-col items-center justify-center w-full h-full min-h-screen bg-slate-950 text-slate-200 p-8">
      <div className="absolute inset-0 bg-gradient-to-br from-indigo-900/20 via-slate-900 to-slate-950 pointer-events-none" />
      
      <div className="relative z-10 max-w-3xl w-full backdrop-blur-xl bg-slate-900/60 border border-slate-700/50 rounded-3xl p-10 shadow-2xl overflow-hidden">
        <div className={`absolute top-0 left-0 w-full h-1 ${unit?.color || 'bg-blue-500'}`} />
        
        <div className="flex items-center space-x-4 mb-6">
          <div className={`p-4 rounded-2xl bg-slate-800 ${unit?.color?.replace('bg-', 'text-') || 'text-blue-400'}`}>
            <Construction size={40} strokeWidth={1.5} />
          </div>
          <div>
            <h2 className="text-sm font-semibold tracking-widest text-slate-400 uppercase">
              {unit?.titleEn} • Lesson {lessonId}
            </h2>
            <h1 className="text-3xl font-light text-white mt-1">
              {section?.titleEn || 'Unknown Section'}
            </h1>
          </div>
        </div>

        <div className="bg-slate-800/50 border border-slate-700 rounded-xl p-6 mb-8">
          <div className="flex items-start space-x-3 text-slate-300">
            <Info className="w-6 h-6 text-indigo-400 shrink-0 mt-0.5" />
            <p className="text-lg leading-relaxed font-light">
              {section?.description || 'No description available for this section.'}
            </p>
          </div>
        </div>

        <div className="flex items-center justify-between pt-6 border-t border-slate-700/50">
          <div className="flex items-center space-x-2 text-sm text-slate-400">
            <Beaker size={16} />
            <span>Simulation Path: </span>
            <code className="bg-slate-950 px-2 py-1 rounded-md border border-slate-800 font-mono text-xs">
              {section?.path || `/unit/${unitId}/lesson/${lessonId}/section/${sectionId}`}
            </code>
          </div>
          <span className="px-4 py-2 rounded-full bg-amber-500/10 text-amber-400 text-xs font-medium border border-amber-500/20">
            Awaiting Simulation Injection
          </span>
        </div>
      </div>
    </div>
  );
};
