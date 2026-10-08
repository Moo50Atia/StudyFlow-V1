/**
 * Types for the Procedural vs OOP Encapsulation Visualizer
 * Lecture: Unit 2 – Objects and Classes (Lec1: Sec1)
 */

export type PerspectiveMode = 'unified' | 'coupling' | 'encapsulation';

export interface ExecutionStep {
  id: number;
  label: string;
  action: 'deposit' | 'withdraw' | 'direct_corrupt' | 'initial';
  amount: number;
  description: string;
  codeHighlightProcedural: number; // line index in procedural code
  codeHighlightOOP: number; // line index in OOP code
  proceduralExpectedBalance: number;
  oopExpectedBalance: number;
  proceduralOutcome: string;
  oopOutcome: string;
  oopValidationPassed: boolean;
  pulseTarget: 'deposit' | 'withdraw' | 'direct_memory' | 'none';
}

export interface SimulationState {
  stepIndex: number;
  isPlaying: boolean;
  speed: number; // 1x, 0.5x, 1.5x
  perspective: PerspectiveMode;
  
  // Memory states
  proceduralBalance: number;
  oopBalance: number;
  proceduralCorrupted: boolean;
  
  // Animation / Signal pulse states
  activePulse: {
    id: string;
    action: 'deposit' | 'withdraw' | 'direct_corrupt';
    amount: number;
    phase: 'traveling' | 'validating' | 'updating' | 'deflected' | 'complete';
    progress: number;
    targetPanel: 'both' | 'procedural' | 'oop';
  } | null;

  // Access violation event in OOP
  accessViolationAlert: {
    visible: boolean;
    attemptedValue: number;
    message: string;
    timestamp: number;
  } | null;

  // Insufficient funds alert in OOP
  validationAlert: {
    visible: boolean;
    type: 'insufficient_funds' | 'invalid_deposit' | 'passed';
    message: string;
    timestamp: number;
  } | null;

  // Logs of interactions
  executionLog: {
    id: string;
    timestamp: string;
    source: 'procedural' | 'oop';
    call: string;
    result: string;
    isError?: boolean;
    isWarning?: boolean;
  }[];

  // Dragging state for Control 1
  isDraggingCorruptPayload: boolean;
}
