import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, ShieldAlert, CheckCircle2, SlidersHorizontal, RotateCcw, Activity } from 'lucide-react';

interface ProcessProps {
  step: number;
}

// ---------------------------------------------------------
// REUSABLE 3D COMPONENTS
// ---------------------------------------------------------
function NumberLine() {
  const ticks = [];
  for (let i = -10; i <= 10; i++) {
    const isZero = i === 0;
    ticks.push(
      <group key={i} position={[i, 0, 0]}>
        <mesh position={[0, 0, 0]}>
          <boxGeometry args={[0.06, 0.6, 0.06]} />
          <meshStandardMaterial color={isZero ? "#fbbf24" : "#94a3b8"} />
        </mesh>
        <Text position={[0, -0.6, 0]} fontSize={0.4} color={isZero ? "#fbbf24" : "#cbd5e1"}>
          {i.toString()}
        </Text>
      </group>
    );
  }
  return (
    <group>
      {/* Main Axis Line */}
      <mesh position={[0, 0, 0]}>
        <boxGeometry args={[22, 0.05, 0.05]} />
        <meshStandardMaterial color="#64748b" />
      </mesh>
      {ticks}
    </group>
  );
}

function AnimatedVector({ startX, deltaX, yOffset, labelText }: { startX: number, deltaX: number, yOffset: number, labelText: string }) {
  const groupRef = useRef<THREE.Group>(null);
  const shaftRef = useRef<THREE.Mesh>(null);
  const headRef = useRef<THREE.Mesh>(null);
  const labelRef = useRef<any>(null);

  const current = useRef({ startX, deltaX });

  useFrame((_, dt) => {
    current.current.startX = THREE.MathUtils.damp(current.current.startX, startX, 4, dt);
    current.current.deltaX = THREE.MathUtils.damp(current.current.deltaX, deltaX, 4, dt);
    
    const s = current.current.startX;
    const d = current.current.deltaX;
    const len = Math.abs(d);
    const dir = d >= 0 ? 1 : -1;

    if (groupRef.current && shaftRef.current && headRef.current) {
       groupRef.current.position.set(s, yOffset, 0);
       
       if (len < 0.1) {
          shaftRef.current.visible = false;
          headRef.current.visible = false;
          if (labelRef.current) labelRef.current.visible = false;
       } else {
          shaftRef.current.visible = true;
          headRef.current.visible = true;
          if (labelRef.current) labelRef.current.visible = true;

          const shaftLen = Math.max(0, len - 0.25);
          shaftRef.current.scale.set(shaftLen, 1, 1);
          shaftRef.current.position.set(shaftLen / 2 * dir, 0, 0);
          
          headRef.current.position.set((len - 0.125) * dir, 0, 0);
          headRef.current.rotation.z = dir > 0 ? -Math.PI/2 : Math.PI/2;
          
          if (labelRef.current) {
             labelRef.current.position.set(len / 2 * dir, 0.6, 0);
          }
       }
    }
  });

  const color = deltaX >= 0 ? "#3b82f6" : "#ef4444"; // Blue for positive, Red for negative

  return (
    <group ref={groupRef}>
       <mesh ref={shaftRef}>
         <boxGeometry args={[1, 0.16, 0.16]} />
         <meshStandardMaterial color={color} />
       </mesh>
       <mesh ref={headRef}>
         <coneGeometry args={[0.3, 0.5, 16]} />
         <meshStandardMaterial color={color} />
       </mesh>
       <Text ref={labelRef} fontSize={0.5} color={color} outlineWidth={0.05} outlineColor="#000">
         {labelText}
       </Text>
    </group>
  )
}

function ResultMarker({ targetX }: { targetX: number }) {
   const ref = useRef<THREE.Group>(null);
   const currentX = useRef(targetX);
   const isZero = targetX === 0;
   
   useFrame((_, dt) => {
      currentX.current = THREE.MathUtils.damp(currentX.current, targetX, 5, dt);
      if (ref.current) {
         ref.current.position.set(currentX.current, 0, 0.5);
      }
   });

   return (
      <group ref={ref}>
         {/* Vertical dashed line down to number line */}
         <mesh position={[0, 1.5, 0]}>
            <cylinderGeometry args={[0.03, 0.03, 3, 8]} />
            <meshStandardMaterial color="#10b981" transparent opacity={0.6} />
         </mesh>
         
         {/* Glowing node at result */}
         <mesh position={[0, 3, 0]}>
            <sphereGeometry args={[0.4, 32, 32]} />
            <meshStandardMaterial color={isZero ? "#fbbf24" : "#10b981"} emissive={isZero ? "#fbbf24" : "#10b981"} emissiveIntensity={0.6} />
         </mesh>
         
         {/* Additive Inverse Special Label */}
         <Text position={[0, 3.8, 0]} fontSize={0.4} color="#fbbf24" outlineWidth={0.03} outlineColor="#000" visible={isZero}>
            Additive Inverse (0)
         </Text>

         {/* Standard Result Label */}
         <Text position={[0, 3.8, 0]} fontSize={0.5} color="#10b981" outlineWidth={0.03} outlineColor="#000" visible={!isZero}>
            Result
         </Text>
      </group>
   );
}

