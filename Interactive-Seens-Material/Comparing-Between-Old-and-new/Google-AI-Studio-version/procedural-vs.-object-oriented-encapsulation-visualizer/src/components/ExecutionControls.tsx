import React from 'react';
import { LECTURE_STEPS } from '../constants';
import { Play, Pause, SkipForward, SkipBack, RotateCcw, Clock, Zap } from 'lucide-react';

interface ExecutionControlsProps {
  currentStepIndex: number;
  isPlaying: boolean;
  speed: number;
  onSetStep: (index: number) => void;
  onTogglePlay: () => void;
  onStepNext: () => void;
  onStepPrev: () => void;
  onReset: () => void;
  onSetSpeed: (speed: number) => void;
}

export const ExecutionControls: React.FC<ExecutionControlsProps> = ({
  currentStepIndex,
  isPlaying,
  speed,
  onSetStep,
  onTogglePlay,
  onStepNext,
  onStepPrev,
  onReset,
  onSetSpeed,
}) => {
  const currentStep = LECTURE_STEPS[currentStepIndex] || LECTURE_STEPS[0];

  return (
    <div className="bg-slate-800/80 border border-slate-700/80 rounded-xl p-3 shadow-md space-y-2.5">
      {/* Top row: Steps Timeline and Action Buttons */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        {/* Step Buttons */}
        <div className="flex items-center gap-1.5 flex-wrap">
          <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-1 mr-1">
            <Clock className="w-3.5 h-3.5 text-sky-400" />
            Progression:
          </span>
          {LECTURE_STEPS.map((step, idx) => {
            const isCurrent = currentStepIndex === idx;
            const isPast = currentStepIndex > idx;
            return (
              <button
                key={step.id}
                id={`btn-step-${step.id}`}
                onClick={() => onSetStep(idx)}
                className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-all flex items-center gap-1.5 ${
                  isCurrent
                    ? 'bg-sky-500 text-white shadow-md shadow-sky-500/20 ring-1 ring-sky-300'
                    : isPast
                    ? 'bg-slate-700/80 text-sky-200 hover:bg-slate-700'
                    : 'bg-slate-800 text-slate-400 hover:text-slate-200 hover:bg-slate-700/60'
                }`}
              >
                <span className="w-4 h-4 rounded-full bg-slate-900/50 flex items-center justify-center text-[10px] font-mono">
                  {idx}
                </span>
                <span>{idx === 0 ? 'Init' : step.label.split(':')[0]}</span>
              </button>
            );
          })}
        </div>

        {/* Playback Controls */}
        <div className="flex items-center gap-1.5 self-end sm:self-auto">
          <button
            id="btn-step-prev"
            onClick={onStepPrev}
            disabled={currentStepIndex <= 0}
            className="p-1.5 rounded-lg bg-slate-700/70 hover:bg-slate-700 text-slate-200 disabled:opacity-40 disabled:cursor-not-allowed transition"
            title="Previous Step"
          >
            <SkipBack className="w-4 h-4" />
          </button>

          <button
            id="btn-play-pause"
            onClick={onTogglePlay}
            className={`px-3 py-1.5 rounded-lg font-medium text-xs flex items-center gap-1.5 transition ${
              isPlaying
                ? 'bg-amber-500 hover:bg-amber-400 text-slate-950 font-semibold'
                : 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-sm'
            }`}
          >
            {isPlaying ? (
              <>
                <Pause className="w-3.5 h-3.5 fill-current" />
                <span>Pause</span>
              </>
            ) : (
              <>
                <Play className="w-3.5 h-3.5 fill-current" />
                <span>Auto Play</span>
              </>
            )}
          </button>

          <button
            id="btn-step-next"
            onClick={onStepNext}
            disabled={currentStepIndex >= LECTURE_STEPS.length - 1}
            className="p-1.5 rounded-lg bg-slate-700/70 hover:bg-slate-700 text-slate-200 disabled:opacity-40 disabled:cursor-not-allowed transition"
            title="Next Step"
          >
            <SkipForward className="w-4 h-4" />
          </button>

          <div className="h-4 w-px bg-slate-700 mx-1" />

          {/* Speed Selector */}
          <div className="flex items-center bg-slate-900/60 rounded-lg p-0.5 border border-slate-700/60 text-[11px]">
            {[0.5, 1, 1.5].map((s) => (
              <button
                key={s}
                onClick={() => onSetSpeed(s)}
                className={`px-1.5 py-0.5 rounded ${
                  speed === s
                    ? 'bg-slate-700 text-sky-400 font-semibold'
                    : 'text-slate-400 hover:text-slate-200'
                }`}
              >
                {s}x
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Step Explanation Banner */}
      <div className="bg-slate-900/90 rounded-lg p-2.5 border border-slate-700/50 flex flex-col md:flex-row md:items-center justify-between gap-2 text-xs">
        <div className="flex items-start md:items-center gap-2">
          <div className="p-1 rounded bg-sky-500/20 text-sky-300 mt-0.5 md:mt-0">
            <Zap className="w-3.5 h-3.5" />
          </div>
          <div>
            <span className="font-semibold text-sky-300 mr-2">{currentStep.label}:</span>
            <span className="text-slate-300 leading-relaxed">{currentStep.description}</span>
          </div>
        </div>
        <div className="text-[11px] font-mono text-slate-400 shrink-0 bg-slate-950 px-2 py-1 rounded border border-slate-800">
          Target: <span className="text-emerald-400 font-semibold">{currentStep.action !== 'initial' ? `${currentStep.action}(${currentStep.amount})` : 'System Initialization'}</span>
        </div>
      </div>
    </div>
  );
};
