import React from 'react';
import { Shield, ShieldAlert, CheckCircle2, XCircle, Info, ArrowRight, Lightbulb } from 'lucide-react';

export const MisconceptionContrast: React.FC = () => {
  return (
    <div className="bg-slate-900/90 border border-slate-800 rounded-xl p-4 shadow-xl space-y-3">
      <div className="flex items-center gap-2 border-b border-slate-800 pb-2">
        <Lightbulb className="w-4 h-4 text-amber-400" />
        <h3 className="text-xs font-bold text-white uppercase tracking-wider">
          Core Misconception Contrast: Is OOP Just Procedural Code with Extra Syntax?
        </h3>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
        {/* Misconception */}
        <div className="p-3 rounded-lg bg-rose-950/20 border border-rose-900/50 space-y-1.5">
          <div className="flex items-center gap-1.5 font-bold text-rose-400">
            <XCircle className="w-4 h-4 shrink-0" />
            <span>Common Novice Misconception</span>
          </div>
          <p className="text-slate-300 leading-relaxed text-[11px]">
            &quot;An object is just a record struct holding data, and methods are just regular functions written inside a class definition. It does the exact same thing with extra boilerplate syntax.&quot;
          </p>
        </div>

        {/* The Reality Disproved by Visual Scene */}
        <div className="p-3 rounded-lg bg-emerald-950/20 border border-emerald-900/50 space-y-1.5">
          <div className="flex items-center gap-1.5 font-bold text-emerald-400">
            <CheckCircle2 className="w-4 h-4 shrink-0" />
            <span>Educational Truth Revealed</span>
          </div>
          <p className="text-slate-300 leading-relaxed text-[11px]">
            OOP fundamentally establishes an <strong className="text-emerald-300">architectural access boundary</strong>. The compiler actively halts unauthorized direct memory mutation. Invariants (e.g., balance cannot drop arbitrarily or turn negative) are guaranteed, protecting software systems from global corruption.
          </p>
        </div>
      </div>

      {/* 4 Governing Rules Comparison Table */}
      <div className="pt-2 border-t border-slate-800/80">
        <span className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider block mb-2">
          Formal Governing Rules Verification
        </span>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 text-[11px]">
          <div className="bg-slate-950/60 p-2 rounded border border-slate-800">
            <span className="text-amber-400 font-bold block mb-0.5">1. Procedural Rule</span>
            <span className="text-slate-300">Any global statement can freely read/write `balance` directly, leaving memory exposed.</span>
          </div>
          <div className="bg-slate-950/60 p-2 rounded border border-slate-800">
            <span className="text-indigo-400 font-bold block mb-0.5">2. Encapsulation Rule</span>
            <span className="text-slate-300">Direct external access to `private: balance` is rejected by compile-time scope enforcement.</span>
          </div>
          <div className="bg-slate-950/60 p-2 rounded border border-slate-800">
            <span className="text-cyan-400 font-bold block mb-0.5">3. Method Gateway Rule</span>
            <span className="text-slate-300">State mutations occur strictly through public member functions (`deposit`, `withdraw`).</span>
          </div>
          <div className="bg-slate-950/60 p-2 rounded border border-slate-800">
            <span className="text-emerald-400 font-bold block mb-0.5">4. Validation Rule</span>
            <span className="text-slate-300">Methods enforce business constraints (`amount &gt; balance &rarr; Insufficient funds`, halting state change).</span>
          </div>
        </div>
      </div>
    </div>
  );
};
