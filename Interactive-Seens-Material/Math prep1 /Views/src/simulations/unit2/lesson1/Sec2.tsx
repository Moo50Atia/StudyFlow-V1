import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Float } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, Scan, Plus, Minus, AlertTriangle, SlidersHorizontal, RotateCcw, Target, ShieldAlert, CheckCircle } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

interface TermData {
   c: number;
   v: string;
   e: number;
   id: string;
}

function TermBlock({ 
   term, position, isSandbox = false, status = "idle", misconception = false
}: { 
   term: TermData, position: [number, number, number], isSandbox?: boolean, status?: "idle" | "match" | "fail", misconception?: boolean
}) {
   const groupRef = useRef<THREE.Group>(null);
   const baseRef = useRef<THREE.Mesh>(null);
   const cargoRef = useRef<THREE.Mesh>(null);
   const alertRef = useRef<THREE.Group>(null);
   
   const isConstant = term.e === 0;
   const displayVar = isConstant ? "" : term.v;
   const displayExp = (isConstant || term.e === 1) ? "" : term.e.toString();

   useEffect(() => {
      if (cargoRef.current && baseRef.current) {
         let cargoColor = "#3b82f6"; // blue default
         let cargoEmissive = "#000000";
         let baseColor = "#475569"; // slate default

         if (status === "match") {
            cargoColor = "#10b981"; // green
            cargoEmissive = "#059669";
         } else if (status === "fail") {
            cargoColor = "#f43f5e"; // red
            cargoEmissive = "#e11d48";
         }

         if (misconception) {
            baseColor = "#eab308"; // highlight base in yellow to show misconception
            gsap.to(baseRef.current.material as any, { color: baseColor, emissive: "#a16207", emissiveIntensity: 0.5, duration: 0.5, yoyo: true, repeat: -1 });
         } else {
            gsap.killTweensOf(baseRef.current.material);
            gsap.to(baseRef.current.material as any, { color: baseColor, emissive: "#000", emissiveIntensity: 0, duration: 0.5 });
         }

         gsap.to(cargoRef.current.material as any, { 
            color: cargoColor, 
            emissive: cargoEmissive, 
            emissiveIntensity: status === "idle" ? 0 : 0.8, 
            duration: 0.5 
         });
      }

      if (alertRef.current) {
         if (status === "fail") {
            gsap.to(alertRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: "back.out(1.5)" });
         } else {
            gsap.to(alertRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
         }
      }
   }, [status, misconception]);

   return (
      <group position={position} ref={groupRef}>
         {/* Base Plate (Coefficient) */}
         <mesh ref={baseRef} position={[0, -0.6, 0]} castShadow>
            <boxGeometry args={[1.8, 0.4, 1.4]} />
            <meshStandardMaterial color="#475569" roughness={0.7} />
         </mesh>
         <Text position={[-0.5, -0.6, 0.71]} fontSize={0.3} color="#fff">
            {term.c}
         </Text>

         {/* Cargo Block (Variable & Exponent) */}
         <group position={[0, 0.4, 0]}>
             <mesh ref={cargoRef} castShadow>
                <boxGeometry args={[1.4, 1.4, 1.2]} />
                <meshStandardMaterial color="#3b82f6" transparent opacity={0.9} roughness={0.1} metalness={0.2} />
             </mesh>
             
             {/* Text Group */}
             <group position={[0, 0, 0.61]}>
                 <Text position={[displayExp ? -0.2 : 0, 0, 0]} fontSize={0.8} color="#fff" fontStyle="italic">
                    {displayVar}
                 </Text>
                 {displayExp && (
                     <Text position={[0.4, 0.3, 0]} fontSize={0.4} color="#fde047">
                        {displayExp}
                     </Text>
                 )}
             </group>
         </group>

         {/* Alert Barrier Symbol (Cross) */}
         <group ref={alertRef} position={[0, 1.8, 0]} scale={[0,0,0]}>
            <mesh rotation={[0, 0, Math.PI/4]}>
               <boxGeometry args={[1.2, 0.2, 0.1]} />
               <meshStandardMaterial color="#ef4444" emissive="#ef4444" emissiveIntensity={1} />
            </mesh>
            <mesh rotation={[0, 0, -Math.PI/4]}>
               <boxGeometry args={[1.2, 0.2, 0.1]} />
               <meshStandardMaterial color="#ef4444" emissive="#ef4444" emissiveIntensity={1} />
            </mesh>
         </group>
      </group>
   );
}

