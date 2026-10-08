import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { AlertCircle, Tag, PlusCircle, Divide, Sparkles, CheckCircle2, RotateCcw, MoveRight } from 'lucide-react';

interface ProcessProps {
  step: number;
  basePrice: number;
  newPrice: number;
}

const format = (n: number) => n.toLocaleString();

function ProcessVisualizer({ step, basePrice, newPrice }: ProcessProps) {
  const increase = newPrice - basePrice;
  const percentage = (increase / basePrice) * 100;
  
  // Visual Widths
  const maxW = 8;
  const wBase = maxW;
  const wExt = (increase / basePrice) * maxW;
  const gap = 0.2;

  // Refs
  const baseGrp = useRef<THREE.Group>(null);
  const extGrp = useRef<THREE.Group>(null);
  const replicaGrp = useRef<THREE.Group>(null);
  const fractionLine = useRef<THREE.Mesh>(null);
  const particleGrp = useRef<THREE.Group>(null);
  const badgeGrp = useRef<THREE.Group>(null);

  useEffect(() => {
    // 1. Base Price Block
    if (baseGrp.current) {
       if (step === 0) {
         gsap.to(baseGrp.current.position, { x: 0, y: 0, z: 0, duration: 0.8, ease: 'power2.out' });
         gsap.to(baseGrp.current.scale, { x: wBase, y: 1.2, z: 1, duration: 0.8 });
       } else if (step === 1) {
         const totalLeft = -(wBase + gap + wExt) / 2;
         gsap.to(baseGrp.current.position, { x: totalLeft + wBase/2, y: 0, duration: 0.6, ease: 'power2.out' });
         gsap.to(baseGrp.current.scale, { x: wBase, y: 1.2, z: 1, duration: 0.6 });
       } else if (step >= 2) {
         gsap.to(baseGrp.current.position, { x: -8, y: -4, duration: 0.8, ease: 'power2.inOut' });
         gsap.to(baseGrp.current.scale, { x: wBase * 0.5, y: 0.5, z: 0.5, duration: 0.8 });
       }
    }

    // 2. Extension Block
    if (extGrp.current) {
       if (step === 0) {
          gsap.to(extGrp.current.scale, { x: 0, y: 0, z: 0, duration: 0.4 });
          const totalLeft = -(wBase + gap + wExt) / 2;
          gsap.set(extGrp.current.position, { x: totalLeft + wBase + gap + wExt/2 });
       } else if (step === 1) {
          const totalLeft = -(wBase + gap + wExt) / 2;
          gsap.to(extGrp.current.scale, { x: wExt, y: 1.2, z: 1, duration: 0.6, ease: 'power2.out', delay: 0.2 });
          gsap.to(extGrp.current.position, { x: totalLeft + wBase + gap + wExt/2, y: 0, z: 0, duration: 0.6, ease: 'power2.out', delay: 0.2 });
       } else if (step >= 2) {
          gsap.to(extGrp.current.scale, { x: wExt, y: 1.2, z: 1, duration: 0.8 });
          gsap.to(extGrp.current.position, { x: 0, y: 1.8, z: 0, duration: 0.8, ease: 'power2.inOut' });
       }
    }

    // 3. Replica Block (Denominator)
    if (replicaGrp.current) {
       if (step < 2) {
          gsap.to(replicaGrp.current.scale, { x: wBase, y: 0, z: 0, duration: 0.4 });
          gsap.to(replicaGrp.current.position, { y: 0, duration: 0.4 });
       } else if (step >= 2 && step < 4) {
          gsap.to(replicaGrp.current.scale, { x: wBase, y: 1.2, z: 1, duration: 0.6, ease: 'back.out(1.2)' });
          gsap.to(replicaGrp.current.position, { x: 0, y: -1.8, z: 0, duration: 0.6 });
       } else if (step === 4) {
          gsap.to(replicaGrp.current.scale, { x: wBase, y: 0, z: 0, duration: 0.6 });
       }
    }

    // 4. Fraction Line
    if (fractionLine.current) {
       if (step === 2 || step === 3) {
          gsap.to(fractionLine.current.scale, { x: wBase * 1.1, y: 1, z: 1, duration: 0.6, delay: 0.3 });
       } else {
          gsap.to(fractionLine.current.scale, { x: 0, y: 0, z: 0, duration: 0.4 });
       }
    }

    // 5. Multiplication Particle (x 100%)
    if (particleGrp.current) {
       if (step < 3) {
          gsap.to(particleGrp.current.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
          gsap.set(particleGrp.current.position, { x: 10, y: 0, z: 1 });
       } else if (step === 3) {
          gsap.to(particleGrp.current.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: 'back.out(1.5)' });
          gsap.to(particleGrp.current.position, { x: 6.5, y: 0, z: 1, duration: 0.8, ease: 'power2.out' });
       } else if (step === 4) {
          gsap.to(particleGrp.current.position, { x: 0, y: 0, z: 2, duration: 0.5, ease: 'power2.in' });
          gsap.to(particleGrp.current.scale, { x: 0, y: 0, z: 0, duration: 0.2, delay: 0.4 });
       }
    }

    // 6. Final Percentage Badge
    if (badgeGrp.current) {
       if (step < 4) {
          gsap.to(badgeGrp.current.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
       } else if (step === 4) {
          gsap.to(badgeGrp.current.scale, { x: 1, y: 1, z: 1, duration: 0.8, delay: 0.5, ease: 'elastic.out(1, 0.5)' });
       }
    }

  }, [step, wBase, wExt, gap]);

  return (
    <group>
      
      {/* 1. Base Price Block */}
      <group ref={baseGrp} position={[0, 0, 0]}>
        <mesh castShadow receiveShadow>
          <boxGeometry args={[1, 1, 1]} />
          <meshStandardMaterial color="#475569" roughness={0.3} metalness={0.1} />
        </mesh>
        <group position={[0, 0, 0.51]}>
          <Html center className="pointer-events-none">
            <div className={`flex flex-col items-center justify-center transition-opacity duration-300 ${step >= 2 ? 'opacity-40' : 'opacity-100'}`}>
              <span className="text-[10px] uppercase font-bold text-slate-300">Base Price</span>
              <span className="font-mono font-bold text-white whitespace-nowrap">{format(basePrice)} LE</span>
            </div>
          </Html>
        </group>
      </group>

      {/* 2. Extension Block */}
      <group ref={extGrp} position={[0, 0, 0]} scale={[0,0,0]}>
        <mesh castShadow receiveShadow>
          <boxGeometry args={[1, 1, 1]} />
          <meshStandardMaterial color="#22c55e" roughness={0.3} emissive={step >= 2 ? "#22c55e" : "#000000"} emissiveIntensity={step >= 2 ? 0.3 : (step === 1 ? 0.1 : 0)} />
        </mesh>
        <group position={[0, 0, 0.51]}>
          <Html center className="pointer-events-none">
            <div className={`flex flex-col items-center justify-center transition-opacity duration-300 ${wExt < 0.2 && step < 2 ? 'opacity-0' : 'opacity-100'}`}>
              <span className="text-[10px] uppercase font-bold text-green-200">{step >= 2 ? 'Numerator' : 'Increase'}</span>
              <span className="font-mono font-bold text-white whitespace-nowrap">+{format(increase)} LE</span>
            </div>
          </Html>
        </group>
      </group>

      {/* 3. Base Replica (Denominator) */}
      <group ref={replicaGrp} position={[0, 0, 0]} scale={[0,0,0]}>
        <mesh castShadow receiveShadow>
          <boxGeometry args={[1, 1, 1]} />
          <meshStandardMaterial color="#3b82f6" roughness={0.3} metalness={0.1} emissive="#1d4ed8" emissiveIntensity={0.2} />
        </mesh>
        <group position={[0, 0, 0.51]}>
          <Html center className="pointer-events-none">
            <div className="flex flex-col items-center justify-center bg-blue-950/80 px-3 py-1 rounded border border-blue-500/50 backdrop-blur-sm">
              <span className="text-[10px] uppercase font-bold text-blue-300">Denominator (Original Base)</span>
              <span className="font-mono font-bold text-white whitespace-nowrap">{format(basePrice)} LE</span>
            </div>
          </Html>
        </group>
      </group>

      {/* 4. Fraction Line */}
      <mesh ref={fractionLine} position={[0, 0, 0]} scale={[0,0,0]}>
         <boxGeometry args={[1, 0.08, 1]} />
         <meshStandardMaterial color="#ffffff" emissive="#ffffff" emissiveIntensity={0.5} />
      </mesh>

      {/* 5. Multiplication Particle */}
      <group ref={particleGrp} position={[10, 0, 1]} scale={[0,0,0]}>
         <mesh>
            <sphereGeometry args={[0.8, 32, 32]} />
            <meshStandardMaterial color="#10b981" emissive="#059669" emissiveIntensity={0.6} />
         </mesh>
         <Text position={[0, 0, 0.81]} fontSize={0.35} color="white" outlineWidth={0.02} outlineColor="#000">
            × 100%
         </Text>
      </group>

      {/* 6. Percentage Badge */}
      <group ref={badgeGrp} position={[0, -0.5, 1]} scale={[0,0,0]}>
         <Html center className="pointer-events-none">
            <div className="bg-emerald-500/90 border-2 border-emerald-300 px-6 py-4 rounded-3xl shadow-[0_0_30px_rgba(16,185,129,0.8)] backdrop-blur-md flex flex-col items-center">
               <span className="text-sm font-bold uppercase tracking-widest text-emerald-100 mb-1 flex items-center gap-2">
                  <Sparkles size={16} /> Increase Rate
               </span>
               <span className="text-4xl font-black font-mono text-white whitespace-nowrap">
                  +{Number.isInteger(percentage) ? percentage : percentage.toFixed(1)}%
               </span>
            </div>
         </Html>
      </group>

    </group>
  );
}

export default function PriceIncreaseSimulation() {
  const [step, setStep] = useState(0);
  
  const basePrice = 16000;
  const [newPrice, setNewPrice] = useState(18000);

  const increase = newPrice - basePrice;
  const percentage = (increase / basePrice) * 100;

  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const format = (n: number) => n.toLocaleString();
  const formatDec = (n: number) => Number.isInteger(n) ? n.toString() : n.toFixed(1);

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`\\text{Base Price} = ${format(basePrice)}`} />;
    if (step === 1) return <BlockMath math={`\\text{Increase} = ${format(newPrice)} - ${format(basePrice)} = ${format(increase)}`} />;
    if (step === 2) return <BlockMath math={`\\text{Ratio} = \\frac{\\text{Increase}}{\\text{Base Price}} = \\frac{${format(increase)}}{${format(basePrice)}}`} />;
    if (step === 3) return <BlockMath math={`\\text{Rate} = \\frac{${format(increase)}}{${format(basePrice)}} \\times 100\\%`} />;
    if (step === 4) return <BlockMath math={`\\text{Increase Rate} = +${formatDec(percentage)}\\%`} />;
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "The Foundation", icon: <Tag size={18}/>, color: "border-l-slate-500 text-slate-300 bg-slate-900/50", body: <>Every percentage change must be anchored to an original starting point. Our foundation is the <span className="font-bold text-white">Base Price</span> of {format(basePrice)} LE.</> };
    if (step === 1) return { title: "The Price Extension", icon: <PlusCircle size={18}/>, color: "border-l-green-500 text-green-400 bg-green-900/10", body: <>The price increases to {format(newPrice)} LE. We isolate the growth by subtracting the base from the new price, revealing an extension of <span className="font-bold text-green-300">+{format(increase)} LE</span>.</> };
    if (step === 2) return { title: "The Denominator Trap", icon: <AlertCircle size={18}/>, color: "border-l-rose-500 text-rose-400 bg-rose-900/10", body: <><b>CRITICAL:</b> To find the increase rate, we compare the added segment against the <span className="font-bold text-rose-300">ORIGINAL Base Price</span>. Never divide by the new higher price!</> };
    if (step === 3) return { title: "Percentage Conversion", icon: <Divide size={18}/>, color: "border-l-purple-500 text-purple-400 bg-purple-900/10", body: <>This ratio represents the fractional growth relative to the original amount. We multiply by <span className="font-bold text-purple-300">100%</span> to scale the fraction into a standard percentage rate.</> };
    if (step === 4) return { title: "Final Rate Verified", icon: <CheckCircle2 size={18}/>, color: "border-l-emerald-500 text-emerald-400 bg-emerald-900/10", body: <>The calculation completes! An addition of {format(increase)} LE on a base of {format(basePrice)} LE represents exactly a <span className="font-bold text-emerald-300">{formatDec(percentage)}%</span> increase.</> };
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
              Accumulation & Increase Rate
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Visualizing how price increases are mapped strictly to the original base value.
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
           <Canvas shadows camera={{ position: [0, 1, 16], fov: 50 }}>
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
                <div key={s} className={`h-1.5 flex-1 rounded-full transition-colors duration-500 ${s <= step ? 'bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.6)]' : 'bg-slate-700'}`} />
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

              <div className="flex flex-col gap-2 border border-green-900/50 bg-green-950/30 p-3 rounded-xl">
                 <div className="flex justify-between items-center text-slate-300">
                   <label className="text-[10px] font-bold uppercase tracking-wider text-green-400">Adjust New Total Price</label>
                   <span className="text-green-400 font-mono font-bold text-sm bg-slate-900 px-2 py-1 rounded border border-green-900/50">{format(newPrice)} LE</span>
                 </div>
                 <input 
                   type="range" min="16000" max="32000" step="400" value={newPrice} 
                   onChange={e => {setNewPrice(Number(e.target.value)); setStep(0);}} 
                   className="w-full accent-green-500 h-2 mt-2 bg-slate-800 rounded-lg appearance-none cursor-pointer" 
                 />
                 <div className="flex justify-between mt-1 text-[9px] text-slate-500 font-mono">
                    <span>0% Growth</span>
                    <span>100% Growth</span>
                 </div>
              </div>
           </div>

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-1">
             <button 
                onClick={handleNextStep} 
                disabled={step === 4 || newPrice === basePrice} 
                className="bg-green-600 hover:bg-green-500 text-white font-bold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-green-900/20 text-sm"
             >
                {step === 0 ? "Apply Increase" : step === 4 ? "Verified" : "Next Step"} 
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
