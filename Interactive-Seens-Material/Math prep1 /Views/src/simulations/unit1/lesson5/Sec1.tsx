import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { Info, List, Code, CircleDashed, LayoutGrid, AlertTriangle, CheckCircle2, RotateCcw, MoveRight } from 'lucide-react';

interface ProcessProps {
  step: number;
}

const COLORS = {
  red: '#ef4444',
  white: '#f8fafc',
  black: '#1e293b',
  pink: '#ec4899', // subjective color
};

// Common Ball Geometry
const ballGeo = new THREE.SphereGeometry(0.6, 32, 32);

function Ball({ color, position, label }: { color: string; position: [number, number, number]; label?: string }) {
  return (
    <group position={position}>
      <mesh geometry={ballGeo} castShadow receiveShadow>
        <meshStandardMaterial color={color} roughness={0.2} metalness={0.1} />
      </mesh>
      {label && (
        <Html position={[0, -0.9, 0]} center className="pointer-events-none">
          <div className="text-[10px] font-bold uppercase tracking-wider text-slate-300 drop-shadow-md bg-slate-900/50 px-2 py-0.5 rounded-full backdrop-blur-sm">
            {label}
          </div>
        </Html>
      )}
    </group>
  );
}

function CameraController({ step }: { step: number }) {
  const { camera } = useThree();
  const controlsRef = useRef<any>(null);

  useEffect(() => {
    // Determine Target & Camera positions
    // Pods: Listing (-8), Builder (0), Venn (8)
    const targets = [
      { camX: 0, camY: 2, camZ: 12, tarX: 0, tarY: 0 },      // Step 0: Tray
      { camX: -8, camY: 0, camZ: 10, tarX: -8, tarY: 0 },    // Step 1: Listing
      { camX: 0, camY: 0, camZ: 10, tarX: 0, tarY: 0 },      // Step 2: Builder
      { camX: 8, camY: 0, camZ: 10, tarX: 8, tarY: 0 },      // Step 3: Venn
      { camX: 0, camY: 1, camZ: 22, tarX: 0, tarY: 0 },      // Step 4: Unified View
    ];

    const t = targets[step];

    gsap.to(camera.position, { x: t.camX, y: t.camY, z: t.camZ, duration: 1.2, ease: 'power3.inOut' });
    
    if (controlsRef.current) {
      gsap.to(controlsRef.current.target, { x: t.tarX, y: t.tarY, z: 0, duration: 1.2, ease: 'power3.inOut' });
    }
  }, [step, camera]);

  return (
    <OrbitControls 
      ref={controlsRef} 
      enablePan={false} 
      enableZoom={false}
      maxPolarAngle={Math.PI / 2 + 0.1}
      minPolarAngle={Math.PI / 2 - 0.5}
      maxAzimuthAngle={0.3}
      minAzimuthAngle={-0.3}
    />
  );
}

