import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Html, Line } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, Zap, Combine, SlidersHorizontal, RotateCcw, AlertTriangle } from 'lucide-react';

interface ProcessProps {
  step: number;
}

// ---------------------------------------------------------
// REUSABLE 3D COMPONENTS
// ---------------------------------------------------------
function MathBlock({ 
    text, color, position, scale = 1, groupRef, opacity = 1 
  }: { 
    text: string, color: string, position: [number, number, number], scale?: number, groupRef?: React.RefObject<THREE.Group>, opacity?: number 
  }) {
  return (
    <group ref={groupRef} position={position} scale={[scale, scale, scale]}>
      <mesh castShadow receiveShadow position={[0, 0, 0.25]}>
        <boxGeometry args={[1.6, 1.2, 0.5]} />
        <meshStandardMaterial color={color} roughness={0.3} metalness={0.2} transparent opacity={opacity} />
      </mesh>
      <Text position={[0, 0, 0.51]} fontSize={0.7} color="#ffffff" outlineWidth={0.04} outlineColor="#000" fillOpacity={opacity}>
        {text}
      </Text>
    </group>
  );
}

function TubeLink({ start, end, opacity }: { start: [number, number, number], end: [number, number, number], opacity: number }) {
  const points = useMemo(() => [new THREE.Vector3(...start), new THREE.Vector3(...end)], [start, end]);
  return (
    <Line
      points={points}
      color="#94a3b8"
      lineWidth={4}
      transparent
      opacity={opacity}
    />
  );
}

// ---------------------------------------------------------
// SCENE ZONES
// ---------------------------------------------------------

