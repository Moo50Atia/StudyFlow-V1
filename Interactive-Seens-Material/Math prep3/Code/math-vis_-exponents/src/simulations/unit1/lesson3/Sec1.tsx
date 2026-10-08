import React, { useState, useRef } from 'react';
import { useSceneAnimation } from '../../../hooks/useSceneAnimation';
import { Canvas } from '../../../components/SceneCanvas';
import { OrbitControls, Box, Cylinder } from '@react-three/drei';
import { Text } from '../../../components/SceneText';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath, InlineMath } from 'react-katex';
import { Play, RotateCcw, Check, Calculator, Info, X as Close, Layers, ArrowDown } from 'lucide-react';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

// --- 3D Scene Components ---

function AssemblyLineScene({ step, onStepComplete }: { step: number, onStepComplete: () => void }) {
  const term1Ref = useRef<THREE.Group>(null!);
  const term1OuterRef = useRef<THREE.Group>(null!);
  const term1ExpRef = useRef<any>(null!);
  
  const term2Ref = useRef<THREE.Group>(null!);
  const term2ExpRef = useRef<any>(null!);
  
  const term3Ref = useRef<THREE.Group>(null!);
  const term3ExpRef = useRef<any>(null!);
  
  const dividerRef = useRef<THREE.Group>(null!);
  
  // Gate references for glow effects
  const gate1MatRef = useRef<THREE.MeshStandardMaterial>(null!);
  const gate2MatRef = useRef<THREE.MeshStandardMaterial>(null!);
  const gate3MatRef = useRef<THREE.MeshStandardMaterial>(null!);

  useSceneAnimation(() => {
    // Kill existing tweens on re-render to prevent ghosting
    gsap.killTweensOf([
      term1Ref.current?.position, term1OuterRef.current?.scale, term1ExpRef.current,
      term2Ref.current?.position, term2Ref.current?.scale, term2ExpRef.current,
      term3Ref.current?.position, term3Ref.current?.scale, term3ExpRef.current,
      dividerRef.current?.scale,
      gate1MatRef.current, gate2MatRef.current, gate3MatRef.current
    ]);

    if (step === 0) {
      // RESET TO INITIAL STATE
      gsap.set(term1Ref.current.position, { x: -2, y: 3, z: 0 });
      gsap.set(term1OuterRef.current.scale, { x: 1, y: 1, z: 1 });
      gsap.set(term1ExpRef.current, { color: '#ffffff' });
      term1ExpRef.current.text = "2";

      gsap.set(term2Ref.current.position, { x: 2, y: 3, z: 0 });
      gsap.set(term2Ref.current.scale, { x: 1, y: 1, z: 1 });
      gsap.set(term2ExpRef.current, { color: '#ffffff' });
      term2ExpRef.current.text = "4";

      gsap.set(term3Ref.current.position, { x: 0, y: -2, z: 0 });
      gsap.set(term3Ref.current.scale, { x: 1, y: 1, z: 1 });
      gsap.set(term3ExpRef.current, { color: '#ffffff' });
      term3ExpRef.current.text = "5";

      gsap.set(dividerRef.current.scale, { x: 1, y: 1, z: 1 });
      
      gsap.set([gate1MatRef.current, gate2MatRef.current, gate3MatRef.current], { emissiveIntensity: 0.2 });
    }
    else if (step === 1) {
      // RULE 1: POWER OF A POWER (x^2)^3 -> x^6
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      // Move term1 through Gate 1
      tl.to(term1Ref.current.position, { y: 1.5, duration: 1, ease: 'power2.inOut' }, 0);
      tl.to(gate1MatRef.current, { emissiveIntensity: 2, duration: 0.3, yoyo: true, repeat: 1 }, 0.5);
      
      // Outer shell collapses and multiplies
      tl.to(term1OuterRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.6, ease: 'back.in(1.5)' }, 0.6);
      tl.to(term1ExpRef.current, { color: '#fbbf24', duration: 0.2 }, 1.0);
      tl.call(() => { term1ExpRef.current.text = "6"; }, undefined, 1.1);
      tl.to(term1ExpRef.current, { color: '#ffffff', duration: 0.3 }, 1.2);
    }
    else if (step === 2) {
      // RULE 2: PRODUCT RULE x^6 * x^4 -> x^10
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      // Both terms move through Gate 2 to center
      tl.to(term1Ref.current.position, { x: 0, y: 0.8, duration: 1, ease: 'power2.inOut' }, 0);
      tl.to(term2Ref.current.position, { x: 0, y: 0.8, duration: 1, ease: 'power2.inOut' }, 0);
      tl.to(gate2MatRef.current, { emissiveIntensity: 2, duration: 0.3, yoyo: true, repeat: 1 }, 0.5);
      
      // Merge term2 into term1
      tl.to(term2Ref.current.scale, { x: 0, y: 0, z: 0, duration: 0.4 }, 0.8);
      tl.to(term1ExpRef.current, { color: '#fbbf24', duration: 0.2 }, 0.9);
      tl.call(() => { term1ExpRef.current.text = "10"; }, undefined, 1.0);
      tl.to(term1ExpRef.current, { color: '#ffffff', duration: 0.3 }, 1.1);
    }
    else if (step === 3) {
      // RULE 3: QUOTIENT RULE x^10 / x^5 -> x^5
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      // Divider vanishes
      tl.to(dividerRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.4 }, 0);
      
      // Terms move through Gate 3
      tl.to(term1Ref.current.position, { y: -1, duration: 1.2, ease: 'power2.inOut' }, 0.2);
      tl.to(term3Ref.current.position, { y: -1, duration: 1.2, ease: 'power2.inOut' }, 0.2);
      tl.to(gate3MatRef.current, { emissiveIntensity: 2, duration: 0.3, yoyo: true, repeat: 1 }, 0.8);
      
      // Merge term3 into term1
      tl.to(term3Ref.current.scale, { x: 0, y: 0, z: 0, duration: 0.4 }, 1.0);
      tl.to(term1ExpRef.current, { color: '#10b981', duration: 0.2 }, 1.2); // Final green success color
      tl.call(() => { term1ExpRef.current.text = "5"; }, undefined, 1.3);
      tl.to(term1ExpRef.current, { color: '#10b981', duration: 0.3 }, 1.4);
    }

  }, [step]);

  return (
    <group position={[0, -0.5, 0]}>
      {/* Background / Environment */}
      <gridHelper args={[40, 40, '#334155', '#0f172a']} position={[0, -4, 0]} />

      {/* --- GATES (Rule Nodes) --- */}
      {/* Gate 1: Power Node */}
      <group position={[-2, 2.2, 0]}>
        <Cylinder args={[1.5, 1.5, 0.2, 32]} rotation={[Math.PI/2, 0, 0]}>
          <meshStandardMaterial ref={gate1MatRef} color="#8b5cf6" emissive="#8b5cf6" emissiveIntensity={0.2} transparent opacity={0.5} wireframe />
        </Cylinder>
        <Text position={[2, 0, 0]} fontSize={0.3} color="#a78bfa" fontWeight="bold">Power Gate</Text>
      </group>

      {/* Gate 2: Product Node */}
      <group position={[0, 1.2, 0]}>
        <Cylinder args={[2.5, 2.5, 0.2, 32]} rotation={[Math.PI/2, 0, 0]}>
          <meshStandardMaterial ref={gate2MatRef} color="#3b82f6" emissive="#3b82f6" emissiveIntensity={0.2} transparent opacity={0.5} wireframe />
        </Cylinder>
        <Text position={[3, 0, 0]} fontSize={0.3} color="#93c5fd" fontWeight="bold">Product Gate</Text>
      </group>

      {/* Gate 3: Quotient Node */}
      <group position={[0, -0.2, 0]}>
        <Cylinder args={[2, 2, 0.2, 32]} rotation={[Math.PI/2, 0, 0]}>
          <meshStandardMaterial ref={gate3MatRef} color="#f59e0b" emissive="#f59e0b" emissiveIntensity={0.2} transparent opacity={0.5} wireframe />
        </Cylinder>
        <Text position={[2.5, 0, 0]} fontSize={0.3} color="#fcd34d" fontWeight="bold">Quotient Gate</Text>
      </group>


      {/* --- EXPRESSION BLOCKS --- */}
      
      {/* Fraction Divider */}
      <group ref={dividerRef} position={[0, 0, 0]}>
        <Box args={[6, 0.1, 1.5]}>
          <meshStandardMaterial color="#64748b" metalness={0.5} roughness={0.2} />
        </Box>
      </group>

      {/* Term 1: (x^2)^3 initially */}
      <group ref={term1Ref} position={[-2, 3, 0]}>
        <Box args={[1.2, 1.2, 1.2]}>
          <meshStandardMaterial color="#2563eb" />
        </Box>
        <Text position={[0, 0, 0.61]} fontSize={0.6} color="white" fontWeight="bold">x</Text>
        <Text ref={term1ExpRef} position={[0.7, 0.7, 0.61]} fontSize={0.5} color="white" fontWeight="bold">2</Text>

        {/* Outer Wrapper for the ^3 */}
        <group ref={term1OuterRef}>
          <Box args={[1.6, 1.6, 1.6]}>
            <meshStandardMaterial color="#db2777" transparent opacity={0.3} />
          </Box>
          <Box args={[1.6, 1.6, 1.6]}>
            <meshBasicMaterial color="#fbcfe8" wireframe />
          </Box>
          <Text position={[1.1, 1.1, 0.81]} fontSize={0.5} color="#fbcfe8" fontWeight="bold">3</Text>
        </group>
      </group>

      {/* Term 2: x^4 initially */}
      <group ref={term2Ref} position={[2, 3, 0]}>
        <Box args={[1.2, 1.2, 1.2]}>
          <meshStandardMaterial color="#2563eb" />
        </Box>
        <Text position={[0, 0, 0.61]} fontSize={0.6} color="white" fontWeight="bold">x</Text>
        <Text ref={term2ExpRef} position={[0.7, 0.7, 0.61]} fontSize={0.5} color="white" fontWeight="bold">4</Text>
      </group>

      {/* Term 3: x^5 initially (Denominator) */}
      <group ref={term3Ref} position={[0, -2, 0]}>
        <Box args={[1.2, 1.2, 1.2]}>
          <meshStandardMaterial color="#2563eb" />
        </Box>
        <Text position={[0, 0, 0.61]} fontSize={0.6} color="white" fontWeight="bold">x</Text>
        <Text ref={term3ExpRef} position={[0.7, 0.7, 0.61]} fontSize={0.5} color="white" fontWeight="bold">5</Text>
      </group>

    </group>
  );
}

