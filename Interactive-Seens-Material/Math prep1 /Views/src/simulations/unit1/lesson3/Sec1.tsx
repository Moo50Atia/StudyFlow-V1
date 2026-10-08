import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { AlertCircle, User, Users, MoveRight, RotateCcw, PieChart } from 'lucide-react';

// --- 3D Scene Components ---

interface SegmentProps {
  index: number;
  totalParts: number;
  isA: boolean;
  step: number;
  unitValue: number;
}

function Segment({ index, totalParts, isA, step, unitValue }: SegmentProps) {
  const groupRef = useRef<THREE.Group>(null);
  const meshRef = useRef<THREE.Mesh>(null);
  const matRef = useRef<THREE.MeshStandardMaterial>(null);
  const textRef = useRef<THREE.Group>(null);

  const totalWidth = 10;
  const width = totalWidth / totalParts;
  const baseX = -totalWidth / 2 + (index + 0.5) * width;

  useEffect(() => {
    if (!groupRef.current || !matRef.current || !meshRef.current || !textRef.current) return;

    // Position logic
    let targetX = baseX;
    if (step === 4) {
      // Create a gap in the middle to group A and B
      targetX = isA ? baseX - 0.5 : baseX + 0.5;
    }

    // Scale logic (Step 0 is monolithic, Step 1+ is segmented)
    const targetScaleX = step === 0 ? width : width * 0.92;

    // Color logic
    const neutralColor = new THREE.Color('#64748b'); // slate-500
    const aColor = new THREE.Color('#3b82f6'); // blue-500
    const bColor = new THREE.Color('#f97316'); // orange-500
    
    let targetColor = neutralColor;
    if (step >= 2) {
      targetColor = isA ? aColor : bColor;
    }

    gsap.to(groupRef.current.position, {
      x: targetX,
      duration: 1,
      ease: 'power2.inOut',
    });

    gsap.to(meshRef.current.scale, {
      x: targetScaleX,
      duration: 0.6,
      ease: 'power2.out',
    });

    gsap.to(matRef.current.color, {
      r: targetColor.r,
      g: targetColor.g,
      b: targetColor.b,
      duration: 0.8,
    });
    
    // Label visibility
    if (step >= 3) {
       textRef.current.visible = true;
       gsap.to(textRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.4, delay: 0.3, ease: 'back.out(1.5)' });
    } else {
       gsap.to(textRef.current.scale, { 
           x: 0, y: 0, z: 0, duration: 0.2, 
           onComplete: () => { if (textRef.current) textRef.current.visible = false; } 
       });
    }

  }, [step, isA, baseX, width]);

  // Dynamic font size depending on how thin the blocks are
  const fontSize = Math.min(0.4, width * 0.6);

  return (
    <group ref={groupRef} position={[baseX, 0, 0]}>
      <mesh ref={meshRef} castShadow receiveShadow scale={[width, 1.5, 1]}>
        <boxGeometry args={[1, 1, 1]} />
        <meshStandardMaterial ref={matRef} color="#64748b" roughness={0.3} metalness={0.1} />
      </mesh>
      <group ref={textRef} position={[0, 0, 0.51]} scale={[0,0,0]} visible={false}>
         <Text
           fontSize={fontSize}
           color="white"
           anchorX="center"
           anchorY="middle"
           outlineWidth={0.02}
           outlineColor="#000000"
         >
           {Number.isInteger(unitValue) ? unitValue : unitValue.toFixed(1)}
         </Text>
      </group>
    </group>
  );
}

interface VisualizerProps {
  step: number;
  totalAmount: number;
  ratioA: number;
  ratioB: number;
}