function Scene({ step }: ProcessProps) {
  const trayRef = useRef<THREE.Group>(null);
  const listingRef = useRef<THREE.Group>(null);
  const builderRef = useRef<THREE.Group>(null);
  const vennRef = useRef<THREE.Group>(null);
  const subjectiveRef = useRef<THREE.Group>(null);
  const subjectiveLabelRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const dur = 0.6;
    
    // Visibility Logic
    const showTray = step === 0;
    const showList = step === 1 || step === 4;
    const showBuild = step === 2 || step === 4;
    const showVenn = step === 3 || step === 4;

    // Tray Scale
    if (trayRef.current) {
      gsap.to(trayRef.current.scale, { x: showTray ? 1 : 0, y: showTray ? 1 : 0, z: showTray ? 1 : 0, duration: dur, ease: 'back.out(1.2)' });
    }

    // Subjective Rejection (only happens inside Step 0)
    if (subjectiveRef.current) {
       if (step === 0) {
         // Reset then reject
         gsap.set(subjectiveRef.current.position, { y: 0.5 });
         gsap.set(subjectiveRef.current.scale, { x: 1, y: 1, z: 1 });
         if(subjectiveLabelRef.current) subjectiveLabelRef.current.style.opacity = '1';

         gsap.to(subjectiveRef.current.position, { y: 2, duration: 0.8, delay: 0.5, ease: 'power2.out' });
         gsap.to(subjectiveRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.5, delay: 1.2, ease: 'back.in(2)' });
         if(subjectiveLabelRef.current) {
            gsap.to(subjectiveLabelRef.current, { opacity: 0, duration: 0.3, delay: 1.2 });
         }
       }
    }

    // Listing Scale
    if (listingRef.current) {
      gsap.to(listingRef.current.scale, { x: showList ? 1 : 0, y: showList ? 1 : 0, z: showList ? 1 : 0, duration: dur, delay: showList && step !== 4 ? 0.3 : 0, ease: 'back.out(1.2)' });
    }

    // Builder Scale
    if (builderRef.current) {
      gsap.to(builderRef.current.scale, { x: showBuild ? 1 : 0, y: showBuild ? 1 : 0, z: showBuild ? 1 : 0, duration: dur, delay: showBuild && step !== 4 ? 0.3 : 0, ease: 'back.out(1.2)' });
    }

    // Venn Scale
    if (vennRef.current) {
      gsap.to(vennRef.current.scale, { x: showVenn ? 1 : 0, y: showVenn ? 1 : 0, z: showVenn ? 1 : 0, duration: dur, delay: showVenn && step !== 4 ? 0.3 : 0, ease: 'back.out(1.2)' });
    }

  }, [step]);

  return (
    <group>
      <CameraController step={step} />

      {/* STEP 0: The Tray (Well-Defined Check) */}
      <group ref={trayRef} position={[0, -1, 0]}>
         {/* Base Plate */}
         <mesh receiveShadow position={[0, -0.2, 0]}>
            <cylinderGeometry args={[3, 3, 0.2, 64]} />
            <meshStandardMaterial color="#334155" roughness={0.7} />
         </mesh>
         
         <Ball color={COLORS.red} position={[-1.2, 0.6, 0]} label="Red" />
         <Ball color={COLORS.white} position={[0, 0.6, 1.2]} label="White" />
         <Ball color={COLORS.black} position={[1.2, 0.6, 0]} label="Black" />

         {/* Subjective Item */}
         <group ref={subjectiveRef} position={[0, 0.5, -1.2]}>
            <mesh geometry={ballGeo}>
               <meshStandardMaterial color={COLORS.pink} roughness={0.1} emissive={COLORS.pink} emissiveIntensity={0.4} />
            </mesh>
            <Html position={[0, 1.2, 0]} center className="pointer-events-none">
               <div ref={subjectiveLabelRef} className="bg-rose-900/90 border border-rose-500 text-rose-200 px-2 py-1 rounded shadow-lg whitespace-nowrap backdrop-blur flex flex-col items-center">
                  <span className="text-[10px] uppercase font-bold tracking-widest text-rose-400">Subjective</span>
                  <span className="font-bold">"Beautiful"</span>
               </div>
            </Html>
         </group>
      </group>

      {/* STEP 1: Listing Method */}
      <group ref={listingRef} position={[-8, 0, 0]} scale={[0,0,0]}>
         {/* Glass Podium */}
         <mesh position={[0, -1.5, 0]} receiveShadow>
            <boxGeometry args={[7, 0.2, 3]} />
            <meshStandardMaterial color="#1e293b" transparent opacity={0.6} />
         </mesh>
         <Html position={[0, -2, 0]} center className="pointer-events-none">
            <div className="text-slate-400 text-sm font-bold uppercase tracking-widest whitespace-nowrap bg-slate-900/80 px-4 py-1 rounded-full border border-slate-700">
               Listing Method
            </div>
         </Html>

         {/* Elements */}
         <Text position={[-3.2, 0, 0]} fontSize={2.5} color="#60a5fa">{'{'}</Text>
         <Ball color={COLORS.red} position={[-1.8, 0, 0]} />
         <Text position={[-0.8, -0.4, 0]} fontSize={1.5} color="#94a3b8">,</Text>
         <Ball color={COLORS.white} position={[0.2, 0, 0]} />
         <Text position={[1.2, -0.4, 0]} fontSize={1.5} color="#94a3b8">,</Text>
         <Ball color={COLORS.black} position={[2.2, 0, 0]} />
         <Text position={[3.5, 0, 0]} fontSize={2.5} color="#60a5fa">{'}'}</Text>
      </group>

      {/* STEP 2: Set-Builder Method */}
      <group ref={builderRef} position={[0, 0, 0]} scale={[0,0,0]}>
         {/* Glass Podium */}
         <mesh position={[0, -1.5, 0]} receiveShadow>
            <boxGeometry args={[7, 0.2, 3]} />
            <meshStandardMaterial color="#1e293b" transparent opacity={0.6} />
         </mesh>
         <Html position={[0, -2, 0]} center className="pointer-events-none">
            <div className="text-slate-400 text-sm font-bold uppercase tracking-widest whitespace-nowrap bg-slate-900/80 px-4 py-1 rounded-full border border-slate-700">
               Set-Builder Notation
            </div>
         </Html>

         {/* Property Panel */}
         <Html position={[0, 2, 0]} center className="pointer-events-none z-10">
            <div className="bg-blue-950/80 border border-blue-500/50 p-4 rounded-xl shadow-2xl backdrop-blur-md whitespace-nowrap flex items-center justify-center">
               <BlockMath math={`E = \\{ x \\mid x \\text{ is a color of the Egyptian flag} \\}`} />
            </div>
         </Html>

         {/* "x" Container holding the instances */}
         <mesh position={[0, -0.2, 0]}>
            <boxGeometry args={[5, 1.8, 2]} />
            <meshStandardMaterial color="#3b82f6" transparent opacity={0.1} wireframe />
         </mesh>
         <Text position={[-2.8, 0, 0]} fontSize={1.5} color="#93c5fd" fontStyle="italic">x =</Text>
         <Ball color={COLORS.red} position={[-1, -0.2, 0]} />
         <Ball color={COLORS.white} position={[0.5, -0.2, 0]} />
         <Ball color={COLORS.black} position={[2, -0.2, 0]} />
      </group>

      {/* STEP 3: Venn Diagram */}
      <group ref={vennRef} position={[8, 0, 0]} scale={[0,0,0]}>
         {/* Glass Podium */}
         <mesh position={[0, -1.5, 0]} receiveShadow>
            <boxGeometry args={[7, 0.2, 3]} />
            <meshStandardMaterial color="#1e293b" transparent opacity={0.6} />
         </mesh>
         <Html position={[0, -2, 0]} center className="pointer-events-none">
            <div className="text-slate-400 text-sm font-bold uppercase tracking-widest whitespace-nowrap bg-slate-900/80 px-4 py-1 rounded-full border border-slate-700">
               Venn Diagram
            </div>
         </Html>

         {/* Boundary */}
         <mesh position={[0, 0.3, 0]} rotation={[Math.PI/2, 0, 0]}>
            <torusGeometry args={[2.2, 0.05, 16, 64]} />
            <meshStandardMaterial color="#10b981" emissive="#059669" emissiveIntensity={1} />
         </mesh>
         <mesh position={[0, 0.3, 0]} rotation={[Math.PI/2, 0, 0]}>
             <circleGeometry args={[2.2, 32]} />
             <meshStandardMaterial color="#10b981" transparent opacity={0.05} side={THREE.DoubleSide} />
         </mesh>
         <Text position={[2, 2, 0]} fontSize={1} color="#34d399">E</Text>

         {/* Elements inside */}
         <Ball color={COLORS.red} position={[0, 0.3, -1]} label="• Red" />
         <Ball color={COLORS.white} position={[-0.8, 0.3, 0.8]} label="• White" />
         <Ball color={COLORS.black} position={[0.8, 0.3, 0.8]} label="• Black" />
      </group>

    </group>
  );
}

