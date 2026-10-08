import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, Search, Zap, AlertTriangle, SlidersHorizontal, RotateCcw, ShieldCheck } from 'lucide-react';

// ---------------------------------------------------------
// MATHEMATICS UTILS
// ---------------------------------------------------------
function getGCD(a: number, b: number): number {
  a = Math.abs(a);
  b = Math.abs(b);
  if (b === 0) return a;
  return getGCD(b, a % b);
}

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------
function FractionBlock({ 
  n, d, position, scale = 1, isInput = false 
}: { 
  n: number, d: number, position: [number, number, number], scale?: number, isInput?: boolean 
}) {
  const groupRef = useRef<THREE.Group>(null);
  const nRef = useRef<THREE.Group>(null);
  const dRef = useRef<THREE.Group>(null);

  // Pop animation on value change
  useEffect(() => {
    if (nRef.current) gsap.fromTo(nRef.current.scale, { x: 1.5, y: 1.5, z: 1.5 }, { x: 1, y: 1, z: 1, duration: 0.4, ease: "back.out(2)" });
    if (dRef.current) gsap.fromTo(dRef.current.scale, { x: 1.5, y: 1.5, z: 1.5 }, { x: 1, y: 1, z: 1, duration: 0.4, ease: "back.out(2)" });
  }, [n, d]);

  return (
    <group ref={groupRef} position={position} scale={[scale, scale, scale]}>
      {/* Container Background */}
      <mesh position={[0, 0, -0.3]}>
         <boxGeometry args={[2.5, 3.5, 0.2]} />
         <meshStandardMaterial color={isInput ? "#1e293b" : "#0f172a"} roughness={0.4} />
      </mesh>
      
      {/* Numerator */}
      <group ref={nRef} position={[0, 1, 0]}>
         <mesh castShadow receiveShadow>
            <boxGeometry args={[1.5, 1.2, 0.4]} />
            <meshStandardMaterial color="#3b82f6" roughness={0.3} />
         </mesh>
         <Text position={[0, 0, 0.21]} fontSize={0.8} color="#ffffff" outlineWidth={0.04} outlineColor="#000">{n}</Text>
      </group>

      {/* Fraction Bar */}
      <mesh position={[0, 0, 0]} castShadow>
         <boxGeometry args={[1.8, 0.15, 0.4]} />
         <meshStandardMaterial color="#94a3b8" />
      </mesh>

      {/* Denominator */}
      <group ref={dRef} position={[0, -1, 0]}>
         <mesh castShadow receiveShadow>
            <boxGeometry args={[1.5, 1.2, 0.4]} />
            <meshStandardMaterial color={d === 0 ? "#ef4444" : "#8b5cf6"} roughness={0.3} />
         </mesh>
         <Text position={[0, 0, 0.21]} fontSize={0.8} color="#ffffff" outlineWidth={0.04} outlineColor="#000">{d}</Text>
      </group>

      {/* Label */}
      <Text position={[0, -2.2, 0]} fontSize={0.3} color="#cbd5e1" fontStyle="italic">
         {isInput ? "Input: a/b" : "Standard Form"}
      </Text>
    </group>
  );
}

function ScannerLens({ gcd, divisor, signFlip, active }: { gcd: number, divisor: number, signFlip: boolean, active: boolean }) {
  const ringRef = useRef<THREE.Mesh>(null);
  const groupRef = useRef<THREE.Group>(null);

  useFrame((_, dt) => {
    if (ringRef.current) {
      ringRef.current.rotation.z -= dt * 1.5;
      ringRef.current.rotation.x = Math.sin(_.clock.elapsedTime) * 0.2;
    }
  });

  useEffect(() => {
    if (active) {
       gsap.to(groupRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: "back.out(1.5)" });
       gsap.to((ringRef.current!.material as THREE.MeshStandardMaterial), { emissiveIntensity: 1, duration: 0.5 });
    } else {
       gsap.to(groupRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.5, ease: "power2.in" });
    }
  }, [active, divisor]);

  return (
    <group ref={groupRef} position={[0, 0, 0]}>
       {/* Tech Ring */}
       <mesh ref={ringRef} castShadow>
          <torusGeometry args={[1.8, 0.1, 16, 64]} />
          <meshStandardMaterial color="#06b6d4" emissive="#06b6d4" emissiveIntensity={0.8} transparent opacity={0.8} />
       </mesh>
       
       {/* Scanner Glass */}
       <mesh position={[0, 0, 0]}>
          <cylinderGeometry args={[1.7, 1.7, 0.2, 32]} />
          <meshPhysicalMaterial color="#38bdf8" transparent opacity={0.2} transmission={0.9} roughness={0.1} />
       </mesh>

       {/* Operation Display */}
       <group position={[0, 0, 0.3]}>
          <mesh position={[0, 0, -0.1]}>
             <boxGeometry args={[2.5, 1.2, 0.1]} />
             <meshStandardMaterial color="#0f172a" transparent opacity={0.8} />
          </mesh>
          <Text position={[0, 0.2, 0]} fontSize={0.4} color="#38bdf8">Operation</Text>
          <Text position={[0, -0.3, 0]} fontSize={0.5} color="#ffffff" outlineWidth={0.03} outlineColor="#000">
             {signFlip ? `÷ (${-divisor})` : `÷ ${divisor}`}
          </Text>
       </group>

       {/* Flow Arrows */}
       <Text position={[-2.8, 0, 0]} fontSize={1} color="#64748b">→</Text>
       <Text position={[2.8, 0, 0]} fontSize={1} color="#64748b">→</Text>
    </group>
  );
}

