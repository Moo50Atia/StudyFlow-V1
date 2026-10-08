import React, { useState, useRef } from 'react';
import { useSceneAnimation } from '../../../hooks/useSceneAnimation';
import { Canvas } from '../../../components/SceneCanvas';
import { OrbitControls, Box, RoundedBox } from '@react-three/drei';
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
function ObjectiveScene({ step, onStepComplete }: { step: number, onStepComplete: () => void }) {
  // Main terms
  const t1Ref = useRef<THREE.Group>(null!);
  const t2Ref = useRef<THREE.Group>(null!);
  const rhsRef = useRef<THREE.Group>(null!);
  
  // Operators & Extras
  const plusRef = useRef<THREE.Group>(null!);
  const eqRef = useRef<THREE.Group>(null!);
  const bracketRef = useRef<THREE.Group>(null!);
  const beamRef = useRef<THREE.Mesh>(null!);
  const optionBlockRef = useRef<THREE.Group>(null!);

  // Materials
  const t1Mat = useRef<THREE.MeshStandardMaterial>(null!);
  const t2Mat = useRef<THREE.MeshStandardMaterial>(null!);
  const rhsMat = useRef<THREE.MeshStandardMaterial>(null!);
  const bracketMat = useRef<THREE.MeshStandardMaterial>(null!);
  const optionMat = useRef<THREE.MeshStandardMaterial>(null!);
  
  // Texts
  const t1Text = useRef<any>(null!);
  const t2Text = useRef<any>(null!);
  const rhsText = useRef<any>(null!);
  const optionText = useRef<any>(null!);

  useSceneAnimation(() => {
    if (step === 0) {
      // Reset everything
      gsap.killTweensOf([
        t1Ref.current.position, t1Ref.current.scale, 
        t2Ref.current.position, t2Ref.current.scale,
        rhsRef.current.position, rhsRef.current.scale,
        plusRef.current.scale, eqRef.current.position, eqRef.current.scale,
        beamRef.current.scale, bracketRef.current.scale, bracketRef.current.position,
        optionBlockRef.current.scale, optionBlockRef.current.position
      ]);
      
      gsap.killTweensOf([t1Mat.current, t2Mat.current, rhsMat.current, bracketMat.current, optionMat.current]);

      // Initial Setup: 5^x + 5^x = 250
      gsap.set(t1Ref.current.position, { x: -3.5, y: 1, z: 0 });
      gsap.set(t2Ref.current.position, { x: -0.5, y: 1, z: 0 });
      gsap.set(plusRef.current.position, { x: -2, y: 1, z: 0 });
      gsap.set(eqRef.current.position, { x: 1.5, y: 1, z: 0 });
      gsap.set(rhsRef.current.position, { x: 3.5, y: 1, z: 0 });
      
      gsap.set(optionBlockRef.current.position, { x: 0, y: -2, z: 0 });

      gsap.set([t1Ref.current.scale, t2Ref.current.scale, rhsRef.current.scale, eqRef.current.scale, plusRef.current.scale], { x: 1, y: 1, z: 1 });
      
      gsap.set([t1Mat.current, t2Mat.current], { color: '#0ea5e9', transparent: true, opacity: 0.9, emissive: '#000000' });
      gsap.set(rhsMat.current, { color: '#8b5cf6', transparent: true, opacity: 0.9, emissive: '#000000' });
      gsap.set(optionMat.current, { color: '#1e293b', emissive: '#000000', emissiveIntensity: 0 });
      
      gsap.set(beamRef.current.scale, { x: 1, y: 0, z: 1 }); // Hidden beam
      gsap.set(bracketRef.current.scale, { x: 1, y: 0, z: 1 }); // Hidden bracket
      gsap.set(bracketRef.current.position, { x: -2, y: 3, z: -0.5 });
      gsap.set(bracketMat.current, { opacity: 0 });
      gsap.set(optionBlockRef.current.scale, { x: 0, y: 0, z: 0 });

      if (t1Text.current) t1Text.current.text = "5^x";
      if (t2Text.current) t2Text.current.text = "5^x";
      if (rhsText.current) rhsText.current.text = "250";
      if (optionText.current) optionText.current.text = "Option Block";
    }

    if (step === 1) {
      // Step 1: Accumulation / Grouping -> 2 . 5^x
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      // Drop bracket from above
      tl.to(bracketRef.current.scale, { y: 1, duration: 0.4, ease: "back.out(1.5)" });
      tl.to(bracketMat.current, { opacity: 1, duration: 0.2 }, "-=0.4");
      tl.to(bracketRef.current.position, { y: 2.5, duration: 0.4 }, "-=0.4");
      
      // Plus sign fades out
      tl.to(plusRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.3 }, "+=0.2");
      
      // Terms move together and merge
      tl.to(t1Ref.current.position, { x: -2, duration: 0.6, ease: "power2.inOut" }, "merge");
      tl.to(t2Ref.current.position, { x: -2, duration: 0.6, ease: "power2.inOut" }, "merge");
      
      tl.to(t1Mat.current, { emissive: '#0284c7', emissiveIntensity: 1, duration: 0.3, yoyo: true, repeat: 1 }, "merge+=0.4");
      
      tl.call(() => {
        if (t1Text.current) t1Text.current.text = "2 · 5^x";
      });
      
      // Scale down inner term (t2 disappears inside t1)
      tl.to(t2Ref.current.scale, { x: 0, y: 0, z: 0, duration: 0.2 }, "-=0.2");
      
      // Hide Bracket
      tl.to(bracketRef.current.scale, { y: 0, duration: 0.3 }, "+=0.2");
    }

    if (step === 2) {
      // Step 2: Division -> 5^x = 125
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      // Beam strikes RHS
      tl.to(beamRef.current.scale, { y: 1, duration: 0.3, ease: "power2.out" });
      tl.to(rhsMat.current, { emissive: '#7c3aed', emissiveIntensity: 1, duration: 0.2, yoyo: true, repeat: 1 });
      
      tl.call(() => {
        if (t1Text.current) t1Text.current.text = "5^x";
        if (rhsText.current) rhsText.current.text = "125";
      });
      
      // Beam retracts
      tl.to(beamRef.current.scale, { y: 0, duration: 0.3, ease: "power2.in" });
    }

    if (step === 3) {
      // Step 3: Factorize -> 125 becomes 5^3
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      tl.to(rhsRef.current.scale, { x: 1.2, y: 1.2, z: 1.2, duration: 0.3, yoyo: true, repeat: 1 });
      tl.to(rhsMat.current, { emissive: '#a855f7', emissiveIntensity: 1.5, duration: 0.3, yoyo: true, repeat: 1 }, "-=0.6");
      
      tl.call(() => {
        if (rhsText.current) rhsText.current.text = "5^3";
      });
    }

    if (step === 4) {
      // Step 4: Base match -> x = 3 into option block
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      // Show Option Block
      tl.to(optionBlockRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: "back.out(1.5)" });
      
      // Fade bases
      tl.to([t1Mat.current, rhsMat.current], { opacity: 0, duration: 0.6, ease: "power2.inOut" }, "-=0.2");
      tl.to(eqRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.4 }, "-=0.4");
      
      tl.call(() => {
        if (t1Text.current) t1Text.current.text = "x";
        if (rhsText.current) rhsText.current.text = "3";
        if (optionText.current) optionText.current.text = "x = 3";
      });
      
      // Drop exponents into option block
      tl.to(t1Ref.current.position, { x: -0.5, y: -2, z: 0.3, duration: 0.6, ease: "power2.in" }, "drop");
      tl.to(rhsRef.current.position, { x: 0.5, y: -2, z: 0.3, duration: 0.6, ease: "power2.in" }, "drop");
      
      // Scale down dropped text
      tl.to([t1Ref.current.scale, rhsRef.current.scale], { x: 0, y: 0, z: 0, duration: 0.2 });

      // Highlight Option Block Green
      tl.to(optionMat.current, { color: '#10b981', emissive: '#059669', emissiveIntensity: 1, duration: 0.4 });
      tl.to(optionBlockRef.current.scale, { x: 1.1, y: 1.1, z: 1.1, duration: 0.3, yoyo: true, repeat: 1 });
    }
  }, [step]);

  return (
    <group position={[0, 0, 0]}>
      {/* Grid Floor */}
      <gridHelper args={[40, 40, '#1e293b', '#0f172a']} position={[0, -4, -2]} />

      {/* Bracket Graphic (Top) */}
      <group ref={bracketRef} position={[-2, 3, -0.5]}>
        <Box args={[4, 0.2, 0.2]} position={[0, 0, 0]}>
          <meshStandardMaterial ref={bracketMat} color="#facc15" transparent opacity={0} emissive="#ca8a04" emissiveIntensity={0.5} />
        </Box>
        <Box args={[0.2, 0.5, 0.2]} position={[-2, -0.25, 0]}>
          <meshStandardMaterial color="#facc15" transparent opacity={0} emissive="#ca8a04" />
        </Box>
        <Box args={[0.2, 0.5, 0.2]} position={[2, -0.25, 0]}>
          <meshStandardMaterial color="#facc15" transparent opacity={0} emissive="#ca8a04" />
        </Box>
        <Text position={[0, 0.5, 0]} fontSize={0.4} color="#facc15" fontWeight="bold">Term Grouping</Text>
      </group>

      {/* Left Terms */}
      <group ref={t1Ref}>
        <RoundedBox args={[2, 2, 0.5]} radius={0.1}>
          <meshStandardMaterial ref={t1Mat} />
        </RoundedBox>
        <Text ref={t1Text} position={[0, 0, 0.26]} fontSize={0.7} color="white" fontWeight="bold">5^x</Text>
      </group>

      <group ref={plusRef}>
        <Text fontSize={1.2} color="#64748b" fontWeight="bold">+</Text>
      </group>

      <group ref={t2Ref}>
        <RoundedBox args={[2, 2, 0.5]} radius={0.1}>
          <meshStandardMaterial ref={t2Mat} />
        </RoundedBox>
        <Text ref={t2Text} position={[0, 0, 0.26]} fontSize={0.7} color="white" fontWeight="bold">5^x</Text>
      </group>

      {/* Equation Center */}
      <group ref={eqRef}>
        <Text fontSize={1.2} color="#94a3b8" fontWeight="bold">=</Text>
      </group>

      {/* Right Term */}
      <group ref={rhsRef}>
        <RoundedBox args={[2.5, 2.5, 0.5]} radius={0.1}>
          <meshStandardMaterial ref={rhsMat} />
        </RoundedBox>
        <Text ref={rhsText} position={[0, 0, 0.26]} fontSize={0.8} color="white" fontWeight="bold">250</Text>
        
        {/* Beam hitting RHS */}
        <group position={[0, 3, 0]}>
          <mesh ref={beamRef} position={[0, -1.5, 0]}>
            <cylinderGeometry args={[1.5, 1.5, 3, 32, 1, true]} />
            <meshStandardMaterial color="#ec4899" transparent opacity={0.3} emissive="#db2777" emissiveIntensity={1} side={THREE.DoubleSide} />
          </mesh>
        </group>
      </group>

      {/* Option Block (Bottom) */}
      <group ref={optionBlockRef}>
        <RoundedBox args={[3.5, 1.5, 0.5]} radius={0.1}>
          <meshStandardMaterial ref={optionMat} color="#1e293b" />
        </RoundedBox>
        <Text ref={optionText} position={[0, 0, 0.27]} fontSize={0.6} color="white" fontWeight="bold">Option Block</Text>
      </group>
    </group>
  );
}


