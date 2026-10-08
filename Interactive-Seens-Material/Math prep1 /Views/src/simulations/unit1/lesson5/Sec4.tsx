import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Html, Line } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { CopyX, Zap, CheckCircle2, RotateCcw, MoveRight, ArrowRightLeft } from 'lucide-react';

interface ProcessProps {
  step: number;
}

// ---------------------------------------------------------
// REUSABLE COMPONENTS
// ---------------------------------------------------------
function Particle({ text, color, position, meshRef, textRef }: { text: string, color: string, position: [number, number, number], meshRef?: React.Ref<THREE.Group>, textRef?: any }) {
  return (
    <group ref={meshRef} position={position}>
      <mesh castShadow receiveShadow>
        <sphereGeometry args={[0.5, 32, 32]} />
        <meshStandardMaterial color={color} roughness={0.2} metalness={0.1} />
      </mesh>
      <Text ref={textRef} position={[0, 0, 0.51]} fontSize={0.6} color="white" outlineWidth={0.03} outlineColor="#000">
        {text}
      </Text>
    </group>
  );
}

function Pouch({ radius, color, label, position, children }: { radius: number, color: string, label: string, position: [number, number, number], children?: React.ReactNode }) {
  return (
    <group position={position}>
      {/* Floor */}
      <mesh position={[0, 0.05, 0]} receiveShadow rotation={[-Math.PI / 2, 0, 0]}>
        <circleGeometry args={[radius, 64]} />
        <meshStandardMaterial color={color} transparent opacity={0.15} depthWrite={false} />
      </mesh>
      {/* Border Torus */}
      <mesh position={[0, 0.1, 0]} rotation={[-Math.PI / 2, 0, 0]}>
        <torusGeometry args={[radius, 0.06, 16, 64]} />
        <meshStandardMaterial color={color} emissive={color} emissiveIntensity={0.5} />
      </mesh>
      {/* Label */}
      <Text position={[0, 0.15, radius + 0.5]} fontSize={0.8} color={color} fontStyle="italic" outlineWidth={0.02} outlineColor="#000">
        {label}
      </Text>
      {children}
    </group>
  );
}

function Beam({ start, end, opacity, color = "#10b981" }: { start: [number, number, number], end: [number, number, number], opacity: number, color?: string }) {
  const points = useMemo(() => [new THREE.Vector3(...start), new THREE.Vector3(...end)], [start, end]);
  return (
    <Line
      points={points}
      color={color}
      lineWidth={4}
      transparent
      opacity={opacity}
    />
  );
}