function ProcessVisualizer({ step, totalAmount, ratioA, ratioB }: VisualizerProps) {
  const totalParts = ratioA + ratioB;
  const unitValue = totalAmount / totalParts;
  
  const segments = useMemo(() => {
    return Array.from({ length: totalParts }).map((_, i) => ({
      id: i,
      isA: i < ratioA
    }));
  }, [totalParts, ratioA]);

  const totalWidth = 10;
  
  // Calculate pod widths and centers for Step 4 grouping labels
  const widthA = (ratioA / totalParts) * totalWidth;
  const widthB = (ratioB / totalParts) * totalWidth;
  
  const centerA = -totalWidth / 2 + (widthA / 2) - 0.5;
  const centerB = totalWidth / 2 - (widthB / 2) + 0.5;

  const totalShareA = unitValue * ratioA;
  const totalShareB = unitValue * ratioB;

  return (
    <group position={[0, 0, 0]}>
      {segments.map((seg, i) => (
        <Segment
          key={`${totalParts}-${i}`}
          index={i}
          totalParts={totalParts}
          isA={seg.isA}
          step={step}
          unitValue={unitValue}
        />
      ))}
      
      {/* Top Total Label */}
      <group position={[0, 1.5, 0]}>
         <Html center className={`transition-opacity duration-500 ${step < 4 ? 'opacity-100' : 'opacity-0 pointer-events-none'}`}>
             <div className="bg-slate-800/90 border border-slate-600 text-white px-4 py-2 rounded-xl shadow-xl flex flex-col items-center whitespace-nowrap backdrop-blur-md">
                 <span className="text-xs text-slate-400 uppercase tracking-widest font-bold mb-1">Total Amount</span>
                 <span className="text-xl font-mono font-bold text-emerald-400">{totalAmount} LE</span>
             </div>
         </Html>
      </group>

      {/* Bottom Pod Labels (Step 4) */}
      <group position={[centerA, -1.5, 0]}>
         <Html center className={`transition-all duration-700 delay-300 ${step === 4 ? 'opacity-100 translate-y-0' : 'opacity-0 -translate-y-4 pointer-events-none'}`}>
             <div className="bg-blue-900/90 border border-blue-500 text-white px-4 py-3 rounded-xl shadow-2xl flex flex-col items-center whitespace-nowrap backdrop-blur-md min-w-[140px]">
                 <span className="text-xs text-blue-300 uppercase tracking-widest font-bold mb-1 flex items-center gap-1"><User size={14}/> Person A</span>
                 <span className="text-lg font-mono font-bold text-blue-100">
                    {ratioA} × {Number.isInteger(unitValue) ? unitValue : unitValue.toFixed(1)}
                 </span>
                 <div className="w-full h-px bg-blue-500/50 my-1"/>
                 <span className="text-2xl font-mono font-black text-blue-400">
                    {Number.isInteger(totalShareA) ? totalShareA : totalShareA.toFixed(1)} LE
                 </span>
             </div>
         </Html>
      </group>

      <group position={[centerB, -1.5, 0]}>
         <Html center className={`transition-all duration-700 delay-300 ${step === 4 ? 'opacity-100 translate-y-0' : 'opacity-0 -translate-y-4 pointer-events-none'}`}>
             <div className="bg-orange-900/90 border border-orange-500 text-white px-4 py-3 rounded-xl shadow-2xl flex flex-col items-center whitespace-nowrap backdrop-blur-md min-w-[140px]">
                 <span className="text-xs text-orange-300 uppercase tracking-widest font-bold mb-1 flex items-center gap-1"><User size={14}/> Person B</span>
                 <span className="text-lg font-mono font-bold text-orange-100">
                    {ratioB} × {Number.isInteger(unitValue) ? unitValue : unitValue.toFixed(1)}
                 </span>
                 <div className="w-full h-px bg-orange-500/50 my-1"/>
                 <span className="text-2xl font-mono font-black text-orange-400">
                    {Number.isInteger(totalShareB) ? totalShareB : totalShareB.toFixed(1)} LE
                 </span>
             </div>
         </Html>
      </group>
    </group>
  );
}

// --- Main Application Component ---

