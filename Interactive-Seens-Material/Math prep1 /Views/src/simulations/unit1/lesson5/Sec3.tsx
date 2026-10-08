import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MousePointer2, LogIn, Maximize, Ban, AlertTriangle, CheckCircle2, RotateCcw, MoveRight } from 'lucide-react';

interface ProcessProps {
  step: number;
}

// ---------------------------------------------------------
// REUSABLE COMPONENTS
// ---------------------------------------------------------
function Particle({ number, color, position, meshRef }: { number: number, color: string, position: [number, number, number], meshRef?: React.Ref<THREE.Group> }) {
  return (
    <group ref={meshRef} position={position}>
      <mesh castShadow receiveShadow>
        <sphereGeometry args={[0.5, 32, 32]} />
        <meshStandardMaterial color={color} roughness={0.2} metalness={0.1} />
      </mesh>
      <Text position={[0, 0, 0.51]} fontSize={0.6} color="white" outlineWidth={0.03} outlineColor="#000">
        {number}
      </Text>
    </group>
  );
}

function Pouch({ radius, color, label, position, children, meshRef, borderRef }: { radius: number, color: string, label: string, position: [number, number, number], children?: React.ReactNode, meshRef?: React.Ref<THREE.Group>, borderRef?: React.Ref<THREE.Mesh> }) {
  return (
    <group ref={meshRef} position={position}>
      {/* Floor */}
      <mesh position={[0, 0.05, 0]} receiveShadow rotation={[-Math.PI / 2, 0, 0]}>
        <circleGeometry args={[radius, 64]} />
        <meshStandardMaterial color={color} transparent opacity={0.15} depthWrite={false} />
      </mesh>
      {/* Border Torus */}
      <mesh ref={borderRef} position={[0, 0.1, 0]} rotation={[-Math.PI / 2, 0, 0]}>
        <torusGeometry args={[radius, 0.06, 16, 64]} />
        <meshStandardMaterial color={color} emissive={color} emissiveIntensity={0.5} />
      </mesh>
      {/* Label */}
      <Text position={[0, 0.15, -radius - 0.5]} fontSize={0.8} color={color} fontStyle="italic" outlineWidth={0.02} outlineColor="#000">
        {label}
      </Text>
      {children}
    </group>
  );
}