// ---------------------------------------------------------
// MAIN SCENE
// ---------------------------------------------------------
function Scene({ step }: ProcessProps) {
  const { camera } = useThree();
  const controlsRef = useRef<any>(null);

  // Refs for Group 1
  const b5_1Ref = useRef<THREE.Group>(null);
  const b5_2Ref = useRef<THREE.Group>(null);
  const eqSign1Ref = useRef<THREE.Group>(null);

  // Refs for Group 2
  const dXTextRef = useRef<any>(null);
  const eqSign2Ref = useRef<THREE.Group>(null);
  const dxParticleRef = useRef<THREE.Group>(null);

  const [beamOpacity, setBeamOpacity] = useState(0);
  const [beam2Opacity, setBeam2Opacity] = useState(0);

  // Camera Management
  useEffect(() => {
    const targets = [
      { camX: 0, camY: 7, camZ: 10, tarX: 0, tarY: 0, tarZ: 0 },         // 0: Initial sets A and B
      { camX: 0, camY: 7, camZ: 10, tarX: 0, tarY: 0, tarZ: 0 },         // 1: Condense duplicates
      { camX: 0, camY: 7, camZ: 10, tarX: 0, tarY: 0, tarZ: 0 },         // 2: Matching beams A=B
      { camX: 0, camY: 7, camZ: -5, tarX: 0, tarY: 0, tarZ: -15 },       // 3: Secondary sets C and D
      { camX: 0, camY: 7, camZ: -5, tarX: 0, tarY: 0, tarZ: -15 },       // 4: Solving x
    ];

    const t = targets[step];

    gsap.to(camera.position, { x: t.camX, y: t.camY, z: t.camZ, duration: 1.5, ease: 'power3.inOut' });
    if (controlsRef.current) {
      gsap.to(controlsRef.current.target, { x: t.tarX, y: t.tarY, z: t.tarZ, duration: 1.5, ease: 'power3.inOut' });
    }
  }, [step, camera]);

  // Object Animations
  useEffect(() => {
    const dur = 0.8;

    // Reset Group 1
    if (step === 0) {
       gsap.to(b5_2Ref.current!.position, { x: 0, z: -1.2, duration: dur });
       gsap.to(b5_2Ref.current!.scale, { x: 1, y: 1, z: 1, duration: dur });
       setBeamOpacity(0);
       gsap.to(eqSign1Ref.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
    }

    // Step 1: Condense duplicate
    if (step === 1) {
       gsap.to(b5_2Ref.current!.position, { x: 0, z: 1.2, duration: dur, ease: 'power2.inOut' });
       gsap.to(b5_2Ref.current!.scale, { x: 0, y: 0, z: 0, duration: dur, ease: 'power2.inOut' });
       setBeamOpacity(0);
       gsap.to(eqSign1Ref.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
    }

    // Step 2: Beams & Equal Sign
    if (step === 2) {
       gsap.to({ val: 0 }, { val: 1, duration: dur, onUpdate: function() { setBeamOpacity(this.targets()[0].val) } });
       gsap.to(eqSign1Ref.current!.scale, { x: 1, y: 1, z: 1, duration: dur, ease: 'back.out(1.5)', delay: 0.5 });
    }

    // Step 3: Move to Group 2
    if (step === 3) {
       setBeam2Opacity(0);
       gsap.to(eqSign2Ref.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
       if (dXTextRef.current) dXTextRef.current.text = "x";
       if (dxParticleRef.current) {
           (dxParticleRef.current.children[0] as THREE.Mesh).material = new THREE.MeshStandardMaterial({ color: '#8b5cf6', roughness: 0.2, metalness: 0.1 });
       }
    }

    // Step 4: Solve x
    if (step === 4) {
       gsap.to({ val: 0 }, { val: 1, duration: dur, onUpdate: function() { setBeam2Opacity(this.targets()[0].val) } });
       
       if (dXTextRef.current) {
           gsap.delayedCall(0.6, () => {
               dXTextRef.current.text = "4";
               if (dxParticleRef.current) {
                   const mat = (dxParticleRef.current.children[0] as THREE.Mesh).material as THREE.MeshStandardMaterial;
                   gsap.to(mat.color, { r: 59/255, g: 130/255, b: 246/255, duration: 0.5 }); // morph to blue
               }
           });
       }

       gsap.to(eqSign2Ref.current!.scale, { x: 1, y: 1, z: 1, duration: dur, ease: 'back.out(1.5)', delay: 1.0 });
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
      {/* ZONE 1: Duplicates & Order (Steps 0-2)            */}
      {/* ------------------------------------------------- */}
      
      {/* Set A */}
      <Pouch position={[-4, 0, 0]} radius={2.2} color="#3b82f6" label="Set A">
         <Particle text="5" color="#ec4899" position={[-1, 0.5, 0.5]} />
         <Particle text="2" color="#f59e0b" position={[1, 0.5, 0.8]} />
         <Particle text="4" color="#10b981" position={[0, 0.5, -1]} />
      </Pouch>

      {/* Set B */}
      <Pouch position={[4, 0, 0]} radius={2.5} color="#8b5cf6" label="Set B">
         <Particle text="2" color="#f59e0b" position={[-1.2, 0.5, -0.5]} />
         <Particle text="4" color="#10b981" position={[1.2, 0.5, -0.5]} />
         <Particle meshRef={b5_1Ref} text="5" color="#ec4899" position={[0, 0.5, 1.2]} />
         <Particle meshRef={b5_2Ref} text="5" color="#ec4899" position={[0, 0.5, -1.2]} />
      </Pouch>

      {/* Matching Beams */}
      <Beam start={[-3, 0.5, 0.8]} end={[2.8, 0.5, -0.5]} opacity={beamOpacity} color="#fbbf24" />
      <Beam start={[-4, 0.5, -1]} end={[5.2, 0.5, -0.5]} opacity={beamOpacity} color="#34d399" />
      <Beam start={[-5, 0.5, 0.5]} end={[4, 0.5, 1.2]} opacity={beamOpacity} color="#f472b6" />

      {/* Equality Sign */}
      <group ref={eqSign1Ref} position={[0, 1.5, 0]} scale={[0,0,0]}>
         <Html center className="pointer-events-none">
            <div className="bg-emerald-900/90 border-2 border-emerald-500 px-4 py-2 rounded-xl shadow-[0_0_15px_rgba(16,185,129,0.5)] backdrop-blur-md flex items-center gap-2">
               <span className="text-emerald-300 font-bold text-xl">A = B</span>
            </div>
         </Html>
      </group>


      {/* ------------------------------------------------- */}
      {/* ZONE 2: Solving Unknowns (Steps 3-4)              */}
      {/* ------------------------------------------------- */}
      
      {/* Set C */}
      <Pouch position={[-4, 0, -15]} radius={1.8} color="#3b82f6" label="Set C">
         <Particle text="4" color="#3b82f6" position={[-0.6, 0.5, 0]} />
         <Particle text="3" color="#f97316" position={[0.6, 0.5, 0]} />
      </Pouch>

      {/* Set D */}
      <Pouch position={[4, 0, -15]} radius={1.8} color="#8b5cf6" label="Set D">
         <Particle text="3" color="#f97316" position={[-0.6, 0.5, 0]} />
         <Particle meshRef={dxParticleRef} textRef={dXTextRef} text="x" color="#8b5cf6" position={[0.6, 0.5, 0]} />
      </Pouch>

      {/* Matching Beams Zone 2 */}
      <Beam start={[-3.4, 0.5, -15]} end={[3.4, 0.5, -15]} opacity={beam2Opacity} color="#fdba74" />
      <Beam start={[-4.6, 0.5, -15]} end={[4.6, 0.5, -15]} opacity={beam2Opacity} color="#93c5fd" />

      {/* Equality Sign Zone 2 */}
      <group ref={eqSign2Ref} position={[0, 1.5, -15]} scale={[0,0,0]}>
         <Html center className="pointer-events-none">
            <div className="bg-blue-900/90 border-2 border-blue-500 px-4 py-2 rounded-xl shadow-[0_0_15px_rgba(59,130,246,0.5)] backdrop-blur-md flex flex-col items-center gap-1">
               <span className="text-blue-300 font-bold text-sm uppercase tracking-widest">Equality Enforced</span>
               <BlockMath math={`x = 4`} />
            </div>
         </Html>
      </group>

    </group>
  );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function SetEqualitySimulation() {
  const [step, setStep] = useState(0);
  
  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const renderMath = () => {
    if (step === 0 || step === 1 || step === 2) {
       return <BlockMath math={`A = \\{5, 2, 4\\} \\quad B = \\{2, 4, 5, 5\\}`} />;
    } else {
       return <BlockMath math={`C = \\{4, 3\\} \\quad D = \\{3, x\\}`} />;
    }
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Visualizing Set Elements", icon: <CopyX size={18}/>, color: "border-l-slate-500 text-slate-300 bg-slate-900/40", body: <>We have two sets: A and B. Notice that the elements in B are arranged in a different order, and the number 5 is repeated.</> };
    if (step === 1) return { title: "Condensing Duplicates", icon: <CopyX size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <><b>CRITICAL RULE:</b> Sets only care about <i>unique</i> identity. Writing the number 5 twice does not create a new element. The duplicates merge into a single entity.</> };
    if (step === 2) return { title: "Set Equality", icon: <ArrowRightLeft size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>Because every unique element in A exists in B, and vice versa, the sets are perfectly equal (<span className="font-bold text-emerald-400">A = B</span>). <b>Order and repetition do not matter.</b></> };
    if (step === 3) return { title: "Solving with Equality", icon: <Zap size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>We are given that <span className="font-bold">Set C = Set D</span>. Let's look at their elements: C has <span className="font-mono">4, 3</span> and D has <span className="font-mono">3, x</span>.</> };
    if (step === 4) return { title: "Deducing Variables", icon: <CheckCircle2 size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>Since 3 matches 3, the remaining element in D must match the remaining element in C for the sets to be equal. Therefore, <span className="font-bold text-emerald-400">x = 4</span>.</> };
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
              Set Equality & Unknowns
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Proving that order and repetition do not affect set equality.
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
           <Canvas shadows camera={{ position: [0, 7, 10], fov: 45 }}>
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
                 <span>Inspect</span>
                 <span>Condense</span>
                 <span>Match</span>
                 <span>Apply</span>
                 <span>Solve</span>
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
                {step === 0 ? "Remove Duplicates" : 
                 step === 1 ? "Scan Elements" : 
                 step === 2 ? "Next Example" : 
                 step === 3 ? "Solve for x" :
                 "Lesson Complete"} 
                {step < 4 && <MoveRight size={16} />}
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
