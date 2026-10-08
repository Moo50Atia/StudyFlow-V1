import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { AlertCircle, Tag, SplitSquareHorizontal, ArrowDownCircle, CheckCircle2, RotateCcw, MoveRight } from 'lucide-react';

interface ProcessProps {
  step: number;
  basePrice: number;
  discountRate: number;
}

const format = (n: number) => n.toLocaleString();

function ProcessVisualizer({ step, basePrice, discountRate }: ProcessProps) {
  const discountValue = basePrice * (discountRate / 100);
  const netPayment = basePrice - discountValue;
  const netRate = 100 - discountRate;
  
  const baseScaleY = 5;
  const discScaleY = baseScaleY * (discountRate / 100);
  const netScaleY = baseScaleY * (netRate / 100);

  const baseGrp = useRef<THREE.Group>(null);
  const portalGrp = useRef<THREE.Group>(null);
  const discGrp = useRef<THREE.Group>(null);
  const netGrp = useRef<THREE.Group>(null);
  const summaryGrp = useRef<THREE.Group>(null);

  useEffect(() => {
    // X Positions
    const xStart = -8;
    const xPortal = -2;
    const xSplit = 2;
    const xSummary = 7;

    // Y Positions
    const yTop = 2.5;
    const yBottom = -2.5;

    // 0. Base Price Block
    if (baseGrp.current) {
       if (step === 0) {
         gsap.to(baseGrp.current.position, { x: xStart, duration: 0.8, ease: 'power2.out' });
         gsap.to(baseGrp.current.scale, { x: 1, y: 1, z: 1, duration: 0.8 });
       } else if (step === 1) {
         gsap.to(baseGrp.current.position, { x: xPortal, duration: 1, ease: 'power2.inOut' });
         gsap.to(baseGrp.current.scale, { x: 1, y: 1, z: 1, duration: 0.8 });
       } else {
         gsap.to(baseGrp.current.scale, { x: 0, y: 0, z: 0, duration: 0.5 });
       }
    }

    // 1. Portal Ring
    if (portalGrp.current) {
       if (step >= 1 && step <= 2) {
          gsap.to(portalGrp.current.scale, { x: 1, y: 1, z: 1, duration: 0.6, ease: 'back.out(1.5)' });
       } else {
          gsap.to(portalGrp.current.scale, { x: 0, y: 0, z: 0, duration: 0.4 });
       }
    }

    // 2. Splitting Streams (Discount & Net Payment)
    if (discGrp.current && netGrp.current) {
       if (step < 2) {
          gsap.to(discGrp.current.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
          gsap.set(discGrp.current.position, { x: xPortal, y: 0 });
          
          gsap.to(netGrp.current.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
          gsap.set(netGrp.current.position, { x: xPortal, y: 0 });
       } else if (step === 2 || step === 3) {
          // Emerge from portal and split
          gsap.to(discGrp.current.scale, { x: 1, y: 1, z: 1, duration: 0.8 });
          gsap.to(discGrp.current.position, { x: xSplit, y: yTop, z: 0, duration: 1, ease: 'power2.out' });

          gsap.to(netGrp.current.scale, { x: 1, y: 1, z: 1, duration: 0.8 });
          gsap.to(netGrp.current.position, { x: xSplit, y: yBottom, z: 0, duration: 1, ease: 'power2.out' });
       } else if (step === 4) {
          // Move to summary box
          gsap.to(discGrp.current.position, { x: xSummary, y: yTop - 0.5, z: 0, duration: 1, ease: 'power2.inOut' });
          gsap.to(netGrp.current.position, { x: xSummary, y: yBottom + 0.5, z: 0, duration: 1, ease: 'power2.inOut' });
       }
    }

    // 4. Summary Box
    if (summaryGrp.current) {
       if (step === 4) {
          gsap.to(summaryGrp.current.scale, { x: 1, y: 1, z: 1, duration: 0.8, delay: 0.4, ease: 'back.out(1.2)' });
       } else {
          gsap.to(summaryGrp.current.scale, { x: 0, y: 0, z: 0, duration: 0.4 });
       }
    }

  }, [step]);

  return (
    <group>
      {/* 0. Base Price Block */}
      <group ref={baseGrp} position={[-8, 0, 0]}>
        <mesh castShadow receiveShadow>
          <boxGeometry args={[1.5, baseScaleY, 1]} />
          <meshStandardMaterial color="#475569" roughness={0.3} metalness={0.1} />
        </mesh>
        <Html position={[0, 0, 0.51]} center className="pointer-events-none">
          <div className={`flex flex-col items-center justify-center transition-opacity duration-300 ${step > 1 ? 'opacity-0' : 'opacity-100'}`}>
            <span className="text-[10px] uppercase font-bold text-slate-300 whitespace-nowrap">Original Price (100%)</span>
            <span className="font-mono font-bold text-white whitespace-nowrap">{format(basePrice)} LE</span>
          </div>
        </Html>
      </group>

      {/* 1. Discount Portal */}
      <group ref={portalGrp} position={[-2, 0, 0]} scale={[0,0,0]} rotation={[0, Math.PI/4, 0]}>
         <mesh castShadow>
            <torusGeometry args={[3, 0.2, 16, 64]} />
            <meshStandardMaterial color="#8b5cf6" emissive="#7c3aed" emissiveIntensity={0.8} />
         </mesh>
         <Html position={[0, 3.5, 0]} center className="pointer-events-none">
            <div className="bg-purple-900/80 border border-purple-500 px-3 py-1.5 rounded-lg shadow-xl backdrop-blur-md">
               <span className="font-bold text-purple-200 text-sm whitespace-nowrap">{discountRate}% Portal</span>
            </div>
         </Html>
      </group>

      {/* 2. Top Stream: Discount Value */}
      <group ref={discGrp} position={[-2, 0, 0]} scale={[0,0,0]}>
        <mesh castShadow receiveShadow>
          <boxGeometry args={[1.5, discScaleY, 1]} />
          <meshStandardMaterial color="#f97316" roughness={0.3} emissive={step === 4 ? "#f97316" : "#000000"} emissiveIntensity={step === 4 ? 0.3 : 0} />
        </mesh>
        <Html position={[0, discScaleY/2 + 0.6, 0]} center className="pointer-events-none">
          <div className="flex flex-col items-center justify-center bg-orange-950/80 border border-orange-700/50 px-2 py-1 rounded-lg backdrop-blur-md">
            <span className="text-[9px] uppercase font-bold text-orange-300">Discount ({discountRate}%)</span>
            <span className="font-mono font-bold text-orange-100 whitespace-nowrap">{format(discountValue)} LE</span>
          </div>
        </Html>
        {/* Subtraction indicator */}
        <Html position={[0, -discScaleY/2 - 0.4, 0]} center className={`transition-opacity duration-500 pointer-events-none ${step >= 3 ? 'opacity-100' : 'opacity-0'}`}>
           <div className="text-orange-500 font-bold text-xl drop-shadow-md">-</div>
        </Html>
      </group>

      {/* 3. Bottom Stream: Net Payment */}
      <group ref={netGrp} position={[-2, 0, 0]} scale={[0,0,0]}>
        <mesh castShadow receiveShadow>
          <boxGeometry args={[1.5, netScaleY, 1]} />
          <meshStandardMaterial color="#3b82f6" roughness={0.3} emissive={step === 4 ? "#3b82f6" : "#000000"} emissiveIntensity={step === 4 ? 0.3 : 0} />
        </mesh>
        <Html position={[0, netScaleY/2 + 0.6, 0]} center className="pointer-events-none">
          <div className="flex flex-col items-center justify-center bg-blue-950/80 border border-blue-700/50 px-2 py-1 rounded-lg backdrop-blur-md">
            <span className="text-[9px] uppercase font-bold text-blue-300">Net Payment ({netRate}%)</span>
            <span className="font-mono font-bold text-blue-100 whitespace-nowrap">{format(netPayment)} LE</span>
          </div>
        </Html>
      </group>

      {/* 4. Summary Box */}
      <group ref={summaryGrp} position={[7, 0, -1]} scale={[0,0,0]}>
         <mesh receiveShadow>
            <boxGeometry args={[4, 8, 0.2]} />
            <meshStandardMaterial color="#1e293b" roughness={0.8} />
         </mesh>
         <Html position={[0, 4.5, 0]} center className="pointer-events-none">
            <div className="bg-emerald-900/90 border-2 border-emerald-500 px-4 py-2 rounded-xl shadow-[0_0_20px_rgba(16,185,129,0.4)] backdrop-blur-md">
               <span className="text-sm font-bold uppercase tracking-widest text-emerald-100 whitespace-nowrap">Final Validation</span>
            </div>
         </Html>
         <Html position={[0, 0, 0.2]} center className="pointer-events-none">
             <div className="flex items-center justify-center font-mono font-bold text-2xl text-slate-400">
                +
             </div>
         </Html>
         <Html position={[0, -3.8, 0.2]} center className="pointer-events-none">
             <div className="flex flex-col items-center justify-center">
                <div className="h-0.5 w-16 bg-slate-500 mb-1"></div>
                <span className="font-mono font-bold text-lg text-emerald-400 whitespace-nowrap">{format(basePrice)} LE</span>
                <span className="text-[10px] uppercase text-emerald-200 tracking-wider">Original Total</span>
             </div>
         </Html>
      </group>

    </group>
  );
}

export default function ParallelDiscountSimulation() {
  const [step, setStep] = useState(0);
  const basePrice = 18500;
  const [discountRate, setDiscountRate] = useState(12);

  const discountValue = basePrice * (discountRate / 100);
  const netRate = 100 - discountRate;
  const netPayment = basePrice - discountValue;

  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const format = (n: number) => Number.isInteger(n) ? n.toString() : n.toFixed(1);

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`\\text{Original Price} = ${format(basePrice)}`} />;
    if (step === 1) return <BlockMath math={`\\text{Discount Rate} = ${discountRate}\\%`} />;
    if (step === 2) return <BlockMath math={`\\text{Savings} = ${format(basePrice)} \\times \\frac{${discountRate}}{100} = ${format(discountValue)}`} />;
    if (step === 3) return (
       <div className="text-sm">
         <BlockMath math={`\\text{Net Rate} = 100\\% - ${discountRate}\\% = ${netRate}\\%`} />
         <BlockMath math={`\\text{Net Payment} = ${format(basePrice)} - ${format(discountValue)} = ${format(netPayment)}`} />
       </div>
    );
    if (step === 4) return <BlockMath math={`\\text{Total} = ${format(discountValue)} + ${format(netPayment)} = ${format(basePrice)}`} />;
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "The Full 100%", icon: <Tag size={18}/>, color: "border-l-slate-500 text-slate-300 bg-slate-900/50", body: <>We start with the product's original price tag of <span className="font-bold text-white">{format(basePrice)} LE</span>. This block represents the entire 100% of the cost.</> };
    if (step === 1) return { title: "The Discount Portal", icon: <MoveRight size={18}/>, color: "border-l-purple-500 text-purple-400 bg-purple-900/10", body: <>The price enters the calculation portal where the <span className="font-bold text-purple-300">{discountRate}% discount</span> is applied. What happens next is crucial.</> };
    if (step === 2) return { title: "Parallel Splitting", icon: <SplitSquareHorizontal size={18}/>, color: "border-l-blue-500 text-blue-400 bg-blue-900/10", body: <>The total splits into TWO streams. The top stream is your Savings ({discountRate}%), and the bottom stream is your Net Payment ({netRate}%).</> };
    if (step === 3) return { title: "Subtract, Don't Add!", icon: <ArrowDownCircle size={18}/>, color: "border-l-rose-500 text-rose-400 bg-rose-900/10", body: <><b>CRITICAL MISCONCEPTION:</b> A discount is never added! Notice how the orange savings block is physically separated and subtracted from the total, leaving only the blue net payment block for you to pay.</> };
    if (step === 4) return { title: "Reconciliation", icon: <CheckCircle2 size={18}/>, color: "border-l-emerald-500 text-emerald-400 bg-emerald-900/10", body: <>If we place the savings ({format(discountValue)}) and the net payment ({format(netPayment)}) side by side, they perfectly reconstruct the original <span className="font-bold text-emerald-300">{format(basePrice)} LE</span>.</> };
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
              Parallel Discount Flow
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Visualizing how a discount splits a price into savings and net payment.
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
           <Canvas shadows camera={{ position: [0, 0, 16], fov: 50 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[10, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <ProcessVisualizer 
                 step={step} 
                 basePrice={basePrice}
                 discountRate={discountRate}
              />
              
              <OrbitControls 
                enablePan={false} 
                enableZoom={false}
                maxPolarAngle={Math.PI / 2 + 0.1}
                minPolarAngle={Math.PI / 2 - 0.3}
                maxAzimuthAngle={0.2}
                minAzimuthAngle={-0.2}
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

        <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col gap-4 pointer-events-auto shrink-0 w-full lg:w-[480px]">
           
           {/* Progress Indicator */}
           <div className="flex gap-1.5">
             {[0, 1, 2, 3, 4].map(s => (
                <div key={s} className={`h-1.5 flex-1 rounded-full transition-colors duration-500 ${s <= step ? 'bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.6)]' : 'bg-slate-700'}`} />
             ))}
           </div>

           {/* Sliders */}
           <div className="flex flex-col gap-4 mt-2">
              <div className="flex flex-col gap-2">
                <div className="flex justify-between items-center text-slate-300">
                  <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Fixed Product Price</label>
                  <span className="font-mono font-bold text-xs bg-slate-950 px-2 py-0.5 rounded border border-slate-800 text-slate-300">
                     {format(basePrice)} LE
                  </span>
                </div>
              </div>

              <div className="flex flex-col gap-2 border border-purple-900/50 bg-purple-950/30 p-3 rounded-xl">
                 <div className="flex justify-between items-center text-slate-300">
                   <label className="text-[10px] font-bold uppercase tracking-wider text-purple-400">Adjust Discount Rate</label>
                   <span className="text-purple-400 font-mono font-bold text-sm bg-slate-900 px-2 py-1 rounded border border-purple-900/50">{discountRate}%</span>
                 </div>
                 <input 
                   type="range" min="5" max="50" step="1" value={discountRate} 
                   onChange={e => {setDiscountRate(Number(e.target.value)); setStep(0);}} 
                   className="w-full accent-purple-500 h-2 mt-2 bg-slate-800 rounded-lg appearance-none cursor-pointer" 
                 />
                 <div className="flex justify-between mt-1 text-[9px] text-slate-500 font-mono">
                    <span>5%</span>
                    <span>50%</span>
                 </div>
              </div>
           </div>

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-1">
             <button 
                onClick={handleNextStep} 
                disabled={step === 4} 
                className="bg-blue-600 hover:bg-blue-500 text-white font-bold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-blue-900/20 text-sm"
             >
                {step === 0 ? "Enter Portal" : step === 4 ? "Verified" : "Next Step"} 
                {step < 4 && <MoveRight size={16} />}
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
