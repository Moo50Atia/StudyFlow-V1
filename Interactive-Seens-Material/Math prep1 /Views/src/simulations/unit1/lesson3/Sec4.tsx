import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { AlertCircle, PieChart, Scissors, Users, Coins, CheckCircle2, RotateCcw, MoveRight } from 'lucide-react';

interface SliceProps {
  step: number;
  activeStep: number;
  thetaStart: number;
  thetaLength: number;
  color: string;
  label: string;
  value: number;
  radius?: number;
  isWife?: boolean;
}

const format = (n: number) => n.toLocaleString();

function PieSlice({ step, activeStep, thetaStart, thetaLength, color, label, value, radius = 3, isWife = false }: SliceProps) {
  const meshRef = useRef<THREE.Group>(null);
  const midAngle = thetaStart + thetaLength / 2;
  
  // In three.js cylinder geometry, theta=0 is at +Z axis, moving counter-clockwise towards +X.
  const dirX = Math.sin(midAngle);
  const dirZ = Math.cos(midAngle);

  useEffect(() => {
    if (!meshRef.current) return;

    let targetOffset = 0;
    
    if (step === 0) {
      targetOffset = 0;
    } else if (step === 1) {
      targetOffset = isWife ? 1.5 : 0;
    } else if (step === 2) {
      targetOffset = isWife ? 2 : 0.4;
    } else if (step === 3) {
      targetOffset = isWife ? 2 : 1.5;
    } else if (step === 4) {
      targetOffset = isWife ? 2.5 : 2.5;
    }

    gsap.to(meshRef.current.position, {
      x: dirX * targetOffset,
      z: dirZ * targetOffset,
      duration: 1,
      ease: 'power2.inOut',
    });

  }, [step, isWife, dirX, dirZ]);

  const showLabel = step >= activeStep || (step >= 2 && !isWife);

  return (
    <group ref={meshRef}>
      <mesh castShadow receiveShadow>
        <cylinderGeometry args={[radius, radius, 0.8, 32, 1, false, thetaStart, thetaLength]} />
        <meshStandardMaterial color={color} roughness={0.3} metalness={0.1} />
      </mesh>
      
      {/* 3D Label on top of the slice */}
      <Html 
         position={[dirX * (radius * 0.6), 0.5, dirZ * (radius * 0.6)]} 
         center 
         className={`transition-all duration-700 pointer-events-none ${showLabel ? 'opacity-100 scale-100' : 'opacity-0 scale-50'}`}
      >
         <div className="bg-slate-900/90 border border-slate-700 text-white px-3 py-1.5 rounded-xl shadow-2xl flex flex-col items-center whitespace-nowrap backdrop-blur-md">
            <span className="text-[10px] uppercase tracking-widest font-bold text-slate-400 mb-0.5">{label}</span>
            <span className="font-mono font-bold" style={{ color: color }}>{format(value)} LE</span>
         </div>
      </Html>
    </group>
  );
}

interface ProcessProps {
  step: number;
  totalAmount: number;
}

function ProcessVisualizer({ step, totalAmount }: ProcessProps) {
  // Math Constants
  const wifeShare = totalAmount / 8;
  const remainder = totalAmount - wifeShare;
  
  const partsDaughter = 1;
  const partsSon1 = 2;
  const partsSon2 = 2;
  const totalChildrenParts = partsDaughter + partsSon1 + partsSon2; // 5
  
  const unitValue = remainder / totalChildrenParts;
  const daughterShare = unitValue * partsDaughter;
  const sonShare = unitValue * partsSon1; // same for both sons

  // Angle Calculations
  const fullCircle = Math.PI * 2;
  const tWife = fullCircle * (1 / 8);
  const tDaughter = fullCircle * (7 / 8) * (1 / 5);
  const tSon1 = fullCircle * (7 / 8) * (2 / 5);
  const tSon2 = fullCircle * (7 / 8) * (2 / 5);

  let currentAngle = 0;
  
  const wifeAngleStart = currentAngle;
  currentAngle += tWife;
  
  const daughterAngleStart = currentAngle;
  currentAngle += tDaughter;
  
  const son1AngleStart = currentAngle;
  currentAngle += tSon1;
  
  const son2AngleStart = currentAngle;

  // Remainder marker (ghost pie)
  const remRef = useRef<THREE.Group>(null);
  useEffect(() => {
     if(!remRef.current) return;
     if (step === 2) {
       gsap.to(remRef.current.position, {y: -0.5, duration: 1, ease: 'power2.inOut'});
       gsap.to(remRef.current.scale, {x: 1, y: 1, z: 1, duration: 1, ease: 'power2.inOut'});
     } else {
       gsap.to(remRef.current.scale, {x: 0.8, y: 0, z: 0.8, duration: 0.5});
     }
  }, [step]);

  return (
    <group rotation={[0.2, Math.PI, 0]}>
      {/* Total Estate Base Plate - Visible in Step 0 */}
      <mesh position={[0, -0.6, 0]} receiveShadow>
         <cylinderGeometry args={[3.2, 3.2, 0.1, 64]} />
         <meshStandardMaterial color="#1e293b" />
      </mesh>

      <PieSlice 
         step={step} activeStep={1} isWife={true}
         thetaStart={wifeAngleStart} thetaLength={tWife} 
         color="#c084fc" label="Wife (1/8)" value={wifeShare} 
      />
      
      <PieSlice 
         step={step} activeStep={4} 
         thetaStart={daughterAngleStart} thetaLength={tDaughter} 
         color="#ec4899" label="Daughter" value={daughterShare} 
      />
      
      <PieSlice 
         step={step} activeStep={4} 
         thetaStart={son1AngleStart} thetaLength={tSon1} 
         color="#06b6d4" label="Son 1" value={sonShare} 
      />
      
      <PieSlice 
         step={step} activeStep={4} 
         thetaStart={son2AngleStart} thetaLength={tSon2} 
         color="#3b82f6" label="Son 2" value={sonShare} 
      />
    </group>
  );
}

