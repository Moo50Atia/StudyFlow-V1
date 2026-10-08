import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { AlertCircle, Tag, Percent, LayoutGrid, X, CheckCircle2, RotateCcw, MoveRight } from 'lucide-react';

interface ProcessProps {
  step: number;
  salePrice: number;
  discountRate: number;
  netRate: number;
  originalPrice: number;
}

const format = (n: number) => n.toLocaleString();

function ProcessVisualizer({ step, salePrice, discountRate, netRate, originalPrice }: ProcessProps) {
  
  // Visual Widths
  const fullWidth = 12;
  const wSale = fullWidth * (netRate / 100);
  const startX = -fullWidth / 2;

  // Refs
  const saleBarRef = useRef<THREE.Mesh>(null);
  const movingLabelRef = useRef<THREE.Group>(null);
  const discountBadgeRef = useRef<THREE.Group>(null);
  const compNodeRef = useRef<THREE.Group>(null);
  const gridRef = useRef<THREE.Group>(null);
  const ray1Ref = useRef<THREE.Mesh>(null);
  const ray2Ref = useRef<THREE.Mesh>(null);

  const [displayVal, setDisplayVal] = useState(salePrice);

  // Pre-translate box geometry so it scales rightward from x=0
  const boxGeo = useMemo(() => {
    const geo = new THREE.BoxGeometry(1, 1.2, 1);
    geo.translate(0.5, 0, 0);
    return geo;
  }, []);

  useEffect(() => {
    // Shared durations
    const dur = 0.8;

    // 1. Sale Bar Scaling
    if (saleBarRef.current && movingLabelRef.current) {
       if (step < 4) {
          gsap.to(saleBarRef.current.scale, { x: wSale, y: 1, z: 1, duration: dur, ease: 'power2.out' });
          gsap.to(movingLabelRef.current.position, { x: wSale / 2, duration: dur, ease: 'power2.out' });
       } else if (step === 4) {
          gsap.to(saleBarRef.current.scale, { x: fullWidth, y: 1, z: 1, duration: 1.2, ease: 'power2.inOut' });
          gsap.to(movingLabelRef.current.position, { x: fullWidth / 2, duration: 1.2, ease: 'power2.inOut' });
       }
    }

    // 2. Display Value interpolation during Step 4 expansion
    if (step === 4) {
       const proxy = { val: salePrice };
       gsap.to(proxy, {
          val: originalPrice,
          duration: 1.2,
          ease: 'power2.inOut',
          onUpdate: () => setDisplayVal(Math.round(proxy.val))
       });
    } else {
       setDisplayVal(salePrice);
    }

    // 3. Discount Badge Visibility
    if (discountBadgeRef.current) {
       if (step === 4) {
          gsap.to(discountBadgeRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.4 });
       } else {
          // Keep it centered in the empty space
          const emptySpaceCenter = startX + wSale + (fullWidth - wSale) / 2;
          gsap.to(discountBadgeRef.current.position, { x: emptySpaceCenter, duration: dur });
          gsap.to(discountBadgeRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.5 });
       }
    }

    // 4. Complementary Node (Step 1 to 3)
    if (compNodeRef.current) {
       if (step >= 1 && step < 4) {
          gsap.to(compNodeRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: 'back.out(1.2)' });
       } else {
          gsap.to(compNodeRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
       }
    }

    // 5. Proportion Grid (Step 2 to 4)
    if (gridRef.current) {
       if (step >= 2) {
          gsap.to(gridRef.current.position, { y: 0, duration: dur, ease: 'power2.out' });
          gsap.to(gridRef.current.scale, { x: 1, y: 1, z: 1, duration: dur });
       } else {
          gsap.to(gridRef.current.position, { y: -2, duration: 0.4 });
          gsap.to(gridRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.4 });
       }
    }

    // 6. Cross Multiplication Rays (Step 3 to 4)
    if (ray1Ref.current && ray2Ref.current) {
       if (step >= 3) {
          gsap.to(ray1Ref.current.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: 'power2.out' });
          gsap.to(ray2Ref.current.scale, { x: 1, y: 1, z: 1, duration: 0.5, delay: 0.2, ease: 'power2.out' });
       } else {
          gsap.to(ray1Ref.current.scale, { x: 0, y: 0, z: 0, duration: 0.2 });
          gsap.to(ray2Ref.current.scale, { x: 0, y: 0, z: 0, duration: 0.2 });
       }
    }

  }, [step, wSale, fullWidth, originalPrice, salePrice, startX]);

  return (
    <group>
      
      {/* 100% Ghost Outline */}
      <mesh position={[0, 0, -0.5]}>
         <boxGeometry args={[fullWidth, 1.4, 0.2]} />
         <meshStandardMaterial color="#334155" transparent opacity={0.6} wireframe={true} />
      </mesh>
      <Text position={[fullWidth/2 + 1, 0, 0]} fontSize={0.5} color="#94a3b8">100%</Text>

      {/* Sale Bar */}
      <group position={[startX, 0, 0]}>
         <mesh ref={saleBarRef} geometry={boxGeo} scale={[wSale, 1, 1]} castShadow receiveShadow>
            <meshStandardMaterial color="#3b82f6" roughness={0.3} metalness={0.1} />
         </mesh>
         
         <group ref={movingLabelRef} position={[wSale / 2, 0, 0.6]}>
            <Html center className="pointer-events-none">
               <div className={`transition-all duration-300 border px-3 py-1.5 rounded-lg shadow-xl flex flex-col items-center backdrop-blur-md ${step === 4 ? 'bg-emerald-900/90 border-emerald-500' : 'bg-blue-900/90 border-blue-500'}`}>
                  <span className={`text-[10px] uppercase font-bold tracking-widest mb-0.5 ${step === 4 ? 'text-emerald-300' : 'text-blue-300'}`}>
                     {step === 4 ? 'Original Price' : 'Sale Price'}
                  </span>
                  <span className="font-mono font-bold text-white text-lg whitespace-nowrap">{format(displayVal)} LE</span>
               </div>
            </Html>
         </group>
      </group>

      {/* Discount Badge */}
      <group ref={discountBadgeRef} position={[0, 0, 0]}>
         <Html center className="pointer-events-none">
            <div className="bg-rose-900/90 border border-rose-500 text-rose-200 px-3 py-1.5 rounded-full text-sm font-bold shadow-[0_0_15px_rgba(244,63,94,0.4)] whitespace-nowrap">
               -{discountRate}% Off
            </div>
         </Html>
      </group>

      {/* Complementary Node */}
      <group ref={compNodeRef} position={[0, -2.5, 0]} scale={[0,0,0]}>
         <Html center className="pointer-events-none">
            <div className="bg-slate-800/90 border border-slate-600 px-5 py-2 rounded-xl shadow-2xl whitespace-nowrap flex items-center gap-3 backdrop-blur-md">
               <span className="text-slate-400 font-mono text-sm">100% - {discountRate}%</span>
               <span className="text-slate-500">=</span>
               <span className="text-blue-400 font-mono font-bold text-lg">{netRate}% Net</span>
            </div>
         </Html>
      </group>

      {/* Proportion Grid */}
      <group ref={gridRef} position={[0, -2, 0]} scale={[0,0,0]}>
         
         {/* Background plate */}
         <mesh position={[0, 4, -0.5]} receiveShadow>
            <boxGeometry args={[9, 3.5, 0.1]} />
            <meshStandardMaterial color="#0f172a" roughness={0.8} />
         </mesh>

         {/* Grid Values */}
         <Text position={[-2.5, 4.8, 0]} fontSize={0.6} color="#60a5fa" font="/fonts/Inter-Bold.ttf">{format(salePrice)} LE</Text>
         <Text position={[2.5, 4.8, 0]} fontSize={0.6} color="#94a3b8" font="/fonts/Inter-Bold.ttf">{netRate}%</Text>
         
         <Text position={[-2.5, 3.2, 0]} fontSize={0.6} color="#34d399" font="/fonts/Inter-Bold.ttf">{step === 4 ? format(originalPrice) + " LE" : "? (Original)"}</Text>
         <Text position={[2.5, 3.2, 0]} fontSize={0.6} color="#f8fafc" font="/fonts/Inter-Bold.ttf">100%</Text>

         {/* Ray 1: Top-Left to Bottom-Right */}
         <mesh ref={ray1Ref} position={[0, 4, 0.1]} rotation={[0, 0, Math.atan2(-1.6, 5)]} scale={[0,0,0]}>
            <boxGeometry args={[5.25, 0.05, 0.05]} />
            <meshStandardMaterial color="#fbbf24" emissive="#f59e0b" emissiveIntensity={1} />
         </mesh>

         {/* Ray 2: Bottom-Left to Top-Right */}
         <mesh ref={ray2Ref} position={[0, 4, 0.1]} rotation={[0, 0, Math.atan2(1.6, 5)]} scale={[0,0,0]}>
            <boxGeometry args={[5.25, 0.05, 0.05]} />
            <meshStandardMaterial color="#fbbf24" emissive="#f59e0b" emissiveIntensity={1} />
         </mesh>
         
         {/* Divide Icon overlay */}
         <Html position={[3.5, 4.8, 0]} center className={`transition-opacity duration-500 pointer-events-none ${step >= 3 ? 'opacity-100' : 'opacity-0'}`}>
            <div className="bg-amber-500/20 text-amber-400 p-1 rounded-full border border-amber-500/50">
               <span className="font-bold text-lg px-2">÷</span>
            </div>
         </Html>
      </group>

    </group>
  );
}