// ---------------------------------------------------------
// MAIN SCENE INTEGRATION
// ---------------------------------------------------------
function Scene({ a, b }: { a: number, b: number }) {
  return (
    <group>
      <OrbitControls 
        enablePan={true} 
        enableZoom={true}
        enableRotate={true}
        maxPolarAngle={Math.PI / 2 - 0.1}
        minPolarAngle={Math.PI / 2 - 0.6}
        maxAzimuthAngle={0.3}
        minAzimuthAngle={-0.3}
      />
      
      <group position={[0, -1, 0]}>
         <NumberLine />
         
         {/* Vector 1 (a) */}
         <AnimatedVector startX={0} deltaX={a} yOffset={0.6} labelText={`Vector 1: ${a > 0 ? '+'+a : a}`} />
         
         {/* Vector 2 (b) attached to end of Vector 1 */}
         <AnimatedVector startX={a} deltaX={b} yOffset={1.6} labelText={`Vector 2: ${b > 0 ? '+'+b : b}`} />
         
         {/* Result visualizer */}
         {(a !== 0 || b !== 0) && (
           <ResultMarker targetX={a + b} />
         )}
      </group>
    </group>
  );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function IntegerAdditionSimulation() {
  const [step, setStep] = useState(0);
  const [sliderA, setSliderA] = useState(-3);
  const [sliderB, setSliderB] = useState(5);

  // State Overrides per Step
  const a = step === 0 ? -3 : step === 1 ? -3 : step === 2 ? -5 : sliderA;
  const b = step === 0 ? 0  : step === 1 ? 5  : step === 2 ? 5  : sliderB;
  const result = a + b;

  const handleNextStep = () => {
    if (step < 3) setStep(s => s + 1);
  };

  const renderMath = () => {
    // Format equation nicely
    const formatNum = (n: number) => n < 0 ? `(${n})` : n.toString();
    const strA = formatNum(a);
    const strB = b < 0 ? `- ${Math.abs(b)}` : `+ ${b}`;
    return <BlockMath math={`${strA} ${strB} = ${result}`} />;
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Negative Vectors", icon: <MoveRight size={18} className="rotate-180"/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <>A negative integer is a vector pointing left. We start at zero and move 3 units to the left, landing at -3.</> };
    if (step === 1) return { title: "The Sign Misconception", icon: <ShieldAlert size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> Adding a positive to a negative doesn't always equal a negative! Starting from -3, we move 5 units right (+5). Because the positive vector is longer, we cross zero and land in positive territory (+2).</> };
    if (step === 2) return { title: "Additive Inverse", icon: <Activity size={18}/>, color: "border-l-yellow-500 text-yellow-300 bg-yellow-900/40", body: <>When you add two numbers with the exact same magnitude but opposite signs (-5 and +5), they perfectly cancel each other out. The result is always zero.</> };
    if (step === 3) return { title: "Interactive Sandbox", icon: <SlidersHorizontal size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>Use the sliders to test your own vectors. Watch how the final result depends entirely on which vector has the larger magnitude (length).</> };
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
              Adding Integers on the Number Line
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Visualizing magnitude, direction, and additive inverses.
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
           <Canvas shadows camera={{ position: [0, 4, 15], fov: 45 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[0, 20, 10]} intensity={1.2} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <Scene a={a} b={b} />
              
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
                 <span className="text-rose-400">Negative Base</span>
                 <span className="text-blue-400">Positive Addend</span>
                 <span className="text-yellow-400">Zero Node</span>
                 <span className="text-emerald-400">Sandbox</span>
              </div>
              <div className="flex gap-1">
                 {[0, 1, 2, 3].map(s => (
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
           {step === 3 ? (
             <div className="flex flex-col gap-4 mb-4">
               <div>
                  <label className="text-xs font-bold text-slate-400 flex justify-between">
                    <span>Vector 1 (Start)</span>
                    <span className={sliderA < 0 ? 'text-rose-400' : 'text-blue-400'}>{sliderA}</span>
                  </label>
                  <input 
                     type="range" min="-10" max="10" step="1" 
                     value={sliderA} onChange={(e) => setSliderA(parseInt(e.target.value))}
                     className="w-full accent-slate-400 mt-2"
                  />
               </div>
               <div>
                  <label className="text-xs font-bold text-slate-400 flex justify-between">
                    <span>Vector 2 (Addend)</span>
                    <span className={sliderB < 0 ? 'text-rose-400' : 'text-blue-400'}>{sliderB}</span>
                  </label>
                  <input 
                     type="range" min="-10" max="10" step="1" 
                     value={sliderB} onChange={(e) => setSliderB(parseInt(e.target.value))}
                     className="w-full accent-slate-400 mt-2"
                  />
               </div>
             </div>
           ) : null}

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3">
             <button 
                onClick={handleNextStep} 
                disabled={step === 3} 
                className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm ${step === 3 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
             >
                {step === 0 ? "Add Positive" : 
                 step === 1 ? "Test Opposites" : 
                 step === 2 ? "Unlock Sandbox" : 
                 "Freestyle Mode"} 
                {step < 3 && <MoveRight size={16} />}
             </button>
             <button 
                onClick={() => {
                   setStep(0);
                   setSliderA(-3);
                   setSliderB(5);
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
