import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { OrbitControls, Html, Environment, Text } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { InlineMath, BlockMath } from 'react-katex';
import { AlertTriangle, ArrowRight, RotateCcw, Calculator, Sparkles, MoveRight } from 'lucide-react';

// --- 3D Scene Components ---

interface VisualizerProps {
  step: number;
  realKm: number;
}

function ProcessVisualizer({ step, realKm }: VisualizerProps) {
  const drawBoxRef = useRef<THREE.Group>(null);
  const realBoxRef = useRef<THREE.Group>(null);
  const particle1Ref = useRef<THREE.Group>(null);
  const particle2Ref = useRef<THREE.Group>(null);
  const particle3Ref = useRef<THREE.Group>(null);
  const dividerRef = useRef<THREE.Mesh>(null);

  const [displayState, setDisplayState] = useState({
     realLabel: `${realKm} km`,
     drawLabel: `4 cm`,
     drawBoxColor: '#3b82f6',
     realBoxColor: '#f97316',
     showDivider: false
  });

  // Step 0: Reset & Idle
  useEffect(() => {
     if (step === 0 && drawBoxRef.current && realBoxRef.current && particle1Ref.current && particle2Ref.current && particle3Ref.current && dividerRef.current) {
        gsap.killTweensOf([drawBoxRef.current.position, realBoxRef.current.position, drawBoxRef.current.scale, realBoxRef.current.scale]);
        
        drawBoxRef.current.position.set(-4.5, 0, 0);
        realBoxRef.current.position.set(4.5, 0, 0);
        drawBoxRef.current.scale.set(1, 1, 1);
        realBoxRef.current.scale.set(1, 1, 1);
        
        particle1Ref.current.visible = false;
        particle2Ref.current.visible = false;
        particle3Ref.current.visible = false;
        dividerRef.current.scale.x = 0;
        
        setDisplayState({
           realLabel: `${realKm} km`,
           drawLabel: `4 cm`,
           drawBoxColor: '#3b82f6', // blue-500
           realBoxColor: '#f97316', // orange-500
           showDivider: false
        });
     }
  }, [step, realKm]);

  // Step 1: Unit Conversion Particle
  useEffect(() => {
     if (step === 1 && particle1Ref.current && realBoxRef.current) {
         particle1Ref.current.visible = true;
         particle1Ref.current.position.set(4.5, 6, 0);
         particle1Ref.current.scale.set(0.1, 0.1, 0.1);
         
         gsap.to(particle1Ref.current.scale, { x: 1, y: 1, z: 1, duration: 0.5 });
         gsap.to(particle1Ref.current.position, {
            y: 0,
            duration: 1.2,
            ease: "back.in(1.5)",
            delay: 0.3,
            onComplete: () => {
               particle1Ref.current!.visible = false;
               
               // Impact Pulse
               gsap.to(realBoxRef.current!.scale, {
                  x: 1.15, y: 1.15, z: 1.15, duration: 0.15, yoyo: true, repeat: 1
               });
               
               setDisplayState(prev => ({
                  ...prev,
                  realLabel: `${(realKm * 100000).toLocaleString()} cm`,
                  realBoxColor: '#10b981' // emerald-500 (matched units)
               }));
            }
         });
     }
  }, [step, realKm]);

  // Step 2: Fractional Alignment
  useEffect(() => {
     if (step === 2 && drawBoxRef.current && realBoxRef.current && dividerRef.current) {
         gsap.to(drawBoxRef.current.position, { x: 0, y: 2.5, duration: 1.2, ease: "power3.inOut" });
         gsap.to(realBoxRef.current.position, { x: 0, y: -2.5, duration: 1.2, ease: "power3.inOut" });
         
         gsap.to(dividerRef.current.scale, {
            x: 1,
            duration: 1,
            delay: 0.8,
            ease: "elastic.out(1, 0.7)",
            onStart: () => setDisplayState(prev => ({ ...prev, showDivider: true }))
         });
     }
  }, [step]);

  // Step 3: Simplification Division
  useEffect(() => {
     if (step === 3 && particle2Ref.current && particle3Ref.current && drawBoxRef.current && realBoxRef.current) {
         particle2Ref.current.visible = true;
         particle3Ref.current.visible = true;
         particle2Ref.current.position.set(-8, 2.5, 0);
         particle3Ref.current.position.set(-8, -2.5, 0);
         
         gsap.to([particle2Ref.current.position, particle3Ref.current.position], {
            x: 0,
            duration: 1.2,
            ease: "power2.in",
            onComplete: () => {
               particle2Ref.current!.visible = false;
               particle3Ref.current!.visible = false;
               
               gsap.to(drawBoxRef.current!.scale, { x: 0.8, y: 0.8, z: 0.8, duration: 0.2, yoyo: true, repeat: 1 });
               gsap.to(realBoxRef.current!.scale, { x: 0.9, y: 0.9, z: 0.9, duration: 0.2, yoyo: true, repeat: 1 });
               
               setDisplayState(prev => ({
                  ...prev,
                  drawLabel: `1`,
                  realLabel: `${((realKm * 100000) / 4).toLocaleString()}`
               }));
            }
         });
     }
  }, [step, realKm]);

  return (
     <group>
        {/* Drawing Length Block */}
        <group ref={drawBoxRef} position={[-4.5, 0, 0]}>
           <mesh castShadow receiveShadow>
              <boxGeometry args={[4.5, 2, 2]} />
              <meshStandardMaterial color={displayState.drawBoxColor} roughness={0.3} metalness={0.2} />
           </mesh>
           <Text position={[0, 0, 1.01]} fontSize={0.7} color="white" anchorX="center" anchorY="middle" outlineWidth={0.02} outlineColor="#000000">
              {displayState.drawLabel}
           </Text>
        </group>

        {/* Real Length Block */}
        <group ref={realBoxRef} position={[4.5, 0, 0]}>
           <mesh castShadow receiveShadow>
              <boxGeometry args={[7.5, 2, 2]} />
              <meshStandardMaterial color={displayState.realBoxColor} roughness={0.3} metalness={0.2} />
           </mesh>
           <Text position={[0, 0, 1.01]} fontSize={0.7} color="white" anchorX="center" anchorY="middle" outlineWidth={0.02} outlineColor="#000000">
              {displayState.realLabel}
           </Text>
        </group>

        {/* Fractional Divider */}
        <mesh ref={dividerRef} position={[0, 0, 0]} scale={[0, 1, 1]}>
           <boxGeometry args={[8, 0.1, 0.1]} />
           <meshStandardMaterial color="#ffffff" emissive="#ffffff" emissiveIntensity={0.5} />
        </mesh>

        {/* Particle: Multiply */}
        <group ref={particle1Ref} visible={false}>
           <mesh>
              <sphereGeometry args={[0.5, 32, 32]} />
              <meshStandardMaterial color="#fbbf24" emissive="#f59e0b" emissiveIntensity={1} />
           </mesh>
           <Text position={[0, 1.2, 0]} fontSize={0.6} color="#fbbf24" outlineWidth={0.02} outlineColor="#000">
              × 100,000
           </Text>
        </group>

        {/* Particle: Divide Numerator */}
        <group ref={particle2Ref} visible={false}>
           <mesh>
              <sphereGeometry args={[0.4, 32, 32]} />
              <meshStandardMaterial color="#a78bfa" emissive="#8b5cf6" emissiveIntensity={1} />
           </mesh>
           <Text position={[0, 1, 0]} fontSize={0.6} color="#a78bfa" outlineWidth={0.02} outlineColor="#000">
              ÷ 4
           </Text>
        </group>
        
        {/* Particle: Divide Denominator */}
        <group ref={particle3Ref} visible={false}>
           <mesh>
              <sphereGeometry args={[0.4, 32, 32]} />
              <meshStandardMaterial color="#a78bfa" emissive="#8b5cf6" emissiveIntensity={1} />
           </mesh>
           <Text position={[0, -1, 0]} fontSize={0.6} color="#a78bfa" outlineWidth={0.02} outlineColor="#000">
              ÷ 4
           </Text>
        </group>
     </group>
  )
}

