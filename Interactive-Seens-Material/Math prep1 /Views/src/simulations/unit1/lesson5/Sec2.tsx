import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath, InlineMath } from 'react-katex';
import { Box, Infinity as InfinityIcon, Ban, CheckCircle2, RotateCcw, MoveRight, Layers } from 'lucide-react';

interface ProcessProps {
  step: number;
}

// ---------------------------------------------------------
// POD 1: Finite Set
// ---------------------------------------------------------
function FinitePod({ step, position }: { step: number, position: [number, number, number] }) {
  const blocksRef = useRef<THREE.Group>(null);
  const badgeRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const isActive = step === 0 || step === 3;
    
    if (blocksRef.current) {
      blocksRef.current.children.forEach((child, i) => {
        gsap.to(child.scale, {
          x: isActive ? 1 : 0,
          y: isActive ? 1 : 0,
          z: isActive ? 1 : 0,
          duration: 0.6,
          delay: isActive ? i * 0.15 : 0,
          ease: 'back.out(1.5)'
        });
      });
    }

    if (badgeRef.current) {
      gsap.to(badgeRef.current, { opacity: isActive ? 1 : 0.3, duration: 0.5 });
    }
  }, [step]);

  return (
    <group position={position}>
      {/* Base */}
      <mesh position={[0, -0.2, 0]} receiveShadow>
        <cylinderGeometry args={[2.5, 2.8, 0.4, 32]} />
        <meshStandardMaterial color="#1e3a8a" roughness={0.7} metalness={0.2} />
      </mesh>
      
      {/* Glass Dome */}
      <mesh position={[0, 1.5, 0]}>
        <cylinderGeometry args={[2.2, 2.2, 3, 32]} />
        <meshStandardMaterial color="#60a5fa" transparent opacity={0.1} depthWrite={false} />
      </mesh>

      {/* Elements */}
      <group ref={blocksRef}>
        <mesh position={[-0.8, 0.4, -0.8]} castShadow>
          <boxGeometry args={[0.8, 0.8, 0.8]} />
          <meshStandardMaterial color="#3b82f6" />
        </mesh>
        <mesh position={[0.8, 0.4, -0.5]} castShadow>
          <boxGeometry args={[0.8, 0.8, 0.8]} />
          <meshStandardMaterial color="#ef4444" />
        </mesh>
        <mesh position={[-0.4, 0.4, 0.8]} castShadow>
          <boxGeometry args={[0.8, 0.8, 0.8]} />
          <meshStandardMaterial color="#10b981" />
        </mesh>
        <mesh position={[0.5, 1.2, 0.2]} castShadow>
          <boxGeometry args={[0.8, 0.8, 0.8]} />
          <meshStandardMaterial color="#f59e0b" />
        </mesh>
      </group>

      {/* Badge */}
      <Html position={[0, -0.5, 2.5]} center className="pointer-events-none">
        <div ref={badgeRef} className="bg-blue-900/90 border-2 border-blue-500 text-blue-100 px-4 py-2 rounded-xl shadow-[0_0_15px_rgba(59,130,246,0.5)] backdrop-blur-md whitespace-nowrap flex flex-col items-center">
          <span className="text-[10px] font-bold uppercase tracking-widest text-blue-300">Finite Set</span>
          <span className="text-lg font-mono font-bold">Count = 4</span>
        </div>
      </Html>
    </group>
  );
}

