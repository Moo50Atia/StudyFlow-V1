import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { OrbitControls, Environment, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { AlertCircle, Tag, PlusSquare, Zap, CheckCircle2, RotateCcw, MoveRight } from 'lucide-react';

interface ProcessProps {
  step: number;
  method: 'two-step' | 'direct';
  baseBill: number;
  taxRate: number;
}

const format = (n: number) => {
  return Number.isInteger(n) 
    ? n.toLocaleString() 
    : n.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
};

function ProcessVisualizer({ step, method, baseBill, taxRate }: ProcessProps) {
  const taxValue = baseBill * (taxRate / 100);
  const totalBill = baseBill + taxValue;
  const multiplier = 100 + taxRate;

  // Visual Scales
  const maxVisualHeight = 8;
  const maxPossibleTotal = 1000 * 1.30; // Max base 1000, max tax 30%
  const scaleFactor = maxVisualHeight / maxPossibleTotal;
  
  const baseHeight = baseBill * scaleFactor;
  const taxHeight = taxValue * scaleFactor;
  
  const originY = -3;

  // Refs
  const baseMeshRef = useRef<THREE.Mesh>(null);
  const taxMeshRef = useRef<THREE.Mesh>(null);
  const beamRef = useRef<THREE.Group>(null);
  const totalBadgeRef = useRef<THREE.Group>(null);

  // Geometry anchored at the bottom (Y=0 -> bottom, Y=1 -> top before scaling)
  const boxGeo = useMemo(() => {
    const geo = new THREE.BoxGeometry(2.5, 1, 0.8);
    geo.translate(0, 0.5, 0); 
    return geo;
  }, []);

  useEffect(() => {
    // 1. Base Block
    if (baseMeshRef.current) {
        gsap.to(baseMeshRef.current.position, { x: 0, y: originY, z: 0, duration: 0.8 });
        gsap.to(baseMeshRef.current.scale, { x: 1, y: baseHeight, z: 1, duration: 0.8 });
    }

    // 2. Tax Block
    if (taxMeshRef.current) {
      if (method === 'two-step') {
        if (step === 0) {
           gsap.to(taxMeshRef.current.position, { x: 3.5, y: originY, z: 0, duration: 0.8 });
           gsap.to(taxMeshRef.current.scale, { x: 1, y: 0, z: 1, duration: 0.5 });
        } else if (step === 1) {
           gsap.to(taxMeshRef.current.position, { x: 3.5, y: originY, z: 0, duration: 0.8 });
           gsap.to(taxMeshRef.current.scale, { x: 1, y: taxHeight, z: 1, duration: 0.8, ease: 'back.out(1.2)' });
        } else if (step === 2) {
           gsap.to(taxMeshRef.current.position, { x: 0, y: originY + baseHeight, z: 0, duration: 0.8, ease: 'power2.inOut' });
           gsap.to(taxMeshRef.current.scale, { x: 1, y: taxHeight, z: 1, duration: 0.8 });
        }
      } else {
        // Direct Method
        gsap.set(taxMeshRef.current.position, { x: 0, z: 0 }); 
        if (step === 0 || step === 1) {
           gsap.to(taxMeshRef.current.position, { y: originY + baseHeight, duration: 0.4 });
           gsap.to(taxMeshRef.current.scale, { x: 1, y: 0, z: 1, duration: 0.4 });
        } else if (step === 2) {
           gsap.to(taxMeshRef.current.position, { y: originY + baseHeight, duration: 0.4 });
           gsap.to(taxMeshRef.current.scale, { x: 1, y: taxHeight, z: 1, duration: 1.2, ease: 'power2.out' });
        }
      }
    }

    // 3. Multiplier Beam
    if (beamRef.current) {
      if (method === 'direct') {
        if (step === 0) {
           gsap.to(beamRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
           gsap.to(beamRef.current.position, { y: originY, duration: 0.3 });
        } else if (step === 1) {
           gsap.to(beamRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: 'back.out(1.5)' });
           gsap.to(beamRef.current.position, { y: originY, duration: 0.5 });
        } else if (step === 2) {
           gsap.to(beamRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.2 });
           gsap.to(beamRef.current.position, { y: originY + baseHeight + taxHeight, duration: 1.2, ease: 'power2.out' });
           gsap.to(beamRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.4, delay: 1.2 }); 
        }
      } else {
         gsap.to(beamRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.2 });
      }
    }

    // 4. Total Badge
    if (totalBadgeRef.current) {
       gsap.to(totalBadgeRef.current.position, { y: originY + baseHeight + taxHeight, duration: 0.8 });
    }

  }, [step, method, baseHeight, taxHeight]);

  return (
    <group>
      {/* Base Bill */}
      <mesh ref={baseMeshRef} geometry={boxGeo} castShadow receiveShadow>
        <meshStandardMaterial color="#475569" roughness={0.4} metalness={0.1} />
        <Html position={[0, 0.5, 0.41]} center className="pointer-events-none">
           <div className="flex flex-col items-center">
              <span className="text-[10px] uppercase font-bold text-slate-400">Base Bill</span>
              <span className="font-mono font-bold text-white whitespace-nowrap">{format(baseBill)} LE</span>
           </div>
        </Html>
      </mesh>

      {/* Tax Block */}
      <mesh ref={taxMeshRef} geometry={boxGeo} castShadow receiveShadow>
        <meshStandardMaterial color="#e11d48" roughness={0.3} emissive={step === 2 ? "#e11d48" : "#000000"} emissiveIntensity={step === 2 ? 0.3 : 0} />
        <Html position={method === 'two-step' && step === 1 ? [0, 0.5, 0.41] : [1.6, 0.5, 0]} center className="pointer-events-none">
           <div className={`transition-opacity duration-300 ${step > 0 ? 'opacity-100' : 'opacity-0'}`}>
              <div className="bg-rose-950/90 border border-rose-500 text-rose-200 px-3 py-1.5 rounded-lg shadow-xl whitespace-nowrap backdrop-blur-md flex flex-col items-center">
                 <span className="font-bold text-[10px] uppercase tracking-wider text-rose-300">Tax ({taxRate}%)</span>
                 <span className="font-mono text-base font-bold">+{format(taxValue)} LE</span>
              </div>
           </div>
        </Html>
      </mesh>

      {/* Direct Multiplier Beam */}
      <group ref={beamRef} scale={[0,0,0]} position={[0, originY, 0]}>
        <mesh>
           <boxGeometry args={[3.2, 0.08, 1.2]} />
           <meshStandardMaterial color="#fbbf24" emissive="#f59e0b" emissiveIntensity={2} transparent opacity={0.8} />
        </mesh>
        <Html position={[2.5, 0, 0]} center className="pointer-events-none">
           <div className="bg-amber-900/90 border border-amber-500 px-3 py-1.5 rounded-lg shadow-xl text-amber-100 whitespace-nowrap backdrop-blur-md flex items-center gap-2">
              <Zap size={14} className="text-amber-400" />
              <span className="font-bold text-sm">× {multiplier}% Multiplier</span>
           </div>
        </Html>
      </group>

      {/* Total Badge */}
      <group ref={totalBadgeRef}>
         <Html position={[0, 1.2, 0]} center className="pointer-events-none">
            <div className={`transition-all duration-500 origin-bottom ${step === 2 ? 'opacity-100 scale-100 translate-y-0' : 'opacity-0 scale-50 translate-y-4'}`}>
               <div className="bg-emerald-500/90 border-2 border-emerald-300 px-6 py-3 rounded-2xl shadow-[0_0_30px_rgba(16,185,129,0.6)] backdrop-blur-md flex flex-col items-center">
                  <span className="text-[10px] font-bold uppercase tracking-widest text-emerald-100 mb-0.5">Total Payable</span>
                  <span className="text-3xl font-black font-mono text-white whitespace-nowrap">{format(totalBill)} LE</span>
               </div>
            </div>
         </Html>
      </group>

    </group>
  );
}

