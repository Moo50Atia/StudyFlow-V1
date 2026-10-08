import React, { useState, useRef } from 'react';
import { useSceneAnimation } from '../../../hooks/useSceneAnimation';
import { Canvas } from '../../../components/SceneCanvas';
import { OrbitControls, Box, RoundedBox, Cylinder } from '@react-three/drei';
import { Text } from '../../../components/SceneText';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { Play, RotateCcw, Check, Calculator, Info, X as Close, Zap, Code, ArrowRight, Target, CheckCircle2 } from 'lucide-react';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

// --- 3D Scene Component ---
function ProblemScene({ step, onStepComplete }: { step: number, onStepComplete: () => void }) {
  const termLRef = useRef<THREE.Group>(null!);
  const termRRef = useRef<THREE.Group>(null!);
  const eqRef = useRef<THREE.Group>(null!);
  
  const factorRingRef = useRef<THREE.Group>(null!);
  const quadBoxRef = useRef<THREE.Group>(null!);
  const factoringBoxRef = useRef<THREE.Group>(null!);
  
  const binLRef = useRef<THREE.Group>(null!);
  const binRRef = useRef<THREE.Group>(null!);
  
  const numLineRef = useRef<THREE.Group>(null!);
  const solutionRef = useRef<THREE.Group>(null!);

  const termLText = useRef<any>(null!);
  const termRText = useRef<any>(null!);
  const binLText = useRef<any>(null!);
  const binRText = useRef<any>(null!);

  const termRMat = useRef<THREE.MeshStandardMaterial>(null!);

  useSceneAnimation(() => {
    if (step === 0) {
      // Reset animations
      gsap.killTweensOf([
        termLRef.current.position, termLRef.current.scale,
        eqRef.current.position, eqRef.current.scale,
        termRRef.current.position, termRRef.current.scale,
        factorRingRef.current.scale, factorRingRef.current.position,
        quadBoxRef.current.scale, quadBoxRef.current.rotation,
        factoringBoxRef.current.scale,
        binLRef.current.scale, binLRef.current.position,
        binRRef.current.scale, binRRef.current.position,
        numLineRef.current.scale, solutionRef.current.scale
      ]);

      // Step 0 states
      gsap.set(termLRef.current.position, { x: -3.5, y: 2, z: 0 });
      gsap.set(termLRef.current.scale, { x: 1, y: 1, z: 1 });
      
      gsap.set(eqRef.current.position, { x: 0, y: 2, z: 0 });
      gsap.set(eqRef.current.scale, { x: 1, y: 1, z: 1 });
      
      gsap.set(termRRef.current.position, { x: 3.5, y: 2, z: 0 });
      gsap.set(termRRef.current.scale, { x: 1, y: 1, z: 1 });
      gsap.set(termRMat.current, { emissiveIntensity: 0 });
      
      gsap.set(factorRingRef.current.scale, { x: 0, y: 0, z: 0 });
      gsap.set(factorRingRef.current.position, { x: 3.5, y: 2, z: 0 });
      
      gsap.set(quadBoxRef.current.scale, { x: 0, y: 0, z: 0 });
      gsap.set(factoringBoxRef.current.scale, { x: 0, y: 0, z: 0 });
      
      gsap.set(binLRef.current.scale, { x: 0, y: 0, z: 0 });
      gsap.set(binRRef.current.scale, { x: 0, y: 0, z: 0 });
      gsap.set(binLRef.current.position, { x: 0, y: 0, z: 0 });
      gsap.set(binRRef.current.position, { x: 0, y: 0, z: 0 });
      
      gsap.set(numLineRef.current.scale, { x: 0, y: 0, z: 0 });
      gsap.set(solutionRef.current.scale, { x: 0, y: 0, z: 0 });

      // Reset Text
      if (termLText.current) termLText.current.text = "5^(x²-4)";
      if (termRText.current) termRText.current.text = "125^x";
      if (binLText.current) binLText.current.text = "(x + 1)";
      if (binRText.current) binRText.current.text = "(x - 4)";

      // Reset markers box scale (child 0)
      if (binLRef.current.children[0]) gsap.set(binLRef.current.children[0].scale, { x: 1, y: 1, z: 1 });
      if (binRRef.current.children[0]) gsap.set(binRRef.current.children[0].scale, { x: 1, y: 1, z: 1 });
    }

    if (step === 1) {
      // Step 1: Prime factorize 125^x to 5^(3x)
      const tl = gsap.timeline({ onComplete: onStepComplete });
      tl.to(factorRingRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.3 });
      tl.to(factorRingRef.current.position, { x: 2, duration: 0.5, yoyo: true, repeat: 1 });
      tl.to(termRMat.current, { emissive: '#8b5cf6', emissiveIntensity: 1, duration: 0.3 }, "-=0.5");
      tl.call(() => {
        if (termRText.current) termRText.current.text = "5^(3x)";
      });
      tl.to(factorRingRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
    }

    if (step === 2) {
      // Step 2: Drop bases, equate exponents, form quadratic
      const tl = gsap.timeline({ onComplete: onStepComplete });
      tl.to([termLRef.current.position, eqRef.current.position, termRRef.current.position], { y: 0, duration: 0.6, ease: "power2.inOut" });
      tl.call(() => {
        if (termLText.current) termLText.current.text = "x² - 4";
        if (termRText.current) termRText.current.text = "3x";
      });
      
      tl.to([termLRef.current.scale, eqRef.current.scale, termRRef.current.scale], { x: 0, y: 0, z: 0, duration: 0.5, delay: 0.5 });
      tl.to(quadBoxRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: "back.out(1.5)" });
    }

    if (step === 3) {
      // Step 3: Factoring Module
      const tl = gsap.timeline({ onComplete: onStepComplete });
      tl.to(factoringBoxRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.4 });
      tl.to(quadBoxRef.current.rotation, { z: Math.PI * 2, duration: 0.6 }, "-=0.2");
      tl.to(quadBoxRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.4 });
      tl.to(factoringBoxRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
      
      tl.to(binLRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.4 }, "split");
      tl.to(binRRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.4 }, "split");
      tl.to(binLRef.current.position, { x: -2, y: 0, duration: 0.6, ease: "power2.out" }, "split");
      tl.to(binRRef.current.position, { x: 2, y: 0, duration: 0.6, ease: "power2.out" }, "split");
    }

    if (step === 4) {
      // Step 4: Resolve Roots on Number Line
      const tl = gsap.timeline({ onComplete: onStepComplete });
      tl.to(numLineRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.5 });
      
      // Transform binomials to root markers
      tl.to(binLRef.current.position, { x: 0.5, y: -2.3, z: 0, duration: 0.8, ease: "power2.inOut" }, "drop");
      tl.to(binRRef.current.position, { x: 5.5, y: -2.3, z: 0, duration: 0.8, ease: "power2.inOut" }, "drop");
      
      tl.call(() => {
        if (binLText.current) binLText.current.text = "x = -1";
        if (binRText.current) binRText.current.text = "x = 4";
      });
      
      // Make boxes smaller to act as markers
      tl.to([binLRef.current.children[0].scale, binRRef.current.children[0].scale], { x: 0.2, y: 0.5, z: 0.2, duration: 0.4 }, "drop+=0.4");
      // Offset text up
      tl.to([binLRef.current.children[1].position, binRRef.current.children[1].position], { y: 0.8, duration: 0.4 }, "drop+=0.4");
    }

    if (step === 5) {
      // Step 5: Highlight Solution Set
      const tl = gsap.timeline({ onComplete: onStepComplete });
      tl.to(solutionRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.6, ease: "elastic.out(1, 0.5)" });
      // Minor pulse
      tl.to(solutionRef.current.scale, { x: 1.1, y: 1.1, z: 1.1, duration: 0.4, yoyo: true, repeat: 3 });
    }
  }, [step]);

  return (
    <group position={[0, 0, 0]}>
      <gridHelper args={[40, 40, '#1e293b', '#0f172a']} position={[0, -4, -2]} />

      {/* Step 0/1: Initial Equation */}
      <group ref={termLRef}>
        <RoundedBox args={[3.5, 1.5, 0.5]} radius={0.1}>
          <meshStandardMaterial color="#334155" />
        </RoundedBox>
        <Text ref={termLText} position={[0, 0, 0.26]} fontSize={0.6} color="white" fontWeight="bold">5^(x²-4)</Text>
      </group>

      <group ref={eqRef}>
        <Text fontSize={1} color="#64748b" fontWeight="bold">=</Text>
      </group>

      <group ref={termRRef}>
        <RoundedBox args={[2.5, 1.5, 0.5]} radius={0.1}>
          <meshStandardMaterial ref={termRMat} color="#334155" />
        </RoundedBox>
        <Text ref={termRText} position={[0, 0, 0.26]} fontSize={0.6} color="white" fontWeight="bold">125^x</Text>
      </group>

      <group ref={factorRingRef} rotation={[0, Math.PI/2, 0]}>
        <Cylinder args={[1.5, 1.5, 3, 32, 1, true]}>
          <meshStandardMaterial color="#a855f7" emissive="#a855f7" emissiveIntensity={1} transparent opacity={0.4} side={THREE.DoubleSide} />
        </Cylinder>
      </group>

      {/* Step 2: Quadratic Equation */}
      <group ref={quadBoxRef} position={[0, 0, 0]}>
        <RoundedBox args={[5.5, 1.5, 0.5]} radius={0.1}>
          <meshStandardMaterial color="#0ea5e9" />
        </RoundedBox>
        <Text position={[0, 0, 0.26]} fontSize={0.7} color="white" fontWeight="bold">x² - 3x - 4 = 0</Text>
      </group>

      {/* Step 3: Factoring Module */}
      <group ref={factoringBoxRef} position={[0, 0, 0]}>
        <Box args={[6, 2, 1]}>
          <meshBasicMaterial color="#38bdf8" wireframe={true} />
        </Box>
      </group>

      <group ref={binLRef}>
        <RoundedBox args={[2.2, 1.2, 0.5]} radius={0.1}>
          <meshStandardMaterial color="#3b82f6" />
        </RoundedBox>
        <Text ref={binLText} position={[0, 0, 0.26]} fontSize={0.6} color="white" fontWeight="bold">(x + 1)</Text>
      </group>

      <group ref={binRRef}>
        <RoundedBox args={[2.2, 1.2, 0.5]} radius={0.1}>
          <meshStandardMaterial color="#3b82f6" />
        </RoundedBox>
        <Text ref={binRText} position={[0, 0, 0.26]} fontSize={0.6} color="white" fontWeight="bold">(x - 4)</Text>
      </group>

      {/* Step 4: Number Line */}
      <group ref={numLineRef} position={[1.5, -3, 0]}>
        {/* Main Axis */}
        <Cylinder args={[0.05, 0.05, 12]} rotation={[0, 0, Math.PI/2]} position={[0, 0, -0.2]}>
          <meshStandardMaterial color="#64748b" />
        </Cylinder>
        {/* Ticks: -3 to 6 */}
        {[-3, -2, -1, 0, 1, 2, 3, 4, 5, 6].map(i => (
          <group key={i} position={[i, 0, -0.2]}>
            <Box args={[0.05, 0.4, 0.05]}>
              <meshStandardMaterial color="#94a3b8" />
            </Box>
            <Text position={[0, -0.4, 0]} fontSize={0.3} color="#cbd5e1">{i}</Text>
          </group>
        ))}
      </group>

      {/* Step 5: Solution Set */}
      <group ref={solutionRef} position={[1.5, -1, 0]}>
        <Text fontSize={1.2} color="#10b981" fontWeight="bold">{`{ -1, 4 }`}</Text>
      </group>

    </group>
  );
}