function HazardBarrier({ active }: { active: boolean }) {
  const groupRef = useRef<THREE.Group>(null);
  
  useEffect(() => {
    if (active) {
       gsap.to(groupRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.6, ease: "elastic.out(1, 0.6)" });
       gsap.to(groupRef.current!.position, { y: 0, duration: 0.6, ease: "power2.out" });
    } else {
       gsap.to(groupRef.current!.scale, { x: 0.1, y: 0.1, z: 0.1, duration: 0.3 });
       gsap.to(groupRef.current!.position, { y: -5, duration: 0.3 });
    }
  }, [active]);

  return (
    <group ref={groupRef} position={[4, -5, 0]} scale={[0,0,0]}>
       <mesh castShadow position={[0, 0, 0]}>
          <boxGeometry args={[3, 3, 1]} />
          <meshStandardMaterial color="#e11d48" roughness={0.3} emissive="#e11d48" emissiveIntensity={0.4} />
       </mesh>
       <Text position={[0, 0.5, 0.51]} fontSize={0.6} color="#ffffff" outlineWidth={0.04} outlineColor="#000">HAZARD</Text>
       <Text position={[0, -0.2, 0.51]} fontSize={0.4} color="#fecdd3">b = 0</Text>
       <Text position={[0, -0.8, 0.51]} fontSize={0.3} color="#fecdd3">∉ ℚ (Not Rational)</Text>
       
       <mesh position={[0, 0, 0.5]} rotation={[0, 0, Math.PI / 4]}>
          <boxGeometry args={[3.2, 0.2, 0.1]} />
          <meshStandardMaterial color="#ffffff" />
       </mesh>
    </group>
  );
}