function MultiplicationChamber({ step }: { step: number }) {
  const neg4Ref = useRef<THREE.Group>(null);
  const neg6Ref = useRef<THREE.Group>(null);
  const pos24Ref = useRef<THREE.Group>(null);
  const matrixHighlightRef = useRef<THREE.Mesh>(null);
  
  useEffect(() => {
    const dur = 1.0;

    if (step === 0) {
      // Reset State
      gsap.to(neg4Ref.current!.position, { x: -4, duration: dur });
      gsap.to(neg4Ref.current!.scale, { x: 1, y: 1, z: 1, duration: dur });
      gsap.to(neg4Ref.current!.rotation, { z: 0, duration: dur });

      gsap.to(neg6Ref.current!.position, { x: 4, duration: dur });
      gsap.to(neg6Ref.current!.scale, { x: 1, y: 1, z: 1, duration: dur });
      gsap.to(neg6Ref.current!.rotation, { z: 0, duration: dur });

      gsap.to(pos24Ref.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
      gsap.to((matrixHighlightRef.current!.material as THREE.MeshBasicMaterial), { opacity: 0, duration: 0.3 });
    } 
    else if (step >= 1) {
      // Collision
      const tl = gsap.timeline();
      tl.to(neg4Ref.current!.position, { x: -0.8, duration: 0.6, ease: "power2.in" }, 0);
      tl.to(neg6Ref.current!.position, { x: 0.8, duration: 0.6, ease: "power2.in" }, 0);
      
      // Impact
      tl.to(neg4Ref.current!.scale, { x: 0.1, y: 1.5, z: 0.1, duration: 0.1 }, 0.6);
      tl.to(neg6Ref.current!.scale, { x: 0.1, y: 1.5, z: 0.1, duration: 0.1 }, 0.6);
      tl.to(neg4Ref.current!.scale, { x: 0, y: 0, z: 0, duration: 0.1 }, 0.7);
      tl.to(neg6Ref.current!.scale, { x: 0, y: 0, z: 0, duration: 0.1 }, 0.7);

      // Result +24
      tl.to(pos24Ref.current!.scale, { x: 1.2, y: 1.2, z: 1.2, duration: 0.6, ease: "back.out(1.5)" }, 0.7);
      
      // Matrix Highlight
      tl.to((matrixHighlightRef.current!.material as THREE.MeshBasicMaterial), { opacity: 0.3, duration: 0.5 }, 0.8);
    }
  }, [step]);

  return (
    <group position={[0, 0, 0]}>
      
      {/* 2x2 Sign Matrix (Background) */}
      <group position={[0, 4, -2]}>
         <mesh position={[0, 0, -0.1]}>
           <boxGeometry args={[6, 4.5, 0.2]} />
           <meshStandardMaterial color="#0f172a" transparent opacity={0.6} />
         </mesh>
         
         <Text position={[-1.5, 1.2, 0]} fontSize={0.5} color="#cbd5e1">(+) × (+) = (+)</Text>
         <Text position={[1.5, 1.2, 0]} fontSize={0.5} color="#cbd5e1">(+) × (-) = (-)</Text>
         
         {/* Highlight Box for (-) x (-) */}
         <mesh ref={matrixHighlightRef} position={[-1.5, -1.2, 0.05]}>
            <boxGeometry args={[2.8, 1.0, 0.1]} />
            <meshBasicMaterial color="#10b981" transparent opacity={0} />
         </mesh>
         <Text position={[-1.5, -1.2, 0.1]} fontSize={0.5} color="#10b981">(-) × (-) = (+)</Text>
         
         <Text position={[1.5, -1.2, 0]} fontSize={0.5} color="#cbd5e1">(-) × (+) = (-)</Text>
      </group>

      {/* Chamber Floor */}
      <mesh position={[0, -1.5, 0]} receiveShadow>
        <boxGeometry args={[12, 0.2, 3]} />
        <meshStandardMaterial color="#1e293b" />
      </mesh>
      
      {/* Dynamic Blocks */}
      <MathBlock groupRef={neg4Ref} text="-4" color="#ef4444" position={[-4, 0, 0]} />
      <MathBlock groupRef={neg6Ref} text="-6" color="#ef4444" position={[4, 0, 0]} />
      <MathBlock groupRef={pos24Ref} text="+24" color="#10b981" position={[0, 0, 0]} scale={0} />

      {step >= 1 && (
         <Html position={[0, -2.5, 0]} center className="pointer-events-none">
            <div className="bg-emerald-950/90 border border-emerald-500 p-2 rounded shadow-[0_0_15px_rgba(16,185,129,0.4)] text-emerald-300 text-sm font-bold whitespace-nowrap">
               Positive Product Generated
            </div>
         </Html>
      )}
    </group>
  );
}

function DistributivePipeline({ step, valX, valY }: { step: number, valX: number, valY: number }) {
  const baseGroupRef = useRef<THREE.Group>(null);
  const branchLeftRef = useRef<THREE.Group>(null);
  const branchRightRef = useRef<THREE.Group>(null);
  const resultRef = useRef<THREE.Group>(null);
  const [lineOpacity, setLineOpacity] = useState(0);

  useEffect(() => {
    const dur = 1.0;

    if (step < 3) {
      // Base State (Unbranched)
      gsap.to(baseGroupRef.current!.position, { y: 2, duration: dur });
      gsap.to(branchLeftRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
      gsap.to(branchRightRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
      gsap.to(resultRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
      gsap.to({ val: 0 }, { val: 0, duration: dur, onUpdate: function() { setLineOpacity(this.targets()[0].val) } });
    } else {
      // Branched State
      gsap.to(baseGroupRef.current!.position, { y: 4.5, duration: dur, ease: "power2.out" });
      gsap.to(branchLeftRef.current!.scale, { x: 1, y: 1, z: 1, duration: dur, ease: "back.out(1.2)", delay: 0.3 });
      gsap.to(branchRightRef.current!.scale, { x: 1, y: 1, z: 1, duration: dur, ease: "back.out(1.2)", delay: 0.5 });
      gsap.to(resultRef.current!.scale, { x: 1, y: 1, z: 1, duration: dur, ease: "back.out(1.2)", delay: 1.0 });
      gsap.to({ val: 0 }, { val: 1, duration: dur, delay: 0.8, onUpdate: function() { setLineOpacity(this.targets()[0].val) } });
    }
  }, [step]);

  return (
    <group position={[14, 0, 0]}>
      {/* Central Pipeline Frame */}
      <mesh position={[0, 0, -1]}>
        <boxGeometry args={[14, 10, 0.2]} />
        <meshStandardMaterial color="#0f172a" transparent opacity={0.5} />
      </mesh>

      {/* Base Expression: 3 ( 10 + 2 ) */}
      <group ref={baseGroupRef} position={[0, 2, 0]}>
         <MathBlock text="3" color="#3b82f6" position={[-2, 0, 0]} />
         <Text position={[-0.8, 0, 0.2]} fontSize={1} color="#cbd5e1">(</Text>
         <MathBlock text={valX.toString()} color="#8b5cf6" position={[0, 0, 0]} />
         <Text position={[1, 0, 0.2]} fontSize={0.8} color="#cbd5e1">+</Text>
         <MathBlock text={valY.toString()} color="#f59e0b" position={[2, 0, 0]} />
         <Text position={[3.2, 0, 0.2]} fontSize={1} color="#cbd5e1">)</Text>
      </group>

      {/* Branch Lines */}
      <TubeLink start={[-1, 3.5, 0]} end={[-3, 1, 0]} opacity={lineOpacity} />
      <TubeLink start={[1, 3.5, 0]} end={[3, 1, 0]} opacity={lineOpacity} />
      <TubeLink start={[-3, -0.5, 0]} end={[0, -2.5, 0]} opacity={lineOpacity} />
      <TubeLink start={[3, -0.5, 0]} end={[0, -2.5, 0]} opacity={lineOpacity} />

      {/* Left Branch Output: 3 * valX */}
      <group ref={branchLeftRef} position={[-3.5, 1, 0]} scale={[0,0,0]}>
         <MathBlock text={`3 × ${valX}`} color="#4f46e5" position={[0, 0, 0]} />
         <MathBlock text={(3 * valX).toString()} color="#4338ca" position={[0, -1.5, 0]} />
      </group>

      {/* Right Branch Output: 3 * valY */}
      <group ref={branchRightRef} position={[3.5, 1, 0]} scale={[0,0,0]}>
         <MathBlock text={`3 × ${valY}`} color="#d97706" position={[0, 0, 0]} />
         <MathBlock text={(3 * valY).toString()} color="#b45309" position={[0, -1.5, 0]} />
      </group>

      {/* Final Result */}
      <group ref={resultRef} position={[0, -3.5, 0]} scale={[0,0,0]}>
         <Text position={[0, 1, 0.2]} fontSize={0.8} color="#cbd5e1">+</Text>
         <MathBlock text={(3 * valX + 3 * valY).toString()} color="#10b981" position={[0, 0, 0]} scale={1.2} />
      </group>
    </group>
  );
}

// ---------------------------------------------------------
// MAIN SCENE CONTROLLER
// ---------------------------------------------------------
function Scene({ step, valX, valY }: ProcessProps & { valX: number, valY: number }) {
  const { camera } = useThree();
  const controlsRef = useRef<any>(null);

  useEffect(() => {
    const targets = [
      { camX: 0, camY: 3, camZ: 14, tarX: 0, tarY: 0 },    // 0: Sign Matrix Base
      { camX: 0, camY: 3, camZ: 14, tarX: 0, tarY: 0 },    // 1: Chamber Collision
      { camX: 14, camY: 1, camZ: 16, tarX: 14, tarY: 1 },  // 2: Distributive Base
      { camX: 14, camY: 1, camZ: 16, tarX: 14, tarY: 1 },  // 3: Branching Expand
      { camX: 7, camY: 3, camZ: 22, tarX: 7, tarY: 0 },    // 4: Sandbox View (Both visible)
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
        maxAzimuthAngle={0.4}
        minAzimuthAngle={-0.4}
      />
      <MultiplicationChamber step={step} />
      <DistributivePipeline step={step} valX={valX} valY={valY} />
    </group>
  );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function MultiplicationSimulation() {
  const [step, setStep] = useState(0);
  const [sliderX, setSliderX] = useState(10);
  const [sliderY, setSliderY] = useState(2);

  // Lock values during steps 2 and 3
  const valX = step < 4 ? 10 : sliderX;
  const valY = step < 4 ? 2 : sliderY;

  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const renderMath = () => {
    if (step === 0 || step === 1) return <BlockMath math={`(-4) \\times (-6) = 24`} />;
    if (step === 2) return <BlockMath math={`3(${valX} + ${valY})`} />;
    if (step >= 3) return <BlockMath math={`3(${valX}) + 3(${valY}) = ${3*valX + 3*valY}`} />;
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Sign Multiplication Matrix", icon: <MoveRight size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>When multiplying integers, we follow specific sign rules. Let's send two negative numbers, <span className="font-mono text-red-400">-4</span> and <span className="font-mono text-red-400">-6</span>, into the chamber.</> };
    if (step === 1) return { title: "Negative × Negative Misconception", icon: <AlertTriangle size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> Multiplying two negatives does <b>NOT</b> result in a negative. The collision forces the negatives to cancel each other out, producing a strictly <span className="font-bold text-emerald-400">Positive (+24)</span> product.</> };
    if (step === 2) return { title: "The Distributive Pipeline", icon: <Zap size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>Now, let's look at multiplication involving parentheses: <span className="font-mono">3(10 + 2)</span>. The number outside the bracket is a multiplier waiting to be applied.</> };
    if (step === 3) return { title: "Branching Multiplication", icon: <Combine size={18}/>, color: "border-l-yellow-500 text-yellow-300 bg-yellow-900/40", body: <>The Distributive Property forces the <span className="font-mono text-blue-400">3</span> to branch out and independently multiply <b>every</b> term inside the brackets. It generates <span className="font-mono">30</span> and <span className="font-mono">6</span>, which are then summed.</> };
    if (step === 4) return { title: "Interactive Properties", icon: <SlidersHorizontal size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>We can now see both properties operating flawlessly. Use the sliders to change the bracketed terms and watch the distributive pipeline perfectly recalculate the dual branches in real-time.</> };
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
              Integer Multiplication
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Sign rules and the Distributive Property visually unrolled.
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
           <Canvas shadows camera={{ position: [0, 3, 14], fov: 45 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <Scene step={step} valX={valX} valY={valY} />
              
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
                 <span>Setup</span>
                 <span className="text-emerald-400">Collide</span>
                 <span className="text-purple-400">Distribute</span>
                 <span className="text-yellow-400">Branch</span>
                 <span className="text-blue-400">Sandbox</span>
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
                    <span>Term 1 (x)</span>
                    <span className="text-purple-400 font-mono">{sliderX}</span>
                  </label>
                  <input 
                     type="range" min="1" max="20" step="1" 
                     value={sliderX} onChange={(e) => setSliderX(parseInt(e.target.value))}
                     className="w-full accent-purple-500 mt-2"
                  />
               </div>
               <div>
                  <label className="text-xs font-bold text-slate-400 flex justify-between">
                    <span>Term 2 (y)</span>
                    <span className="text-yellow-400 font-mono">{sliderY}</span>
                  </label>
                  <input 
                     type="range" min="1" max="20" step="1" 
                     value={sliderY} onChange={(e) => setSliderY(parseInt(e.target.value))}
                     className="w-full accent-yellow-500 mt-2"
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
                {step === 0 ? "Multiply Negatives" : 
                 step === 1 ? "View Distributive" : 
                 step === 2 ? "Branch Multiplier" : 
                 step === 3 ? "Unlock Sandbox" : 
                 "Freestyle Mode"} 
                {step < 4 && <MoveRight size={16} />}
             </button>
             <button 
                onClick={() => {
                   setStep(0);
                   setSliderX(10);
                   setSliderY(2);
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
