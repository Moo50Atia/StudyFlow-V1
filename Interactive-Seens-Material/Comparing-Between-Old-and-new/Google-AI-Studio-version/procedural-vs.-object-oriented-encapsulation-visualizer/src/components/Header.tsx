import React from 'react';
import { PerspectiveMode } from '../types';
import { Shield, Layers, Eye, RotateCcw, Sparkles } from 'lucide-react';

interface HeaderProps {
  perspective: PerspectiveMode;
  onSelectPerspective: (mode: PerspectiveMode) => void;
  onReset: () => void;
  isCorrupted: boolean;
}

export const Header: React.FC<HeaderProps> = ({
  perspective,
  onSelectPerspective,
  onReset,
  isCorrupted,
}) => {
  return (
    <header className="border-b border-slate-800 bg-slate-900/95 backdrop-blur px-4 py-3 sticky top-0 z-50">
      <div className="max-w-7xl mx-auto flex flex-col md:flex-row md:items-center justify-between gap-3">
        {/* Left: Lecture Metadata & Learning Target */}
        <div className="space-y-0.5">
          <div className="flex items-center gap-2 text-xs font-semibold text-sky-400 uppercase tracking-wider">
            <span className="px-2 py-0.5 rounded bg-sky-950/80 border border-sky-800/60">
              Unit 2 – Objects and Classes
            </span>
            <span className="text-slate-400">•</span>
            <span className="text-slate-300">Lec1 (Sec1)</span>
            {isCorrupted && (
              <span className="px-2 py-0.5 rounded bg-amber-500/20 text-amber-300 border border-amber-500/40 text-[11px] animate-pulse">
                Procedural State Corrupted
              </span>
            )}
          </div>
          <h1 className="text-lg md:text-xl font-bold text-white tracking-tight flex items-center gap-2">
            <span>Shift from Procedural to Object-Oriented Encapsulation</span>
          </h1>
          <p className="text-xs text-slate-400 max-w-2xl line-clamp-1 md:line-clamp-none">
            <strong className="text-slate-200 font-medium">Learning Target:</strong> Demonstrate separated data & standalone functions vs. encapsulating private state and public behavior within an object boundary.
          </p>
        </div>

        {/* Right: Perspectives & Global Reset */}
        <div className="flex items-center flex-wrap gap-2">
          {/* Custom Perspective Selectors */}
          <div className="flex items-center bg-slate-800/90 p-1 rounded-lg border border-slate-700/70 text-xs">
            <button
              id="btn-perspective-unified"
              onClick={() => onSelectPerspective('unified')}
              className={`flex items-center gap-1.5 px-2.5 py-1.5 rounded-md font-medium transition-all ${
                perspective === 'unified'
                  ? 'bg-sky-600 text-white shadow-sm'
                  : 'text-slate-300 hover:text-white hover:bg-slate-700/50'
              }`}
              title="Side-by-side interactive split view"
            >
              <Eye className="w-3.5 h-3.5" />
              <span>Split View</span>
            </button>

            <button
              id="btn-perspective-coupling"
              onClick={() => onSelectPerspective('coupling')}
              className={`flex items-center gap-1.5 px-2.5 py-1.5 rounded-md font-medium transition-all ${
                perspective === 'coupling'
                  ? 'bg-sky-600 text-white shadow-sm'
                  : 'text-slate-300 hover:text-white hover:bg-slate-700/50'
              }`}
              title="Visualize structural coupling of data + functions"
            >
              <Layers className="w-3.5 h-3.5" />
              <span>Data-Function Coupling</span>
            </button>

            <button
              id="btn-perspective-encapsulation"
              onClick={() => onSelectPerspective('encapsulation')}
              className={`flex items-center gap-1.5 px-2.5 py-1.5 rounded-md font-medium transition-all ${
                perspective === 'encapsulation'
                  ? 'bg-indigo-600 text-white shadow-sm'
                  : 'text-slate-300 hover:text-white hover:bg-slate-700/50'
              }`}
              title="Visualize access restrictions and protective boundary shield"
            >
              <Shield className="w-3.5 h-3.5" />
              <span>Encapsulation Shield</span>
            </button>
          </div>

          {/* Reset Button */}
          <button
            id="btn-global-reset"
            onClick={onReset}
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 hover:text-white border border-slate-700 text-xs font-medium transition shadow-sm active:scale-95"
            title="Reset model to initial lecture state"
          >
            <RotateCcw className="w-3.5 h-3.5 text-slate-400" />
            <span>Reset Scene</span>
          </button>
        </div>
      </div>
    </header>
  );
};
