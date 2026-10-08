import React, { useState, useRef } from 'react';
import { useSceneAnimation } from '../../../hooks/useSceneAnimation';
import { Canvas } from '../../../components/SceneCanvas';
import { OrbitControls, Box, RoundedBox, Cylinder } from '@react-three/drei';
import { Text } from '../../../components/SceneText';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath, InlineMath } from 'react-katex';
import { Play, RotateCcw, Check, Calculator, Info, X as Close, Zap, Code, ArrowRight } from 'lucide-react';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

// --- 3D Scene Component ---
function CompletionScene({ step, onStepComplete }: { step: number, onStepComplete: () => void }) {
  // Main terms
  const t1Ref = useRef<THREE.Group>(null!);
  const t2Ref = useRef<THREE.Group>(null!);
  const t3Ref = useRef<THREE.Group>(null!);
  const rhsRef = useRef<THREE.Group>(null!);
  
  // Operators
  const p1Ref = useRef<THREE.Group>(null!);
  const p2Ref = useRef<THREE.Group>(null!);
  const eqRef = useRef<THREE.Group>(null!);

  // Materials
  const t1Mat = useRef<THREE.MeshStandardMaterial>(null!);
  const t2Mat = useRef<THREE.MeshStandardMaterial>(null!);
  const t3Mat = useRef<THREE.MeshStandardMaterial>(null!);
  const rhsMat = useRef<THREE.MeshStandardMaterial>(null!);
  const bracketMat = useRef<THREE.MeshStandardMaterial>(null!);
  
  // Texts
  const t2Text = useRef<any>(null!);
  const rhsText = useRef<any>(null!);
  
  // Special FX
  const beamRef = useRef<THREE.Mesh>(null!);
  const bracketRef = useRef<THREE.Group>(null!);

  useSceneAnimation(() => {
    if (step === 0) {
      // Reset everything
      gsap.killTweensOf([
        t1Ref.current.position, t1Ref.current.scale, 
        t2Ref.current.position, t2Ref.current.scale,
        t3Ref.current.position, t3Ref.current.scale,
        rhsRef.current.position, rhsRef.current.scale,
        p1Ref.current.scale, p2Ref.current.scale, eqRef.current.position,
        beamRef.current.scale, bracketRef.current.scale, bracketRef.current.position
      ]);
      
      gsap.killTweensOf([t1Mat.current, t2Mat.current, t3Mat.current, rhsMat.current, bracketMat.current]);

      // Initial Setup: 2^a + 2^a + 2^a = 24
      gsap.set(t1Ref.current.position, { x: -4, y: 0, z: 0 });
      gsap.set(t2Ref.current.position, { x: -1.5, y: 0, z: 0 });
      gsap.set(t3Ref.current.position, { x: 1, y: 0, z: 0 });
      
      gsap.set(p1Ref.current.position, { x: -2.75, y: 0, z: 0 });
      gsap.set(p2Ref.current.position, { x: -0.25, y: 0, z: 0 });
      
      gsap.set(eqRef.current.position, { x: 2.75, y: 0, z: 0 });
      gsap.set(rhsRef.current.position, { x: 4.5, y: 0, z: 0 });

      gsap.set([t1Ref.current.scale, t2Ref.current.scale, t3Ref.current.scale, rhsRef.current.scale, eqRef.current.scale], { x: 1, y: 1, z: 1 });
      gsap.set([p1Ref.current.scale, p2Ref.current.scale], { x: 1, y: 1, z: 1 });
      
      gsap.set([t1Mat.current, t2Mat.current, t3Mat.current], { color: '#3b82f6', transparent: true, opacity: 0.9, emissive: '#000000' });
      gsap.set(rhsMat.current, { color: '#8b5cf6', transparent: true, opacity: 0.9, emissive: '#000000' });
      
      gsap.set(beamRef.current.scale, { x: 1, y: 0, z: 1 }); // Hidden beam
      gsap.set(bracketRef.current.scale, { x: 1, y: 0, z: 1 }); // Hidden bracket
      gsap.set(bracketRef.current.position, { y: 2 });
      gsap.set(bracketMat.current, { opacity: 0 });

      if (t2Text.current) t2Text.current.text = "2^a";
      if (rhsText.current) rhsText.current.text = "24";
    }

    if (step === 1) {
      // Step 1: Accumulation / Grouping -> 3 . 2^a
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      // Drop bracket from above
      tl.to(bracketRef.current.scale, { y: 1, duration: 0.4, ease: "back.out(1.5)" });
      tl.to(bracketMat.current, { opacity: 1, duration: 0.2 }, "-=0.4");
      tl.to(bracketRef.current.position, { y: 1.5, duration: 0.4 }, "-=0.4");
      
      // Plus signs fade out
      tl.to([p1Ref.current.scale, p2Ref.current.scale], { x: 0, y: 0, z: 0, duration: 0.3 }, "+=0.2");
      
      // Terms move together and merge
      tl.to(t1Ref.current.position, { x: -1.5, duration: 0.6, ease: "power2.inOut" }, "merge");
      tl.to(t3Ref.current.position, { x: -1.5, duration: 0.6, ease: "power2.inOut" }, "merge");
      
      tl.to(t2Mat.current, { emissive: '#1d4ed8', emissiveIntensity: 1, duration: 0.3, yoyo: true, repeat: 1 }, "merge+=0.4");
      
      tl.call(() => {
        if (t2Text.current) t2Text.current.text = "3 · 2^a";
      });
      
      // Scale down inner terms (t1, t3 disappear inside t2)
      tl.to([t1Ref.current.scale, t3Ref.current.scale], { x: 0, y: 0, z: 0, duration: 0.2 }, "-=0.2");
      
      // Re-center equation beautifully
      tl.to(bracketRef.current.scale, { y: 0, duration: 0.3 }, "+=0.2");
      tl.to(t2Ref.current.position, { x: -2, duration: 0.5, ease: "power2.out" }, "recenter");
      tl.to(eqRef.current.position, { x: 0, duration: 0.5, ease: "power2.out" }, "recenter");
      tl.to(rhsRef.current.position, { x: 2, duration: 0.5, ease: "power2.out" }, "recenter");
    }

    if (step === 2) {
      // Step 2: Division -> 2^a = 8
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      // Beam strikes RHS
      tl.to(beamRef.current.scale, { y: 1, duration: 0.3, ease: "power2.out" });
      tl.to(rhsMat.current, { emissive: '#6d28d9', emissiveIntensity: 1, duration: 0.2, yoyo: true, repeat: 1 });
      
      tl.call(() => {
        if (t2Text.current) t2Text.current.text = "2^a";
        if (rhsText.current) rhsText.current.text = "8";
      });
      
      // Beam retracts
      tl.to(beamRef.current.scale, { y: 0, duration: 0.3, ease: "power2.in" });
    }

    if (step === 3) {
      // Step 3: Factorize -> 8 becomes 2^3
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      tl.to(rhsRef.current.scale, { x: 1.3, y: 1.3, z: 1.3, duration: 0.3, yoyo: true, repeat: 1 });
      tl.to(rhsMat.current, { emissive: '#8b5cf6', emissiveIntensity: 1.5, duration: 0.3, yoyo: true, repeat: 1 }, "-=0.6");
      
      tl.call(() => {
        if (rhsText.current) rhsText.current.text = "2^3";
      });
    }

    if (step === 4) {
      // Step 4: Base match -> 2s disappear, a = 3
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      // Fade boxes, leave text
      tl.to([t2Mat.current, rhsMat.current], { opacity: 0, duration: 0.6, ease: "power2.inOut" });
      
      tl.call(() => {
        if (t2Text.current) t2Text.current.text = "a";
        if (rhsText.current) rhsText.current.text = "3";
      });
      
      // Exponents shift down/center to become the final equation
      tl.to(t2Ref.current.position, { x: -1, duration: 0.6, ease: "back.out(1.2)" }, "final");
      tl.to(rhsRef.current.position, { x: 1, duration: 0.6, ease: "back.out(1.2)" }, "final");
      
      // Glowing text effect (we pulse the scale)
      tl.to([t2Ref.current.scale, rhsRef.current.scale, eqRef.current.scale], { x: 1.3, y: 1.3, z: 1.3, duration: 0.4, yoyo: true, repeat: 1 }, "+=0.2");
    }
  }, [step]);

  return (
    <group position={[0, -0.5, 0]}>
      {/* Grid Floor */}
      <gridHelper args={[40, 40, '#1e293b', '#0f172a']} position={[0, -2, -2]} />

      {/* Bracket Graphic (Top) */}
      <group ref={bracketRef} position={[0, 2, -0.5]}>
        <Box args={[6, 0.2, 0.2]} position={[-1.5, 0, 0]}>
          <meshStandardMaterial ref={bracketMat} color="#facc15" transparent opacity={0} emissive="#ca8a04" emissiveIntensity={0.5} />
        </Box>
        <Box args={[0.2, 0.5, 0.2]} position={[-4.5, -0.25, 0]}>
          <meshStandardMaterial color="#facc15" transparent opacity={0} emissive="#ca8a04" />
        </Box>
        <Box args={[0.2, 0.5, 0.2]} position={[1.5, -0.25, 0]}>
          <meshStandardMaterial color="#facc15" transparent opacity={0} emissive="#ca8a04" />
        </Box>
        <Text position={[-1.5, 0.5, 0]} fontSize={0.4} color="#facc15" fontWeight="bold">Grouping</Text>
      </group>

      {/* Left Terms */}
      <group ref={t1Ref}>
        <RoundedBox args={[2, 2, 0.5]} radius={0.1}>
          <meshStandardMaterial ref={t1Mat} />
        </RoundedBox>
        <Text position={[0, 0, 0.26]} fontSize={0.8} color="white" fontWeight="bold">2^a</Text>
      </group>

      <group ref={p1Ref}>
        <Text fontSize={1.2} color="#64748b" fontWeight="bold">+</Text>
      </group>

      <group ref={t2Ref}>
        <RoundedBox args={[2.2, 2.2, 0.5]} radius={0.1}>
          <meshStandardMaterial ref={t2Mat} />
        </RoundedBox>
        <Text ref={t2Text} position={[0, 0, 0.26]} fontSize={0.8} color="white" fontWeight="bold">2^a</Text>
      </group>

      <group ref={p2Ref}>
        <Text fontSize={1.2} color="#64748b" fontWeight="bold">+</Text>
      </group>

      <group ref={t3Ref}>
        <RoundedBox args={[2, 2, 0.5]} radius={0.1}>
          <meshStandardMaterial ref={t3Mat} />
        </RoundedBox>
        <Text position={[0, 0, 0.26]} fontSize={0.8} color="white" fontWeight="bold">2^a</Text>
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
        <Text ref={rhsText} position={[0, 0, 0.26]} fontSize={0.9} color="white" fontWeight="bold">24</Text>
        
        {/* Beam hitting RHS */}
        <group position={[0, 3, 0]}>
          <mesh ref={beamRef} position={[0, -1.5, 0]}>
            <cylinderGeometry args={[1.5, 1.5, 3, 32, 1, true]} />
            <meshStandardMaterial color="#ec4899" transparent opacity={0.3} emissive="#db2777" emissiveIntensity={1} side={THREE.DoubleSide} />
          </mesh>
        </group>
      </group>
    </group>
  );
}