export default function EstateInheritanceSimulation() {
  const [step, setStep] = useState(0);
  const [totalAmount, setTotalAmount] = useState(600000);

  // Computed
  const wifeShare = totalAmount / 8;
  const remainder = totalAmount - wifeShare;
  const unitValue = remainder / 5;
  const daughterShare = unitValue;
  const sonShare = unitValue * 2;

  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`\\text{Total Estate} = ${format(totalAmount)}`} />;
    if (step === 1) return <BlockMath math={`\\text{Wife (1/8)} = \\frac{${format(totalAmount)}}{8} = ${format(wifeShare)}`} />;
    if (step === 2) return <BlockMath math={`\\text{Remainder} = ${format(totalAmount)} - ${format(wifeShare)} = ${format(remainder)}`} />;
    if (step === 3) return <BlockMath math={`\\text{Unit} = \\frac{${format(remainder)}}{2+2+1} = \\frac{${format(remainder)}}{5} = ${format(unitValue)}`} />;
    if (step === 4) return (
       <div className="text-sm">
         <BlockMath math={`\\text{Daughter} = ${format(daughterShare)}, \\quad \\text{Son} = 2 \\times ${format(unitValue)} = ${format(sonShare)}`} />
       </div>
    );
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Total Estate", icon: <PieChart size={18}/>, color: "border-l-emerald-500 text-emerald-400 bg-emerald-900/10", body: <>We have a total inheritance estate of <span className="font-bold text-emerald-300">{format(totalAmount)} LE</span> to be distributed among a wife, one daughter, and two sons.</> };
    if (step === 1) return { title: "Statutory Deduction (CRITICAL)", icon: <AlertCircle size={18}/>, color: "border-l-rose-500 text-rose-400 bg-rose-900/10", body: <>A major misconception is dividing the total among the children first. <span className="font-bold text-rose-300">WRONG!</span> The spouse's legal share (1/8) MUST be deducted from the total before anything else.</> };
    if (step === 2) return { title: "The Remainder", icon: <Scissors size={18}/>, color: "border-l-yellow-500 text-yellow-400 bg-yellow-900/10", body: <>After deducting the wife's {format(wifeShare)} LE, we are left with a remainder of <span className="font-bold text-yellow-300">{format(remainder)} LE</span>. THIS is what the children will split.</> };
    if (step === 3) return { title: "Ratio Parts", icon: <Users size={18}/>, color: "border-l-blue-500 text-blue-400 bg-blue-900/10", body: <>By inheritance rules, a son receives twice the share of a daughter (2:1). With 2 sons (4 parts) and 1 daughter (1 part), we have <span className="font-bold text-blue-300">5 total parts</span>.</> };
    if (step === 4) return { title: "Final Distribution", icon: <CheckCircle2 size={18}/>, color: "border-l-emerald-500 text-emerald-400 bg-emerald-900/10", body: <>The remainder is divided by 5 to find the unit value ({format(unitValue)}). The daughter gets 1 unit, and each son gets 2 units. The entire pie is now fully allocated!</> };
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
              Estate Time Evolution
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Correcting the sequence of proportional inheritance deductions.
            </p>
          </div>
          <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[280px] flex justify-center shadow-inner">
             <div className="text-white text-lg">
               {renderMath()}
             </div>
          </div>
        </div>
      </div>
      
      {/* R3F Canvas */}
      <div className="flex-1 w-full h-full relative z-0 touch-none">
         <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
           <Canvas shadows camera={{ position: [0, 8, 10], fov: 50 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[5, 20, 10]} intensity={1.2} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <ProcessVisualizer 
                 step={step} 
                 totalAmount={totalAmount}
              />
              
              <OrbitControls 
                enablePan={false} 
                enableZoom={false}
                maxPolarAngle={Math.PI / 2 - 0.1}
                minPolarAngle={Math.PI / 4}
                maxAzimuthAngle={Math.PI / 4}
                minAzimuthAngle={-Math.PI / 4}
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

           {/* Amount Slider */}
           <div className="flex flex-col gap-2 mt-2">
              <div className="flex justify-between items-center text-slate-300">
                <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Estate Size</label>
                <span className="font-mono font-bold text-xs bg-slate-950 px-2 py-0.5 rounded border border-slate-800 text-emerald-400">
                   {format(totalAmount)} LE
                </span>
              </div>
              <input 
                type="range" min="200000" max="1600000" step="40000" value={totalAmount} 
                onChange={e => {setTotalAmount(Number(e.target.value)); setStep(0);}} 
                className="w-full h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer accent-emerald-500" 
              />
              <p className="text-[9px] text-slate-500 text-center mt-1">Adjust to see calculations scale perfectly.</p>
           </div>

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-1">
             <button 
                onClick={handleNextStep} 
                disabled={step === 4} 
                className="bg-blue-600 hover:bg-blue-500 text-white font-bold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-blue-900/20 text-sm"
             >
                {step === 0 ? "Deduct Spouse" : step === 4 ? "Allocated" : "Next Step"} 
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