function Scanner({ position, targetTerm, active }: { position: [number, number, number], targetTerm: TermData, active: boolean }) {
    const groupRef = useRef<THREE.Group>(null);
    const laserRef = useRef<THREE.Mesh>(null);
    const lightRef = useRef<THREE.PointLight>(null);

    useEffect(() => {
        if (active) {
            gsap.to(laserRef.current!.scale, { y: 1, duration: 0.3 });
            gsap.to(lightRef.current!, { intensity: 1, duration: 0.3 });
        } else {
            gsap.to(laserRef.current!.scale, { y: 0, duration: 0.3 });
            gsap.to(lightRef.current!, { intensity: 0, duration: 0.3 });
        }
    }, [active]);

    useEffect(() => {
        gsap.to(groupRef.current!.position, { 
            x: position[0], 
            y: position[1], 
            z: position[2], 
            duration: 0.8, 
            ease: "power2.inOut" 
        });
    }, [position]);

    return (
        <group ref={groupRef} position={[0, 3, 0]}>
            {/* Scanner Head */}
            <mesh position={[0, 0, 0]}>
                <boxGeometry args={[2.5, 0.4, 2]} />
                <meshStandardMaterial color="#1e293b" metalness={0.8} />
            </mesh>
            <Text position={[0, 0, 1.01]} fontSize={0.25} color="#38bdf8">TARGET TEMPLATE</Text>
            
            {/* Hologram of Target */}
            <group position={[0, -0.6, 1.01]}>
               <Text position={[-0.2, 0, 0]} fontSize={0.6} color="#38bdf8" fontStyle="italic">{targetTerm.v}</Text>
               <Text position={[0.2, 0.2, 0]} fontSize={0.3} color="#fde047">{targetTerm.e}</Text>
            </group>

            {/* Laser Beam */}
            <mesh ref={laserRef} position={[0, -2, 0]} scale={[1,0,1]}>
                <cylinderGeometry args={[1.2, 1.2, 4, 32]} />
                <meshStandardMaterial color="#38bdf8" transparent opacity={0.2} emissive="#38bdf8" emissiveIntensity={0.5} depthWrite={false} side={THREE.DoubleSide}/>
            </mesh>
            <pointLight ref={lightRef} position={[0, -1, 0]} color="#38bdf8" intensity={0} distance={5} />
        </group>
    );
}

function Bins({ step }: { step: number }) {
    const groupRef = useRef<THREE.Group>(null);
    useEffect(() => {
        if (step === 4) {
            gsap.to(groupRef.current!.position, { y: 0, duration: 1, ease: "back.out(1.2)" });
        } else {
            gsap.to(groupRef.current!.position, { y: -10, duration: 0.5 });
        }
    }, [step]);

    return (
        <group ref={groupRef} position={[0, -10, 0]}>
            {/* Like Bin (Back) */}
            <group position={[-2.5, -1, -3]}>
                <mesh position={[0, -0.5, 0]} receiveShadow>
                    <boxGeometry args={[4, 1, 3]} />
                    <meshStandardMaterial color="#064e3b" transparent opacity={0.8}/>
                </mesh>
                <Text position={[0, 0.5, 0]} rotation={[-Math.PI/4, 0, 0]} fontSize={0.5} color="#10b981">LIKE TERMS</Text>
            </group>
            
            {/* Unlike Bin (Front) */}
            <group position={[3.5, -1, 3]}>
                <mesh position={[0, -0.5, 0]} receiveShadow>
                    <boxGeometry args={[6, 1, 3]} />
                    <meshStandardMaterial color="#7f1d1d" transparent opacity={0.8}/>
                </mesh>
                <Text position={[0, 0.5, 0]} rotation={[-Math.PI/4, 0, 0]} fontSize={0.5} color="#f43f5e">UNLIKE TERMS</Text>
            </group>
        </group>
    )
}

// ---------------------------------------------------------
// MAIN SCENE CONTROLLER
// ---------------------------------------------------------
const termsList: TermData[] = [
    { c: 5, v: 'x', e: 2, id: 't1' },
    { c: -2, v: 'x', e: 2, id: 't2' },
    { c: 3, v: 'x', e: 1, id: 't3' },
    { c: 5, v: 'y', e: 1, id: 't4' },
];
const targetTemplate: TermData = { c: 1, v: 'x', e: 2, id: 'target' };