// --- Main Application UI ---
export default function Sec2() {
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
      
      {/* Header */}
      <header className="absolute top-0 inset-x-0 h-16 bg-slate-900/80 backdrop-blur-md border-b border-slate-800 z-20 flex items-center px-6">
        <h1 className="text-lg font-bold bg-gradient-to-r from-blue-400 to-purple-500 bg-clip-text text-transparent flex items-center gap-3">
          <Code className="w-5 h-5 text-blue-400" />
          Targeted Completion Problems
        </h1>
      </header>

      {/* 3D Canvas Layer */}
      <div className="absolute inset-0 z-0 touch-none">
        <Canvas camera={{ position: [0, 1, 10], fov: 50 }}>
          <ambientLight intensity={0.5} />
          <directionalLight castShadow position={[5, 10, 5]} intensity={1.5} />
          <spotLight position={[-5, 5, 5]} intensity={2} color="#8b5cf6" distance={20} angle={Math.PI / 4} penumbra={0.5} />
          <OrbitControls makeDefault enableDamping dampingFactor={0.05} maxPolarAngle={Math.PI / 2 + 0.1} />
          <CompletionScene 
            step={step} 
            onStepComplete={handleStepComplete} 
          />
        </Canvas>
      </div>

      {/* Misconception Trap HUD - Only visible during Step 1 (Grouping) */}
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
                   <BlockMath math={`2^a + 2^a + 2^a \\neq 6^a`} />
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
                  <BlockMath math={`2^a + 2^a + 2^a = 3 \\cdot 2^a`} />
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
          <div className="bg-slate-950/80 rounded-xl p-4 border border-slate-800 flex flex-col gap-3 text-lg shadow-inner min-h-[140px]">
            
            <div className={cn("transition-all duration-500", step >= 0 ? "opacity-100" : "opacity-0 h-0 overflow-hidden")}>
              <div className="text-[10px] text-slate-500 uppercase tracking-widest mb-1">Equation</div>
              <BlockMath math={`2^a + 2^a + 2^a = 24`} />
            </div>

            <div className={cn("transition-all duration-500", step >= 1 ? "opacity-100" : "opacity-0 h-0 overflow-hidden hidden")}>
              <div className="text-[10px] text-blue-400 uppercase tracking-widest mb-1">Accumulation</div>
              <BlockMath math={`3 \\cdot 2^a = 24`} />
            </div>
            
            <div className={cn("transition-all duration-500", step >= 2 ? "opacity-100" : "opacity-0 h-0 overflow-hidden hidden")}>
              <div className="text-[10px] text-purple-400 uppercase tracking-widest mb-1">Division (÷ 3)</div>
              <BlockMath math={`2^a = 8`} />
            </div>

            <div className={cn("transition-all duration-500", step >= 3 ? "opacity-100" : "opacity-0 h-0 overflow-hidden hidden")}>
              <div className="text-[10px] text-pink-400 uppercase tracking-widest mb-1">Factorization</div>
              <BlockMath math={`2^a = 2^3`} />
            </div>
            
            <div className={cn("transition-all duration-500 text-emerald-400", step >= 4 ? "opacity-100" : "opacity-0 h-0 overflow-hidden hidden")}>
              <div className="text-[10px] text-emerald-500 uppercase tracking-widest mb-1">Base Match</div>
              <BlockMath math={`a = 3`} />
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
              <h3 className="text-lg font-bold text-slate-200 mb-1">Completion Sequence</h3>
              <p className="text-sm text-slate-400">Step through the algebraic transformations to isolate and solve for the unknown exponent.</p>
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
                {step === 2 && <><ArrowRight className="w-5 h-5" /> Factorize Base</>}
                {step === 3 && <><Check className="w-5 h-5" /> Match Exponents</>}
                {step === 4 && <><Check className="w-5 h-5" /> Completed</>}
              </button>
            </div>

          </div>
        </div>
      </div>
    </div>
  );
}