export default function ReverseProportionSimulation() {
  const [step, setStep] = useState(0);
  
  const [salePrice, setSalePrice] = useState(8400);
  const [discountRate, setDiscountRate] = useState(40);

  const netRate = 100 - discountRate;
  const originalPrice = Math.round(salePrice / (netRate / 100));
  const misconceptionVal = Math.round(salePrice + (salePrice * (discountRate / 100)));

  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const format = (n: number) => n.toLocaleString();

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`\\text{Sale Price} = ${format(salePrice)}, \\quad \\text{Discount} = ${discountRate}\\%`} />;
    if (step === 1) return <BlockMath math={`\\text{Net Rate} = 100\\% - ${discountRate}\\% = ${netRate}\\%`} />;
    if (step === 2) return <BlockMath math={`\\frac{${format(salePrice)}}{${netRate}\\%} = \\frac{?}{100\\%}`} />;
    if (step === 3) return <BlockMath math={`\\text{Original} = \\frac{${format(salePrice)} \\times 100}{${netRate}}`} />;
    if (step === 4) return <BlockMath math={`\\text{Original Price} = ${format(originalPrice)}\\text{ LE}`} />;
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "The Sale Tag", icon: <Tag size={18}/>, color: "border-l-slate-500 text-slate-300 bg-slate-900/50", body: <>A common mistake when finding an original price is trying to work backward by calculating {discountRate}% of the sale price and adding it back. Let's see why that fails.</> };
    if (step === 1) return { title: "Complementary Percentage", icon: <Percent size={18}/>, color: "border-l-blue-500 text-blue-400 bg-blue-900/10", body: <>If a <span className="font-bold text-blue-300">{discountRate}%</span> discount was applied to the total, then the sale price represents exactly the remaining <span className="font-bold text-blue-300">{netRate}%</span>.</> };
    if (step === 2) return { title: "Reverse Proportion", icon: <LayoutGrid size={18}/>, color: "border-l-yellow-500 text-yellow-400 bg-yellow-900/10", body: <>We map the variables into a proportion grid: the sale price ({format(salePrice)}) maps to {netRate}%, and the unknown original price maps to the full 100%.</> };
    if (step === 3) return { title: "Cross-Multiplication", icon: <X size={18}/>, color: "border-l-purple-500 text-purple-400 bg-purple-900/10", body: <>Multiply the known diagonal values (<span className="font-bold text-purple-300">{format(salePrice)} × 100</span>) and divide by the remaining value (<span className="font-bold text-purple-300">{netRate}</span>) to isolate the unknown.</> };
    if (step === 4) return { title: "Original Restored", icon: <CheckCircle2 size={18}/>, color: "border-l-emerald-500 text-emerald-400 bg-emerald-900/10", body: <>The bar expands to 100%! Notice the naive mistake ({format(salePrice)} + {discountRate}%) yields <span className="text-rose-400 line-through">{format(misconceptionVal)}</span> (Wrong!). The true original is <span className="font-bold text-emerald-300">{format(originalPrice)} LE</span>.</> };
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
              Reverse Proportion Calculator
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Calculating the true original price from a discounted sale tag.
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
           <Canvas shadows camera={{ position: [0, 2, 13], fov: 50 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[10, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <ProcessVisualizer 
                 step={step} 
                 salePrice={salePrice}
                 discountRate={discountRate}
                 netRate={netRate}
                 originalPrice={originalPrice}
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
           <div className="grid grid-cols-2 gap-4 mt-2">
              <div className="flex flex-col gap-2 border border-blue-900/50 bg-blue-950/30 p-3 rounded-xl">
                 <div className="flex justify-between items-center text-slate-300">
                   <label className="text-[10px] font-bold uppercase tracking-wider text-blue-400">Sale Price</label>
                   <span className="text-blue-400 font-mono font-bold text-sm">{format(salePrice)}</span>
                 </div>
                 <input 
                   type="range" min="2000" max="20000" step="200" value={salePrice} 
                   onChange={e => {setSalePrice(Number(e.target.value)); setStep(0);}} 
                   className="w-full accent-blue-500 h-2 mt-1 bg-slate-800 rounded-lg appearance-none cursor-pointer" 
                 />
              </div>

              <div className="flex flex-col gap-2 border border-rose-900/50 bg-rose-950/30 p-3 rounded-xl">
                 <div className="flex justify-between items-center text-slate-300">
                   <label className="text-[10px] font-bold uppercase tracking-wider text-rose-400">Discount</label>
                   <span className="text-rose-400 font-mono font-bold text-sm">-{discountRate}%</span>
                 </div>
                 <input 
                   type="range" min="10" max="80" step="5" value={discountRate} 
                   onChange={e => {setDiscountRate(Number(e.target.value)); setStep(0);}} 
                   className="w-full accent-rose-500 h-2 mt-1 bg-slate-800 rounded-lg appearance-none cursor-pointer" 
                 />
              </div>
           </div>

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-1">
             <button 
                onClick={handleNextStep} 
                disabled={step === 4} 
                className="bg-blue-600 hover:bg-blue-500 text-white font-bold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-blue-900/20 text-sm"
             >
                {step === 0 ? "Find Net %" : step === 4 ? "Restored" : "Next Step"} 
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