// ---------------------------------------------------------
// POD 2: Infinite Set
// ---------------------------------------------------------
function InfinitePod({ step, position }: { step: number, position: [number, number, number] }) {
  const streamRef = useRef<THREE.Group>(null);
  const portalRef = useRef<THREE.Mesh>(null);
  const badgeRef = useRef<HTMLDivElement>(null);
  const isActive = step === 1 || step === 3;

  useFrame(() => {
    if (isActive && streamRef.current) {
      streamRef.current.children.forEach((child) => {
        child.position.z -= 0.04;
        if (child.position.z < -1.5) {
          child.position.z = 3;
        }
        const scale = Math.max(0, (child.position.z + 1.5) / 2);
        child.scale.setScalar(Math.min(1, scale));
        (child.children[0] as THREE.Mesh).material.opacity = Math.min(1, scale);
      });
    }
    if (isActive && portalRef.current) {
       portalRef.current.rotation.z -= 0.02;
    }
  });

  useEffect(() => {
    if (badgeRef.current) {
      gsap.to(badgeRef.current, { opacity: isActive ? 1 : 0.3, duration: 0.5 });
    }
  }, [isActive]);

  const particles = [1, 2, 3, 4, 5];

  return (
    <group position={position}>
      {/* Base */}
      <mesh position={[0, -0.2, 0]} receiveShadow>
        <cylinderGeometry args={[2.5, 2.8, 0.4, 32]} />
        <meshStandardMaterial color="#4c1d95" roughness={0.7} metalness={0.2} />
      </mesh>
      
      {/* Glass Dome */}
      <mesh position={[0, 1.5, 0]}>
        <cylinderGeometry args={[2.2, 2.2, 3, 32]} />
        <meshStandardMaterial color="#a78bfa" transparent opacity={0.1} depthWrite={false} />
      </mesh>

      {/* Portal */}
      <mesh ref={portalRef} position={[0, 1.5, -1.8]}>
        <torusGeometry args={[1, 0.1, 16, 64]} />
        <meshStandardMaterial color="#c084fc" emissive="#9333ea" emissiveIntensity={2} />
      </mesh>
      <mesh position={[0, 1.5, -1.8]}>
         <circleGeometry args={[1, 32]} />
         <meshBasicMaterial color="#000000" />
      </mesh>

      {/* Particle Stream */}
      <group ref={streamRef}>
        {particles.map((num, i) => (
          <group key={i} position={[0, 1.5, 3 - i * 1.1]}>
            <mesh>
              <sphereGeometry args={[0.3, 16, 16]} />
              <meshStandardMaterial color="#d8b4fe" transparent />
            </mesh>
            <Text position={[0, 0.5, 0]} fontSize={0.4} color="#f3e8ff" anchorX="center" anchorY="middle">
              {num}
            </Text>
          </group>
        ))}
      </group>

      {/* Badge */}
      <Html position={[0, -0.5, 2.5]} center className="pointer-events-none">
        <div ref={badgeRef} className="bg-purple-900/90 border-2 border-purple-500 text-purple-100 px-4 py-2 rounded-xl shadow-[0_0_15px_rgba(168,85,247,0.5)] backdrop-blur-md whitespace-nowrap flex flex-col items-center">
          <span className="text-[10px] font-bold uppercase tracking-widest text-purple-300">Infinite Set</span>
          <span className="text-lg font-mono font-bold flex items-center gap-2">Count = <InfinityIcon size={18} /></span>
        </div>
      </Html>
    </group>
  );
}

