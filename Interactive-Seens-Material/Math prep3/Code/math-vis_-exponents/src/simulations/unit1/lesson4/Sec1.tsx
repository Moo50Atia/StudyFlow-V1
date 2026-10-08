import React, { useState, useRef } from 'react';
import { useSceneAnimation } from '../../../hooks/useSceneAnimation';
import { Canvas } from '../../../components/SceneCanvas';
import { OrbitControls, Box, RoundedBox, Ring } from '@react-three/drei';
import { Text } from '../../../components/SceneText';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath, InlineMath } from 'react-katex';
import { Play, RotateCcw, Check, Calculator, Info, X as Close, Search, Zap, CheckCircle2, ArrowRight } from 'lucide-react';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

// --- 3D Scene Components ---

function ScannerScene({ step, onStepComplete }: { step: number, onStepComplete: () => void }) {
  // Refs
  const mainBlockRef = useRef<THREE.Group>(null!);
  const mainTextRef = useRef<any>(null!);
  
  // Candidates
  const c11Ref = useRef<THREE.Group>(null!);
  const c13Ref = useRef<THREE.Group>(null!);
  const c40Ref = useRef<THREE.Group>(null!);
  const c30Ref = useRef<THREE.Group>(null!);

  const c11Mat = useRef<THREE.MeshStandardMaterial>(null!);
  const c13Mat = useRef<THREE.MeshStandardMaterial>(null!);
  const c40Mat = useRef<THREE.MeshStandardMaterial>(null!);
  const c30Mat = useRef<THREE.MeshStandardMaterial>(null!);

  // Scanner Beam
  const scannerBeamRef = useRef<THREE.Mesh>(null!);
  const scannerRingRef = useRef<THREE.Group>(null!);

  useSceneAnimation(() => {
    // Reset state
    if (step === 0) {
      gsap.killTweensOf([mainBlockRef.current.position, mainBlockRef.current.scale, scannerBeamRef.current.scale, scannerRingRef.current.position]);
      gsap.killTweensOf([c11Ref.current.scale, c13Ref.current.scale, c40Ref.current.scale, c30Ref.current.scale]);
      gsap.killTweensOf([c13Mat.current]);

      // Reset positions & scales
      gsap.set(mainBlockRef.current.position, { x: 0, y: 0, z: 4 });
      gsap.set(mainBlockRef.current.scale, { x: 1, y: 1, z: 1 });
      
      gsap.set(scannerRingRef.current.position, { y: 3 });
      gsap.set(scannerBeamRef.current.scale, { x: 1, y: 0, z: 1 }); // hidden

      gsap.set([c11Ref.current.scale, c13Ref.current.scale, c40Ref.current.scale, c30Ref.current.scale], { x: 1, y: 1, z: 1 });
      
      gsap.set([c11Mat.current, c13Mat.current, c40Mat.current, c30Mat.current], { 
        color: '#1e293b', emissive: '#000000', emissiveIntensity: 0, transparent: true, opacity: 1 
      });

      if (mainTextRef.current) mainTextRef.current.text = "(a²)^{4} · a⁵";
    }

    if (step === 1) {
      // Step 1: Scanner drops down and scans, text changes to a^8 . a^5
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      // Scanner ring moves down
      tl.to(scannerRingRef.current.position, { y: 0, duration: 0.8, ease: "power2.inOut" });
      tl.to(scannerBeamRef.current.scale, { y: 1, duration: 0.3 }, "-=0.4");
      
      // Block pulses
      tl.to(mainBlockRef.current.scale, { x: 1.2, y: 1.2, z: 1.2, duration: 0.3, yoyo: true, repeat: 1 });
      tl.call(() => {
        if (mainTextRef.current) mainTextRef.current.text = "a⁸ · a⁵";
      });
      
      // Scanner back up
      tl.to(scannerRingRef.current.position, { y: 3, duration: 0.8, ease: "power2.inOut" }, "+=0.2");
      tl.to(scannerBeamRef.current.scale, { y: 0, duration: 0.3 }, "-=0.6");
    }

    if (step === 2) {
      // Step 2: Scanner scans again, text changes to a^13
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      tl.to(scannerRingRef.current.position, { y: 0, duration: 0.8, ease: "power2.inOut" });
      tl.to(scannerBeamRef.current.scale, { y: 1, duration: 0.3 }, "-=0.4");
      
      tl.to(mainBlockRef.current.scale, { x: 1.3, y: 1.3, z: 1.3, duration: 0.3, yoyo: true, repeat: 1 });
      tl.call(() => {
        if (mainTextRef.current) mainTextRef.current.text = "a¹³";
      });
      
      tl.to(scannerRingRef.current.position, { y: 3, duration: 0.8, ease: "power2.inOut" }, "+=0.2");
      tl.to(scannerBeamRef.current.scale, { y: 0, duration: 0.3 }, "-=0.6");
    }

    if (step === 3) {
      // Step 3: Block flies back to candidates, matches a^13, others dissolve
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      // Move main block towards the candidates
      tl.to(mainBlockRef.current.position, { x: -1.5, z: -1, duration: 1, ease: "power3.inOut" });
      
      // Match a^13
      tl.to(c13Mat.current, { color: '#10b981', emissive: '#059669', emissiveIntensity: 1.5, duration: 0.5 });
      
      // Dissolve others
      tl.to([c11Ref.current.scale, c40Ref.current.scale, c30Ref.current.scale], { x: 0, y: 0, z: 0, duration: 0.6, ease: "back.in(2)" }, "-=0.3");
      
      // Main block absorbs into candidate or just scales down
      tl.to(mainBlockRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.4 }, "-=0.2");
    }

  }, [step]);

  return (
    <group position={[0, -1, 0]}>
      {/* Floor Grid */}
      <gridHelper args={[40, 40, '#1e293b', '#0f172a']} position={[0, -0.5, 0]} />

      {/* Candidates in the background */}
      <group position={[0, 0.5, -2]}>
        <group ref={c11Ref} position={[-4.5, 0, 0]}>
          <RoundedBox args={[2, 2, 0.5]} radius={0.1} smoothness={4}>
            <meshStandardMaterial ref={c11Mat} color="#1e293b" />
          </RoundedBox>
          <Text position={[0, 0, 0.26]} fontSize={0.7} color="white" fontWeight="bold">a¹¹</Text>
        </group>
        
        <group ref={c13Ref} position={[-1.5, 0, 0]}>
          <RoundedBox args={[2, 2, 0.5]} radius={0.1} smoothness={4}>
            <meshStandardMaterial ref={c13Mat} color="#1e293b" />
          </RoundedBox>
          <Text position={[0, 0, 0.26]} fontSize={0.7} color="white" fontWeight="bold">a¹³</Text>
        </group>
        
        <group ref={c30Ref} position={[1.5, 0, 0]}>
          <RoundedBox args={[2, 2, 0.5]} radius={0.1} smoothness={4}>
            <meshStandardMaterial ref={c30Mat} color="#1e293b" />
          </RoundedBox>
          <Text position={[0, 0, 0.26]} fontSize={0.7} color="white" fontWeight="bold">a³⁰</Text>
        </group>

        <group ref={c40Ref} position={[4.5, 0, 0]}>
          <RoundedBox args={[2, 2, 0.5]} radius={0.1} smoothness={4}>
            <meshStandardMaterial ref={c40Mat} color="#1e293b" />
          </RoundedBox>
          <Text position={[0, 0, 0.26]} fontSize={0.7} color="white" fontWeight="bold">a⁴⁰</Text>
        </group>
      </group>

      {/* Main Expression Block (Front) */}
      <group ref={mainBlockRef} position={[0, 0, 4]}>
        <Box args={[3.5, 1.5, 0.5]}>
          <meshStandardMaterial color="#3b82f6" emissive="#1d4ed8" emissiveIntensity={0.5} transparent opacity={0.9} />
        </Box>
        <Text ref={mainTextRef} position={[0, 0, 0.26]} fontSize={0.6} color="white" fontWeight="bold">
          (a²)^{4} · a⁵
        </Text>

        {/* Scanner Ring & Beam relative to main block */}
        <group ref={scannerRingRef} position={[0, 3, 0]} rotation={[-Math.PI / 2, 0, 0]}>
          <Ring args={[2.2, 2.5, 32]}>
            <meshStandardMaterial color="#0ea5e9" emissive="#0ea5e9" emissiveIntensity={2} side={THREE.DoubleSide} />
          </Ring>
          <mesh ref={scannerBeamRef} position={[0, 0, -1.5]}>
            <cylinderGeometry args={[2.2, 2.2, 3, 32, 1, true]} />
            <meshStandardMaterial color="#38bdf8" transparent opacity={0.2} emissive="#38bdf8" emissiveIntensity={1} side={THREE.DoubleSide} />
          </mesh>
        </group>
      </group>

    </group>
  );
}