export default function SetRepresentationSimulation() {
  const [step, setStep] = useState(0);
  
  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`E = \\text{Well-Defined Collection}`} />;
    if (step === 1) return <BlockMath math={`E = \\{\\text{Red}, \\text{White}, \\text{Black}\\}`} />;
    if (step === 2) return <BlockMath math={`E = \\{ x \\mid x \\text{ is a color...} \\}`} />;
    if (step === 3) return <BlockMath math={`x \\in E \\text{ (Visually Enclosed)}`} />;
    if (step === 4) return <BlockMath math={`E_{list} = E_{builder} = E_{venn}`} />;
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Well-Defined Check", icon: <AlertTriangle size={18}/>, color: "border-l-rose-500 text-rose-400 bg-rose-900/10", body: <>Before representing a set, we must ensure its elements are <b>well-defined</b>. "Colors of the Egyptian Flag" is exact. "Beautiful colors" is subjective and must be rejected from the set.</> };
    if (step === 1) return { title: "The Listing Method", icon: <List size={18}/>, color: "border-l-blue-500 text-blue-400 bg-blue-900/10", body: <>We wrap the exact elements in curly brackets <b>{'{ }'}</b> and separate them with commas. The physical identity of the elements is laid out explicitly.</> };
    if (step === 2) return { title: "Set-Builder Notation", icon: <Code size={18}/>, color: "border-l-amber-500 text-amber-400 bg-amber-900/10", body: <>Instead of listing, we describe a <b>rule or property</b>. We define a variable <span className="font-mono text-amber-300">x</span> and state the condition it must meet. The resulting items are identical.</> };
    if (step === 3) return { title: "Venn Diagram", icon: <CircleDashed size={18}/>, color: "border-l-emerald-500 text-emerald-400 bg-emerald-900/10", body: <>A closed geometric boundary physically encloses the points belonging to Set E. Anything outside the ring is not in the set.</> };
    if (step === 4) return { title: "Representation Independence", icon: <LayoutGrid size={18}/>, color: "border-l-purple-500 text-purple-400 bg-purple-900/10", body: <><b>CRITICAL:</b> Changing the representation style <b>does not change the set</b>. Whether listed, described by a rule, or drawn in a circle, Set E remains the exact same collection of three colors.</> };
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
              Set Representations
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Visualizing how different notations describe the exact same mathematical set.
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
           <Canvas shadows camera={{ position: [0, 2, 12], fov: 50 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[10, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
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
             <p className="text-slate-300 text-sm leading-relaxed">
               {alertContent.body}
             </p>
           </div>
        </div>

        <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[400px]">
           
           {/* Steps Map */}
           <div className="flex flex-col gap-2 mb-4">
              <div className="flex justify-between text-[10px] font-bold uppercase tracking-wider text-slate-400 px-1">
                 <span>Setup</span>
                 <span>Forms</span>
                 <span>Unified</span>
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
              <div className="flex justify-between text-[9px] font-bold uppercase tracking-wider text-slate-500 px-1 mt-1">
                 <span className={step === 1 ? 'text-blue-400' : ''}>List</span>
                 <span className={step === 2 ? 'text-amber-400' : ''}>Build</span>
                 <span className={step === 3 ? 'text-emerald-400' : ''}>Venn</span>
              </div>
           </div>

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-2">
             <button 
                onClick={handleNextStep} 
                disabled={step === 4} 
                className="bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-blue-900/20 text-sm"
             >
                {step === 0 ? "Format 1: Listing" : 
                 step === 1 ? "Format 2: Builder" : 
                 step === 2 ? "Format 3: Venn" : 
                 step === 3 ? "Unify Views" : "All Views Locked"} 
                {step < 4 && <MoveRight size={16} />}
             </button>
             <button 
                onClick={() => setStep(0)} 
                className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
             >
                <RotateCcw size={16} /> Start Over
             </button>
           </div>
           
        </div>
      </div>
    </div>
  );
}