// --- Main Application UI ---
export default function Sec3() {
  const [step, setStep] = useState<number>(0);
  const [isAnimating, setIsAnimating] = useState(false);

  const nextStep = () => {
    if (step < 5 && !isAnimating) {
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
          <Target className="w-5 h-5 text-blue-400" />
          Comprehensive Free-Response Solver
        </h1>
      </header>

      {/* 3D Canvas Layer */}
      <div className="absolute inset-0 z-0 touch-none">
        <Canvas camera={{ position: [0, 1, 10], fov: 50 }}>
          <ambientLight intensity={0.5} />
          <directionalLight castShadow position={[5, 10, 5]} intensity={1.5} />
          <spotLight position={[-5, 5, 5]} intensity={2} color="#8b5cf6" distance={20} angle={Math.PI / 4} penumbra={0.5} />
          <OrbitControls makeDefault enableDamping dampingFactor={0.05} maxPolarAngle={Math.PI / 2 + 0.1} />
          <ProblemScene 
            step={step} 
            onStepComplete={handleStepComplete} 
          />
        </Canvas>
      </div>

      {/* Misconception Trap HUD - Visible on Step 4 & 5 */}
      <div className={cn(
        "absolute top-20 left-6 z-10 pointer-events-none w-full max-w-[360px] transition-all duration-700 transform",
        (step >= 4) ? "translate-x-0 opacity-100 scale-100" : "-translate-x-12 opacity-0 scale-95 pointer-events-none"
      )}>
        <div className="bg-slate-900/95 backdrop-blur-xl border border-slate-700 rounded-2xl shadow-2xl p-5 pointer-events-auto">
          <h4 className="text-sm font-semibold text-slate-200 flex items-center gap-2 mb-4">
            <Info className="w-4 h-4 text-emerald-400" />
            Root Verification
          </h4>
          
          <div className="flex flex-col gap-3">
            <div className="bg-red-500/10 border border-red-500/20 rounded-xl p-3 flex items-center gap-4">
              <div className="bg-red-500/20 p-2 rounded-full shrink-0">
                <Close className="w-4 h-4 text-red-400" />
              </div>
              <div className="w-full">
                <p className="text-[10px] text-red-400/80 font-bold uppercase tracking-wider mb-0.5">Incorrect: Ignoring Negative Roots</p>
                <div className="text-xs text-slate-200">
                   Do not discard <span className="font-mono text-pink-300">x = -1</span>. In exponents, the domain is all real numbers <span className="font-mono">R</span>.
                </div>
              </div>
            </div>

            <div className="bg-emerald-500/10 border border-emerald-500/20 rounded-xl p-3 flex items-center gap-4">
              <div className="bg-emerald-500/20 p-2 rounded-full shrink-0">
                <Check className="w-4 h-4 text-emerald-400" />
              </div>
              <div className="w-full">
                <p className="text-[10px] text-emerald-400/80 font-bold uppercase tracking-wider mb-0.5">Correct: Complete Solution Set</p>
                <div className="text-xs text-slate-200">
                  <BlockMath math={`S.S. = \\{-1, 4\\}`} />
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Live Math HUD Overlay - Log */}
      <div className="absolute top-20 right-6 z-10 pointer-events-none w-full max-w-[340px]">
        <div className="bg-slate-900/80 backdrop-blur-md border border-slate-700/80 p-5 rounded-2xl shadow-2xl pointer-events-auto transition-all duration-300">
          <h3 className="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-2">
            <Calculator className="w-4 h-4" /> Trace Log
          </h3>
          <div className="bg-slate-950/80 rounded-xl p-4 border border-slate-800 flex flex-col gap-3 text-lg shadow-inner min-h-[140px] overflow-hidden">
            
            {step >= 0 && (
              <div className="animate-in fade-in slide-in-from-right-4 duration-500">
                <div className="text-[10px] text-slate-500 uppercase tracking-widest mb-1">Equation</div>
                <BlockMath math={`5^{x^2-4} = 125^x`} />
              </div>
            )}

            {step >= 1 && (
              <div className="animate-in fade-in slide-in-from-right-4 duration-500">
                <div className="text-[10px] text-purple-400 uppercase tracking-widest mb-1">Base Factorization</div>
                <BlockMath math={`5^{x^2-4} = 5^{3x}`} />
              </div>
            )}
            
            {step >= 2 && (
              <div className="animate-in fade-in slide-in-from-right-4 duration-500">
                <div className="text-[10px] text-blue-400 uppercase tracking-widest mb-1">Quadratic Formation</div>
                <BlockMath math={`x^2 - 3x - 4 = 0`} />
              </div>
            )}

            {step >= 3 && (
              <div className="animate-in fade-in slide-in-from-right-4 duration-500">
                <div className="text-[10px] text-sky-400 uppercase tracking-widest mb-1">Factoring</div>
                <BlockMath math={`(x-4)(x+1) = 0`} />
              </div>
            )}
            
            {step >= 5 && (
              <div className="animate-in fade-in slide-in-from-right-4 duration-500 text-emerald-400">
                <div className="text-[10px] text-emerald-500 uppercase tracking-widest mb-1">Final Solution</div>
                <BlockMath math={`S.S. = \\{-1, 4\\}`} />
              </div>
            )}

          </div>
        </div>
      </div>

      {/* Bottom Interface: Controls */}
      <div className="absolute bottom-0 inset-x-0 z-20 pointer-events-none p-4 sm:p-6 flex flex-col gap-4 max-w-7xl mx-auto items-center">
        
        {/* Master Control Panel */}
        <div className="bg-slate-900/95 backdrop-blur-2xl border border-slate-700/80 p-6 rounded-3xl shadow-[0_10px_50px_-10px_rgba(0,0,0,0.7)] pointer-events-auto w-full transition-all">
          <div className="flex flex-col md:flex-row gap-6 items-center justify-between">
            
            <div className="flex-1">
              <h3 className="text-lg font-bold text-slate-200 mb-1">Free-Response Execution</h3>
              <p className="text-sm text-slate-400">Trace the full multi-step process from exponential form down to the algebraic roots.</p>
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
                disabled={step >= 5 || isAnimating}
                className={cn(
                  "flex-1 md:flex-none flex items-center justify-center gap-2 px-8 py-3 rounded-xl font-bold transition-all shadow-lg text-sm",
                  step >= 5 || isAnimating
                    ? "bg-slate-800 text-slate-500 cursor-not-allowed border border-slate-700" 
                    : "bg-blue-600 hover:bg-blue-500 text-white shadow-[0_0_20px_rgba(37,99,235,0.3)]"
                )}
              >
                {step === 0 && <><Zap className="w-5 h-5" /> Factorize Bases</>}
                {step === 1 && <><Play className="w-5 h-5 fill-current" /> Form Quadratic</>}
                {step === 2 && <><Code className="w-5 h-5" /> Factor Module</>}
                {step === 3 && <><ArrowRight className="w-5 h-5" /> Plot Roots</>}
                {step === 4 && <><Check className="w-5 h-5" /> Solution Set</>}
                {step === 5 && <><CheckCircle2 className="w-5 h-5" /> Completed</>}
              </button>
            </div>

          </div>
        </div>
      </div>
    </div>
  );
}
