import React, { useState, useRef } from 'react';
import { useSceneAnimation } from '../../../hooks/useSceneAnimation';
import { Canvas } from '../../../components/SceneCanvas';
import { OrbitControls, Box, Edges } from '@react-three/drei';
import { Text } from '../../../components/SceneText';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath, InlineMath } from 'react-katex';
import { Play, RotateCcw, Check, Calculator, Info, X as Close, Network, ArrowRight, ToggleLeft, ToggleRight } from 'lucide-react';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

// --- 3D Scene Components ---

function SimulationScene({ mode, step, onStepComplete }: any) {
  // References for Equal Bases mode
  const leftBaseRef = useRef<THREE.Group>(null!);
  const leftBaseMatRef = useRef<THREE.MeshStandardMaterial>(null!);
  const leftExpRef = useRef<THREE.Group>(null!);
  const leftExpTextRef = useRef<any>(null!);
  
  const rightTargetRef = useRef<THREE.Group>(null!);
  const rightTargetMatRef = useRef<THREE.MeshStandardMaterial>(null!);
  
  const rightBaseRef = useRef<THREE.Group>(null!);
  const rightBaseMatRef = useRef<THREE.MeshStandardMaterial>(null!);
  const rightExpRef = useRef<THREE.Group>(null!);
  const rightExpTextRef = useRef<any>(null!);
  
  const equalsRef = useRef<any>(null!);

  const isMode1 = mode === 'equal_bases';

  useSceneAnimation(() => {
    // Kill existing tweens to prevent ghosting across steps/modes
    gsap.killTweensOf([
      leftBaseRef.current?.position, leftBaseMatRef.current, leftBaseMatRef.current?.emissive,
      leftExpRef.current?.position, leftExpTextRef.current,
      rightTargetRef.current?.position, rightTargetMatRef.current,
      rightBaseRef.current?.position, rightBaseMatRef.current, rightBaseMatRef.current?.emissive,
      rightExpRef.current?.position, rightExpTextRef.current,
      equalsRef.current
    ]);

    if (step === 0) {
      // SETUP STATE
      gsap.set(equalsRef.current, { fillOpacity: 1 });
      
      if (isMode1) {
        // Mode 1: 2^x = 8
        gsap.set(leftBaseRef.current.position, { y: 0 });
        gsap.set(leftBaseMatRef.current, { opacity: 1, emissiveIntensity: 0 });
        leftBaseMatRef.current.color.set('#2563eb');
        
        gsap.set(leftExpRef.current.position, { x: -1, y: 1.5 });
        leftExpTextRef.current.text = "x";
        leftExpTextRef.current.color = "#ffffff";
        
        gsap.set(rightTargetRef.current.position, { y: 0.75 });
        gsap.set(rightTargetMatRef.current, { opacity: 1 });
        
        gsap.set(rightBaseRef.current.position, { y: 0 });
        gsap.set(rightBaseMatRef.current, { opacity: 0, emissiveIntensity: 0 });
        rightBaseMatRef.current.color.set('#2563eb');
        
        gsap.set(rightExpRef.current.position, { x: 3, y: 1.5 });
        gsap.set(rightExpTextRef.current, { fillOpacity: 0 });
        rightExpTextRef.current.text = "3";
        rightExpTextRef.current.color = "#ffffff";
      } else {
        // Mode 2: 3^x = 5^x
        gsap.set(leftBaseRef.current.position, { y: 0 });
        gsap.set(leftBaseMatRef.current, { opacity: 1, emissiveIntensity: 0 });
        leftBaseMatRef.current.color.set('#db2777'); // Pink
        
        gsap.set(leftExpRef.current.position, { x: -1, y: 1.5 });
        leftExpTextRef.current.text = "x";
        leftExpTextRef.current.color = "#ffffff";
        
        gsap.set(rightTargetMatRef.current, { opacity: 0 }); // Hide the tall block
        
        gsap.set(rightBaseRef.current.position, { y: 0 });
        gsap.set(rightBaseMatRef.current, { opacity: 1, emissiveIntensity: 0 });
        rightBaseMatRef.current.color.set('#f59e0b'); // Orange
        
        gsap.set(rightExpRef.current.position, { x: 3, y: 1.5 });
        gsap.set(rightExpTextRef.current, { fillOpacity: 1 });
        rightExpTextRef.current.text = "x";
        rightExpTextRef.current.color = "#ffffff";
      }
    } 
    else if (step === 1) {
      // FACTOR / ANALYZE
      const tl = gsap.timeline({ onComplete: onStepComplete });
      if (isMode1) {
        // 8 decomposes into 2^3
        tl.to(rightTargetMatRef.current, { opacity: 0, duration: 0.8 }, 0);
        tl.to(rightTargetRef.current.position, { y: -1, duration: 0.8, ease: 'power2.in' }, 0);
        
        tl.to(rightBaseMatRef.current, { opacity: 1, duration: 0.8 }, 0.4);
        tl.to(rightExpTextRef.current, { fillOpacity: 1, duration: 0.8 }, 0.6);
      } else {
        // 3^x = 5^x : Highlight that exponents are equal but bases are not
        tl.to(leftExpRef.current.position, { y: 1.8, duration: 0.3, yoyo: true, repeat: 1 }, 0);
        tl.to(rightExpRef.current.position, { y: 1.8, duration: 0.3, yoyo: true, repeat: 1 }, 0);
      }
    }
    else if (step === 2) {
      // ALIGN / GLOW
      const tl = gsap.timeline({ onComplete: onStepComplete });
      if (isMode1) {
        // Bases glow green to indicate match
        leftBaseMatRef.current.emissive.set('#10b981');
        rightBaseMatRef.current.emissive.set('#10b981');
        tl.to([leftBaseMatRef.current, rightBaseMatRef.current], { emissiveIntensity: 0.8, duration: 0.5 });
      } else {
        // Exponents glow green, Bases glow red to indicate mismatch
        leftBaseMatRef.current.emissive.set('#ef4444');
        rightBaseMatRef.current.emissive.set('#ef4444');
        tl.to([leftBaseMatRef.current, rightBaseMatRef.current], { emissiveIntensity: 0.6, duration: 0.5 });
        tl.to(leftExpTextRef.current, { color: '#10b981', duration: 0.5 }, 0);
        tl.to(rightExpTextRef.current, { color: '#10b981', duration: 0.5 }, 0);
      }
    }
    else if (step === 3) {
      // DROP & SOLVE
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      // Bases fade and drop
      tl.to([leftBaseMatRef.current, rightBaseMatRef.current], { opacity: 0, duration: 1 }, 0);
      tl.to([leftBaseRef.current.position, rightBaseRef.current.position], { y: -2, duration: 1, ease: 'power2.in' }, 0);
      
      // Exponents slide to center
      tl.to(leftExpRef.current.position, { x: -1, y: 0, duration: 1.5, ease: 'back.out(1)' }, 0.5);
      tl.to(rightExpRef.current.position, { x: 1, y: 0, duration: 1.5, ease: 'back.out(1)' }, 0.5);
      
      // Final highlight
      tl.to(leftExpTextRef.current, { color: '#10b981', duration: 0.5 }, 2);
      if (isMode1) {
        tl.to(rightExpTextRef.current, { color: '#10b981', duration: 0.5 }, 2);
      } else {
        // Change right exponent text from 'x' to '0'
        tl.to(rightExpTextRef.current, { fillOpacity: 0, duration: 0.2 }, 1.5);
        tl.call(() => { rightExpTextRef.current.text = "0"; }, undefined, 1.7);
        tl.to(rightExpTextRef.current, { fillOpacity: 1, color: '#10b981', duration: 0.3 }, 1.7);
      }
    }
  }, [step, mode, isMode1]);

  return (
    <group position={[0, -0.5, 0]}>
      {/* Floor Grid */}
      <gridHelper args={[40, 40, '#334155', '#0f172a']} position={[0, -3, 0]} />

      {/* Equals Sign */}
      <Text ref={equalsRef} position={[0, 0, 0]} fontSize={1.2} color="#94a3b8" fontWeight="bold">
        =
      </Text>

      {/* LEFT EXPRESSION */}
      <group>
        <group ref={leftBaseRef} position={[-2, 0, 0]} visible={step < 3}>
          <Box args={[1.5, 1.5, 1.5]}>
            <meshStandardMaterial ref={leftBaseMatRef} transparent opacity={1} />
          </Box>
          <Text position={[0, 0, 0.76]} fontSize={0.8} color="white" fontWeight="bold">
            {isMode1 ? "2" : "3"}
          </Text>
        </group>
        <group ref={leftExpRef} position={[-1, 1.5, 0]}>
          <Text ref={leftExpTextRef} position={[0, 0, 0]} fontSize={1.2} color="white" fontWeight="bold">
            x
          </Text>
        </group>
      </group>

      {/* RIGHT EXPRESSION */}
      <group>
        {/* Target Numeric Block (Used in Mode 1) */}
        <group ref={rightTargetRef} position={[2, 0.75, 0]} visible={isMode1 && step === 0}>
          <Box args={[1.5, 3, 1.5]}>
            <meshStandardMaterial ref={rightTargetMatRef} color="#7e22ce" transparent opacity={1} />
          </Box>
          <Text position={[0, 0, 0.76]} fontSize={1} color="white" fontWeight="bold">
            8
          </Text>
        </group>

        {/* Factored / Unmatched Base */}
        <group ref={rightBaseRef} position={[2, 0, 0]} visible={(!isMode1 || step > 0) && step < 3}>
          <Box args={[1.5, 1.5, 1.5]}>
            <meshStandardMaterial ref={rightBaseMatRef} transparent opacity={0} />
          </Box>
          <Text position={[0, 0, 0.76]} fontSize={0.8} color="white" fontWeight="bold">
            {isMode1 ? "2" : "5"}
          </Text>
        </group>
        
        {/* Right Exponent */}
        <group ref={rightExpRef} position={[3, 1.5, 0]}>
          <Text ref={rightExpTextRef} position={[0, 0, 0]} fontSize={1.2} color="white" fontWeight="bold">
            {isMode1 ? "3" : "x"}
          </Text>
        </group>
      </group>

    </group>
  );
}