function Scene({ step, sbTerm, sbScanState }: any) {
  const { camera } = useThree();
  const controlsRef = useRef<any>(null);
  
  // Positional logic for static terms
  const tRefs = [useRef<THREE.Group>(null), useRef<THREE.Group>(null), useRef<THREE.Group>(null), useRef<THREE.Group>(null)];
  const sbRef = useRef<THREE.Group>(null);

  const [scanPos, setScanPos] = useState<[number, number, number]>([-8, 3, 0]);
  const [scanActive, setScanActive] = useState(false);

  const getStatus = (idx: number) => {
      if (step === 1 && (idx === 0 || idx === 1)) return "match";
      if (step === 2 && idx === 2) return "fail";
      if (step === 3 && idx === 3) return "fail";
      if (step === 4) {
          if (idx === 0 || idx === 1) return "match";
          return "fail";
      }
      return "idle";
  };

  useEffect(() => {
    let t = { camX: 0, camY: 2, camZ: 14, tarX: 0, tarY: 0 };
    
    if (step === 0) {
        setScanPos([-8, 3, 0]);
        setScanActive(false);
        // Reset positions
        tRefs.forEach((ref, i) => {
            if(ref.current) gsap.to(ref.current.position, { x: -4.5 + i * 3, y: 0, z: 0, duration: 1, ease: "power2.out" });
        });
    } else if (step === 1) {
        t = { camX: -3, camY: 2, camZ: 10, tarX: -3, tarY: 0 };
        setScanPos([-3, 3, 0]); // Hover between 5x^2 and -2x^2
        setScanActive(true);
    } else if (step === 2) {
        t = { camX: 1.5, camY: 2, camZ: 10, tarX: 1.5, tarY: 0 };
        setScanPos([1.5, 3, 0]); // Hover 3x
    } else if (step === 3) {
        t = { camX: 0, camY: 2, camZ: 12, tarX: 0, tarY: 0 };
        setScanPos([4.5, 3, 0]); // Hover 5y
    } else if (step === 4) {
        t = { camX: 0, camY: 4, camZ: 14, tarX: 0, tarY: -2 };
        setScanPos([0, 6, 0]); // Scanner retracts
        setScanActive(false);
        // Sort animation
        tRefs.forEach((ref, i) => {
            if(ref.current) {
                if (i === 0 || i === 1) { // Like
                   gsap.to(ref.current.position, { x: -3.5 + i * 2, y: -0.5, z: -3, duration: 1, ease: "back.in(1.2)" });
                } else { // Unlike
                   gsap.to(ref.current.position, { x: 0 + i * 1.5, y: -0.5, z: 3, duration: 1, ease: "back.in(1.2)" });
                }
            }
        });
    } else if (step === 5) {
        t = { camX: 0, camY: 2, camZ: 10, tarX: 0, tarY: 0 };
        // Hide static terms
        tRefs.forEach((ref) => {
            if(ref.current) gsap.to(ref.current.position, { y: -10, duration: 0.5 });
        });

        // Sandbox logic
        if (sbScanState === "idle") {
            setScanPos([0, 5, 0]);
            setScanActive(false);
            if(sbRef.current) gsap.to(sbRef.current.position, { x: -6, y: 0, z: 0, duration: 0.5 });
        } else if (sbScanState === "scanning") {
            setScanPos([0, 3, 0]);
            setScanActive(true);
            if(sbRef.current) gsap.to(sbRef.current.position, { x: 0, y: 0, z: 0, duration: 0.5 });
        } else if (sbScanState === "sorted") {
            setScanActive(false);
            setScanPos([0, 5, 0]);
            const isMatch = sbTerm.v === targetTemplate.v && sbTerm.e === targetTemplate.e;
            if(sbRef.current) {
                if (isMatch) gsap.to(sbRef.current.position, { x: -2.5, y: -0.5, z: -3, duration: 1, ease: "back.in(1.2)" });
                else gsap.to(sbRef.current.position, { x: 3.5, y: -0.5, z: 3, duration: 1, ease: "back.in(1.2)" });
            }
        }
    }

    gsap.to(camera.position, { x: t.camX, y: t.camY, z: t.camZ, duration: 1.5, ease: 'power3.inOut' });
    if (controlsRef.current) {
      gsap.to(controlsRef.current.target, { x: t.tarX, y: t.tarY, z: 0, duration: 1.5, ease: 'power3.inOut' });
    }
  }, [step, camera, sbScanState]);

  return (
    <group>
      <OrbitControls 
        ref={controlsRef} 
        enablePan={false} 
        enableZoom={false}
        maxPolarAngle={Math.PI / 2}
        minPolarAngle={Math.PI / 4}
        maxAzimuthAngle={0.4}
        minAzimuthAngle={-0.4}
      />
      
      {/* Belt Platform */}
      <mesh position={[0, -1, 0]} receiveShadow>
         <boxGeometry args={[20, 0.2, 4]} />
         <meshStandardMaterial color="#1e293b" roughness={0.9} />
      </mesh>
      {/* Stripes for belt */}
      <mesh position={[0, -0.89, 0]} receiveShadow>
         <planeGeometry args={[20, 2]} />
         <meshStandardMaterial color="#334155" />
      </mesh>

      {/* The Target Scanner */}
      <Scanner position={scanPos} targetTerm={targetTemplate} active={scanActive} />

      {/* Static Terms */}
      <group ref={tRefs[0]}><TermBlock term={termsList[0]} position={[-4.5, 0, 0]} status={getStatus(0)} misconception={step===3} /></group>
      <group ref={tRefs[1]}><TermBlock term={termsList[1]} position={[-1.5, 0, 0]} status={getStatus(1)} /></group>
      <group ref={tRefs[2]}><TermBlock term={termsList[2]} position={[1.5, 0, 0]} status={getStatus(2)} /></group>
      <group ref={tRefs[3]}><TermBlock term={termsList[3]} position={[4.5, 0, 0]} status={getStatus(3)} misconception={step===3} /></group>

      {/* Sorting Bins */}
      <Bins step={step >= 4 ? 4 : 0} />

      {/* Sandbox Term */}
      {step === 5 && (
          <group ref={sbRef}>
              <TermBlock 
                 term={sbTerm} 
                 position={[-6, 0, 0]} 
                 isSandbox={true} 
                 status={sbScanState === "idle" ? "idle" : (sbTerm.v === targetTemplate.v && sbTerm.e === targetTemplate.e ? "match" : "fail")} 
              />
          </group>
      )}

    </group>
  );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function LikeTermsSimulation() {
  const [step, setStep] = useState(0);
  
  // Sandbox State
  const [sbCoef, setSbCoef] = useState(-3);
  const [sbVar, setSbVar] = useState('x');
  const [sbExp, setSbExp] = useState(2);
  const [sbScanState, setSbScanState] = useState<"idle"|"scanning"|"sorted">("idle");

  const sbTerm = useMemo(() => ({ c: sbCoef, v: sbVar, e: sbExp, id: 'sb1' }), [sbCoef, sbVar, sbExp]);

  const handleNextStep = () => {
    if (step < 5) setStep(s => s + 1);
  };

  const runSandboxTest = () => {
      setSbScanState("scanning");
      setTimeout(() => {
          setSbScanState("sorted");
      }, 1500);
  };

  const resetSandbox = () => {
      setSbScanState("idle");
  };

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`5x^2, \\; -2x^2, \\; 3x, \\; 5y`} />;
    if (step === 1) return <BlockMath math={`5x^2 \\text{ and } -2x^2 \\implies \\text{Like Terms}`} />;
    if (step === 2) return <BlockMath math={`x^2 \\neq x^1 \\implies \\text{Unlike Term}`} />;
    if (step === 3) return <BlockMath math={`5x^2 \\text{ and } 5y \\implies \\text{UNLIKE! (Ignore 5)}`} />;
    if (step === 4) return <BlockMath math={`\\text{Sorted successfully.}`} />;
    if (step === 5) {
       return <BlockMath math={`\\text{Test: } ${sbCoef}${sbVar === 'none' ? '' : sbVar}^{${sbExp}} \\quad \\text{Target: } x^2`} />;
    }
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Term Sorter Setup", icon: <MoveRight size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>Four terms approach the scanner. The sorter's target template is set to <span className="font-mono">x²</span>. It will strictly inspect the variable blocks (the cargo) and ignore the base coefficients.</> };
    if (step === 1) return { title: "Perfect Structural Match", icon: <CheckCircle size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>Both <span className="font-mono">5x²</span> and <span className="font-mono">-2x²</span> have exactly the same variable (<span className="font-mono">x</span>) and the same exponent (<span className="font-mono">2</span>). They are <b>Like Terms</b>.</> };
    if (step === 2) return { title: "Exponent Mismatch", icon: <ShieldAlert size={18}/>, color: "border-l-yellow-500 text-yellow-300 bg-yellow-900/40", body: <>The term <span className="font-mono">3x</span> fails the scan. While it has the correct variable (<span className="font-mono">x</span>), its exponent is implicitly <span className="font-mono">1</span>, not <span className="font-mono">2</span>. Exponents must match exactly.</> };
    if (step === 3) return { title: "The Coefficient Misconception", icon: <AlertTriangle size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> Look at <span className="font-mono">5x²</span> and <span className="font-mono">5y</span>. They both have a coefficient of <span className="font-mono">5</span>. Does this make them like terms? <b>NO!</b> The scanner completely ignores the coefficient base. Variables must match.</> };
    if (step === 4) return { title: "Sorting Complete", icon: <Target size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>Like terms are collected together because they can be mathematically combined ($5x^2 - 2x^2 = 3x^2$). Unlike terms must remain separate.</> };
    if (step === 5) return { title: "Sandbox Testing", icon: <SlidersHorizontal size={18}/>, color: "border-l-slate-500 text-slate-300 bg-slate-800/40", body: <>Build your own term to test against the <span className="font-mono">x²</span> template. See if you can trick the scanner!</> };
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
              Like vs. Unlike Terms
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Variable structure scanning and coefficient filtering.
            </p>
          </div>
          <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[340px] flex justify-center shadow-inner overflow-x-auto">
             <div className="text-white text-lg px-2 whitespace-nowrap">
               {renderMath()}
             </div>
          </div>
        </div>
      </div>
      
      {/* R3F Canvas */}
      <div className="flex-1 w-full h-full relative z-0 touch-none">
         <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
           <Canvas shadows camera={{ position: [0, 2, 14], fov: 45 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <Scene step={step} sbTerm={sbTerm} sbScanState={sbScanState} />
              
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
                 <span className="text-blue-400">Belt</span>
                 <span className="text-emerald-400">Match</span>
                 <span className="text-yellow-400">Exp Fail</span>
                 <span className="text-rose-400">Coef Trap</span>
                 <span className="text-purple-400">Sort</span>
                 <span className="text-slate-300">Sandbox</span>
              </div>
              <div className="flex gap-1">
                 {[0, 1, 2, 3, 4, 5].map(s => (
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

           {/* Sandbox Controls */}
           {step === 5 && (
              <div className="flex flex-col gap-3 mb-4">
                 <div className="grid grid-cols-3 gap-3">
                    <div>
                       <label className="text-[10px] uppercase text-slate-500 mb-1 block">Coefficient</label>
                       <input 
                           type="range" min="-10" max="10" 
                           value={sbCoef} onChange={e=>setSbCoef(parseInt(e.target.value))} 
                           disabled={sbScanState !== "idle"}
                           className="w-full accent-blue-500" 
                        />
                       <div className="text-xs text-center text-slate-400">{sbCoef}</div>
                    </div>
                    <div>
                       <label className="text-[10px] uppercase text-slate-500 mb-1 block">Variable</label>
                       <select 
                          value={sbVar} onChange={e=>setSbVar(e.target.value)}
                          disabled={sbScanState !== "idle"}
                          className="w-full bg-slate-800 text-white text-xs p-1 rounded border border-slate-700 outline-none"
                       >
                           <option value="x">x</option>
                           <option value="y">y</option>
                           <option value="z">z</option>
                       </select>
                    </div>
                    <div>
                       <label className="text-[10px] uppercase text-slate-500 mb-1 block">Exponent</label>
                       <input 
                           type="range" min="1" max="4" 
                           value={sbExp} onChange={e=>setSbExp(parseInt(e.target.value))} 
                           disabled={sbScanState !== "idle"}
                           className="w-full accent-yellow-500" 
                        />
                       <div className="text-xs text-center text-slate-400">{sbExp}</div>
                    </div>
                 </div>
              </div>
           )}

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-auto">
             {step < 5 ? (
                 <button 
                    onClick={handleNextStep} 
                    className="bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm"
                 >
                    {step === 0 ? "Scan Term 1 & 2" : 
                     step === 1 ? "Scan Term 3" : 
                     step === 2 ? "Scan Term 4" : 
                     step === 3 ? "Run Sorter" : 
                     step === 4 ? "Enter Sandbox" : ""} 
                    <MoveRight size={16} />
                 </button>
             ) : (
                 <button 
                    onClick={sbScanState === "idle" ? runSandboxTest : resetSandbox} 
                    disabled={sbScanState === "scanning"}
                    className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm ${
                        sbScanState === "scanning" ? 'bg-slate-700 text-slate-400' : 
                        sbScanState === "sorted" ? 'bg-amber-600 hover:bg-amber-500 text-white' : 
                        'bg-blue-600 hover:bg-blue-500 text-white'
                    }`}
                 >
                    {sbScanState === "idle" ? "Send to Scanner" : sbScanState === "scanning" ? "Scanning..." : "Build New Term"}
                    {sbScanState === "idle" && <Scan size={16} />}
                 </button>
             )}

             <button 
                onClick={() => { setStep(0); resetSandbox(); }} 
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
