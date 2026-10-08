import React from 'react';
import { PerspectiveMode } from '../types';
import { OOP_CODE_LINES } from '../constants';
import { Shield, Lock, Unlock, FileCode, CheckCircle2, XCircle, AlertTriangle, Zap, ShieldCheck } from 'lucide-react';
import { motion, AnimatePresence } from 'motion/react';

interface OOPPanelProps {
  balance: number;
  activeLineIndex: number;
  perspective: PerspectiveMode;
  pulseState: {
    action: 'deposit' | 'withdraw' | 'direct_corrupt';
    amount: number;
    phase: string;
  } | null;
  accessViolationAlert: {
    visible: boolean;
    attemptedValue: number;
    message: string;
  } | null;
  validationAlert: {
    visible: boolean;
    type: 'insufficient_funds' | 'invalid_deposit' | 'passed';
    message: string;
  } | null;
  onDismissAlert?: () => void;
}

export const OOPPanel: React.FC<OOPPanelProps> = ({
  balance,
  activeLineIndex,
  perspective,
  pulseState,
  accessViolationAlert,
  validationAlert,
  onDismissAlert,
}) => {
  const isDirectCorrupt = pulseState?.action === 'direct_corrupt';
  const isDeposit = pulseState?.action === 'deposit';
  const isWithdraw = pulseState?.action === 'withdraw';

  return (
    <div className="flex flex-col h-full bg-slate-900/90 border border-slate-800 rounded-xl overflow-hidden shadow-lg">
      {/* Panel Header */}
      <div className="bg-slate-800/80 px-4 py-3 border-b border-slate-700/80 flex items-center justify-between">
        <div className="flex items-center gap-2">
          <div className="w-2.5 h-2.5 rounded-full bg-indigo-400" />
          <h3 className="font-bold text-sm text-slate-100 uppercase tracking-wide">
            Object-Oriented Programming (OOP)
          </h3>
        </div>
        <span className="text-[11px] font-mono px-2 py-0.5 rounded bg-slate-900 border border-indigo-500/40 text-indigo-300 flex items-center gap-1">
          <ShieldCheck className="w-3 h-3 text-indigo-400" />
          Encapsulated Class Instance
        </span>
      </div>

      {/* Main Canvas Area */}
      <div className="p-4 flex-1 flex flex-col gap-4">
        {/* Unified Class Instance Container: BankAccount myAccount(50) */}
        <div className="relative bg-slate-950/80 border-2 border-indigo-500/80 rounded-2xl p-4 min-h-[320px] flex flex-col justify-between shadow-2xl shadow-indigo-950/40">
          {/* Class Container Header / Solid Boundary Badge */}
          <div className="flex items-center justify-between border-b border-indigo-900/50 pb-2">
            <div className="flex items-center gap-2">
              <span className="px-2 py-0.5 rounded bg-indigo-950 text-indigo-300 border border-indigo-800 text-[11px] font-mono font-semibold">
                class BankAccount
              </span>
              <span className="text-xs font-mono text-slate-300">
                instance: <span className="text-indigo-400 font-bold">myAccount</span>
              </span>
            </div>
            <div className="flex items-center gap-1.5 text-[10px] font-mono text-indigo-300 bg-indigo-950/60 px-2 py-0.5 rounded-full border border-indigo-800/50">
              <Lock className="w-3 h-3 text-indigo-400" />
              <span>Scope Boundary Enforced</span>
            </div>
          </div>

          {/* Central Protected Chamber with Semi-Transparent Protective Shield */}
          <div className="relative my-auto flex flex-col items-center justify-center py-4">
            {/* The Semi-Transparent Protective Shield Visual Barrier */}
            <div className="relative p-5 rounded-2xl transition-all duration-500 flex flex-col items-center justify-center">
              {/* Shield Glow Ring / Membrane */}
              <div
                className={`absolute inset-0 rounded-2xl transition-all duration-300 pointer-events-none ${
                  accessViolationAlert?.visible
                    ? 'border-2 border-rose-500 bg-rose-500/15 shadow-[0_0_30px_rgba(244,63,94,0.6)] animate-pulse'
                    : 'border-2 border-cyan-400/60 bg-gradient-to-b from-cyan-500/10 to-indigo-500/10 shadow-[0_0_20px_rgba(34,211,238,0.25)]'
                }`}
              />

              {/* Shield Label */}
              <div className="relative z-10 -top-1 px-3 py-0.5 rounded-full bg-slate-900/90 border border-cyan-400/50 text-[10px] font-mono uppercase tracking-wider text-cyan-300 flex items-center gap-1.5 shadow-sm">
                <Shield className="w-3 h-3 text-cyan-400" />
                <span>Protective Boundary Shield (Private Zone)</span>
              </div>

              {/* Private balance cell */}
              <motion.div
                animate={
                  accessViolationAlert?.visible
                    ? { x: [-4, 4, -4, 4, 0], scale: 1.02 }
                    : pulseState
                    ? { scale: [1, 1.05, 1] }
                    : {}
                }
                transition={{ duration: 0.3 }}
                className="relative z-10 w-56 mt-2 p-3 rounded-xl bg-slate-900/95 border border-indigo-500/60 shadow-lg text-center"
              >
                <div className="flex items-center justify-between text-[11px] font-mono text-slate-300">
                  <span className="flex items-center gap-1 text-cyan-400 font-bold">
                    <Lock className="w-3 h-3" />
                    private: balance
                  </span>
                  <span className="text-[10px] text-emerald-400 bg-emerald-950/60 px-1.5 py-0.2 rounded border border-emerald-800/50">
                    Protected
                  </span>
                </div>

                {/* Balance Value */}
                <div className="mt-2 py-2 px-3 rounded-lg bg-slate-950 border border-slate-800 flex items-baseline justify-center gap-1">
                  <span className="text-xs text-slate-500 font-mono">val:</span>
                  <span
                    id="oop-balance-value"
                    className="text-2xl font-bold font-mono text-cyan-300"
                  >
                    {balance.toFixed(1)}
                  </span>
                </div>

                <p className="text-[10px] text-slate-400 mt-1">
                  Direct external write rejected at compile time
                </p>
              </motion.div>

              {/* Invariant guarantee badge */}
              <div className="relative z-10 mt-2 text-center">
                <span className="text-[10px] font-mono px-2.5 py-0.5 rounded bg-emerald-950/70 text-emerald-300 border border-emerald-800/60 flex items-center gap-1">
                  <CheckCircle2 className="w-3 h-3 text-emerald-400" />
                  Invariant Guaranteed: balance &ge; 0.0
                </span>
              </div>
            </div>

            {/* Bouncing Animation / Access Violation Overlay (Control 1 Outcome) */}
            <AnimatePresence>
              {accessViolationAlert?.visible && (
                <motion.div
                  initial={{ opacity: 0, scale: 0.8, y: -10 }}
                  animate={{ opacity: 1, scale: 1, y: 0 }}
                  exit={{ opacity: 0, scale: 0.8 }}
                  className="absolute z-30 inset-x-2 top-8 p-3 rounded-xl bg-rose-950/95 border-2 border-rose-500 shadow-2xl text-rose-100 space-y-1.5"
                >
                  <div className="flex items-center justify-between text-xs font-bold text-rose-300">
                    <span className="flex items-center gap-1.5">
                      <XCircle className="w-4 h-4 text-rose-400" />
                      ACCESS VIOLATION: balance is private!
                    </span>
                    <span className="text-[10px] bg-rose-900/80 px-1.5 py-0.5 rounded font-mono">
                      COMPILATION ERROR
                    </span>
                  </div>
                  <p className="text-[11px] text-rose-200 leading-snug">
                    {accessViolationAlert.message}
                  </p>
                  <div className="bg-black/60 p-1.5 rounded font-mono text-[10px] text-rose-300 border border-rose-900">
                    <code>myAccount.balance = {accessViolationAlert.attemptedValue}.0; // ❌ REJECTED</code>
                  </div>
                </motion.div>
              )}
            </AnimatePresence>

            {/* Validation Alert Overlay (e.g. Insufficient Funds in Control 2) */}
            <AnimatePresence>
              {validationAlert?.visible && (
                <motion.div
                  initial={{ opacity: 0, scale: 0.9, y: 10 }}
                  animate={{ opacity: 1, scale: 1, y: 0 }}
                  exit={{ opacity: 0, scale: 0.9 }}
                  className={`absolute z-30 inset-x-2 bottom-12 p-3 rounded-xl border shadow-xl text-xs ${
                    validationAlert.type === 'insufficient_funds'
                      ? 'bg-amber-950/95 border-amber-500 text-amber-100'
                      : 'bg-emerald-950/95 border-emerald-500 text-emerald-100'
                  }`}
                >
                  <div className="flex items-center justify-between font-bold">
                    <span className="flex items-center gap-1.5">
                      {validationAlert.type === 'insufficient_funds' ? (
                        <AlertTriangle className="w-4 h-4 text-amber-400" />
                      ) : (
                        <CheckCircle2 className="w-4 h-4 text-emerald-400" />
                      )}
                      {validationAlert.type === 'insufficient_funds'
                        ? 'Portal Validation: Insufficient Funds!'
                        : 'Portal Validation: Passed'}
                    </span>
                  </div>
                  <p className="text-[11px] mt-1 leading-snug">
                    {validationAlert.message}
                  </p>
                </motion.div>
              )}
            </AnimatePresence>
          </div>

          {/* Flanked Public Interface Portal Gates */}
          <div className="relative z-10 grid grid-cols-2 gap-3 pt-3 border-t border-indigo-900/50">
            {/* Portal Gate 1: deposit(amount) */}
            <div
              className={`p-2.5 rounded-lg border-2 transition-all relative ${
                isDeposit
                  ? 'bg-emerald-950/70 border-emerald-400 shadow-md shadow-emerald-500/20'
                  : 'bg-slate-900/80 border-cyan-500/40'
              }`}
            >
              <div className="flex items-center justify-between text-[11px] font-mono">
                <span className="font-bold text-emerald-400 flex items-center gap-1">
                  <Unlock className="w-3 h-3 text-emerald-400" />
                  deposit(amount)
                </span>
                <span className="text-[9px] px-1.5 py-0.2 rounded bg-emerald-950 border border-emerald-800 text-emerald-300 uppercase font-semibold">
                  Public Portal
                </span>
              </div>
              <div className="mt-1 text-[10px] font-mono text-slate-400">
                Rule: if (amount &gt; 0) balance += amount;
              </div>
              {isDeposit && (
                <div className="mt-1 text-[9px] text-emerald-300 font-mono flex items-center gap-1 animate-pulse">
                  <Zap className="w-2.5 h-2.5 text-emerald-400" />
                  <span>Pulse validated &amp; authorized</span>
                </div>
              )}
            </div>

            {/* Portal Gate 2: withdraw(amount) */}
            <div
              className={`p-2.5 rounded-lg border-2 transition-all relative ${
                isWithdraw
                  ? validationAlert?.type === 'insufficient_funds'
                    ? 'bg-rose-950/70 border-rose-500 shadow-md shadow-rose-500/20'
                    : 'bg-sky-950/70 border-sky-400 shadow-md shadow-sky-500/20'
                  : 'bg-slate-900/80 border-cyan-500/40'
              }`}
            >
              <div className="flex items-center justify-between text-[11px] font-mono">
                <span className="font-bold text-sky-400 flex items-center gap-1">
                  <Unlock className="w-3 h-3 text-sky-400" />
                  withdraw(amount)
                </span>
                <span className="text-[9px] px-1.5 py-0.2 rounded bg-sky-950 border border-sky-800 text-sky-300 uppercase font-semibold">
                  Public Portal
                </span>
              </div>
              <div className="mt-1 text-[10px] font-mono text-slate-400">
                Rule: if (amount &gt; balance) reject;
              </div>
              {isWithdraw && (
                <div className="mt-1 text-[9px] font-mono flex items-center gap-1">
                  {validationAlert?.type === 'insufficient_funds' ? (
                    <span className="text-rose-400 font-bold">❌ Blocked: Insufficient funds</span>
                  ) : (
                    <span className="text-sky-300 animate-pulse">
                      <Zap className="w-2.5 h-2.5 inline mr-1 text-sky-400" />
                      Condition evaluated (TRUE) &rarr; updating
                    </span>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Synchronized OOP Code View */}
        <div className="bg-slate-950 rounded-lg p-2.5 border border-slate-800 text-[11px] font-mono overflow-x-auto max-h-48">
          <div className="flex items-center justify-between pb-1 mb-1.5 border-b border-slate-800 text-slate-400 text-[10px]">
            <span className="flex items-center gap-1">
              <FileCode className="w-3 h-3 text-indigo-400" />
              BankAccount.h &amp; main.cpp
            </span>
            <span className="text-indigo-400 font-semibold">Encapsulated</span>
          </div>
          <div className="space-y-0.5">
            {OOP_CODE_LINES.map((line, idx) => {
              const isHighlight = activeLineIndex === idx;
              const isRejectedAttempt = idx === 20 && accessViolationAlert?.visible;
              return (
                <div
                  key={idx}
                  className={`px-1.5 py-0.5 rounded transition-colors flex items-center gap-2 ${
                    isRejectedAttempt
                      ? 'bg-rose-950/80 text-rose-300 font-bold border-l-2 border-rose-500'
                      : isHighlight
                      ? 'bg-indigo-600/30 text-indigo-200 font-semibold border-l-2 border-indigo-400'
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