// --- Main Application UI ---
export default function Sec1() {
  const [step, setStep] = useState<number>(0);
  const [isAnimating, setIsAnimating] = useState(false);

  const nextStep = () => {
    if (step < 4 && !isAnimating) {
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
      
      {/* 3D Canvas Layer */}
      <div className="absolute inset-0 z-0 touch-none">
        <Canvas camera={{ position: [0, 1, 10], fov: 50 }}>
          <ambientLight intensity={0.5} />
          <directionalLight castShadow position={[5, 10, 5]} intensity={1.5} />
          <spotLight position={[-5, 5, 5]} intensity={2} color="#38bdf8" distance={20} angle={Math.PI / 4} penumbra={0.5} />
          <OrbitControls makeDefault enableDamping dampingFactor={0.05} maxPolarAngle={Math.PI / 2 + 0.1} />
          <ObjectiveScene 
            step={step} 
            onStepComplete={handleStepComplete} 
          />
        </Canvas>
      </div>

      {/* Misconception Trap HUD - Visible on Step 1 */}
      <div className={cn(
        "absolute top-20 left-6 z-10 pointer-events-none w-full max-w-[360px] transition-all duration-700 transform",
        (step >= 1 && step < 4) ? "translate-x-0 opacity-100 scale-100" : "-translate-x-12 opacity-0 scale-95 pointer-events-none"
      )}>
        <div className="bg-slate-900/95 backdrop-blur-xl border border-slate-700 rounded-2xl shadow-2xl p-5 pointer-events-auto">
          <h4 className="text-sm font-semibold text-slate-200 flex items-center gap-2 mb-4">
            <Info className="w-4 h-4 text-amber-400" />
            Term Accumulation Verification
          </h4>
          
          <div className="flex flex-col gap-3">
            <div className="bg-red-500/10 border border-red-500/20 rounded-xl p-3 flex items-center gap-4">
              <div className="bg-red-500/20 p-2 rounded-full shrink-0">
                <Close className="w-4 h-4 text-red-400" />
              </div>
              <div className="w-full">
                <p className="text-[10px] text-red-400/80 font-bold uppercase tracking-wider mb-0.5">Incorrect: Adding Bases</p>
                <div className="text-xs text-slate-200">
                   <BlockMath math={`5^x + 5^x \\neq 10^x`} />
                </div>
              </div>
            </div>

            <div className="bg-emerald-500/10 border border-emerald-500/20 rounded-xl p-3 flex items-center gap-4">
              <div className="bg-emerald-500/20 p-2 rounded-full shrink-0">
                <Check className="w-4 h-4 text-emerald-400" />
              </div>
              <div className="w-full">
                <p className="text-[10px] text-emerald-400/80 font-bold uppercase tracking-wider mb-0.5">Correct: Grouping Terms</p>
                <div className="text-xs text-slate-200">
                  <BlockMath math={`5^x + 5^x = 2 \\cdot 5^x`} />
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
            <Calculator className="w-4 h-4" /> Operations Trace
          </h3>
          <div className="bg-slate-950/80 rounded-xl p-4 border border-slate-800 flex flex-col gap-3 text-lg shadow-inner min-h-[140px] overflow-hidden">
            
            {step >= 0 && (
              <div className="animate-in fade-in slide-in-from-right-4 duration-500">
                <div className="text-[10px] text-slate-500 uppercase tracking-widest mb-1">Equation</div>
                <BlockMath math={`5^x + 5^x = 250`} />
              </div>
            )}

            {step >= 1 && (
              <div className="animate-in fade-in slide-in-from-right-4 duration-500">
                <div className="text-[10px] text-blue-400 uppercase tracking-widest mb-1">Accumulation</div>
                <BlockMath math={`2 \\cdot 5^x = 250`} />
              </div>
            )}
            
            {step >= 2 && (
              <div className="animate-in fade-in slide-in-from-right-4 duration-500">
                <div className="text-[10px] text-purple-400 uppercase tracking-widest mb-1">Division (÷ 2)</div>
                <BlockMath math={`5^x = 125`} />
              </div>
            )}

            {step >= 3 && (
              <div className="animate-in fade-in slide-in-from-right-4 duration-500">
                <div className="text-[10px] text-sky-400 uppercase tracking-widest mb-1">Base Factorization</div>
                <BlockMath math={`5^x = 5^3`} />
              </div>
            )}
            
            {step >= 4 && (
              <div className="animate-in fade-in slide-in-from-right-4 duration-500 text-emerald-400">
                <div className="text-[10px] text-emerald-500 uppercase tracking-widest mb-1">Base Match / Solution</div>
                <BlockMath math={`x = 3`} />
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
              <h3 className="text-lg font-bold text-slate-200 mb-1">Speed Evaluation Strategy</h3>
              <p className="text-sm text-slate-400">Apply objective problem-solving methods to quickly isolate the solution for multiple-choice selection.</p>
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
                disabled={step >= 4 || isAnimating}
                className={cn(
                  "flex-1 md:flex-none flex items-center justify-center gap-2 px-8 py-3 rounded-xl font-bold transition-all shadow-lg text-sm",
                  step >= 4 || isAnimating
                    ? "bg-slate-800 text-slate-500 cursor-not-allowed border border-slate-700" 
                    : "bg-blue-600 hover:bg-blue-500 text-white shadow-[0_0_20px_rgba(37,99,235,0.3)]"
                )}
              >
                {step === 0 && <><Zap className="w-5 h-5" /> Group Terms</>}
                {step === 1 && <><Play className="w-5 h-5 fill-current" /> Divide Constant</>}
                {step === 2 && <><Code className="w-5 h-5" /> Factorize Base</>}
                {step === 3 && <><ArrowRight className="w-5 h-5" /> Resolve to Option</>}
                {step === 4 && <><CheckCircle2 className="w-5 h-5" /> Completed</>}
              </button>
            </div>

          </div>
        </div>
      </div>
    </div>
  );
}
