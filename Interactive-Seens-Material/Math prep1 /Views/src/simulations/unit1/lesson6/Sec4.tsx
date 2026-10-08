import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, ShieldAlert, Split, AlertTriangle, ArrowDownUp, CheckCircle2 } from 'lucide-react';

interface ProcessProps {
  step: number;
}

// ---------------------------------------------------------
// REUSABLE 3D COMPONENTS
// ---------------------------------------------------------
function getColor(val: number) {
  if (val < 0) return "#ef4444"; // red
  if (val > 0) return "#3b82f6"; // blue
  return "#fbbf24"; // yellow (zero)
}

function Conduit({ 
  xPos, num, den, status, resultText, playState 
}: { 
  xPos: number, num: number, den: number, status: 'success' | 'filter' | 'hazard', resultText: string, playState: number 
}) {
  const numRef = useRef<THREE.Group>(null);
  const denRef = useRef<THREE.Group>(null);
  const procRef = useRef<THREE.Mesh>(null);
  const outRef = useRef<THREE.Group>(null);
  const filterRef = useRef<THREE.Mesh>(null);
  const hazardRef = useRef<THREE.Group>(null);
  const tlRef = useRef<gsap.core.Timeline>();

  useEffect(() => {
    if (playState === 0) return;
    
    if (tlRef.current) {
       tlRef.current.kill();
    }
    
    const tl = gsap.timeline();
    tlRef.current = tl;

    // 1. Reset everything
    tl.set(numRef.current!.position, { y: 4 });
    tl.set(denRef.current!.position, { y: 2.5 });
    tl.set(numRef.current!.scale, { x: 1, y: 1, z: 1 });
    tl.set(denRef.current!.scale, { x: 1, y: 1, z: 1 });
    
    tl.set(outRef.current!.scale, { x: 0, y: 0, z: 0 });
    tl.set(outRef.current!.position, { y: 1 });
    
    tl.set(filterRef.current!.material, { opacity: 0 });
    tl.set(hazardRef.current!.scale, { x: 0, y: 0, z: 0 });
    if (procRef.current) {
        tl.set(procRef.current.material, { color: "#475569" }); // Reset processor color
    }

    // 2. Drop into processor
    tl.to(numRef.current!.position, { y: 1.5, duration: 0.6, ease: "bounce.out" });
    tl.to(denRef.current!.position, { y: 0.5, duration: 0.6, ease: "bounce.out" }, "<0.1");

    // 3. Process
    tl.to(numRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 }, "+=0.3");
    tl.to(denRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 }, "<");
    if (procRef.current) {
        tl.to(procRef.current.rotation, { z: Math.PI, duration: 0.5 }, "<");
    }

    // 4. Output based on status
    if (status === 'success') {
       if (procRef.current) tl.to(procRef.current.material, { color: "#10b981", duration: 0.2 }, "+=0.1");
       tl.to(outRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.3 });
       tl.to(outRef.current!.position, { y: -2.5, duration: 0.6, ease: "bounce.out" });
    } 
    else if (status === 'filter') {
       if (procRef.current) tl.to(procRef.current.material, { color: "#8b5cf6", duration: 0.2 }, "+=0.1");
       tl.to(outRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.3 });
       tl.to(outRef.current!.position, { y: -0.8, duration: 0.4, ease: "power2.in" });
       tl.to(filterRef.current!.material, { opacity: 0.8, duration: 0.1 }, "<0.2");
       tl.to(outRef.current!.position, { y: -0.6, duration: 0.3, ease: "power1.out" }); // Bounce up slightly
    } 
    else if (status === 'hazard') {
       if (procRef.current) tl.to(procRef.current.material, { color: "#ef4444", duration: 0.1, yoyo: true, repeat: 5 }, "+=0.1");
       tl.to(hazardRef.current!.scale, { x: 1.2, y: 1.2, z: 1.2, duration: 0.6, ease: "elastic.out(1, 0.4)" }, "+=0.2");
    }

  }, [playState, num, den, status]);

  return (
    <group position={[xPos, 0, 0]}>
      {/* Background Track */}
      <mesh position={[0, 1, -0.5]}>
         <boxGeometry args={[2.5, 9, 0.2]} />
         <meshStandardMaterial color="#0f172a" transparent opacity={0.6} />
      </mesh>
      
      {/* Processor Box */}
      <mesh ref={procRef} position={[0, 1, 0]} castShadow>
         <boxGeometry args={[2, 2, 0.8]} />
         <meshStandardMaterial color="#475569" roughness={0.2} metalness={0.5} />
         <Text position={[0, 0, 0.41]} fontSize={1.2} color="#ffffff" outlineWidth={0.04} outlineColor="#000">÷</Text>
      </mesh>

      {/* Numerator Block */}
      <group ref={numRef} position={[0, 4, 0]}>
         <mesh castShadow>
           <boxGeometry args={[1.5, 1, 0.6]} />
           <meshStandardMaterial color={getColor(num)} roughness={0.3} />
         </mesh>
         <Text position={[0, 0, 0.31]} fontSize={0.6} color="#ffffff" outlineWidth={0.03} outlineColor="#000">{num}</Text>
      </group>

      {/* Denominator Block */}
      <group ref={denRef} position={[0, 2.5, 0]}>
         <mesh castShadow>
           <boxGeometry args={[1.5, 1, 0.6]} />
           <meshStandardMaterial color={getColor(den)} roughness={0.3} />
         </mesh>
         <Text position={[0, 0, 0.31]} fontSize={0.6} color="#ffffff" outlineWidth={0.03} outlineColor="#000">{den}</Text>
      </group>

      {/* Fractional Filter Barrier */}
      <mesh ref={filterRef} position={[0, -1.2, 0]}>
         <boxGeometry args={[2.4, 0.1, 1.2]} />
         <meshStandardMaterial color="#06b6d4" transparent opacity={0} emissive="#06b6d4" emissiveIntensity={0.5} />
         <Text position={[0, 0, 0.61]} fontSize={0.3} color="#22d3ee" outlineWidth={0.02} outlineColor="#000">∉ ℤ (Not an Integer)</Text>
      </mesh>

      {/* Result Output Block */}
      <group ref={outRef} position={[0, 1, 0]} scale={[0,0,0]}>
         <mesh castShadow>
           <boxGeometry args={[1.8, 1.2, 0.6]} />
           <meshStandardMaterial color={status === 'success' ? "#10b981" : "#8b5cf6"} roughness={0.3} />
         </mesh>
         <Text position={[0, 0, 0.31]} fontSize={0.6} color="#ffffff" outlineWidth={0.03} outlineColor="#000">{resultText}</Text>
      </group>

      {/* Hazard Output Sign */}
      <group ref={hazardRef} position={[0, 1, 0.5]} scale={[0,0,0]}>
         <mesh rotation={[Math.PI / 2, 0, 0]} castShadow>
           <cylinderGeometry args={[1.5, 1.5, 0.2, 8]} />
           <meshStandardMaterial color="#e11d48" roughness={0.3} emissive="#e11d48" emissiveIntensity={0.3} />
         </mesh>
         <Text position={[0, 0, 0.11]} fontSize={0.4} color="#ffffff" outlineWidth={0.04} outlineColor="#000">UNDEFINED</Text>
         <Text position={[0, -0.4, 0.11]} fontSize={0.25} color="#fecdd3">Division by 0</Text>
      </group>
    </group>
  );
}