// ---------------------------------------------------------
// POD 3: Empty Set (Misconception Checker)
// ---------------------------------------------------------
function EmptyPod({ step, position }: { step: number, position: [number, number, number] }) {
  const nullRef = useRef<THREE.Group>(null);
  const zeroBlockRef = useRef<THREE.Group>(null);
  const domeRef = useRef<THREE.Mesh>(null);
  const badgeRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const isActive = step === 2 || step === 3;
    
    if (badgeRef.current) {
      gsap.to(badgeRef.current, { opacity: isActive ? 1 : 0.3, duration: 0.5 });
    }

    if (nullRef.current) {
       gsap.to(nullRef.current.scale, { x: isActive ? 1 : 0, y: isActive ? 1 : 0, z: isActive ? 1 : 0, duration: 0.6, ease: 'back.out(1.5)' });
    }

    if (step === 2) {
       // Misconception Animation: Zero tries to enter
       const tl = gsap.timeline();
       
       // Setup Zero Block
       gsap.set(zeroBlockRef.current!.position, { x: 3, y: 1, z: 4 });
       gsap.set(zeroBlockRef.current!.scale, { x: 1, y: 1, z: 1 });
       (zeroBlockRef.current!.children[0] as THREE.Mesh).material.opacity = 1;
       
       // Move towards dome
       tl.to(zeroBlockRef.current!.position, { x: 0, z: 2.2, duration: 1, ease: "power2.in", delay: 0.5 });
       
       // Collision & Rejection
       tl.to((domeRef.current!.material as THREE.MeshStandardMaterial), { color: '#ef4444', emissive: '#ef4444', emissiveIntensity: 0.8, duration: 0.1, yoyo: true, repeat: 3 });
       tl.to(zeroBlockRef.current!.position, { x: 3, z: 5, y: -0.5, duration: 0.8, ease: "bounce.out" }, "-=0.3");
       tl.to(zeroBlockRef.current!.rotation, { x: Math.PI, z: Math.PI/2, duration: 0.8 }, "-=0.8");
       
       // Fade out Zero
       tl.to((zeroBlockRef.current!.children[0] as THREE.Mesh).material, { opacity: 0, duration: 0.5 }, "+=0.5");
       tl.to((domeRef.current!.material as THREE.MeshStandardMaterial), { color: '#34d399', emissive: '#000000', emissiveIntensity: 0, duration: 0.5 }, "-=0.5");

    } else {
       // Reset
       gsap.set(zeroBlockRef.current!.scale, { x: 0, y: 0, z: 0 });
       if(domeRef.current) {
          gsap.set(domeRef.current.material, { color: '#34d399', emissiveIntensity: 0 });
       }
    }
  }, [step]);

  return (
    <group position={position}>
      {/* Base */}
      <mesh position={[0, -0.2, 0]} receiveShadow>
        <cylinderGeometry args={[2.5, 2.8, 0.4, 32]} />
        <meshStandardMaterial color="#064e3b" roughness={0.7} metalness={0.2} />
      </mesh>
      
      {/* Glass Dome */}
      <mesh ref={domeRef} position={[0, 1.5, 0]}>
        <cylinderGeometry args={[2.2, 2.2, 3, 32]} />
        <meshStandardMaterial color="#34d399" transparent opacity={0.15} depthWrite={false} />
      </mesh>

      {/* Null Symbol */}
      <group ref={nullRef} position={[0, 1.5, 0]} scale={[0,0,0]}>
         <Text fontSize={2.5} color="#10b981" outlineWidth={0.05} outlineColor="#047857">
           ∅
         </Text>
      </group>

      {/* Zero Block (Misconception Intruder) */}
      <group ref={zeroBlockRef} scale={[0,0,0]}>
         <mesh castShadow>
           <boxGeometry args={[0.8, 0.8, 0.8]} />
           <meshStandardMaterial color="#f43f5e" transparent />
         </mesh>
         <Html position={[0, 0.8, 0]} center className="pointer-events-none z-10">
            <div className="bg-rose-900 text-rose-100 font-bold px-2 py-0.5 rounded text-xs border border-rose-500">Number 0</div>
         </Html>
      </group>

      {/* Badge */}
      <Html position={[0, -0.5, 2.5]} center className="pointer-events-none">
        <div ref={badgeRef} className="bg-emerald-900/90 border-2 border-emerald-500 text-emerald-100 px-4 py-2 rounded-xl shadow-[0_0_15px_rgba(16,185,129,0.5)] backdrop-blur-md whitespace-nowrap flex flex-col items-center">
          <span className="text-[10px] font-bold uppercase tracking-widest text-emerald-300">Empty Set</span>
          <span className="text-lg font-mono font-bold">Count = 0</span>
        </div>
      </Html>
    </group>
  );
}