export default function TaxAccumulationSimulation() {
  const [method, setMethod] = useState<'two-step' | 'direct'>('two-step');
  const [step, setStep] = useState(0);
  
  const [baseBill, setBaseBill] = useState(540);
  const [taxRate, setTaxRate] = useState(14);

  const taxValue = baseBill * (taxRate / 100);
  const totalBill = baseBill + taxValue;
  const multiplier = 100 + taxRate;
  const multiplierDec = (1 + taxRate / 100).toFixed(2);

  const handleNextStep = () => {
    if (step < 2) setStep(s => s + 1);
  };

  const renderMath = () => {
    if (method === 'two-step') {
       if (step === 0) return <BlockMath math={`\\text{Base Bill} = ${format(baseBill)}`} />;
       if (step === 1) return <BlockMath math={`\\text{Tax} = ${format(baseBill)} \\times ${taxRate}\\% = ${format(taxValue)}`} />;
       if (step === 2) return <BlockMath math={`\\text{Total} = ${format(baseBill)} + ${format(taxValue)} = ${format(totalBill)}`} />;
    } else {
       if (step === 0) return <BlockMath math={`\\text{Base Bill} = ${format(baseBill)} \\ (100\\%)`} />;
       if (step === 1) return <BlockMath math={`\\text{Multiplier} = 100\\% + ${taxRate}\\% = ${multiplier}\\%`} />;
       if (step === 2) return <BlockMath math={`\\text{Total} = ${format(baseBill)} \\times ${multiplierDec} = ${format(totalBill)}`} />;
    }
  };

  const getAlertContent = () => {
    if (method === 'two-step') {
       if (step === 0) return { title: "The 100% Foundation", icon: <Tag size={18}/>, color: "border-l-slate-500 text-slate-300 bg-slate-900/50", body: <>The base bill of <span className="font-bold text-white">{format(baseBill)} LE</span> represents 100% of the initial cost before any external fees are attached.</> };
       if (step === 1) return { title: "Tax Addition", icon: <PlusSquare size={18}/>, color: "border-l-rose-500 text-rose-400 bg-rose-900/10", body: <><b>CRITICAL MISCONCEPTION:</b> A tax rate is an ADDITION, not a discount! We calculate {taxRate}% of the base bill to find the <span className="font-bold text-rose-300">+{format(taxValue)} LE</span> surcharge.</> };
       if (step === 2) return { title: "Combined Total", icon: <CheckCircle2 size={18}/>, color: "border-l-emerald-500 text-emerald-400 bg-emerald-900/10", body: <>The tax block is stacked strictly ON TOP of the base bill. The final total you must pay is always strictly greater than the original cost: <span className="font-bold text-emerald-300">{format(totalBill)} LE</span>.</> };
    } else {
       if (step === 0) return { title: "The 100% Foundation", icon: <Tag size={18}/>, color: "border-l-slate-500 text-slate-300 bg-slate-900/50", body: <>The base bill is our 100% foundation: <span className="font-bold text-white">{format(baseBill)} LE</span>.</> };
       if (step === 1) return { title: "The Direct Multiplier", icon: <Zap size={18}/>, color: "border-l-amber-500 text-amber-400 bg-amber-900/10", body: <>Instead of calculating tax and adding it later, we can combine the original (100%) and the tax ({taxRate}%) into a single direct mathematical multiplier of <span className="font-bold text-amber-300">{multiplier}%</span> (or {multiplierDec}).</> };
       if (step === 2) return { title: "Instant Scale", icon: <CheckCircle2 size={18}/>, color: "border-l-emerald-500 text-emerald-400 bg-emerald-900/10", body: <>By multiplying the base bill directly by {multiplierDec}, the bar instantly scales up to incorporate the tax, arriving at the final payable total of <span className="font-bold text-emerald-300">{format(totalBill)} LE</span>.</> };
    }
    return { title: "", icon: null, color: "", body: <></> };
  }

  const alertContent = getAlertContent();

  return (
    <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
      
      {/* Header Overlay */}
      <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
        <div className="bg-slate-900/80 backdrop-blur-md border border-slate-700 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
          <div>
            <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
              Tax Accumulation & Multipliers
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Correcting the misconception of tax reduction vs. accumulation.
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
           <Canvas shadows camera={{ position: [0, 1, 14], fov: 50 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[10, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <ProcessVisualizer 
                 step={step} 
                 method={method}
                 baseBill={baseBill}
                 taxRate={taxRate}
              />
              
              <OrbitControls 
                enablePan={false} 
                enableZoom={false}
                maxPolarAngle={Math.PI / 2 + 0.1}
                minPolarAngle={Math.PI / 2 - 0.3}
                maxAzimuthAngle={0.4}
                minAzimuthAngle={-0.4}
              />
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
             <p className="text-slate-300 text-sm leading-relaxed">
               {alertContent.body}
             </p>
           </div>
        </div>

        <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[480px]">
           
           {/* Method Toggle */}
           <div className="flex bg-slate-950 p-1.5 rounded-xl border border-slate-800 mb-4 shadow-inner">
              <button 
                onClick={() => { setMethod('two-step'); setStep(0); }}
                className={`flex-1 py-2 text-xs font-bold uppercase tracking-wider rounded-lg transition-all ${method === 'two-step' ? 'bg-blue-600 text-white shadow-md' : 'text-slate-400 hover:text-white'}`}
              >
                Two-Step Addition
              </button>
              <button 
                onClick={() => { setMethod('direct'); setStep(0); }}
                className={`flex-1 py-2 text-xs font-bold uppercase tracking-wider rounded-lg transition-all ${method === 'direct' ? 'bg-amber-600 text-white shadow-md' : 'text-slate-400 hover:text-white'}`}
              >
                Direct Multiplier
              </button>
           </div>

           {/* Progress Indicator */}
           <div className="flex gap-1.5 mb-4">
             {[0, 1, 2].map(s => (
                <div key={s} className={`h-1.5 flex-1 rounded-full transition-colors duration-500 ${s <= step ? (method === 'two-step' ? 'bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.6)]' : 'bg-amber-500 shadow-[0_0_8px_rgba(245,158,11,0.6)]') : 'bg-slate-700'}`} />
             ))}
           </div>

           {/* Sliders */}
           <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-2 border border-slate-700 bg-slate-800/50 p-3 rounded-xl">
                 <div className="flex justify-between items-center text-slate-300">
                   <label className="text-[10px] font-bold uppercase tracking-wider">Base Bill</label>
                   <span className="font-mono font-bold text-sm bg-slate-900 px-2 py-1 rounded border border-slate-700">{format(baseBill)}</span>
                 </div>
                 <input 
                   type="range" min="200" max="1000" step="20" value={baseBill} 
                   onChange={e => {setBaseBill(Number(e.target.value)); setStep(0);}} 
                   className="w-full accent-blue-500 h-2 mt-1 bg-slate-900 rounded-lg appearance-none cursor-pointer" 
                 />
              </div>

              <div className="flex flex-col gap-2 border border-rose-900/50 bg-rose-950/30 p-3 rounded-xl">
                 <div className="flex justify-between items-center text-rose-300">
                   <label className="text-[10px] font-bold uppercase tracking-wider">Tax Rate</label>
                   <span className="font-mono font-bold text-sm bg-slate-900 px-2 py-1 rounded border border-rose-900/50">+{taxRate}%</span>
                 </div>
                 <input 
                   type="range" min="5" max="30" step="1" value={taxRate} 
                   onChange={e => {setTaxRate(Number(e.target.value)); setStep(0);}} 
                   className="w-full accent-rose-500 h-2 mt-1 bg-slate-900 rounded-lg appearance-none cursor-pointer" 
                 />
              </div>
           </div>

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-4">
             <button 
                onClick={handleNextStep} 
                disabled={step === 2} 
                className={`text-white font-bold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-lg text-sm ${method === 'two-step' ? 'bg-blue-600 hover:bg-blue-500 shadow-blue-900/20' : 'bg-amber-600 hover:bg-amber-500 shadow-amber-900/20'}`}
             >
                {step === 0 ? (method === 'two-step' ? "Calculate Tax" : "Activate Beam") : step === 2 ? "Verified" : (method === 'two-step' ? "Stack Total" : "Scale Up")} 
                {step < 2 && <MoveRight size={16} />}
             </button>
             <button 
                onClick={() => setStep(0)} 
                className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
             >
                <RotateCcw size={16} /> Reset
             </button>
           </div>
           
        </div>
      </div>
    </div>
  );
}