// --- Main Application UI ---

export default function App() {
  const [mode, setMode] = useState<'equal_bases' | 'zero_exponent'>('equal_bases');
  const [step, setStep] = useState<number>(0);

  const isComplete = step === 3;
  const isAnimating = step === 1 || step === 2;

  const nextStep = () => {
    if (step < 3) setStep(step + 1);
  };
  
  const reset = () => {
    setStep(0);
  };

  const handleModeChange = (newMode: 'equal_bases' | 'zero_exponent') => {
    setMode(newMode);
    setStep(0);
  };

  return (
    <div className="w-full h-full relative overflow-hidden bg-slate-950 text-slate-100 font-sans">
      
      {/* Header */}
      <header className="absolute top-0 inset-x-0 h-16 bg-slate-900/80 backdrop-blur-md border-b border-slate-800 z-20 flex items-center px-6">
        <h1 className="text-lg font-bold bg-gradient-to-r from-blue-400 to-indigo-500 bg-clip-text text-transparent flex items-center gap-3">
          <Network className="w-5 h-5 text-blue-400" />
          Solving Exponential Equations
        </h1>
      </header>

      {/* 3D Canvas Layer */}
      <div className="absolute inset-0 z-0 touch-none">
        <Canvas camera={{ position: [0, 1, 12], fov: 45 }}>
          <ambientLight intensity={0.6} />
          <directionalLight castShadow position={[10, 15, 10]} intensity={1.5} />
          <spotLight position={[-10, 10, -5]} intensity={1} color="#38bdf8" />
          <OrbitControls makeDefault enableDamping dampingFactor={0.05} maxPolarAngle={Math.PI / 2 + 0.1} />
          <SimulationScene 
            mode={mode} step={step} 
            onStepComplete={() => setStep(s => s + 1)} // Auto-advance intermediate steps
          />
        </Canvas>
      </div>

      {/* Live Math HUD Overlay */}
      <div className="absolute top-20 right-6 z-10 pointer-events-none w-full max-w-[340px]">
        <div className="bg-slate-900/80 backdrop-blur-md border border-slate-700/80 p-5 rounded-2xl shadow-2xl pointer-events-auto transition-all duration-300">
          <h3 className="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-2">
            <Calculator className="w-4 h-4" /> Live Equation
          </h3>
          <div className="bg-slate-950/80 rounded-xl p-4 border border-slate-800 flex flex-col items-center gap-2 text-xl overflow-x-auto shadow-inner">
            {mode === 'equal_bases' ? (
              <>
                <BlockMath math={`2^x = 8`} />
                <div className={cn("transition-all duration-500 transform", step >= 1 ? "opacity-100 translate-y-0" : "opacity-0 -translate-y-4 h-0 overflow-hidden")}>
                  <BlockMath math={`2^x = 2^3`} />
                </div>
                <div className={cn("transition-all duration-500 transform", step >= 3 ? "opacity-100 translate-y-0 text-emerald-400" : "opacity-0 -translate-y-4 h-0 overflow-hidden")}>
                  <BlockMath math={`x = 3`} />
                </div>
              </>
            ) : (
              <>
                <BlockMath math={`3^x = 5^x`} />
                <div className={cn("transition-all duration-500 transform", step >= 1 ? "opacity-100 translate-y-0" : "opacity-0 -translate-y-4 h-0 overflow-hidden")}>
                  <div className="text-sm text-slate-400 mb-1 text-center font-sans">Since bases <InlineMath math="3 \neq 5" /></div>
                  <BlockMath math={`x = 0`} />
                </div>
              </>
            )}
          </div>
        </div>
      </div>

      {/* Bottom Interface: Misconceptions & Controls */}
      <div className="absolute bottom-0 inset-x-0 z-20 pointer-events-none p-4 sm:p-6 flex flex-col gap-4 max-w-7xl mx-auto items-center">
        
        {/* Misconception Trap HUD */}
        <div className={cn(
          "backdrop-blur-xl p-5 rounded-2xl shadow-2xl pointer-events-auto w-full max-w-4xl transition-all duration-700 transform",
          step >= 2 ? "translate-y-0 opacity-100 scale-100" : "translate-y-12 opacity-0 scale-95 pointer-events-none absolute bottom-0",
          "bg-slate-900/95 border border-slate-700"
        )}>
          <h4 className="text-sm font-semibold text-slate-200 flex items-center gap-2 mb-4 justify-center">
            <Info className="w-4 h-4 text-emerald-400" />
            Crucial Step: Base Unification
          </h4>
          
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="bg-red-500/10 border border-red-500/20 rounded-xl p-4 flex items-center gap-4 group">
              <div className="bg-red-500/20 p-3 rounded-full shrink-0">
                <Close className="w-5 h-5 text-red-400" />
              </div>
              <div className="w-full">
                <p className="text-[10px] text-red-400/80 font-bold uppercase tracking-wider mb-1">Fatal Mistake</p>
                <div className="text-sm text-slate-200">
                  {mode === 'equal_bases' ? (
                    <BlockMath math={`2^x = 8 \\rightarrow x = 8`} />
                  ) : (
                    <BlockMath math={`3^x = 5^x \\rightarrow 3 = 5`} />
                  )}
                </div>
              </div>
            </div>

            <div className="bg-emerald-500/10 border border-emerald-500/20 rounded-xl p-4 flex items-center gap-4 group">
              <div className="bg-emerald-500/20 p-3 rounded-full shrink-0">
                <Check className="w-5 h-5 text-emerald-400" />
              </div>
              <div className="w-full">
                <p className="text-[10px] text-emerald-400/80 font-bold uppercase tracking-wider mb-1">Correct Logic</p>
                <div className="text-sm text-slate-200">
                  {mode === 'equal_bases' ? (
                    <BlockMath math={`a^x = a^y \\Rightarrow x = y`} />
                  ) : (
                    <BlockMath math={`a^x = b^x \\text{ (} a \\neq b \\text{)} \\Rightarrow x = 0`} />
                  )}
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Master Control Panel */}
        <div className="bg-slate-900/95 backdrop-blur-2xl border border-slate-700/80 p-6 rounded-3xl shadow-[0_10px_50px_-10px_rgba(0,0,0,0.7)] pointer-events-auto w-full transition-all">
          <div className="flex flex-col md:flex-row gap-6 items-center justify-between">
            
            {/* Mode Toggle */}
            <div className="flex flex-col sm:flex-row bg-slate-950 p-1.5 rounded-xl border border-slate-800 w-full md:w-auto shrink-0 shadow-inner gap-1">
              <button 
                onClick={() => handleModeChange('equal_bases')}
                className={cn("px-5 py-2.5 rounded-lg text-sm font-semibold transition-all duration-300 flex items-center justify-center gap-2", mode === 'equal_bases' ? "bg-blue-600 text-white shadow-md" : "text-slate-400 hover:text-slate-200")}
              >
                Equal Bases Rule
              </button>
              <button 
                onClick={() => handleModeChange('zero_exponent')}
                className={cn("px-5 py-2.5 rounded-lg text-sm font-semibold transition-all duration-300 flex items-center justify-center gap-2", mode === 'zero_exponent' ? "bg-pink-600 text-white shadow-md" : "text-slate-400 hover:text-slate-200")}
              >
                Zero Exponent Rule
              </button>
            </div>

            {/* Execution Actions */}
            <div className="flex gap-3 w-full md:w-auto shrink-0">
              <button 
                onClick={reset}
                className="flex-1 md:flex-none flex items-center justify-center p-3 px-6 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-xl transition-colors border border-slate-600 shadow-md"
                aria-label="Reset State"
              >
                <RotateCcw className="w-5 h-5 mr-2" /> Reset
              </button>
              
              <button 
                onClick={nextStep}
                disabled={isComplete || isAnimating}
                className={cn(
                  "flex-1 md:flex-none flex items-center justify-center gap-2 px-8 py-3 rounded-xl font-bold transition-all shadow-lg text-sm",
                  isComplete || isAnimating
                    ? "bg-slate-800 text-slate-500 cursor-not-allowed border border-slate-700" 
                    : "bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-400 hover:to-teal-500 text-white shadow-[0_0_20px_rgba(16,185,129,0.3)]"
                )}
              >
                {isComplete ? <><Check className="w-5 h-5" /> Solved</> : <><Play className="w-5 h-5 fill-current" /> Run Solution Step</>}
              </button>
            </div>

          </div>
        </div>
      </div>
    </div>
  );
}