// --- Main Application UI ---

export default function App() {
  const [step, setStep] = useState<number>(0);

  const isComplete = step === 3;
  const isAnimating = step === 1 || step === 2; // Rough block for fast clicks, step complete handler resets it via exact state matching if needed, but here we just step linearly.

  const nextStep = () => {
    if (step < 3) setStep(step + 1);
  };
  
  const reset = () => {
    setStep(0);
  };

  // Content for the button based on current step
  const getButtonContent = () => {
    switch(step) {
      case 0: return <><Play className="w-5 h-5 fill-current" /> Apply Power Rule</>;
      case 1: return <><Play className="w-5 h-5 fill-current" /> Apply Product Rule</>;
      case 2: return <><Play className="w-5 h-5 fill-current" /> Apply Quotient Rule</>;
      default: return <><Check className="w-5 h-5" /> Fully Simplified</>;
    }
  };

  return (
    <div className="w-full h-full relative overflow-hidden bg-slate-950 text-slate-100 font-sans">
      
      {/* Header */}
      <header className="absolute top-0 inset-x-0 h-16 bg-slate-900/80 backdrop-blur-md border-b border-slate-800 z-20 flex items-center px-6">
        <h1 className="text-lg font-bold bg-gradient-to-r from-purple-400 to-pink-500 bg-clip-text text-transparent flex items-center gap-3">
          <Layers className="w-5 h-5 text-purple-400" />
          Synthesis of Exponent Rules
        </h1>
      </header>

      {/* 3D Canvas Layer */}
      <div className="absolute inset-0 z-0 touch-none">
        <Canvas camera={{ position: [0, 1, 14], fov: 45 }}>
          <ambientLight intensity={0.6} />
          <directionalLight castShadow position={[10, 15, 10]} intensity={1.5} />
          <spotLight position={[-10, 10, -5]} intensity={1} color="#38bdf8" />
          <OrbitControls makeDefault enableDamping dampingFactor={0.05} maxPolarAngle={Math.PI / 2 + 0.1} />
          <AssemblyLineScene 
            step={step} 
            onStepComplete={() => {
              // We could auto-advance, but the prompt implies a step-by-step assembly line controlled by the user.
            }} 
          />
        </Canvas>
      </div>

      {/* Live Math HUD Overlay */}
      <div className="absolute top-20 right-6 z-10 pointer-events-none w-full max-w-[340px]">
        <div className="bg-slate-900/80 backdrop-blur-md border border-slate-700/80 p-5 rounded-2xl shadow-2xl pointer-events-auto transition-all duration-300">
          <h3 className="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-2">
            <Calculator className="w-4 h-4" /> Live Simplification
          </h3>
          <div className="bg-slate-950/80 rounded-xl p-4 border border-slate-800 flex flex-col items-center gap-4 text-xl overflow-x-auto shadow-inner min-h-[140px] justify-center relative">
            
            {/* Step 0 */}
            <div className={cn("absolute transition-all duration-500 transform", step === 0 ? "opacity-100 scale-100" : "opacity-0 scale-90 pointer-events-none")}>
              <BlockMath math={`\\frac{(x^2)^3 \\cdot x^4}{x^5}`} />
            </div>

            {/* Step 1 */}
            <div className={cn("absolute transition-all duration-500 transform", step === 1 ? "opacity-100 scale-100" : "opacity-0 scale-90 pointer-events-none")}>
              <div className="text-xs text-purple-400 font-bold mb-1 text-center">Power Rule: <InlineMath math="(x^2)^3 = x^6" /></div>
              <BlockMath math={`\\frac{x^6 \\cdot x^4}{x^5}`} />
            </div>

            {/* Step 2 */}
            <div className={cn("absolute transition-all duration-500 transform", step === 2 ? "opacity-100 scale-100" : "opacity-0 scale-90 pointer-events-none")}>
              <div className="text-xs text-blue-400 font-bold mb-1 text-center">Product Rule: <InlineMath math="x^6 \\cdot x^4 = x^{10}" /></div>
              <BlockMath math={`\\frac{x^{10}}{x^5}`} />
            </div>

            {/* Step 3 */}
            <div className={cn("absolute transition-all duration-500 transform", step === 3 ? "opacity-100 scale-110 text-emerald-400" : "opacity-0 scale-90 pointer-events-none")}>
              <div className="text-xs text-amber-400 font-bold mb-1 text-center">Quotient Rule: <InlineMath math="10 - 5 = 5" /></div>
              <BlockMath math={`x^5`} />
            </div>

          </div>
        </div>
      </div>

      {/* Bottom Interface: Misconceptions & Controls */}
      <div className="absolute bottom-0 inset-x-0 z-20 pointer-events-none p-4 sm:p-6 flex flex-col gap-4 max-w-7xl mx-auto items-center">
        
        {/* Misconception Trap HUD */}
        <div className={cn(
          "backdrop-blur-xl p-5 rounded-2xl shadow-2xl pointer-events-auto w-full max-w-4xl transition-all duration-700 transform",
          "bg-slate-900/95 border border-slate-700"
        )}>
          <h4 className="text-sm font-semibold text-slate-200 flex items-center gap-2 mb-4 justify-center">
            <Info className="w-4 h-4 text-emerald-400" />
            Core Rule: Grouping Common Bases vs. Combining Unequal Bases
          </h4>
          
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="bg-red-500/10 border border-red-500/20 rounded-xl p-4 flex items-center gap-4 group relative overflow-hidden">
              <div className="bg-red-500/20 p-3 rounded-full shrink-0 relative z-10">
                <Close className="w-5 h-5 text-red-400" />
              </div>
              <div className="w-full relative z-10">
                <p className="text-[10px] text-red-400/80 font-bold uppercase tracking-wider mb-1">Fatal Mistake (Unequal Bases)</p>
                <div className="text-sm text-slate-200">
                  <BlockMath math={`x^2 \\cdot y^3 \\neq (xy)^5`} />
                </div>
              </div>
              <div className="absolute inset-0 bg-red-500/5 opacity-0 group-hover:opacity-100 transition-opacity" />
            </div>

            <div className="bg-emerald-500/10 border border-emerald-500/20 rounded-xl p-4 flex items-center gap-4 group relative overflow-hidden">
              <div className="bg-emerald-500/20 p-3 rounded-full shrink-0 relative z-10">
                <Check className="w-5 h-5 text-emerald-400" />
              </div>
              <div className="w-full relative z-10">
                <p className="text-[10px] text-emerald-400/80 font-bold uppercase tracking-wider mb-1">Correct Logic (Common Bases)</p>
                <div className="text-sm text-slate-200">
                  <BlockMath math={`x^2 \\cdot x^3 = x^{2+3} = x^5`} />
                </div>
              </div>
              <div className="absolute inset-0 bg-emerald-500/5 opacity-0 group-hover:opacity-100 transition-opacity" />
            </div>
          </div>
        </div>

        {/* Master Control Panel */}
        <div className="bg-slate-900/95 backdrop-blur-2xl border border-slate-700/80 p-6 rounded-3xl shadow-[0_10px_50px_-10px_rgba(0,0,0,0.7)] pointer-events-auto w-full transition-all">
          <div className="flex flex-col md:flex-row gap-6 items-center justify-between">
            
            {/* Status Info */}
            <div className="flex items-center gap-3 w-full md:w-auto text-slate-300 font-medium">
              <ArrowDown className="w-5 h-5 text-slate-500 animate-bounce" />
              <span>Assembly Line Status: 
                <span className="text-white font-bold ml-2">
                  {step === 0 && "Ready"}
                  {step === 1 && "Numerator Expanded"}
                  {step === 2 && "Numerator Simplified"}
                  {step === 3 && "Fully Simplified"}
                </span>
              </span>
            </div>

            {/* Execution Actions */}
            <div className="flex gap-3 w-full md:w-auto shrink-0">
              <button 
                onClick={reset}
                className="flex-1 md:flex-none flex items-center justify-center p-3 px-6 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-xl transition-colors border border-slate-600 shadow-md"
                aria-label="Reset Assembly Line"
              >
                <RotateCcw className="w-5 h-5 mr-2" /> Reset
              </button>
              
              <button 
                onClick={nextStep}
                disabled={isComplete}
                className={cn(
                  "flex-1 md:flex-none flex items-center justify-center gap-2 px-8 py-3 rounded-xl font-bold transition-all shadow-lg text-sm",
                  isComplete
                    ? "bg-slate-800 text-slate-500 cursor-not-allowed border border-slate-700" 
                    : "bg-gradient-to-r from-purple-500 to-blue-600 hover:from-purple-400 hover:to-blue-500 text-white shadow-[0_0_20px_rgba(168,85,247,0.3)]"
                )}
              >
                {getButtonContent()}
              </button>
            </div>

          </div>
        </div>
      </div>
    </div>
  );
}