// --- Main Application Component ---

export default function UnitConversionSimulation() {
  const [step, setStep] = useState(0);
  const [realKm, setRealKm] = useState(80);

  const handleNextStep = () => {
    if (step < 3) setStep(s => s + 1);
  };

  const renderMath = () => {
    if (step === 0) {
       return <BlockMath math={`\\text{Ratio} = 4 \\text{ cm} : ${realKm} \\text{ km}`} />;
    } else if (step === 1) {
       return <BlockMath math={`\\text{Ratio} = 4 \\text{ cm} : (${realKm} \\times 100,000) \\text{ cm}`} />;
    } else if (step === 2) {
       return <BlockMath math={`\\text{Fraction} = \\frac{4}{${(realKm * 100000).toLocaleString()}}`} />;
    } else if (step === 3) {
       return <BlockMath math={`\\text{Scale} = \\frac{1}{${((realKm * 100000)/4).toLocaleString()}}`} />;
    }
  };

  const getAlertContent = () => {
    if (step === 0) {
       return {
          title: "Critical Error Trap",
          icon: <AlertTriangle size={18} />,
          color: "border-l-rose-500 text-rose-400 bg-rose-900/10",
          body: <>If you write <InlineMath math={`4 : ${realKm}`} /> right now, you get the wrong scale. Why? Because <span className="text-blue-400 font-semibold">cm</span> and <span className="text-orange-400 font-semibold">km</span> are completely unmatched sizes. You MUST unify them first!</>
       }
    } else if (step === 1) {
       return {
          title: "Unit Conversion",
          icon: <Sparkles size={18} />,
          color: "border-l-emerald-500 text-emerald-400 bg-emerald-900/10",
          body: <>We multiply by <span className="font-mono text-emerald-300">100,000</span> to convert kilometers to centimeters. Now both sides of the ratio represent the exact same unit size!</>
       }
    } else if (step >= 2) {
       return {
          title: "Simplification",
          icon: <Calculator size={18} />,
          color: "border-l-blue-500 text-blue-400 bg-blue-900/10",
          body: <>With perfectly matched units, we align them into a fraction and divide both terms by 4 to extract the true, unified scale ratio.</>
       }
    }
    return { title: "", icon: null, color: "", body: <></> };
  };

  const alertContent = getAlertContent();

  return (
    <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
      
      {/* Header Overlay */}
      <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
        <div className="bg-slate-900/80 backdrop-blur-md border border-slate-700 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
          <div>
            <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
              Unit Conversion Process
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Visualizing why units must match before calculating scale.
            </p>
          </div>

          {/* Math HUD */}
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
           <Canvas shadows camera={{ position: [0, 0, 16], fov: 40 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[10, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <ProcessVisualizer step={step} realKm={realKm} />
              
              <OrbitControls 
                enablePan={false} 
                enableZoom={false}
                maxPolarAngle={Math.PI / 2 + 0.2}
                minPolarAngle={Math.PI / 2 - 0.2}
                maxAzimuthAngle={0.2}
                minAzimuthAngle={-0.2}
              />
           </Canvas>
         </Suspense>
      </div>

      {/* Controls Overlay */}
      <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
        
        {/* Misconception Corrector */}
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

        {/* Interactive Controls Panel */}
        <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col gap-5 pointer-events-auto shrink-0 w-full lg:w-[400px]">
           
           {/* Progress Indicator */}
           <div className="flex gap-1.5">
             {[0, 1, 2, 3].map(s => (
                <div key={s} className={`h-1.5 flex-1 rounded-full transition-colors duration-500 ${s <= step ? 'bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.6)]' : 'bg-slate-700'}`} />
             ))}
           </div>

           {/* Input Slider */}
           <div className="flex flex-col gap-2">
             <div className="flex justify-between items-center text-slate-300">
               <label className="text-xs font-semibold uppercase tracking-wider flex items-center gap-2">
                 Real Length
               </label>
               <span className="bg-slate-950 border border-slate-800 px-3 py-1 rounded-lg text-orange-400 font-mono font-bold text-sm shadow-inner">
                 {realKm} km
               </span>
             </div>
             <input 
               type="range" 
               min="10" max="200" step="10"
               value={realKm}
               onChange={(e) => {
                  setRealKm(Number(e.target.value));
                  setStep(0);
               }}
               className="w-full accent-blue-500 h-2 bg-slate-800 rounded-lg appearance-none cursor-pointer"
             />
             <div className="flex justify-between text-[10px] text-slate-500 font-mono mt-1">
                <span>10 km</span>
                <span>200 km</span>
             </div>
           </div>

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-1">
             <button 
                onClick={handleNextStep} 
                disabled={step === 3} 
                className="bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-blue-900/20"
             >
                {step === 0 ? "Start Conversion" : step === 3 ? "Complete" : "Next Step"} 
                {step < 3 && <MoveRight size={18} />}
             </button>
             <button 
                onClick={() => setStep(0)} 
                className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700"
             >
                <RotateCcw size={18} /> Reset
             </button>
           </div>
           
        </div>
      </div>
    </div>
  );
}
