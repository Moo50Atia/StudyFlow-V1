/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

import React, { useState, useEffect, useRef } from 'react';
import { PerspectiveMode, SimulationState } from './types';
import { LECTURE_STEPS, INITIAL_PROCEDURAL_BALANCE, INITIAL_OOP_BALANCE } from './constants';
import { Header } from './components/Header';
import { ExecutionControls } from './components/ExecutionControls';
import { InvestigationPanel } from './components/InvestigationPanel';
import { ProceduralPanel } from './components/ProceduralPanel';
import { OOPPanel } from './components/OOPPanel';
import { MisconceptionContrast } from './components/MisconceptionContrast';

export default function App() {
  // Main simulation state
  const [currentStepIndex, setCurrentStepIndex] = useState<number>(0);
  const [isPlaying, setIsPlaying] = useState<boolean>(false);
  const [speed, setSpeed] = useState<number>(1);
  const [perspective, setPerspective] = useState<PerspectiveMode>('unified');

  // Balances
  const [proceduralBalance, setProceduralBalance] = useState<number>(INITIAL_PROCEDURAL_BALANCE);
  const [oopBalance, setOopBalance] = useState<number>(INITIAL_OOP_BALANCE);
  const [proceduralCorrupted, setProceduralCorrupted] = useState<boolean>(false);

  // Active Line Highlights for Code Viewers
  const [proceduralCodeLine, setProceduralCodeLine] = useState<number>(1);
  const [oopCodeLine, setOopCodeLine] = useState<number>(14);

  // Pulse & Alert states
  const [pulseState, setPulseState] = useState<{
    action: 'deposit' | 'withdraw' | 'direct_corrupt';
    amount: number;
    phase: string;
  } | null>(null);

  const [accessViolationAlert, setAccessViolationAlert] = useState<{
    visible: boolean;
    attemptedValue: number;
    message: string;
  } | null>(null);

  const [validationAlert, setValidationAlert] = useState<{
    visible: boolean;
    type: 'insufficient_funds' | 'invalid_deposit' | 'passed';
    message: string;
  } | null>(null);

  const timerRef = useRef<NodeJS.Timeout | null>(null);

  // Reset to initial conditions
  const handleReset = () => {
    if (timerRef.current) clearTimeout(timerRef.current);
    setIsPlaying(false);
    setCurrentStepIndex(0);
    setProceduralBalance(INITIAL_PROCEDURAL_BALANCE);
    setOopBalance(INITIAL_OOP_BALANCE);
    setProceduralCorrupted(false);
    setProceduralCodeLine(1);
    setOopCodeLine(14);
    setPulseState(null);
    setAccessViolationAlert(null);
    setValidationAlert(null);
  };

  // Synchronize state when stepping through lecture progression (Steps 0, 1, 2, 3)
  const applyStep = (stepIdx: number) => {
    setCurrentStepIndex(stepIdx);
    setAccessViolationAlert(null);
    setValidationAlert(null);

    const step = LECTURE_STEPS[stepIdx];
    if (!step) return;

    setProceduralCodeLine(step.codeHighlightProcedural);
    setOopCodeLine(step.codeHighlightOOP);

    if (step.action === 'initial') {
      setProceduralBalance(INITIAL_PROCEDURAL_BALANCE);
      setOopBalance(INITIAL_OOP_BALANCE);
      setProceduralCorrupted(false);
      setPulseState(null);
      return;
    }

    // Trigger pulse
    setPulseState({
      action: step.action as 'deposit' | 'withdraw',
      amount: step.amount,
      phase: 'validating',
    });

    // Update balances after short animation phase
    setTimeout(() => {
      if (stepIdx === 1) {
        // Step 1: deposit(100) -> procedural 100.0, oop 50 + 100 = 150.0
        setProceduralBalance(100.0);
        setOopBalance(150.0);
      } else if (stepIdx === 2) {
        // Step 2: withdraw(30) -> procedural 70.0, oop 150 - 30 = 120.0
        setProceduralBalance(70.0);
        setOopBalance(120.0);
      } else if (stepIdx === 3) {
        // Step 3: withdraw(80) -> condition 80 <= 120 is true -> oop: 40.0
        // procedural: 70 - 80 = -10.0 (unprotected overdraft) or 40.0
        setProceduralBalance(-10.0);
        setProceduralCorrupted(true);
        setOopBalance(40.0);
      }

      setPulseState((prev) => (prev ? { ...prev, phase: 'complete' } : null));

      setTimeout(() => {
        setPulseState(null);
      }, 1000 / speed);
    }, 600 / speed);
  };

  // Step Next / Prev
  const handleStepNext = () => {
    if (currentStepIndex < LECTURE_STEPS.length - 1) {
      applyStep(currentStepIndex + 1);
    }
  };

  const handleStepPrev = () => {
    if (currentStepIndex > 0) {
      applyStep(currentStepIndex - 1);
    }
  };

  // Auto play sequence
  useEffect(() => {
    if (!isPlaying) {
      if (timerRef.current) clearTimeout(timerRef.current);
      return;
    }

    const intervalMs = (2800 / speed);
    timerRef.current = setTimeout(() => {
      if (currentStepIndex < LECTURE_STEPS.length - 1) {
        applyStep(currentStepIndex + 1);
      } else {
        setIsPlaying(false);
      }
    }, intervalMs);

    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  }, [isPlaying, currentStepIndex, speed]);

  // Investigation Control 1: Direct Memory Modification attempt
  const handleDirectCorruptAttempt = (val: number) => {
    setPulseState({
      action: 'direct_corrupt',
      amount: val,
      phase: 'deflected',
    });

    // In Procedural: accepts rogue assignment directly into naked global balance
    setProceduralBalance(val);
    setProceduralCorrupted(true);
    setProceduralCodeLine(16); // balance = -200.0;

    // In OOP: bounces off protective shield! Access Violation alert
    setOopCodeLine(20); // myAccount.balance = -200.0;
    setAccessViolationAlert({
      visible: true,
      attemptedValue: val,
      message:
        "Access Violation: 'balance' has private access specifier in class 'BankAccount'. Direct external modification is forbidden by compile-time encapsulation rules.",
    });

    // Clear alert after 5 seconds if not dismissed
    setTimeout(() => {
      setPulseState(null);
    }, 1200);
  };

  const handleRestoreProcedural = () => {
    setProceduralBalance(INITIAL_PROCEDURAL_BALANCE);
    setProceduralCorrupted(false);
    setProceduralCodeLine(1);
  };

  // Investigation Control 2: Method Call Trigger (deposit / withdraw with validation)
  const handleMethodTrigger = (method: 'deposit' | 'withdraw', amount: number) => {
    setAccessViolationAlert(null);
    setValidationAlert(null);

    setPulseState({
      action: method,
      amount,
      phase: 'validating',
    });

    if (method === 'deposit') {
      setProceduralCodeLine(12);
      setOopCodeLine(16);

      setTimeout(() => {
        // Procedural directly adds
        setProceduralBalance((prev) => prev + amount);

        // OOP validates amount > 0
        if (amount > 0) {
          setOopBalance((prev) => prev + amount);
          setValidationAlert({
            visible: true,
            type: 'passed',
            message: `deposit(${amount}.0) authorized through public portal. Internal private balance successfully incremented.`,
          });
        } else {
          setValidationAlert({
            visible: true,
            type: 'invalid_deposit',
            message: `deposit(${amount}.0) rejected: deposit amount must be strictly positive.`,
          });
        }

        setTimeout(() => {
          setPulseState(null);
        }, 800);
      }, 500);
    } else {
      // withdraw
      setProceduralCodeLine(13);
      setOopCodeLine(17);

      setTimeout(() => {
        // Procedural blindly decrements (even below zero)
        setProceduralBalance((prev) => {
          const next = prev - amount;
          if (next < 0) setProceduralCorrupted(true);
          return next;
        });

        // OOP validates condition: amount <= balance
        if (amount > oopBalance) {
          // Insufficient funds! Halt state change
          setValidationAlert({
            visible: true,
            type: 'insufficient_funds',
            message: `Validation Rule Enforced: amount (${amount}.0) > balance (${oopBalance.toFixed(
              1
            )}). Output: 'Insufficient funds!'. State change halted.`,
          });
        } else {
          setOopBalance((prev) => prev - amount);
          setValidationAlert({
            visible: true,
            type: 'passed',
            message: `withdraw(${amount}.0) validated (amount <= balance). Private state securely updated.`,
          });
        }

        setTimeout(() => {
          setPulseState(null);
        }, 800);
      }, 500);
    }
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans selection:bg-indigo-500/30">
      {/* Top Header */}
      <Header
        perspective={perspective}
        onSelectPerspective={setPerspective}
        onReset={handleReset}
        isCorrupted={proceduralCorrupted}
      />

      {/* Main Container */}
      <main className="max-w-7xl w-full mx-auto p-4 flex-1 flex flex-col gap-4">
        {/* Execution Order Progression Timeline & Playback Bar */}
        <ExecutionControls
          currentStepIndex={currentStepIndex}
          isPlaying={isPlaying}
          speed={speed}
          onSetStep={applyStep}
          onTogglePlay={() => setIsPlaying(!isPlaying)}
          onStepNext={handleStepNext}
          onStepPrev={handleStepPrev}
          onReset={handleReset}
          onSetSpeed={setSpeed}
        />

        {/* Primary Interactive Canvas: Split-Screen Comparison */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 flex-1">
          {/* Left Panel: Procedural Programming */}
          {(perspective === 'unified' || perspective === 'coupling') && (
            <div className={perspective === 'coupling' ? 'lg:col-span-1' : ''}>
              <ProceduralPanel
                balance={proceduralBalance}
                isCorrupted={proceduralCorrupted}
                activeLineIndex={proceduralCodeLine}
                perspective={perspective}
                pulseState={pulseState}
              />
            </div>
          )}

          {/* Right Panel: Object-Oriented Programming */}
          {(perspective === 'unified' || perspective === 'encapsulation' || perspective === 'coupling') && (
            <div className={perspective === 'encapsulation' ? 'lg:col-span-2' : ''}>
              <OOPPanel
                balance={oopBalance}
                activeLineIndex={oopCodeLine}
                perspective={perspective}
                pulseState={pulseState}
                accessViolationAlert={accessViolationAlert}
                validationAlert={validationAlert}
                onDismissAlert={() => {
                  setAccessViolationAlert(null);
                  setValidationAlert(null);
                }}
              />
            </div>
          )}
        </div>

        {/* Learner Investigations & Interactive Boundary Probes */}
        <InvestigationPanel
          onDirectCorruptAttempt={handleDirectCorruptAttempt}
          onMethodTrigger={handleMethodTrigger}
          proceduralBalance={proceduralBalance}
          oopBalance={oopBalance}
          proceduralCorrupted={proceduralCorrupted}
          onRestoreProcedural={handleRestoreProcedural}
        />

        {/* Misconception Contrast & Verification Panel */}
        <MisconceptionContrast />
      </main>

      {/* Subtle Educational Footer */}
      <footer className="border-t border-slate-900 bg-slate-950/80 py-2.5 px-4 text-center text-xs text-slate-500">
        Unit 2: Objects and Classes • Lecture 1 (Sec 1) • Physical pointer addresses and machine stack frames omitted per slide scope boundary.
      </footer>
    </div>
  );
}