// --- Main Application UI ---

export default function Sec1() {
  const [step, setStep] = useState<number>(0);
  const [isAnimating, setIsAnimating] = useState(false);

  const nextStep = () => {
    if (step < 3 && !isAnimating) {
      setIsAnimating(true);
      setStep(step + 1);
    }
  };
  
  const reset = () => {
    if (!isAnimating) setStep(0);
  };

  const handleStepComplete = () => {
    setIsAnimating(false);
  };

  return (
    <div className="w-full h-full relative overflow-hidden bg-slate-950 text-slate-100 font-sans">
      
      {/* Header */}
      <header className="absolute top-0 inset-x-0 h-16 bg-slate-900/80 backdrop-blur-md border-b border-slate-800 z-20 flex items-center px-6">
        <h1 className="text-lg font-bold bg-gradient-to-r from-blue-400 to-indigo-500 bg-clip-text text-transparent flex items-center gap-3">
          <Search className="w-5 h-5 text-blue-400" />
          Diagnostic Evaluator
        </h1>
      </header>

      {/* 3D Canvas Layer */}
      <div className="absolute inset-0 z-0 touch-none">
        <Canvas camera={{ position: [0, 2, 12], fov: 50 }}>
          <ambientLight intensity={0.5} />
          <directionalLight castShadow position={[5, 10, 5]} intensity={1.5} />
          <spotLight position={[0, 5, 5]} intensity={2} color="#38bdf8" distance={20} angle={Math.PI / 4} penumbra={0.5} />
          <OrbitControls makeDefault enableDamping dampingFactor={0.05} maxPolarAngle={Math.PI / 2 + 0.1} minDistance={5} maxDistance={20} />
          <ScannerScene 
            step={step} 
            onStepComplete={handleStepComplete} 
          />
        </Canvas>
      </div>

      {/* Live Math HUD Overlay */}
      <div className="absolute top-20 right-6 z-10 pointer-events-none w-full max-w-[340px]">
        <div className="bg-slate-900/80 backdrop-blur-md border border-slate-700/80 p-5 rounded-2xl shadow-2xl pointer-events-auto transition-all duration-300">
          <h3 className="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-2">
            <Calculator className="w-4 h-4" /> Operations Log
          </h3>
          <div className="bg-slate-950/80 rounded-xl p-4 border border-slate-800 flex flex-col gap-3 text-lg shadow-inner min-h-[140px]">
            
            <div className={cn("transition-all duration-500", step >= 0 ? "opacity-100" : "opacity-0 h-0 overflow-hidden")}>
              <div className="text-[10px] text-slate-500 uppercase tracking-widest mb-1">Target Expression</div>
              <BlockMath math={`(a^2)^4 \\cdot a^5`} />
            </div>

            <div className={cn("transition-all duration-500", step >= 1 ? "opacity-100" : "opacity-0 h-0 overflow-hidden hidden")}>
              <div className="text-[10px] text-blue-400 uppercase tracking-widest mb-1">Power Rule</div>
              <BlockMath math={`a^{2 \\times 4} \\cdot a^5 = a^8 \\cdot a^5`} />
            </div>

            <div className={cn("transition-all duration-500 text-emerald-400", step >= 2 ? "opacity-100" : "opacity-0 h-0 overflow-hidden hidden")}>
              <div className="text-[10px] text-emerald-500 uppercase tracking-widest mb-1">Product Rule</div>
              <BlockMath math={`a^{8 + 5} = a^{13}`} />
            </div>

          </div>
        </div>
      </div>

      {/* Misconception Trap HUD - Only visible after Product Rule step */}
      <div className={cn(
        "absolute top-20 left-6 z-10 pointer-events-none w-full max-w-[360px] transition-all duration-700 transform",
        step >= 2 ? "translate-x-0 opacity-100 scale-100" : "-translate-x-12 opacity-0 scale-95 pointer-events-none"
      )}>
        <div className="bg-slate-900/95 backdrop-blur-xl border border-slate-700 rounded-2xl shadow-2xl p-5 pointer-events-auto">
          <h4 className="text-sm font-semibold text-slate-200 flex items-center gap-2 mb-4">
            <Info className="w-4 h-4 text-emerald-400" />
            Product Rule Verification
          </h4>
          
          <div className="flex flex-col gap-3">
            <div className="bg-red-500/10 border border-red-500/20 rounded-xl p-3 flex items-center gap-4">
              <div className="bg-red-500/20 p-2 rounded-full shrink-0">
                <Close className="w-4 h-4 text-red-400" />
              </div>
              <div className="w-full">
                <p className="text-[10px] text-red-400/80 font-bold uppercase tracking-wider mb-0.5">Incorrect: Multiplying Exponents</p>
                <div className="text-xs text-slate-200">
                   <BlockMath math={`a^8 \\cdot a^5 \\neq a^{8 \\times 5} \\Rightarrow a^{40}`} />
                </div>
              </div>
            </div>

            <div className="bg-emerald-500/10 border border-emerald-500/20 rounded-xl p-3 flex items-center gap-4">
              <div className="bg-emerald-500/20 p-2 rounded-full shrink-0">
                <Check className="w-4 h-4 text-emerald-400" />
              </div>
              <div className="w-full">
                <p className="text-[10px] text-emerald-400/80 font-bold uppercase tracking-wider mb-0.5">Correct: Adding Exponents</p>
                <div className="text-xs text-slate-200">
                  <BlockMath math={`a^8 \\cdot a^5 = a^{8 + 5} \\Rightarrow a^{13}`} />
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Bottom Interface: Controls */}
      <div className="absolute bottom-0 inset-x-0 z-20 pointer-events-none p-4 sm:p-6 flex flex-col gap-4 max-w-7xl mx-auto items-center">
        
        {/* Master Control Panel */}
        <div className="bg-slate-900/95 backdrop-blur-2xl border border-slate-700/80 p-6 rounded-3xl shadow-[0_10px_50px_-10px_rgba(0,0,0,0.7)] pointer-events-auto w-full transition-all">
          <div className="flex flex-col md:flex-row gap-6 items-center justify-between">
            
            <div className="flex-1">
              <h3 className="text-lg font-bold text-slate-200 mb-1">Evaluation Sequence</h3>
              <p className="text-sm text-slate-400">Step through the algebraic process to find the correct multiple-choice candidate.</p>
            </div>

            {/* Execution Actions */}
            <div className="flex gap-3 w-full md:w-auto shrink-0">
              <button 
                onClick={reset}
                disabled={isAnimating}
                className="flex-1 md:flex-none flex items-center justify-center p-3 px-6 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-xl transition-colors border border-slate-600 shadow-md disabled:opacity-50"
              >
                <RotateCcw className="w-5 h-5 md:mr-2" /> <span className="hidden md:inline">Restart</span>
              </button>
              
              <button 
                onClick={nextStep}
                disabled={step >= 3 || isAnimating}
                className={cn(
                  "flex-1 md:flex-none flex items-center justify-center gap-2 px-8 py-3 rounded-xl font-bold transition-all shadow-lg text-sm",
                  step >= 3 || isAnimating
                    ? "bg-slate-800 text-slate-500 cursor-not-allowed border border-slate-700" 
                    : "bg-blue-600 hover:bg-blue-500 text-white shadow-[0_0_20px_rgba(37,99,235,0.3)]"
                )}
              >
                {step === 0 && <><Zap className="w-5 h-5" /> Analyze Power Rule</>}
                {step === 1 && <><Play className="w-5 h-5 fill-current" /> Analyze Product Rule</>}
                {step === 2 && <><ArrowRight className="w-5 h-5" /> Verify Candidates</>}
                {step === 3 && <><CheckCircle2 className="w-5 h-5" /> Evaluation Complete</>}
              </button>
            </div>

          </div>
        </div>
      </div>
    </div>
  );
}
