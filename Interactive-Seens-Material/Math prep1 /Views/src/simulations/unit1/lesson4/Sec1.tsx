import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { AlertCircle, Tag, Minus, Divide, Sparkles, CheckCircle2, RotateCcw, MoveRight } from 'lucide-react';

interface ProcessProps {
  step: number;
  basePrice: number;
  newPrice: number;
}

const format = (n: number) => n.toLocaleString();

function ProcessVisualizer({ step, basePrice, newPrice }: ProcessProps) {
  const discount = basePrice - newPrice;
  const percentage = (discount / basePrice) * 100;
  
  // Visual Widths (Max width = 12 units)
  const maxW = 12;
  const wBase = maxW;
  const wNew = (newPrice / basePrice) * maxW;
  const wDisc = (discount / basePrice) * maxW;

  // Refs for 3D objects
  const baseGrp = useRef<THREE.Group>(null);
  const newGrp = useRef<THREE.Group>(null);
  const discGrp = useRef<THREE.Group>(null);
  const replicaGrp = useRef<THREE.Group>(null);
  const fractionLine = useRef<THREE.Mesh>(null);
  const particleGrp = useRef<THREE.Group>(null);
  const badgeGrp = useRef<THREE.Group>(null);

  // --- Animation Engine ---
  useEffect(() => {
    // Shared constants
    const gap = 0.5; // Gap during subtraction
    const leftEdge = -maxW / 2;

    // 1. Original Base Block
    if (baseGrp.current) {
       if (step === 0) {
         gsap.to(baseGrp.current.scale, { x: 1, y: 1, z: 1, duration: 0.8, ease: 'power2.out' });
         gsap.to(baseGrp.current.position, { y: 0, duration: 0.8 });
       } else {
         gsap.to(baseGrp.current.scale, { x: 1, y: 0, z: 0, duration: 0.5 });
       }
    }

    // 2. New Price Block
    if (newGrp.current) {
       if (step === 0) {
          gsap.to(newGrp.current.scale, { x: 0, y: 0, z: 0, duration: 0.4 });
       } else if (step === 1) {
          const targetX = leftEdge + wNew / 2 - gap/2;
          gsap.to(newGrp.current.scale, { x: wNew, y: 1.2, z: 1, duration: 0.6, ease: 'power2.out', delay: 0.2 });
          gsap.to(newGrp.current.position, { x: targetX, y: 0, z: 0, duration: 0.6, ease: 'power2.out', delay: 0.2 });
       } else if (step >= 2) {
          // Moves aside and fades/shrinks to emphasize the fraction
          gsap.to(newGrp.current.position, { x: -8, y: -4, duration: 0.8, ease: 'power2.inOut' });
          gsap.to(newGrp.current.scale, { x: wNew * 0.5, y: 0.5, z: 0.5, duration: 0.8 });
       }
    }

    // 3. Discount Block
    if (discGrp.current) {
       if (step === 0) {
          gsap.to(discGrp.current.scale, { x: 0, y: 0, z: 0, duration: 0.4 });
       } else if (step === 1) {
          const targetX = leftEdge + wNew + gap/2 + wDisc / 2;
          gsap.to(discGrp.current.scale, { x: wDisc, y: 1.2, z: 1, duration: 0.6, ease: 'power2.out', delay: 0.2 });
          gsap.to(discGrp.current.position, { x: targetX, y: 0, z: 0, duration: 0.6, ease: 'power2.out', delay: 0.2 });
       } else if (step >= 2) {
          // Centers and moves UP to become Numerator
          gsap.to(discGrp.current.scale, { x: wDisc, y: 1.2, z: 1, duration: 0.8 });
          gsap.to(discGrp.current.position, { x: 0, y: 1.8, z: 0, duration: 0.8, ease: 'power2.inOut' });
       }
    }

    // 4. Base Replica (Denominator)
    if (replicaGrp.current) {
       if (step < 2) {
          gsap.to(replicaGrp.current.scale, { x: wBase, y: 0, z: 0, duration: 0.4 });
          gsap.to(replicaGrp.current.position, { y: 0, duration: 0.4 });
       } else if (step >= 2 && step < 4) {
          gsap.to(replicaGrp.current.scale, { x: wBase, y: 1.2, z: 1, duration: 0.6, ease: 'back.out(1.2)' });
          gsap.to(replicaGrp.current.position, { x: 0, y: -1.8, z: 0, duration: 0.6, ease: 'power2.inOut' });
       } else if (step === 4) {
          // Fades out once percentage is established
          gsap.to(replicaGrp.current.scale, { x: wBase, y: 0, z: 0, duration: 0.6, ease: 'power2.in' });
       }
    }

    // 5. Fraction Line
    if (fractionLine.current) {
       if (step === 2 || step === 3) {
          gsap.to(fractionLine.current.scale, { x: wBase * 1.1, y: 1, z: 1, duration: 0.6, delay: 0.3 });
       } else {
          gsap.to(fractionLine.current.scale, { x: 0, y: 0, z: 0, duration: 0.4 });
       }
    }

    // 6. Multiplication Particle (x 100%)
    if (particleGrp.current) {
       if (step < 3) {
          gsap.to(particleGrp.current.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
          gsap.set(particleGrp.current.position, { x: 10, y: 0, z: 1 });
       } else if (step === 3) {
          // Flies in
          gsap.to(particleGrp.current.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: 'back.out(1.5)' });
          gsap.to(particleGrp.current.position, { x: 7.5, y: 0, z: 1, duration: 0.8, ease: 'power2.out' });
       } else if (step === 4) {
          // Smashes into center
          gsap.to(particleGrp.current.position, { x: 0, y: 0, z: 2, duration: 0.5, ease: 'power2.in' });
          gsap.to(particleGrp.current.scale, { x: 0, y: 0, z: 0, duration: 0.2, delay: 0.4 });
       }
    }

    // 7. Final Percentage Badge
    if (badgeGrp.current) {
       if (step < 4) {
          gsap.to(badgeGrp.current.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
       } else if (step === 4) {
          gsap.to(badgeGrp.current.scale, { x: 1, y: 1, z: 1, duration: 0.8, delay: 0.5, ease: 'elastic.out(1, 0.5)' });
       }
    }

  }, [step, wNew, wDisc, wBase]);

  return (
    <group>
      {/* Step 0: Original Base Block */}
      <group ref={baseGrp} position={[0, 0, 0]}>
        <mesh castShadow receiveShadow scale={[wBase, 1.2, 1]}>
          <boxGeometry args={[1, 1, 1]} />
          <meshStandardMaterial color="#475569" roughness={0.3} metalness={0.1} />
        </mesh>
        <Text position={[0, 0, 0.51]} fontSize={0.4} color="white">
          Base Price: {format(basePrice)} LE
        </Text>
      </group>

      {/* Step 1+: New Price Block */}
      <group ref={newGrp} position={[0, 0, 0]} scale={[0,0,0]}>
        <mesh castShadow receiveShadow>
          <boxGeometry args={[1, 1, 1]} />
          <meshStandardMaterial color="#3b82f6" roughness={0.3} metalness={0.1} />
        </mesh>
        <group position={[0, 0, 0.51]}>
          <Html center className="pointer-events-none">
            <div className={`flex flex-col items-center justify-center transition-opacity duration-300 ${step >= 2 ? 'opacity-40' : 'opacity-100'}`}>
              <span className="text-[10px] uppercase font-bold text-blue-200">New Price</span>
              <span className="font-mono font-bold text-white whitespace-nowrap">{format(newPrice)} LE</span>
            </div>
          </Html>
        </group>
      </group>

      {/* Step 1+: Discount Block */}
      <group ref={discGrp} position={[0, 0, 0]} scale={[0,0,0]}>
        <mesh castShadow receiveShadow>
          <boxGeometry args={[1, 1, 1]} />
          <meshStandardMaterial color="#f97316" roughness={0.3} emissive={step === 4 ? "#f97316" : "#000000"} emissiveIntensity={step === 4 ? 0.4 : 0} />
        </mesh>
        <group position={[0, 0, 0.51]}>
          <Html center className="pointer-events-none">
            <div className="flex flex-col items-center justify-center">
              <span className="text-[10px] uppercase font-bold text-orange-200">Discount</span>
              <span className="font-mono font-bold text-white whitespace-nowrap">{format(discount)} LE</span>
            </div>
          </Html>
        </group>
      </group>

      {/* Step 2+: Base Replica (Denominator) */}
      <group ref={replicaGrp} position={[0, 0, 0]} scale={[0,0,0]}>
        <mesh castShadow receiveShadow>
          <boxGeometry args={[1, 1, 1]} />
          <meshStandardMaterial color="#475569" roughness={0.3} metalness={0.1} />
        </mesh>
        <group position={[0, 0, 0.51]}>
          <Html center className="pointer-events-none">
            <div className="flex flex-col items-center justify-center">
              <span className="text-[10px] uppercase font-bold text-slate-300">Base Price (Original)</span>
              <span className="font-mono font-bold text-white whitespace-nowrap">{format(basePrice)} LE</span>
            </div>
          </Html>
        </group>
      </group>

      {/* Step 2-3: Fraction Line */}
      <mesh ref={fractionLine} position={[0, 0, 0]} scale={[0,0,0]}>
         <boxGeometry args={[1, 0.08, 1]} />
         <meshStandardMaterial color="#ffffff" emissive="#ffffff" emissiveIntensity={0.5} />
      </mesh>

      {/* Step 3: Multiplication Particle */}
      <group ref={particleGrp} position={[10, 0, 1]} scale={[0,0,0]}>
         <mesh>
            <sphereGeometry args={[0.8, 32, 32]} />
            <meshStandardMaterial color="#8b5cf6" emissive="#7c3aed" emissiveIntensity={0.6} />
         </mesh>
         <Text position={[0, 0, 0.81]} fontSize={0.35} color="white" outlineWidth={0.02} outlineColor="#000">
            × 100%
         </Text>
      </group>

      {/* Step 4: Final Percentage Badge */}
      <group ref={badgeGrp} position={[0, -0.5, 1]} scale={[0,0,0]}>
         <Html center className="pointer-events-none">
            <div className="bg-emerald-500/90 border-2 border-emerald-300 px-6 py-4 rounded-3xl shadow-[0_0_30px_rgba(16,185,129,0.8)] backdrop-blur-md flex flex-col items-center">
               <span className="text-sm font-bold uppercase tracking-widest text-emerald-100 mb-1">Discount Rate</span>
               <span className="text-4xl font-black font-mono text-white whitespace-nowrap">{Number.isInteger(percentage) ? percentage : percentage.toFixed(1)}%</span>
            </div>
         </Html>
      </group>

    </group>
  );
}

export default function DiscountPercentageSimulation() {
  const [step, setStep] = useState(0);
  const basePrice = 600000;
  const [newPrice, setNewPrice] = useState(510000);

  const discount = basePrice - newPrice;
  const percentage = (discount / basePrice) * 100;

  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const format = (n: number) => Number.isInteger(n) ? n.toString() : n.toFixed(1);

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`\\text{Base Price} = ${format(basePrice)}`} />;
    if (step === 1) return <BlockMath math={`\\text{Discount} = ${format(basePrice)} - ${format(newPrice)} = ${format(discount)}`} />;
    if (step === 2) return <BlockMath math={`\\text{Ratio} = \\frac{\\text{Discount}}{\\text{Base Price}} = \\frac{${format(discount)}}{${format(basePrice)}}`} />;
    if (step === 3) return <BlockMath math={`\\text{Rate} = \\frac{${format(discount)}}{${format(basePrice)}} \\times 100\\%`} />;
    if (step === 4) return <BlockMath math={`\\text{Discount Rate} = ${format(percentage)}\\%`} />;
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "The Original Tag", icon: <Tag size={18}/>, color: "border-l-slate-500 text-slate-300 bg-slate-900/50", body: <>We always begin with the <span className="font-bold text-white">Base Price</span>. This is the absolute foundation before any sales, cuts, or deals are applied.</> };
    if (step === 1) return { title: "Isolating the Discount", icon: <Minus size={18}/>, color: "border-l-orange-500 text-orange-400 bg-orange-900/10", body: <>Subtract the new selling price from the base price. This isolates the exact amount of money saved: <span className="font-bold text-orange-300">{format(discount)} LE</span>.</> };
    if (step === 2) return { title: "The Denominator Trap", icon: <AlertCircle size={18}/>, color: "border-l-rose-500 text-rose-400 bg-rose-900/10", body: <><b>CRITICAL:</b> To find the discount rate, you must compare the discount against the <span className="font-bold text-rose-300">ORIGINAL base price</span>. Never divide by the new selling price!</> };
    if (step === 3) return { title: "Percentage Conversion", icon: <Divide size={18}/>, color: "border-l-purple-500 text-purple-400 bg-purple-900/10", body: <>A fraction is just a ratio. To convert this ratio into a standard percentage out of 100, we multiply the entire fraction by a <span className="font-bold text-purple-300">100%</span> factor.</> };
    if (step === 4) return { title: "Final Rate Verified", icon: <Sparkles size={18}/>, color: "border-l-emerald-500 text-emerald-400 bg-emerald-900/10", body: <>The calculation is complete! A reduction of {format(discount)} LE on a base of {format(basePrice)} LE is exactly a <span className="font-bold text-emerald-300">{format(percentage)}%</span> discount.</> };
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
              Discount Percentage Logic
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Visualizing the strict relationship between discount and base price.
            </p>
          </div>
          <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[320px] flex justify-center shadow-inner">
             <div className="text-white text-lg">
               {renderMath()}
             </div>
          </div>
        </div>
      </div>
      
      {/* R3F Canvas */}
      <div className="flex-1 w-full h-full relative z-0 touch-none">
         <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
           <Canvas shadows camera={{ position: [0, 1, 15], fov: 50 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[10, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <ProcessVisualizer 
                 step={step} 
                 basePrice={basePrice}
                 newPrice={newPrice}
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
                  <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Fixed Base Price</label>
                  <span className="font-mono font-bold text-xs bg-slate-950 px-2 py-0.5 rounded border border-slate-800 text-slate-300">
                     {format(basePrice)} LE
                  </span>
                </div>
              </div>

              <div className="flex flex-col gap-2 border border-blue-900/50 bg-blue-950/30 p-3 rounded-xl">
                 <div className="flex justify-between items-center text-slate-300">
                   <label className="text-[10px] font-bold uppercase tracking-wider text-blue-400">Adjust New Selling Price</label>
                   <span className="text-blue-400 font-mono font-bold text-sm bg-slate-900 px-2 py-1 rounded border border-blue-900/50">{format(newPrice)} LE</span>
                 </div>
                 <input 
                   type="range" min="60000" max="540000" step="30000" value={newPrice} 
                   onChange={e => {setNewPrice(Number(e.target.value)); setStep(0);}} 
                   className="w-full accent-blue-500 h-2 mt-2 bg-slate-800 rounded-lg appearance-none cursor-pointer" 
                 />
                 <div className="flex justify-between mt-1 text-[9px] text-slate-500 font-mono">
                    <span>90% Off</span>
                    <span>10% Off</span>
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
                {step === 0 ? "Subtract Price" : step === 4 ? "Verified" : "Next Step"} 
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