// ---------------------------------------------------------
// MAIN SCENE CONTROLLER
// ---------------------------------------------------------
function Scene({ step, isZeroNumerator, plays }: { step: number, isZeroNumerator: boolean, plays: number[] }) {
  const { camera } = useThree();
  const controlsRef = useRef<any>(null);

  useEffect(() => {
    const targets = [
      { camX: -7, camY: 1, camZ: 10, tarX: -7, tarY: 1 },    // 0: Conduit 1
      { camX: 0,  camY: 1, camZ: 10, tarX: 0,  tarY: 1 },    // 1: Conduit 2
      { camX: 7,  camY: 1, camZ: 10, tarX: 7,  tarY: 1 },    // 2: Conduit 3 (Hazard)
      { camX: 7,  camY: 1, camZ: 10, tarX: 7,  tarY: 1 },    // 3: Conduit 3 (Toggle)
      { camX: 0,  camY: 2, camZ: 18, tarX: 0,  tarY: 0 },    // 4: Final Comparison
    ];

    const t = targets[step];

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
        maxPolarAngle={Math.PI / 2 + 0.1}
        minPolarAngle={Math.PI / 2 - 0.5}
        maxAzimuthAngle={0.3}
        minAzimuthAngle={-0.3}
      />
      
      {/* Conduit 1: Sign Cancellation */}
      <Conduit xPos={-7} num={-20} den={-4} status="success" resultText="+5" playState={plays[0]} />
      
      {/* Conduit 2: Fractional Filter */}
      <Conduit xPos={0} num={5} den={2} status="filter" resultText="5/2" playState={plays[1]} />
      
      {/* Conduit 3: The Zero Toggle */}
      <Conduit 
         xPos={7} 
         num={isZeroNumerator ? 0 : 8} 
         den={isZeroNumerator ? 8 : 0} 
         status={isZeroNumerator ? 'success' : 'hazard'} 
         resultText="0" 
         playState={plays[2]} 
      />

      {/* Floor */}
      <mesh position={[0, -3, 0]} receiveShadow>
         <boxGeometry args={[30, 0.2, 8]} />
         <meshStandardMaterial color="#1e293b" />
      </mesh>
    </group>
  );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function DivisionSimulation() {
  const [step, setStep] = useState(0);
  const [isZeroNumerator, setIsZeroNumerator] = useState(false);
  const [plays, setPlays] = useState([0, 0, 0]);

  useEffect(() => {
    // Force reset of toggle if we go backward
    if (step < 3 && isZeroNumerator) {
        setIsZeroNumerator(false);
    }
    
    // Trigger animation for current step
    setPlays(prev => {
        const next = [...prev];
        if (step === 0 && next[0] === 0) next[0]++;
        else if (step === 1 && next[1] === 0) next[1]++;
        else if (step === 2 && next[2] === 0) next[2]++;
        return next;
    });
  }, [step]);

  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const toggleZero = () => {
    setIsZeroNumerator(!isZeroNumerator);
    setPlays(prev => {
        const next = [...prev];
        next[2]++; // Re-trigger Conduit 3
        return next;
    });
  };

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`\\frac{-20}{-4} = 5`} />;
    if (step === 1) return <BlockMath math={`\\frac{5}{2} \\notin \\mathbb{Z}`} />;
    if (step === 2) return <BlockMath math={`\\frac{8}{0} = \\text{Undefined}`} />;
    if (step === 3) {
        if (isZeroNumerator) return <BlockMath math={`\\frac{0}{8} = 0`} />;
        return <BlockMath math={`\\frac{8}{0} = \\text{Undefined}`} />;
    }
    if (step === 4) return <BlockMath math={`\\mathbb{Z} \\text{ Division Rules}`} />;
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Sign Cancellation", icon: <MoveRight size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>When dividing two negative integers (<span className="font-mono">-20</span> and <span className="font-mono">-4</span>), the signs cancel each other perfectly, resulting in a strictly positive integer (<span className="font-mono text-blue-400">+5</span>).</> };
    if (step === 1) return { title: "The Fractional Filter", icon: <Split size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>Integers ($\mathbb{Z}$) do not contain fractions. When <span className="font-mono">5</span> is divided by <span className="font-mono">2</span>, it leaves a remainder. The output <span className="font-mono">5/2</span> hits the barrier because it is <b>not an integer</b>.</> };
    if (step === 2) return { title: "Division by Zero", icon: <AlertTriangle size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> Dividing a number by zero does <b>NOT</b> equal zero! It is mathematically impossible. The machine triggers a critical hazard alert: the result is completely <b>Undefined</b>.</> };
    if (step === 3) return { title: "Zero as Numerator", icon: <ArrowDownUp size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>Click the <b>Toggle Values</b> button below. When Zero is the numerator (<span className="font-mono">0 / 8</span>), the math is completely valid. It safely outputs a <span className="font-mono text-emerald-400">0</span>.</> };
    if (step === 4) return { title: "Comparison View", icon: <CheckCircle2 size={18}/>, color: "border-l-slate-500 text-slate-300 bg-slate-900/40", body: <>Observe the structural contrast. A valid integer output drops freely. A fraction is caught by the filter. Division by zero causes a critical error. The order of zero matters!</> };
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
              Integer Division Sorter
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Fractions, Signs, and the Zero Hazard.
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
           <Canvas shadows camera={{ position: [-7, 1, 10], fov: 45 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <Scene step={step} isZeroNumerator={isZeroNumerator} plays={plays} />
              
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
                 <span className="text-blue-400">Signs</span>
                 <span className="text-purple-400">Fraction</span>
                 <span className="text-rose-400">/0 Hazard</span>
                 <span className="text-emerald-400">0/ Numerator</span>
                 <span className="text-slate-400">Compare</span>
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

           {/* Toggle (Only visible in Step 3/4) */}
           {step === 3 || step === 4 ? (
             <div className="flex flex-col gap-2 mb-4">
               <button
                  onClick={toggleZero}
                  className="w-full bg-slate-800 hover:bg-slate-700 border border-slate-600 text-white font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-md"
               >
                  <ArrowDownUp size={16} className={isZeroNumerator ? "text-emerald-400" : "text-rose-400"} />
                  {isZeroNumerator ? "Swapped: (0 / 8)" : "Swapped: (8 / 0)"}
               </button>
             </div>
           ) : null}

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3">
             <button 
                onClick={handleNextStep} 
                disabled={step === 4} 
                className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm ${step === 4 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
             >
                {step === 0 ? "Test Fraction" : 
                 step === 1 ? "Test Div by 0" : 
                 step === 2 ? "Unlock Toggle" : 
                 step === 3 ? "View Comparison" : 
                 "Completed"} 
                {step < 4 && <MoveRight size={16} />}
             </button>
             <button 
                onClick={() => {
                   setStep(0);
                   setIsZeroNumerator(false);
                   setPlays([1, 0, 0]); // Reset to play first only
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