// ---------------------------------------------------------
// MAIN SCENE
// ---------------------------------------------------------
function Scene({ step }: ProcessProps) {
  const { camera } = useThree();
  const controlsRef = useRef<any>(null);

  // Refs for animation
  const ball5Ref = useRef<THREE.Group>(null);
  const setXRef = useRef<THREE.Group>(null);
  
  const setBRef = useRef<THREE.Group>(null);
  const setBBorderRef = useRef<THREE.Mesh>(null);
  
  const setARef = useRef<THREE.Group>(null);
  const setCRef = useRef<THREE.Group>(null);
  const emptySetRef = useRef<THREE.Group>(null);
  
  const misconceptionRef = useRef<THREE.Group>(null);

  // Camera Management
  useEffect(() => {
    const targets = [
      { camX: -15, camY: 8, camZ: 10, tarX: -15, tarY: 0 },   // 0: Set X (Belonging)
      { camX: 0, camY: 9, camZ: 12, tarX: 0, tarY: 0 },       // 1: Set A in B
      { camX: 0, camY: 9, camZ: 12, tarX: 0, tarY: 0 },       // 2: Set C blocked
      { camX: 0, camY: 9, camZ: 12, tarX: 0, tarY: 0 },       // 3: Empty Set in B
      { camX: 0, camY: 6, camZ: 14, tarX: 0, tarY: 2 },       // 4: Misconception Overlay
    ];

    const t = targets[step];

    gsap.to(camera.position, { x: t.camX, y: t.camY, z: t.camZ, duration: 1.5, ease: 'power3.inOut' });
    if (controlsRef.current) {
      gsap.to(controlsRef.current.target, { x: t.tarX, y: t.tarY, z: 0, duration: 1.5, ease: 'power3.inOut' });
    }
  }, [step, camera]);

  // Object Animations
  useEffect(() => {
    const dur = 1.0;

    // STEP 0: Element 5 drops into X
    if (ball5Ref.current) {
       if (step === 0) {
          gsap.set(ball5Ref.current.position, { x: 0, y: 5, z: 0 });
          gsap.to(ball5Ref.current.position, { y: 0.5, z: 0.8, duration: dur, ease: 'bounce.out', delay: 0.5 });
       } else {
          gsap.to(ball5Ref.current.position, { y: 0.5, z: 0.8, duration: 0.1 });
       }
    }

    // Reset others when returning to step 0
    if (step === 0) {
       gsap.to(setARef.current!.position, { x: -7, z: -4, duration: dur });
       gsap.to(setCRef.current!.position, { x: 7, z: -4, duration: dur });
       gsap.to(emptySetRef.current!.position, { x: 0, z: -7, duration: dur });
       gsap.to(misconceptionRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
       return;
    }

    // STEP 1: Set A glides into B
    if (step === 1) {
       gsap.to(setARef.current!.position, { x: -1.2, z: 0, duration: dur, ease: 'power2.out', delay: 0.5 });
       
       gsap.to(setCRef.current!.position, { x: 7, z: -4, duration: dur });
       gsap.to(emptySetRef.current!.position, { x: 0, z: -7, duration: dur });
       gsap.to(misconceptionRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
    }

    // STEP 2: Set C blocked by B
    if (step === 2) {
       // A moves out slightly
       gsap.to(setARef.current!.position, { x: -6, z: 4, duration: dur });
       
       // C attempts entry
       const tl = gsap.timeline();
       tl.to(setCRef.current!.position, { x: 2.2, z: 0, duration: dur, ease: 'power2.in', delay: 0.3 });
       
       // Hit and flash red
       if (setBBorderRef.current) {
          tl.to((setBBorderRef.current.material as THREE.MeshStandardMaterial), { color: '#ef4444', emissive: '#ef4444', duration: 0.1, yoyo: true, repeat: 3 });
          tl.to((setBBorderRef.current.material as THREE.MeshStandardMaterial), { color: '#3b82f6', emissive: '#3b82f6', duration: 0.3 });
       }
       
       // C bounces back
       tl.to(setCRef.current!.position, { x: 4.5, z: 0, duration: 0.6, ease: 'back.out(1.5)' }, "-=0.2");

       gsap.to(emptySetRef.current!.position, { x: 0, z: -7, duration: dur });
       gsap.to(misconceptionRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
    }

    // STEP 3: Empty Set glides into B
    if (step === 3) {
       gsap.to(setCRef.current!.position, { x: 7, z: -4, duration: dur });
       
       // Empty set enters freely
       gsap.to(emptySetRef.current!.position, { x: 1, z: 0, duration: dur, ease: 'power2.out', delay: 0.5 });
       gsap.to(misconceptionRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
    }

    // STEP 4: Misconception Correction
    if (step === 4) {
       gsap.to(emptySetRef.current!.position, { x: 0, z: -7, duration: dur });
       gsap.to(setARef.current!.position, { x: -1.2, z: 0, duration: dur });
       
       // Pop up the misconception panel
       gsap.to(misconceptionRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.6, ease: 'back.out(1.2)', delay: 0.5 });
    }

  }, [step]);

  return (
    <group>
      <OrbitControls 
        ref={controlsRef} 
        enablePan={false} 
        enableZoom={false}
        maxPolarAngle={Math.PI / 2 - 0.1}
        minPolarAngle={Math.PI / 2 - 0.8}
        maxAzimuthAngle={0.4}
        minAzimuthAngle={-0.4}
      />

      {/* ------------------------------------------------- */}
      {/* ZONE 1: Belonging (Step 0)                        */}
      {/* ------------------------------------------------- */}
      <Pouch meshRef={setXRef} position={[-15, 0, 0]} radius={2.2} color="#10b981" label="Set X">
         <Particle number={3} color="#f59e0b" position={[-0.8, 0.5, -0.5]} />
         <Particle number={4} color="#f59e0b" position={[0.8, 0.5, -0.5]} />
         <Particle meshRef={ball5Ref} number={5} color="#ec4899" position={[0, 5, 0]} />
         
         {/* Badge dynamically appears when ball 5 lands */}
         <Html position={[0, 0, 2.5]} center className="pointer-events-none z-10">
            <div className={`transition-all duration-500 bg-emerald-900/90 border border-emerald-500 px-3 py-1 rounded shadow-lg backdrop-blur flex items-center gap-2 ${step === 0 ? 'opacity-100 scale-100 delay-1000' : 'opacity-0 scale-50'}`}>
               <span className="text-emerald-300 font-bold text-sm">Particle 5</span>
               <BlockMath math={`5 \\in X`} />
            </div>
         </Html>
      </Pouch>

      {/* ------------------------------------------------- */}
      {/* ZONE 2: Subsets (Steps 1-4)                       */}
      {/* ------------------------------------------------- */}
      
      {/* Main Set B */}
      <Pouch meshRef={setBRef} borderRef={setBBorderRef} position={[0, 0, 0]} radius={3.8} color="#3b82f6" label="Set B">
         {/* B's native elements */}
         <Particle number={1} color="#3b82f6" position={[0, 0.5, 2.5]} />
         <Particle number={5} color="#3b82f6" position={[2, 0.5, 1.5]} />
      </Pouch>

      {/* Set A (Subset) */}
      <Pouch meshRef={setARef} position={[-7, 0, -4]} radius={1.5} color="#8b5cf6" label="Set A">
         <Particle number={3} color="#8b5cf6" position={[-0.5, 0.5, 0.2]} />
         <Particle number={7} color="#8b5cf6" position={[0.5, 0.5, -0.2]} />
         <Html position={[0, 0.1, 1.8]} center className="pointer-events-none z-10">
            <div className={`transition-opacity duration-300 bg-purple-900/90 border border-purple-500 px-2 py-0.5 rounded shadow-lg backdrop-blur ${step === 1 || step === 4 ? 'opacity-100' : 'opacity-0'}`}>
               <BlockMath math={`A \\subset B`} />
            </div>
         </Html>
      </Pouch>

      {/* Set C (Not Subset) */}
      <Pouch meshRef={setCRef} position={[7, 0, -4]} radius={1.5} color="#f97316" label="Set C">
         <Particle number={3} color="#f97316" position={[-0.5, 0.5, 0.2]} />
         <Particle number={8} color="#ef4444" position={[0.5, 0.5, -0.2]} />
         <Html position={[0, 0.1, 1.8]} center className="pointer-events-none z-10">
            <div className={`transition-opacity duration-300 bg-orange-900/90 border border-orange-500 px-2 py-0.5 rounded shadow-lg backdrop-blur ${step === 2 ? 'opacity-100' : 'opacity-0'}`}>
               <BlockMath math={`C \\not\\subset B`} />
            </div>
         </Html>
      </Pouch>

      {/* Empty Set */}
      <Pouch meshRef={emptySetRef} position={[0, 0, -7]} radius={1.2} color="#14b8a6" label="Set ∅">
         <Text position={[0, 0.2, 0]} fontSize={1.2} color="#14b8a6" rotation={[-Math.PI / 2, 0, 0]}>
            ∅
         </Text>
         <Html position={[0, 0.1, 1.5]} center className="pointer-events-none z-10">
            <div className={`transition-opacity duration-300 bg-teal-900/90 border border-teal-500 px-2 py-0.5 rounded shadow-lg backdrop-blur ${step === 3 ? 'opacity-100' : 'opacity-0'}`}>
               <BlockMath math={`\\emptyset \\subset B`} />
            </div>
         </Html>
      </Pouch>

      {/* ------------------------------------------------- */}
      {/* MISCONCEPTION OVERLAY (Step 4)                    */}
      {/* ------------------------------------------------- */}
      <group ref={misconceptionRef} position={[0, 4, 0]} scale={[0,0,0]}>
         {/* Background Glass Plate */}
         <mesh position={[0, 0, -0.5]}>
            <boxGeometry args={[10, 4, 0.2]} />
            <meshStandardMaterial color="#0f172a" transparent opacity={0.8} />
         </mesh>

         {/* Incorrect Side */}
         <group position={[-2.5, 0, 0]}>
            <Text position={[0, 0.8, 0]} fontSize={0.6} color="#ef4444" fontStyle="italic">INCORRECT</Text>
            <Html position={[0, -0.2, 0]} center className="pointer-events-none">
               <div className="bg-rose-950/80 border-2 border-rose-500 p-3 rounded-xl flex flex-col items-center shadow-[0_0_15px_rgba(239,68,68,0.4)]">
                  <span className="text-xs font-bold text-rose-300 mb-1 uppercase tracking-widest">Syntax Error</span>
                  <div className="text-3xl text-rose-100 opacity-50 relative line-through decoration-rose-500 decoration-4">
                     <BlockMath math={`A \\in B`} />
                  </div>
               </div>
            </Html>
         </group>

         {/* Correct Side */}
         <group position={[2.5, 0, 0]}>
            <Text position={[0, 0.8, 0]} fontSize={0.6} color="#10b981" fontStyle="italic">CORRECT</Text>
            <Html position={[0, -0.2, 0]} center className="pointer-events-none">
               <div className="bg-emerald-950/80 border-2 border-emerald-500 p-3 rounded-xl flex flex-col items-center shadow-[0_0_15px_rgba(16,185,129,0.4)]">
                  <span className="text-xs font-bold text-emerald-300 mb-1 uppercase tracking-widest">Valid Relation</span>
                  <div className="text-3xl text-emerald-100">
                     <BlockMath math={`A \\subset B`} />
                  </div>
               </div>
            </Html>
         </group>
      </group>

    </group>
  );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function SetRelationsSimulation() {
  const [step, setStep] = useState(0);
  
  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`X = \\{3, 4, 5\\}`} />;
    if (step === 1) return <BlockMath math={`B = \\{1, 3, 5, 7\\}, \\ A = \\{3, 7\\}`} />;
    if (step === 2) return <BlockMath math={`B = \\{1, 3, 5, 7\\}, \\ C = \\{3, 8\\}`} />;
    if (step === 3) return <BlockMath math={`\\emptyset = \\{\\}`} />;
    if (step === 4) return <BlockMath math={`A \\subset B \\quad (\\text{Not } A \\in B)`} />;
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Element Belonging", icon: <LogIn size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>Particles are individual elements. When an element drops into a set container, we say it <b>belongs to</b> the set using the symbol <span className="font-bold text-emerald-400">∈</span>.</> };
    if (step === 1) return { title: "Subset Inclusion", icon: <Maximize size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>Set A is a pouch containing its own elements. Because every element inside A (3, 7) is also inside B, Set A glides entirely inside B. We call this a <b>subset</b> (<span className="font-bold text-purple-400">⊂</span>).</> };
    if (step === 2) return { title: "Subset Rejection", icon: <Ban size={18}/>, color: "border-l-orange-500 text-orange-300 bg-orange-900/40", body: <>Set C attempts to enter B. However, particle <span className="font-bold text-red-400">8</span> hits a barrier because it does not exist in B. If even one element fails, the entire set is <b>not a subset</b> (<span className="font-bold text-orange-400">⊄</span>).</> };
    if (step === 3) return { title: "The Empty Set", icon: <CheckCircle2 size={18}/>, color: "border-l-teal-500 text-teal-300 bg-teal-900/40", body: <>The empty set ∅ contains zero elements. Since it brings no conflicting elements, it slips into every other set without friction. ∅ is a subset of <b>all sets</b>.</> };
    if (step === 4) return { title: "The Symbol Misconception", icon: <AlertTriangle size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> Never use ∈ between two sets! Sets are contained within sets (<span className="font-bold text-emerald-400">⊂</span>). Only raw elements use the belonging symbol (<span className="font-bold text-rose-400">∈</span>).</> };
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
              Belonging vs. Subsets
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Visualizing the strict difference between elements (∈) and sets (⊂).
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
           <Canvas shadows camera={{ position: [0, 8, 12], fov: 45 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[5, 20, 10]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <Scene step={step} />
              
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

        <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[400px]">
           
           {/* Steps Map */}
           <div className="flex flex-col gap-2 mb-4">
              <div className="flex justify-between text-[10px] font-bold uppercase tracking-wider text-slate-400 px-1">
                 <span>∈ Element</span>
                 <span>⊂ Subset</span>
                 <span>⊄ Reject</span>
                 <span>∅ Empty</span>
                 <span className="text-rose-400">Syntax</span>
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

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-2">
             <button 
                onClick={handleNextStep} 
                disabled={step === 4} 
                className="bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-blue-900/20 text-sm"
             >
                {step === 0 ? "Drag Set A in" : 
                 step === 1 ? "Drag Set C in" : 
                 step === 2 ? "Drag Empty in" : 
                 step === 3 ? "View Correction" :
                 "Lesson Complete"} 
                {step < 4 && <MousePointer2 size={16} />}
             </button>
             <button 
                onClick={() => setStep(0)} 
                className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
             >
                <RotateCcw size={16} /> Restart
             </button>
           </div>
           
        </div>
      </div>
    </div>
  );
}
