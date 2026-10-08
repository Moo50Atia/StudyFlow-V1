import React, { useState } from 'react';
import { ShieldAlert, ArrowRight, Play, AlertTriangle, CheckCircle2, Sliders, Lock, Unlock, HelpCircle } from 'lucide-react';

interface InvestigationPanelProps {
  onDirectCorruptAttempt: (val: number) => void;
  onMethodTrigger: (method: 'deposit' | 'withdraw', amount: number) => void;
  proceduralBalance: number;
  oopBalance: number;
  proceduralCorrupted: boolean;
  onRestoreProcedural: () => void;
}

export const InvestigationPanel: React.FC<InvestigationPanelProps> = ({
  onDirectCorruptAttempt,
  onMethodTrigger,
  proceduralBalance,
  oopBalance,
  proceduralCorrupted,
  onRestoreProcedural,
}) => {
  const [customAmount, setCustomAmount] = useState<number>(50);
  const [corruptVal, setCorruptVal] = useState<number>(-200);

  return (
    <div className="bg-slate-900/95 border border-slate-800 rounded-xl p-4 shadow-xl space-y-4">
      <div className="flex items-center justify-between border-b border-slate-800 pb-2.5">
        <div className="flex items-center gap-2">
          <Sliders className="w-4 h-4 text-indigo-400" />
          <h2 className="text-sm font-bold text-white tracking-wide uppercase">
            Learner Investigations & Boundary Probes
          </h2>
        </div>
        <span className="text-[11px] text-slate-400 bg-slate-800 px-2 py-0.5 rounded border border-slate-700">
          Governing Rules 1–4
        </span>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {/* Investigation 1: Direct Memory Modification (Vulnerability Probe) */}
        <div className="bg-slate-800/60 border border-slate-700/80 rounded-lg p-3.5 space-y-3 relative overflow-hidden">
          <div className="flex items-start justify-between gap-2">
            <div>
              <div className="flex items-center gap-1.5 text-xs font-bold text-rose-400">
                <ShieldAlert className="w-4 h-4 text-rose-400 shrink-0" />
                <span>Control 1: Direct Memory Modification</span>
              </div>
              <p className="text-[11px] text-slate-400 mt-0.5">
                Attempt to assign rogue value directly to memory without calling functions.
              </p>
            </div>
            <span className="text-[10px] px-1.5 py-0.5 rounded bg-rose-950/70 text-rose-300 border border-rose-800/60 font-mono">
              balance = {corruptVal}
            </span>
          </div>

          <div className="bg-slate-900/80 p-2.5 rounded border border-slate-800 space-y-2 text-xs">
            <div className="flex items-center justify-between text-[11px] text-slate-300">
              <span>Simulate Rogue External Code:</span>
              <code className="text-amber-300 bg-slate-950 px-1.5 py-0.5 rounded text-[11px]">
                balance = {corruptVal}.0;
              </code>
            </div>

            <div className="flex items-center gap-2">
              <button
                id="btn-corrupt-200"
                onClick={() => onDirectCorruptAttempt(corruptVal)}
                className="flex-1 py-2 px-3 bg-gradient-to-r from-rose-600 to-amber-600 hover:from-rose-500 hover:to-amber-500 text-white font-semibold rounded-md shadow-md text-xs transition flex items-center justify-center gap-2 active:scale-98"
              >
                <ShieldAlert className="w-3.5 h-3.5" />
                <span>Inject Direct Write ({corruptVal}.0)</span>
              </button>

              {proceduralCorrupted && (
                <button
                  id="btn-restore-procedural"
                  onClick={onRestoreProcedural}
                  className="px-2.5 py-2 bg-slate-700 hover:bg-slate-600 text-slate-200 rounded-md text-[11px] transition"
                  title="Restore procedural variable"
                >
                  Clear Corruption
                </button>
              )}
            </div>

            {/* Micro comparison outcome preview */}
            <div className="grid grid-cols-2 gap-2 pt-1 text-[10px]">
              <div className="p-1.5 rounded bg-rose-950/40 border border-rose-900/50 text-rose-200">
                <span className="font-semibold block text-rose-400">Procedural:</span>
                Accepts invalid negative value directly into global memory cell.
              </div>
              <div className="p-1.5 rounded bg-emerald-950/40 border border-emerald-900/50 text-emerald-200">
                <span className="font-semibold block text-emerald-400">OOP (Protected):</span>
                Bounces off private shield. Triggers <span className="underline">Access Violation</span>.
              </div>
            </div>
          </div>
        </div>

        {/* Investigation 2: Method Call Trigger (Public Interface Portal) */}
        <div className="bg-slate-800/60 border border-slate-700/80 rounded-lg p-3.5 space-y-3">
          <div className="flex items-start justify-between gap-2">
            <div>
              <div className="flex items-center gap-1.5 text-xs font-bold text-sky-400">
                <Lock className="w-4 h-4 text-sky-400 shrink-0" />
                <span>Control 2: Method Call Trigger</span>
              </div>
              <p className="text-[11px] text-slate-400 mt-0.5">
                Route operations through public portal gates with active validation checks.
              </p>
            </div>
            <span className="text-[10px] px-1.5 py-0.5 rounded bg-sky-950/70 text-sky-300 border border-sky-800/60 font-mono">
              Portal Gate
            </span>
          </div>

          <div className="bg-slate-900/80 p-2.5 rounded border border-slate-800 space-y-2.5 text-xs">
            {/* Slider control */}
            <div className="space-y-1">
              <div className="flex items-center justify-between text-[11px]">
                <span className="text-slate-300">Amount Parameter:</span>
                <span className="font-mono text-sky-300 font-bold">${customAmount}.0</span>
              </div>
              <input
                id="slider-amount"
                type="range"
                min={0}
                max={200}
                step={10}
                value={customAmount}
                onChange={(e) => setCustomAmount(Number(e.target.value))}
                className="w-full h-1.5 bg-slate-700 rounded-lg appearance-none cursor-pointer accent-sky-500"
              />
              <div className="flex justify-between text-[10px] text-slate-400 font-mono">
                <span>$0</span>
                <span>$50</span>
                <span>$100</span>
                <span>$150</span>
                <span>$200</span>
              </div>
            </div>

            {/* Trigger buttons */}
            <div className="grid grid-cols-2 gap-2">
              <button
                id="btn-trigger-deposit"
                onClick={() => onMethodTrigger('deposit', customAmount)}
                className="py-1.5 px-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-md font-medium text-xs transition flex items-center justify-center gap-1.5 active:scale-98"
              >
                <span>deposit({customAmount})</span>
              </button>

              <button
                id="btn-trigger-withdraw"
                onClick={() => onMethodTrigger('withdraw', customAmount)}
                className="py-1.5 px-2.5 bg-sky-600 hover:bg-sky-500 text-white rounded-md font-medium text-xs transition flex items-center justify-center gap-1.5 active:scale-98"
              >
                <span>withdraw({customAmount})</span>
              </button>
            </div>

            {/* Quick Overdraft test */}
            <div className="pt-1 flex items-center justify-between border-t border-slate-800/80 text-[11px]">
              <span className="text-slate-400">Boundary Condition Test:</span>
              <button
                id="btn-test-overdraft"
                onClick={() => onMethodTrigger('withdraw', 180)}
                className="text-amber-400 hover:text-amber-300 hover:underline flex items-center gap-1 text-[11px]"
              >
                <AlertTriangle className="w-3 h-3" />
                <span>Test Insufficient Funds ($180)</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};