// ---------------------------------------------------------
// SCENE CONTROLLER
// ---------------------------------------------------------
function Scene({ n, d, step }: { n: number, d: number, step: number }) {
  const { camera } = useThree();
  const controlsRef = useRef<any>(null);

  // Math Logic
  const isZero = d === 0;
  const rawGCD = getGCD(n, d);
  // Rule: if denominator is negative, we divide by negative GCD to flip signs.
  // Exception: if n is 0, we just want standard form to be 0/1 (so divide by d).
  let divisor = rawGCD;
  let signFlip = false;
  
  if (!isZero) {
    if (n === 0) {
       divisor = d; // makes it 0/1
    } else if (d < 0) {
       divisor = -rawGCD;
       signFlip = true;
    } else {
       signFlip = false;
    }
  }

  const finalN = isZero ? n : n / divisor;
  const finalD = isZero ? d : d / divisor;

  const showScanner = step >= 1 && !isZero;
  const showOutput = step >= 2 && !isZero;
  const showHazard = step >= 3 && isZero;

  const outGroupRef = useRef<THREE.Group>(null);

  useEffect(() => {
     if (showOutput) {
        gsap.to(outGroupRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.6, ease: "back.out(1.5)", delay: 0.2 });
     } else {
        gsap.to(outGroupRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
     }
  }, [showOutput, finalN, finalD]);

  // Camera Management
  useEffect(() => {
    let t = { camX: 0, camY: 0, camZ: 12, tarX: 0, tarY: 0 };
    if (step === 0) t = { camX: -4, camY: 0, camZ: 8, tarX: -4, tarY: 0 };
    if (step === 1) t = { camX: -2, camY: 0, camZ: 9, tarX: -2, tarY: 0 };
    if (step === 2) t = { camX: 0, camY: 0, camZ: 11, tarX: 0, tarY: 0 };
    if (step === 3) t = { camX: 2, camY: 0, camZ: 10, tarX: 2, tarY: 0 };
    if (step === 4) t = { camX: 0, camY: 0, camZ: 12, tarX: 0, tarY: 0 };

    gsap.to(camera.position, { x: t.camX, y: t.camY, z: t.camZ, duration: 1.5, ease: 'power3.inOut' });
    if (controlsRef.current) {
      gsap.to(controlsRef.current.target, { x: t.tarX, y: t.tarY, z: 0, duration: 1.5, ease: 'power3.inOut' });
    }
  }, [step, camera]);

  return (
    <group>
      <OrbitControls 
        ref={controlsRef} 
        enablePan={false} 
        enableZoom={false}
        maxPolarAngle={Math.PI / 2 + 0.2}
        minPolarAngle={Math.PI / 2 - 0.2}
        maxAzimuthAngle={0.4}
        minAzimuthAngle={-0.4}
      />
      
      {/* Background Track */}
      <mesh position={[0, 0, -1]}>
         <boxGeometry args={[14, 5, 0.2]} />
         <meshStandardMaterial color="#020617" transparent opacity={0.8} />
      </mesh>

      {/* 1. Input Station */}
      <FractionBlock n={n} d={d} position={[-4, 0, 0]} isInput={true} />

      {/* 2. Processing Scanner */}
      <ScannerLens gcd={rawGCD} divisor={Math.abs(divisor)} signFlip={signFlip || divisor < 0} active={showScanner} />

      {/* 3. Output Station */}
      <group ref={outGroupRef} position={[4, 0, 0]} scale={[0,0,0]}>
         <FractionBlock n={finalN} d={finalD} position={[0, 0, 0]} />
      </group>

      {/* 4. Hazard Barrier */}
      <HazardBarrier active={showHazard} />

    </group>
  );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function RationalNumberSimulation() {
  const [step, setStep] = useState(0);
  const [sliderN, setSliderN] = useState(6);
  const [sliderD, setSliderD] = useState(-8);

  // Step Overrides
  const n = step < 3 ? 6 : step === 3 ? 5 : sliderN;
  const d = step < 3 ? 8 : step === 3 ? 0 : sliderD;
  const isZero = d === 0;
  
  // Math for UI
  let displayDivisor = getGCD(n, d);
  if (d < 0 && n !== 0) displayDivisor = -displayDivisor;
  if (n === 0 && !isZero) displayDivisor = d;

  const finalN = isZero ? n : n / displayDivisor;
  const finalD = isZero ? d : d / displayDivisor;

  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`\\frac{6}{8}`} />;
    if (step === 1) return <BlockMath math={`\\text{GCD}(6, 8) = 2`} />;
    if (step === 2) return <BlockMath math={`\\frac{6 \\div 2}{8 \\div 2} = \\frac{3}{4}`} />;
    if (step === 3) return <BlockMath math={`\\frac{5}{0} \\notin \\mathbb{Q}`} />;
    if (step === 4) {
       if (isZero) return <BlockMath math={`\\frac{${n}}{0} \\notin \\mathbb{Q}`} />;
       return <BlockMath math={`\\frac{${n}}{${d}} = \\frac{${finalN}}{${finalD}}`} />;
    }
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Rational Form", icon: <MoveRight size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>A rational number ($\mathbb{Q}$) is written as a ratio of integers: <span className="font-mono">a/b</span>. Let's start with <span className="font-mono text-blue-400">6/8</span>.</> };
    if (step === 1) return { title: "Factor Scanner", icon: <Search size={18}/>, color: "border-l-yellow-500 text-yellow-300 bg-yellow-900/40", body: <>To reach <b>Standard Form</b>, we must simplify. The scanner detects the Greatest Common Divisor (GCD) for 6 and 8 is <span className="font-mono text-yellow-400">2</span>.</> };
    if (step === 2) return { title: "Simplification Pipeline", icon: <ShieldCheck size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>Dividing both terms by 2 yields <span className="font-mono text-emerald-400">3/4</span>. Standard form also requires the denominator to be strictly positive, and we've met all conditions.</> };
    if (step === 3) return { title: "The Zero Denominator Hazard", icon: <AlertTriangle size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> What if <span className="font-mono">b = 0</span>? Division by zero is mathematically impossible. This fraction is completely <b>Undefined</b> and does <b>NOT</b> exist in Rational Numbers ($\notin \mathbb{Q}$).</> };
    if (step === 4) return { title: "Interactive Simplifier", icon: <SlidersHorizontal size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>Use the sliders to create your own rational numbers. Watch the scanner automatically extract GCDs, correct negative denominators, and trigger hazards for division by zero.</> };
    return { title: "", icon: null, color: "", body: <></> };
  }

  const alertContent = getAlertContent();

  return (
    <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
      
      {/* Header Overlay */}
      <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
        <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
          <div>
            <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
              Rational Numbers ($\mathbb{Q}$)
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Standard forms, GCD extraction, and the $b \neq 0$ condition.
            </p>
          </div>
          <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[340px] flex justify-center shadow-inner">
             <div className="text-white text-lg">
               {renderMath()}
             </div>
          </div>
        </div>
      </div>
      
      {/* R3F Canvas */}
      <div className="flex-1 w-full h-full relative z-0 touch-none">
         <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
           <Canvas shadows camera={{ position: [0, 0, 12], fov: 45 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <Scene n={n} d={d} step={step} />
              
           </Canvas>
         </Suspense>
      </div>

      {/* Controls Overlay */}
      <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
        
        <div className={`flex-1 pointer-events-auto max-w-md w-full transition-all duration-500 origin-bottom-left ${step === 0 ? 'scale-100 opacity-100' : 'scale-105 opacity-100'}`}>
           <div className={`border-l-4 border-t border-r border-b border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md bg-slate-900/90 ${alertContent.color}`}>
             <h3 className="font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide">
                {alertContent.icon} {alertContent.title}
             </h3>
             <p className="text-sm leading-relaxed drop-shadow-md text-slate-300">
               {alertContent.body}
             </p>
           </div>
        </div>

        <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
           
           {/* Steps Map */}
           <div className="flex flex-col gap-2 mb-4">
              <div className="flex justify-between text-[10px] font-bold uppercase tracking-wider text-slate-400 px-1">
                 <span>Input</span>
                 <span className="text-yellow-400">Scan</span>
                 <span className="text-emerald-400">Standard</span>
                 <span className="text-rose-400">Hazard</span>
                 <span className="text-purple-400">Sandbox</span>
              </div>
              <div className="flex gap-1">
                 {[0, 1, 2, 3, 4].map(s => (
                    <div 
                       key={s} 
                       className={`h-2 flex-1 rounded-full transition-all duration-500 ${
                          s === step ? 'bg-white shadow-[0_0_10px_rgba(255,255,255,0.8)] scale-y-125' : 
                          s < step ? 'bg-slate-500' : 'bg-slate-800'
                       }`} 
                    />
                 ))}
              </div>
           </div>

           {/* Interactive Sliders (Only visible in Sandbox) */}
           {step === 4 ? (
             <div className="flex flex-col gap-4 mb-4">
               <div>
                  <label className="text-xs font-bold text-slate-400 flex justify-between">
                    <span>Numerator (a)</span>
                    <span className="text-blue-400 font-mono">{sliderN}</span>
                  </label>
                  <input 
                     type="range" min="-10" max="10" step="1" 
                     value={sliderN} onChange={(e) => setSliderN(parseInt(e.target.value))}
                     className="w-full accent-blue-500 mt-2"
                  />
               </div>
               <div>
                  <label className="text-xs font-bold text-slate-400 flex justify-between">
                    <span>Denominator (b)</span>
                    <span className={sliderD === 0 ? "text-rose-400 font-mono" : "text-purple-400 font-mono"}>{sliderD}</span>
                  </label>
                  <input 
                     type="range" min="-10" max="10" step="1" 
                     value={sliderD} onChange={(e) => setSliderD(parseInt(e.target.value))}
                     className={`w-full mt-2 ${sliderD === 0 ? 'accent-rose-500' : 'accent-purple-500'}`}
                  />
               </div>
             </div>
           ) : null}

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3">
             <button 
                onClick={handleNextStep} 
                disabled={step === 4} 
                className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm ${step === 4 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
             >
                {step === 0 ? "Scan Factors" : 
                 step === 1 ? "Simplify" : 
                 step === 2 ? "Test b = 0" : 
                 step === 3 ? "Unlock Sandbox" : 
                 "Freestyle Mode"} 
                {step < 4 && <MoveRight size={16} />}
             </button>
             <button 
                onClick={() => {
                   setStep(0);
                   setSliderN(6);
                   setSliderD(-8);
                }} 
                className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
             >
                <RotateCcw size={16} /> Reset
             </button>
           </div>
           
        </div>
      </div>
    </div>
  );
}
