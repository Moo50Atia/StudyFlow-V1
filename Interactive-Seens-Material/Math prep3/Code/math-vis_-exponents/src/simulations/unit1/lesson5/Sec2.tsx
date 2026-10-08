import React, { useState, useRef } from 'react';
import { useSceneAnimation } from '../../../hooks/useSceneAnimation';
import { Canvas } from '../../../components/SceneCanvas';
import { OrbitControls, Box, RoundedBox, Edges } from '@react-three/drei';
import { Text } from '../../../components/SceneText';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { Play, RotateCcw, Check, Calculator, Info, X as Close, Zap, ArrowRight, Target, CheckCircle2, Code } from 'lucide-react';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

// --- 3D Scene Component ---
function GeometryScene({ step, onStepComplete }: { step: number, onStepComplete: () => void }) {
  // Rectangle
  const rectRef = useRef<THREE.Group>(null!);
  const rectMat = useRef<THREE.MeshStandardMaterial>(null!);
  const areaTextRef = useRef<any>(null!);
  
  // Dimensions
  const widthBlockRef = useRef<THREE.Group>(null!);
  const heightBlockRef = useRef<THREE.Group>(null!);
  const heightTextRef = useRef<any>(null!);
  
  // Equation terms
  const eqGroupRef = useRef<THREE.Group>(null!);
  const eqTermLRef = useRef<THREE.Group>(null!);
  const eqTermRRef = useRef<THREE.Group>(null!);
  const eqLText = useRef<any>(null!);
  const eqRText = useRef<any>(null!);
  const eqMatL = useRef<THREE.MeshStandardMaterial>(null!);
  const eqMatR = useRef<THREE.MeshStandardMaterial>(null!);
  const eqSignRef = useRef<THREE.Group>(null!);

  // Division Beam
  const beamRef = useRef<THREE.Mesh>(null!);

  useSceneAnimation(() => {
    if (step === 0) {
      // Reset everything
      gsap.killTweensOf([
        rectMat.current,
        widthBlockRef.current.position, widthBlockRef.current.scale,
        heightBlockRef.current.position, heightBlockRef.current.scale,
        eqGroupRef.current.position, eqGroupRef.current.scale,
        eqTermLRef.current.position, eqTermLRef.current.scale,
        eqTermRRef.current.position, eqTermRRef.current.scale,
        beamRef.current.scale, eqMatL.current, eqMatR.current,
        eqSignRef.current.scale
      ]);

      gsap.set(rectMat.current, { color: '#0f172a', emissive: '#000000', emissiveIntensity: 0 });
      if (areaTextRef.current) areaTextRef.current.text = "Area = 176 cm²";

      gsap.set(widthBlockRef.current.scale, { x: 1, y: 1, z: 1 });
      gsap.set(widthBlockRef.current.position, { x: 0, y: -2.2, z: 0 }); // Bottom of rect
      
      gsap.set(heightBlockRef.current.scale, { x: 1, y: 1, z: 1 });
      gsap.set(heightBlockRef.current.position, { x: -3.8, y: 0, z: 0 }); // Left of rect
      if (heightTextRef.current) heightTextRef.current.text = "2^{x+1} cm";

      // Equation setup (hidden initially, or represents the division)
      gsap.set(eqGroupRef.current.position, { x: 0, y: -4, z: 0 }); // Below everything
      gsap.set(eqGroupRef.current.scale, { x: 1, y: 1, z: 1 });
      
      gsap.set(eqTermLRef.current.position, { x: -2, y: 0, z: 0 });
      gsap.set(eqTermRRef.current.position, { x: 2, y: 0, z: 0 });
      gsap.set(eqSignRef.current.scale, { x: 1, y: 1, z: 1 });

      gsap.set([eqMatL.current, eqMatR.current], { opacity: 0, transparent: true, emissiveIntensity: 0, color: '#334155' });
      gsap.set([eqTermLRef.current.scale, eqTermRRef.current.scale], { x: 1, y: 1, z: 1 });
      
      if (eqLText.current) eqLText.current.text = "2^{x+1}";
      if (eqRText.current) eqRText.current.text = "176 / 11";
      if (eqLText.current) eqLText.current.fillOpacity = 0;
      if (eqRText.current) eqRText.current.fillOpacity = 0;

      gsap.set(beamRef.current.scale, { y: 0 });
    }

    if (step === 1) {
      // Step 1: Area division -> 2^{x+1} = 16
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      // Reveal equation group
      tl.to([eqMatL.current, eqMatR.current], { opacity: 1, duration: 0.5 });
      tl.to([eqLText.current, eqRText.current], { fillOpacity: 1, duration: 0.5 }, "-=0.5");
      
      // Beam strikes to divide
      tl.to(beamRef.current.scale, { y: 1, duration: 0.3, ease: "power2.out" });
      tl.to(eqMatR.current, { emissive: '#db2777', emissiveIntensity: 1, duration: 0.2, yoyo: true, repeat: 1 });
      
      tl.call(() => {
        if (eqRText.current) eqRText.current.text = "16";
        if (areaTextRef.current) areaTextRef.current.text = "Area = 11 * 16";
        if (heightTextRef.current) heightTextRef.current.text = "16 cm";
      });
      
      tl.to(beamRef.current.scale, { y: 0, duration: 0.3, ease: "power2.in" });
    }

    if (step === 2) {
      // Step 2: Factorize -> 2^4
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      tl.to(eqTermRRef.current.scale, { x: 1.2, y: 1.2, z: 1.2, duration: 0.3, yoyo: true, repeat: 1 });
      tl.to(eqMatR.current, { emissive: '#8b5cf6', emissiveIntensity: 1.5, duration: 0.3, yoyo: true, repeat: 1 }, "-=0.6");
      
      tl.call(() => {
        if (eqRText.current) eqRText.current.text = "2^4";
      });
    }

    if (step === 3) {
      // Step 3: Dissolve common base -> x + 1 = 4
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      tl.to([eqMatL.current, eqMatR.current], { opacity: 0, duration: 0.6 });
      
      tl.call(() => {
        if (eqLText.current) eqLText.current.text = "x + 1";
        if (eqRText.current) eqRText.current.text = "4";
      });
      
      // Exponents drop
      tl.to(eqTermLRef.current.position, { x: -1, y: -1, duration: 0.6, ease: "back.out(1.2)" }, "drop");
      tl.to(eqTermRRef.current.position, { x: 1, y: -1, duration: 0.6, ease: "back.out(1.2)" }, "drop");
      tl.to(eqSignRef.current.position, { y: -1, duration: 0.6, ease: "back.out(1.2)" }, "drop");
    }

    if (step === 4) {
      // Step 4: Final root x = 3 and highlight rectangle
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      tl.to([eqTermLRef.current.scale, eqTermRRef.current.scale, eqSignRef.current.scale], { x: 0, y: 0, z: 0, duration: 0.3 });
      
      tl.call(() => {
        if (eqLText.current) eqLText.current.text = "x = 3";
        if (eqRText.current) eqRText.current.text = "";
        // reset scale to show just x=3
        gsap.set(eqTermLRef.current.scale, { x: 1.5, y: 1.5, z: 1.5 });
        gsap.set(eqTermLRef.current.position, { x: 0, y: -1, z: 0 });
      });
      
      // Highlight rect
      tl.to(rectMat.current, { color: '#064e3b', emissive: '#10b981', emissiveIntensity: 0.5, duration: 0.5 });
      tl.call(() => {
        if (heightTextRef.current) heightTextRef.current.text = "2^{3+1} = 16 cm";
      });
    }
  }, [step]);

  return (
    <group position={[0, 1, 0]}>
      {/* Grid Floor */}
      <gridHelper args={[40, 40, '#1e293b', '#0f172a']} position={[0, -6, -2]} />

      {/* Geometric Rectangle */}
      <group ref={rectRef} position={[0, 0, 0]}>
        <Box args={[6, 4, 0.2]}>
          <meshStandardMaterial ref={rectMat} color="#0f172a" />
          <Edges color="#3b82f6" threshold={15} />
        </Box>
        <Text ref={areaTextRef} position={[0, 0, 0.15]} fontSize={0.6} color="#94a3b8" fontWeight="bold">Area = 176 cm²</Text>
      </group>

      {/* Dimensions */}
      <group ref={widthBlockRef}>
        <Text position={[0, 0, 0]} fontSize={0.5} color="#38bdf8" fontWeight="bold">11 cm</Text>
      </group>
      
      <group ref={heightBlockRef}>
        <Text ref={heightTextRef} position={[0, 0, 0]} fontSize={0.5} color="#c084fc" fontWeight="bold">2^{"{x+1}"} cm</Text>
      </group>

      {/* Division Beam Effect */}
      <group position={[2, -1, 0]}>
        <mesh ref={beamRef} position={[0, -1.5, 0]}>
          <cylinderGeometry args={[1.5, 1.5, 3, 32, 1, true]} />
          <meshStandardMaterial color="#ec4899" transparent opacity={0.3} emissive="#db2777" emissiveIntensity={1} side={THREE.DoubleSide} />
        </mesh>
      </group>

      {/* Equation Panel (Below) */}
      <group ref={eqGroupRef}>
        <group ref={eqTermLRef}>
          <RoundedBox args={[2.5, 1.5, 0.5]} radius={0.1}>
            <meshStandardMaterial ref={eqMatL} color="#334155" />
          </RoundedBox>
          <Text ref={eqLText} position={[0, 0, 0.26]} fontSize={0.6} color="white" fontWeight="bold">2^{"{x+1}"}</Text>
        </group>

        <group ref={eqSignRef}>
          <Text fontSize={1} color="#64748b" fontWeight="bold">=</Text>
        </group>

        <group ref={eqTermRRef}>
          <RoundedBox args={[2.5, 1.5, 0.5]} radius={0.1}>
            <meshStandardMaterial ref={eqMatR} color="#334155" />
          </RoundedBox>
          <Text ref={eqRText} position={[0, 0, 0.26]} fontSize={0.6} color="white" fontWeight="bold">176 / 11</Text>
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
      
      {/* 3D Canvas Layer */}
      <div className="absolute inset-0 z-0 touch-none">
        <Canvas camera={{ position: [0, 0, 12], fov: 50 }}>
          <ambientLight intensity={0.5} />
          <directionalLight castShadow position={[5, 10, 5]} intensity={1.5} />
          <spotLight position={[0, 0, 10]} intensity={2} color="#38bdf8" distance={20} angle={Math.PI / 3} penumbra={0.5} />
          <OrbitControls makeDefault enableDamping dampingFactor={0.05} maxPolarAngle={Math.PI / 2 + 0.1} />
          <GeometryScene 
            step={step} 
            onStepComplete={handleStepComplete} 
          />
        </Canvas>
      </div>

      {/* Misconception Trap HUD - Visible on Step 1 & 2 */}
      <div className={cn(
        "absolute top-20 left-6 z-10 pointer-events-none w-full max-w-[360px] transition-all duration-700 transform",
        (step >= 1 && step < 4) ? "translate-x-0 opacity-100 scale-100" : "-translate-x-12 opacity-0 scale-95 pointer-events-none"
      )}>
        <div className="bg-slate-900/95 backdrop-blur-xl border border-slate-700 rounded-2xl shadow-2xl p-5 pointer-events-auto">
          <h4 className="text-sm font-semibold text-slate-200 flex items-center gap-2 mb-4">
            <Info className="w-4 h-4 text-emerald-400" />
            Geometric Verification
          </h4>
          
          <div className="flex flex-col gap-3">
            <div className="bg-red-500/10 border border-red-500/20 rounded-xl p-3 flex items-center gap-4">
              <div className="bg-red-500/20 p-2 rounded-full shrink-0">
                <Close className="w-4 h-4 text-red-400" />
              </div>
              <div className="w-full">
                <p className="text-[10px] text-red-400/80 font-bold uppercase tracking-wider mb-0.5">Incorrect: Subtraction Error</p>
                <div className="text-xs text-slate-200">
                   <BlockMath math={`2^{x+1} \\neq 176 - 11`} />
                   <span className="text-slate-400 mt-1 block">Area is a product, not a sum.</span>
                </div>
              </div>
            </div>

            <div className="bg-emerald-500/10 border border-emerald-500/20 rounded-xl p-3 flex items-center gap-4">
              <div className="bg-emerald-500/20 p-2 rounded-full shrink-0">
                <Check className="w-4 h-4 text-emerald-400" />
              </div>
              <div className="w-full">
                <p className="text-[10px] text-emerald-400/80 font-bold uppercase tracking-wider mb-0.5">Correct: Division Rule</p>
                <div className="text-xs text-slate-200">
                  <BlockMath math={`\\text{Height} = \\frac{\\text{Area}}{\\text{Width}}`} />
                  <BlockMath math={`2^{x+1} = \\frac{176}{11} = 16`} />
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
                <div className="text-[10px] text-slate-500 uppercase tracking-widest mb-1">Geometric Equation</div>
                <BlockMath math={`11 \\cdot 2^{x+1} = 176`} />
              </div>
            )}

            {step >= 1 && (
              <div className="animate-in fade-in slide-in-from-right-4 duration-500">
                <div className="text-[10px] text-blue-400 uppercase tracking-widest mb-1">Area Division (÷ 11)</div>
                <BlockMath math={`2^{x+1} = 16`} />
              </div>
            )}

            {step >= 2 && (
              <div className="animate-in fade-in slide-in-from-right-4 duration-500">
                <div className="text-[10px] text-purple-400 uppercase tracking-widest mb-1">Factorize Base</div>
                <BlockMath math={`2^{x+1} = 2^4`} />
              </div>
            )}
            
            {step >= 3 && (
              <div className="animate-in fade-in slide-in-from-right-4 duration-500">
                <div className="text-[10px] text-sky-400 uppercase tracking-widest mb-1">Equate Exponents</div>
                <BlockMath math={`x + 1 = 4`} />
              </div>
            )}
            
            {step >= 4 && (
              <div className="animate-in fade-in slide-in-from-right-4 duration-500 text-emerald-400">
                <div className="text-[10px] text-emerald-500 uppercase tracking-widest mb-1">Final Root</div>
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
              <h3 className="text-lg font-bold text-slate-200 mb-1">Geometric Execution</h3>
              <p className="text-sm text-slate-400">Extract the equation from the geometric properties and trace the algebraic logic.</p>
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
                {step === 0 && <><Zap className="w-5 h-5" /> Divide Area</>}
                {step === 1 && <><Code className="w-5 h-5" /> Factorize Base</>}
                {step === 2 && <><ArrowRight className="w-5 h-5" /> Match Exponents</>}
                {step === 3 && <><Check className="w-5 h-5" /> Solve for x</>}
                {step === 4 && <><CheckCircle2 className="w-5 h-5" /> Completed</>}
              </button>
            </div>

          </div>
        </div>
      </div>
    </div>
  );
}