// ---------------------------------------------------------
// CAMERA & MAIN SCENE
// ---------------------------------------------------------
function CameraController({ step }: { step: number }) {
  const { camera } = useThree();
  const controlsRef = useRef<any>(null);

  useEffect(() => {
    const targets = [
      { camX: -7, camY: 2, camZ: 8, tarX: -7, tarY: 1 },    // 0: Finite
      { camX: 0, camY: 2, camZ: 8, tarX: 0, tarY: 1 },      // 1: Infinite
      { camX: 7, camY: 2, camZ: 8, tarX: 7, tarY: 1 },      // 2: Empty
      { camX: 0, camY: 3, camZ: 18, tarX: 0, tarY: 0 },     // 3: Unified
    ];

    const t = targets[step];

    gsap.to(camera.position, { x: t.camX, y: t.camY, z: t.camZ, duration: 1.5, ease: 'power3.inOut' });
    
    if (controlsRef.current) {
      gsap.to(controlsRef.current.target, { x: t.tarX, y: t.tarY, z: 0, duration: 1.5, ease: 'power3.inOut' });
    }
  }, [step, camera]);

  return (
    <OrbitControls 
      ref={controlsRef} 
      enablePan={false} 
      enableZoom={false}
      maxPolarAngle={Math.PI / 2}
      minPolarAngle={Math.PI / 2 - 0.6}
      maxAzimuthAngle={0.4}
      minAzimuthAngle={-0.4}
    />
  );
}

function Scene({ step }: ProcessProps) {
  return (
    <group>
      <CameraController step={step} />
      <FinitePod step={step} position={[-7, 0, 0]} />
      <InfinitePod step={step} position={[0, 0, 0]} />
      <EmptyPod step={step} position={[7, 0, 0]} />
    </group>
  );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function SetClassificationSimulation() {
  const [step, setStep] = useState(0);
  
  const handleNextStep = () => {
    if (step < 3) setStep(s => s + 1);
  };

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`A = \\{2, 4, 6, 8\\} \\quad \\text{Count: 4}`} />;
    if (step === 1) return <BlockMath math={`N = \\{1, 2, 3, 4, \\dots\\} \\quad \\text{Count: } \\infty`} />;
    if (step === 2) return <BlockMath math={`E = \\emptyset = \\{\\} \\quad \\text{Count: 0}`} />;
    if (step === 3) return <BlockMath math={`\\text{Sets: } \\{\\text{Finite, Infinite, Empty}\\}`} />;
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Finite Set", icon: <Box size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>A set with a specific, countable number of elements. The counting process will eventually end.</> };
    if (step === 1) return { title: "Infinite Set", icon: <InfinityIcon size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>A set whose elements continue endlessly in a pattern. We cannot list or count them all.</> };
    if (step === 2) return { title: "The Empty Set Misconception", icon: <Ban size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> The set <InlineMath math="\{0\}" /> is <b>NOT</b> empty! It contains exactly one element: the number zero. The true Empty Set (<InlineMath math="\emptyset" />) contains absolutely nothing. Notice how the zero block is violently rejected by the empty set.</> };
    if (step === 3) return { title: "Set Classifications", icon: <Layers size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>Every set falls into one of these structural categories based purely on its element count.</> };
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
              Set Classification
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Exploring Finite, Infinite, and the true Empty Set.
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
           <Canvas shadows camera={{ position: [0, 3, 18], fov: 45 }}>
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
             <p className="text-sm leading-relaxed drop-shadow-md">
               {alertContent.body}
             </p>
           </div>
        </div>

        <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[400px]">
           
           {/* Steps Map */}
           <div className="flex flex-col gap-2 mb-4">
              <div className="flex justify-between text-[10px] font-bold uppercase tracking-wider text-slate-400 px-1">
                 <span>Finite</span>
                 <span>Infinite</span>
                 <span>Empty</span>
                 <span>Compare</span>
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

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-2">
             <button 
                onClick={handleNextStep} 
                disabled={step === 3} 
                className="bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-blue-900/20 text-sm"
             >
                {step === 0 ? "Next: Infinite" : 
                 step === 1 ? "Next: Empty" : 
                 step === 2 ? "Compare All" : 
                 "Classification Complete"} 
                {step < 3 && <MoveRight size={16} />}
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
