import React from 'react';
import { PerspectiveMode } from '../types';
import { PROCEDURAL_CODE_LINES } from '../constants';
import { AlertOctagon, Terminal, Cpu, Database, ArrowDown, FileCode, Unplug, Zap } from 'lucide-react';
import { motion, AnimatePresence } from 'motion/react';

interface ProceduralPanelProps {
  balance: number;
  isCorrupted: boolean;
  activeLineIndex: number;
  perspective: PerspectiveMode;
  pulseState: {
    action: 'deposit' | 'withdraw' | 'direct_corrupt';
    amount: number;
    phase: string;
  } | null;
}

export const ProceduralPanel: React.FC<ProceduralPanelProps> = ({
  balance,
  isCorrupted,
  activeLineIndex,
  perspective,
  pulseState,
}) => {
  const isDirectCorrupt = pulseState?.action === 'direct_corrupt';

  return (
    <div className="flex flex-col h-full bg-slate-900/90 border border-slate-800 rounded-xl overflow-hidden shadow-lg">
      {/* Panel Header */}
      <div className="bg-slate-800/80 px-4 py-3 border-b border-slate-700/80 flex items-center justify-between">
        <div className="flex items-center gap-2">
          <div className="w-2.5 h-2.5 rounded-full bg-amber-400" />
          <h3 className="font-bold text-sm text-slate-100 uppercase tracking-wide">
            Procedural Programming
          </h3>
        </div>
        <span className="text-[11px] font-mono px-2 py-0.5 rounded bg-slate-900 border border-slate-700 text-amber-300">
          Global Scope • Standalone
        </span>
      </div>

      {/* Main Canvas Area */}
      <div className="p-4 flex-1 flex flex-col gap-4">
        {/* Memory Architecture Diagram */}
        <div className="relative bg-slate-950/70 border border-dashed border-slate-700 rounded-xl p-4 min-h-[320px] flex flex-col justify-between overflow-hidden">
          {/* Background grid indicating naked global heap */}
          <div className="absolute inset-0 bg-[linear-gradient(to_right,#1e293b_1px,transparent_1px),linear-gradient(to_bottom,#1e293b_1px,transparent_1px)] bg-[size:1.5rem_1.5rem] opacity-30 pointer-events-none" />

          {/* Perspective Label */}
          <div className="relative z-10 flex items-center justify-between">
            <div className="flex items-center gap-1.5 text-[11px] font-mono text-slate-400">
              <Unplug className="w-3.5 h-3.5 text-amber-400" />
              <span>Uncoupled Architecture (No Boundary)</span>
            </div>
            {isCorrupted ? (
              <span className="flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded bg-rose-500/20 text-rose-300 border border-rose-500/50 animate-pulse">
                <AlertOctagon className="w-3 h-3" />
                CORRUPTED STATE
              </span>
            ) : (
              <span className="text-[10px] px-2 py-0.5 rounded bg-amber-500/10 text-amber-400 border border-amber-500/30 font-mono">
                Vulnerable to Direct Overwrite
              </span>
            )}
          </div>

          {/* Standalone Global Data Cell (balance) */}
          <div className="relative z-10 my-auto flex flex-col items-center justify-center">
            <div className="text-center mb-1.5">
              <span className="text-[11px] font-mono text-slate-400 uppercase tracking-wider">
                [Global Data Segment]
              </span>
            </div>

            {/* Floating Unattached Memory Cell */}
            <motion.div
              layout
              animate={
                isCorrupted
                  ? { scale: [1, 1.05, 1], borderColor: '#f43f5e' }
                  : pulseState
                  ? { scale: [1, 1.04, 1] }
                  : {}
              }
              transition={{ duration: 0.3 }}
              className={`w-64 p-3.5 rounded-xl border-2 transition-all shadow-xl backdrop-blur relative ${
                isCorrupted
                  ? 'bg-rose-950/60 border-rose-500 shadow-rose-900/30'
                  : 'bg-slate-900/90 border-amber-500/70 shadow-amber-500/10'
              }`}
            >
              {/* Unprotected badge */}
              <div className="absolute -top-3 left-4 px-2 py-0.5 rounded text-[10px] font-mono uppercase tracking-wider bg-slate-900 border border-amber-500/50 text-amber-400 flex items-center gap-1">
                <Database className="w-3 h-3" />
                <span>double balance (Global)</span>
              </div>

              <div className="flex items-center justify-between mt-1">
                <span className="text-xs text-slate-400 font-mono">Address: 0x7FFF00</span>
                <span className={`text-[10px] font-bold uppercase px-1.5 py-0.5 rounded ${
                  isCorrupted ? 'bg-rose-500/30 text-rose-200' : 'bg-slate-800 text-slate-300'
                }`}>
                  {isCorrupted ? 'Invariant Viol.' : 'Directly Mutable'}
                </span>
              </div>

              {/* Balance Value Box */}
              <div className="mt-2 text-center py-2 px-3 rounded-lg bg-slate-950 border border-slate-800 flex items-baseline justify-center gap-1">
                <span className="text-xs text-slate-500 font-mono">val:</span>
                <span
                  id="procedural-balance-value"
                  className={`text-2xl font-bold font-mono ${
                    isCorrupted ? 'text-rose-400' : 'text-amber-400'
                  }`}
                >
                  {balance.toFixed(1)}
                </span>
              </div>

              {/* Status footer */}
              <p className="text-[10px] text-center mt-1.5 text-slate-400">
                {isCorrupted
                  ? 'Negative balance accepted without restriction!'
                  : 'Accessible from any file or global statement'}
              </p>

              {/* Pulse entry indicator if active */}
              {pulseState && (
                <div className="absolute -bottom-2 right-4 px-2 py-0.5 rounded-full bg-sky-500 text-[9px] font-bold text-white flex items-center gap-1 animate-bounce">
                  <Zap className="w-2.5 h-2.5" />
                  <span>Pulse Mutating Memory</span>
                </div>
              )}
            </motion.div>

            {/* Invariant indicator */}
            <div className="mt-2 text-center">
              <span className={`text-[10px] font-mono px-2 py-0.5 rounded ${
                balance < 0
                  ? 'bg-rose-950/80 text-rose-300 border border-rose-800 font-semibold'
                  : 'text-slate-400'
              }`}>
                Invariant (balance &ge; 0): {balance >= 0 ? 'Normal (Unverified)' : 'FAILED: balance < 0'}
              </span>
            </div>
          </div>

          {/* Standalone Functions Area (Separated from data) */}
          <div className="relative z-10 grid grid-cols-2 gap-3 pt-3 border-t border-slate-800/80">
            {/* deposit function box */}
            <div
              className={`p-2.5 rounded-lg border transition-all ${
                pulseState?.action === 'deposit'
                  ? 'bg-sky-950/60 border-sky-400 shadow-md shadow-sky-500/20'
                  : 'bg-slate-900/70 border-slate-800'
              }`}
            >
              <div className="flex items-center justify-between text-[11px] font-mono text-slate-300">
                <span className="font-semibold text-sky-400">deposit(amount)</span>
                <span className="text-[10px] text-slate-500">Standalone</span>
              </div>
              <p className="text-[10px] font-mono text-slate-400 mt-1">
                balance += amount;
              </p>
              {pulseState?.action === 'deposit' && (
                <div className="mt-1 text-[9px] text-sky-300 font-mono animate-pulse">
                  &gt; Overwrites global variable
                </div>
              )}
            </div>

            {/* withdraw function box */}
            <div
              className={`p-2.5 rounded-lg border transition-all ${
                pulseState?.action === 'withdraw'
                  ? 'bg-sky-950/60 border-sky-400 shadow-md shadow-sky-500/20'
                  : 'bg-slate-900/70 border-slate-800'
              }`}
            >
              <div className="flex items-center justify-between text-[11px] font-mono text-slate-300">
                <span className="font-semibold text-sky-400">withdraw(amount)</span>
                <span className="text-[10px] text-slate-500">Standalone</span>
              </div>
              <p className="text-[10px] font-mono text-slate-400 mt-1">
                balance -= amount;
              </p>
              {pulseState?.action === 'withdraw' && (
                <div className="mt-1 text-[9px] text-sky-300 font-mono animate-pulse">
                  &gt; Directly decrements global variable
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Synchronized Code View */}
        <div className="bg-slate-950 rounded-lg p-2.5 border border-slate-800 text-[11px] font-mono overflow-x-auto max-h-48">
          <div className="flex items-center justify-between pb-1 mb-1.5 border-b border-slate-800 text-slate-400 text-[10px]">
            <span className="flex items-center gap-1">
              <FileCode className="w-3 h-3 text-amber-400" />
              procedural_bank.cpp
            </span>
            <span>Unencapsulated</span>
          </div>
          <div className="space-y-0.5">
            {PROCEDURAL_CODE_LINES.map((line, idx) => {
              const isHighlight = activeLineIndex === idx;
              const isCorruptLine = idx === 16 && isCorrupted;
              return (
                <div
                  key={idx}
                  className={`px-1.5 py-0.5 rounded transition-colors flex items-center gap-2 ${
                    isCorruptLine
                      ? 'bg-rose-900/60 text-rose-200 font-bold'
                      : isHighlight
                      ? 'bg-amber-500/20 text-amber-300 font-semibold border-l-2 border-amber-400'
                      : 'text-slate-400 hover:text-slate-300'
                  }`}
                >
                  <span className="w-5 text-right text-slate-600 select-none text-[10px]">
                    {idx + 1}
                  </span>
                  <span className="whitespace-pre">{line || ' '}</span>
                </div>
              );
            })}
          </div>
        </div>
      </div>
    </div>
  );
};