export default function ProportionalDivisionSimulation() {
  const [step, setStep] = useState(0);
  
  // Use state for independent variables
  const [baseUnit, setBaseUnit] = useState(40); // Internal driver to keep numbers clean
  const [ratioA, setRatioA] = useState(3);
  const [ratioB, setRatioB] = useState(5);
  
  // Derived state to ensure clean divisions when possible, but allow sliders
  const totalAmount = baseUnit * (ratioA + ratioB);
  const totalParts = ratioA + ratioB;
  const unitValue = totalAmount / totalParts;

  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const renderMath = () => {
    const format = (n: number) => Number.isInteger(n) ? n.toString() : n.toFixed(1);
    
    if (step === 0) return <BlockMath math={`\\text{Ratio} = ${ratioA} : ${ratioB}`} />;
    if (step === 1) return <BlockMath math={`\\text{Total Parts} = ${ratioA} + ${ratioB} = ${totalParts}`} />;
    if (step === 2) return <BlockMath math={`\\text{Assigning } ${ratioA} \\text{ to A, } ${ratioB} \\text{ to B}`} />;
    if (step === 3) return <BlockMath math={`\\text{Unit} = \\frac{${totalAmount}}{${totalParts}} = ${format(unitValue)}`} />;
    if (step === 4) return (
      <div className="text-sm">
        <BlockMath math={`A = ${ratioA} \\times ${format(unitValue)} = ${format(ratioA * unitValue)}`} />
        <BlockMath math={`B = ${ratioB} \\times ${format(unitValue)} = ${format(ratioB * unitValue)}`} />
      </div>
    );
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Total Quantity", icon: <PieChart size={18}/>, color: "border-l-emerald-500 text-emerald-400 bg-emerald-900/10", body: <>We have a total amount of <span className="font-bold text-emerald-300">{totalAmount} LE</span> to divide between two people in a specific ratio.</> };
    if (step === 1) return { title: "Segmentation", icon: <Users size={18}/>, color: "border-l-blue-500 text-blue-400 bg-blue-900/10", body: <>First, we find the total number of ratio parts: {ratioA} + {ratioB} = <span className="font-bold text-blue-300">{totalParts} equal blocks</span>.</> };
    if (step === 2) return { title: "Proportional Misconception", icon: <AlertCircle size={18}/>, color: "border-l-rose-500 text-rose-400 bg-rose-900/10", body: <>Notice they do <span className="font-bold text-rose-300">not</span> get equal halves! Division does not always mean cutting exactly down the middle. It follows the ratio.</> };
    if (step === 3) return { title: "Unit Value", icon: <MoveRight size={18}/>, color: "border-l-yellow-500 text-yellow-400 bg-yellow-900/10", body: <>Divide the total amount by the total blocks to find the value of <span className="font-bold text-yellow-300">one single block</span>.</> };
    if (step === 4) return { title: "Final Shares", icon: <PieChart size={18}/>, color: "border-l-emerald-500 text-emerald-400 bg-emerald-900/10", body: <>Multiply the unit value by each person's ratio parts. Unequal but perfectly fair mathematical division!</> };
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
              Proportional Division
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Visualizing how a total divides into unequal ratio shares.
            </p>
          </div>
          <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[250px] flex justify-center shadow-inner">
             <div className="text-white text-lg">
               {renderMath()}
             </div>
          </div>
        </div>
      </div>
      
      {/* R3F Canvas */}
      <div className="flex-1 w-full h-full relative z-0 touch-none">
         <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
           <Canvas shadows camera={{ position: [0, 0, 14], fov: 45 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[10, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <ProcessVisualizer 
                 step={step} 
                 totalAmount={totalAmount}
                 ratioA={ratioA}
                 ratioB={ratioB}
              />
              
              <OrbitControls 
                enablePan={false} 
                enableZoom={false}
                maxPolarAngle={Math.PI / 2 + 0.1}
                minPolarAngle={Math.PI / 2 - 0.1}
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

        <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col gap-4 pointer-events-auto shrink-0 w-full lg:w-[420px]">
           
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
                  <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Quantity</label>
                  <span className="text-emerald-400 font-mono font-bold text-xs bg-slate-950 px-2 py-0.5 rounded border border-slate-800">{totalAmount} LE</span>
                </div>
                <input 
                  type="range" min="10" max="100" step="10" value={baseUnit} 
                  onChange={e => {setBaseUnit(Number(e.target.value)); setStep(0);}} 
                  className="w-full accent-emerald-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer" 
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                 <div className="flex flex-col gap-2 border border-blue-900/50 bg-blue-950/30 p-2 rounded-xl">
                   <div className="flex justify-between items-center text-slate-300">
                     <label className="text-[10px] font-bold uppercase tracking-wider text-blue-400">Ratio A</label>
                     <span className="text-blue-400 font-mono font-bold text-xs">{ratioA}</span>
                   </div>
                   <input 
                     type="range" min="1" max="8" step="1" value={ratioA} 
                     onChange={e => {setRatioA(Number(e.target.value)); setStep(0);}} 
                     className="w-full accent-blue-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer" 
                   />
                 </div>
                 
                 <div className="flex flex-col gap-2 border border-orange-900/50 bg-orange-950/30 p-2 rounded-xl">
                   <div className="flex justify-between items-center text-slate-300">
                     <label className="text-[10px] font-bold uppercase tracking-wider text-orange-400">Ratio B</label>
                     <span className="text-orange-400 font-mono font-bold text-xs">{ratioB}</span>
                   </div>
                   <input 
                     type="range" min="1" max="8" step="1" value={ratioB} 
                     onChange={e => {setRatioB(Number(e.target.value)); setStep(0);}} 
                     className="w-full accent-orange-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer" 
                   />
                 </div>
              </div>
           </div>

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-2">
             <button 
                onClick={handleNextStep} 
                disabled={step === 4} 
                className="bg-blue-600 hover:bg-blue-500 text-white font-bold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-blue-900/20 text-sm"
             >
                {step === 0 ? "Divide" : step === 4 ? "Completed" : "Next Step"} 
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
