import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Float } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, Plus, Combine, AlertTriangle, SlidersHorizontal, RotateCcw, Ban, ShieldAlert } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function UnitBlock({ 
   type = "base", position, delay = 0 
}: { 
   type?: "base" | "intruder", position: [number, number, number], delay?: number 
}) {
   const groupRef = useRef<THREE.Group>(null);
   const isBase = type === "base";

   useEffect(() => {
       if (groupRef.current) {
           gsap.fromTo(groupRef.current.scale, 
               { x: 0, y: 0, z: 0 }, 
               { x: 1, y: 1, z: 1, duration: 0.5, delay: delay, ease: "back.out(1.5)" }
           );
       }
   }, [delay]);

   return (
       <group ref={groupRef} position={position}>
           {isBase ? (
               <mesh castShadow receiveShadow>
                   <boxGeometry args={[1.8, 0.88, 1.8]} />
                   <meshStandardMaterial color="#3b82f6" roughness={0.4} metalness={0.1} />
               </mesh>
           ) : (
               <mesh castShadow receiveShadow>
                   <cylinderGeometry args={[0.8, 0.8, 0.88, 32]} />
                   <meshStandardMaterial color="#8b5cf6" roughness={0.4} metalness={0.1} />
               </mesh>
           )}
           
           {/* Stamp */}
           <group position={[0, 0, isBase ? 0.91 : 0.81]}>
               <Text position={[-0.1, 0, 0]} fontSize={0.6} color="#ffffff" fontStyle="italic">
                   {isBase ? "x" : "x"}
               </Text>
               {isBase && (
                   <Text position={[0.3, 0.25, 0]} fontSize={0.3} color="#fde047">
                       2
                   </Text>
               )}
           </group>
       </group>
   );
}

function CounterNode({ step, coefA, coefB }: { step: number, coefA: number, coefB: number }) {
    const nodeA = useRef<THREE.Group>(null);
    const nodeB = useRef<THREE.Group>(null);
    const nodeCombined = useRef<THREE.Group>(null);

    const hA = coefA * 0.9 + 0.5;
    const hB = coefB * 0.9 + 0.5;
    const hCombined = (coefA + coefB) * 0.9 + 0.5;

    useEffect(() => {
        if (step === 0) {
            gsap.to(nodeA.current!.scale, { x: 1, y: 1, z: 1, duration: 0.3 });
            gsap.to(nodeB.current!.scale, { x: 1, y: 1, z: 1, duration: 0.3 });
            gsap.to(nodeA.current!.position, { x: -2.5, y: hA, z: 0, duration: 0.8, ease: "power2.out" });
            gsap.to(nodeB.current!.position, { x: 2.5, y: hB, z: 0, duration: 0.8, ease: "power2.out" });
            
            gsap.to(nodeCombined.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
        } else {
            gsap.to(nodeA.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
            gsap.to(nodeB.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
            
            gsap.to(nodeCombined.current!.scale, { x: 1, y: 1, z: 1, duration: 0.5, delay: 0.3, ease: "back.out(1.2)" });
            gsap.to(nodeCombined.current!.position, { x: 0, y: hCombined, z: 0, duration: 0.8, ease: "power2.out" });
        }
    }, [step, coefA, coefB, hA, hB, hCombined]);

    return (
        <group>
            {/* Separate Nodes (Step 0) */}
            <group ref={nodeA} scale={[0,0,0]}>
                <mesh position={[0, 0, 0]}><boxGeometry args={[1.5, 0.8, 0.1]} /><meshStandardMaterial color="#0f172a" transparent opacity={0.8} /></mesh>
                <Text position={[0, 0, 0.1]} fontSize={0.5} color="#60a5fa">{coefA}x²</Text>
            </group>
            <group ref={nodeB} scale={[0,0,0]}>
                <mesh position={[0, 0, 0]}><boxGeometry args={[1.5, 0.8, 0.1]} /><meshStandardMaterial color="#0f172a" transparent opacity={0.8} /></mesh>
                <Text position={[0, 0, 0.1]} fontSize={0.5} color="#60a5fa">{coefB}x²</Text>
            </group>

            {/* Combined Node (Step 1+) */}
            <group ref={nodeCombined} scale={[0,0,0]}>
                <mesh position={[0, 0, 0]}><boxGeometry args={[3.5, 1, 0.1]} /><meshStandardMaterial color="#1e293b" transparent opacity={0.9} /></mesh>
                <Text position={[0, 0, 0.11]} fontSize={0.5} color="#10b981">
                    {coefA} + {coefB} = {coefA + coefB}
                </Text>
            </group>
        </group>
    );
}

function MisconceptionHighlight({ step, targetY }: { step: number, targetY: number }) {
    const groupRef = useRef<THREE.Group>(null);
    useEffect(() => {
        if (step === 2) {
            gsap.to(groupRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: "back.out(1.2)" });
            gsap.to(groupRef.current!.position, { y: targetY, duration: 0.5 });
        } else {
            gsap.to(groupRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
        }
    }, [step, targetY]);

    return (
        <group ref={groupRef} position={[4, 0, 0]} scale={[0,0,0]}>
            <mesh position={[0, 0, 0]}>
               <boxGeometry args={[4.5, 1.5, 0.1]} />
               <meshStandardMaterial color="#7f1d1d" transparent opacity={0.9} />
            </mesh>
            <Text position={[0, 0.3, 0.1]} fontSize={0.4} color="#f87171">STAMP UNCHANGED</Text>
            <Text position={[-0.5, -0.3, 0.1]} fontSize={0.4} color="#fff">NOT</Text>
            <Text position={[0.4, -0.3, 0.1]} fontSize={0.5} color="#fca5a5" fontStyle="italic">x</Text>
            <Text position={[0.8, -0.1, 0.1]} fontSize={0.3} color="#fef08a">4</Text>
            
            {/* Arrow pointing to stack */}
            <mesh position={[-2.8, 0, 0]}>
                <boxGeometry args={[1, 0.1, 0.1]} />
                <meshStandardMaterial color="#f87171" />
            </mesh>
        </group>
    )
}

function Scene({ step, coefA, coefB }: any) {
  const { camera } = useThree();
  const controlsRef = useRef<any>(null);

  const blocksARefs = useRef<THREE.Group[]>([]);
  const blocksBRefs = useRef<THREE.Group[]>([]);
  const intruderGroupRef = useRef<THREE.Group>(null);
  
  // Re-initialize refs based on current coefficients
  blocksARefs.current = blocksARefs.current.slice(0, coefA);
  blocksBRefs.current = blocksBRefs.current.slice(0, coefB);

  useEffect(() => {
      // Animate Block Group A
      blocksARefs.current.forEach((ref, i) => {
          if (!ref) return;
          if (step === 0) {
              gsap.to(ref.position, { x: -2.5, y: i * 0.9, z: 0, duration: 0.8, ease: "power2.out" });
          } else {
              gsap.to(ref.position, { x: 0, y: i * 0.9, z: 0, duration: 1, ease: "back.out(1.2)" });
          }
      });

      // Animate Block Group B
      blocksBRefs.current.forEach((ref, i) => {
          if (!ref) return;
          if (step === 0) {
              gsap.to(ref.position, { x: 2.5, y: i * 0.9, z: 0, duration: 0.8, ease: "power2.out" });
          } else {
              gsap.to(ref.position, { x: 0, y: (coefA + i) * 0.9, z: 0, duration: 1, ease: "back.out(1.2)", delay: 0.1 });
          }
      });

      // Animate Intruder (2x)
      if (intruderGroupRef.current) {
          if (step < 3 || step === 4) {
              gsap.to(intruderGroupRef.current.position, { x: 12, duration: 0.5 }); // Hide offscreen
              gsap.to(intruderGroupRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
          } else if (step === 3) {
              gsap.to(intruderGroupRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.1 });
              const tl = gsap.timeline();
              tl.fromTo(intruderGroupRef.current.position, { x: 10 }, { x: 1.5, duration: 0.6, ease: "power2.in" });
              tl.to(intruderGroupRef.current.position, { x: 4.5, duration: 0.8, ease: "bounce.out" });
          }
      }

      // Camera Adjustment based on height
      const totalH = (coefA + coefB) * 0.9;
      let t = { camX: 0, camY: totalH / 2, camZ: 14 + totalH * 0.2, tarX: 0, tarY: totalH / 2 };
      
      if (step === 3) {
          t = { camX: 2, camY: totalH / 2, camZ: 16 + totalH * 0.2, tarX: 2, tarY: totalH / 2 };
      }
      
      gsap.to(camera.position, { x: t.camX, y: t.camY, z: t.camZ, duration: 1.5, ease: 'power3.inOut' });
      if (controlsRef.current) {
        gsap.to(controlsRef.current.target, { x: t.tarX, y: t.tarY, z: 0, duration: 1.5, ease: 'power3.inOut' });
      }

  }, [step, coefA, coefB, camera]);

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
      
      {/* Floor */}
      <mesh position={[0, -0.44, 0]} receiveShadow>
         <boxGeometry args={[40, 0.1, 10]} />
         <meshStandardMaterial color="#1e293b" roughness={0.8} />
      </mesh>
      {/* Grid lines */}
      <gridHelper args={[40, 40, 0x334155, 0x334155]} position={[0, -0.38, 0]} />

      {/* Block Stack A */}
      {Array.from({ length: coefA }).map((_, i) => (
          <group key={`a-${i}`} ref={el => blocksARefs.current[i] = el!}>
              <UnitBlock type="base" position={[0, 0, 0]} delay={i * 0.05} />
          </group>
      ))}

      {/* Block Stack B */}
      {Array.from({ length: coefB }).map((_, i) => (
          <group key={`b-${i}`} ref={el => blocksBRefs.current[i] = el!}>
              <UnitBlock type="base" position={[0, 0, 0]} delay={i * 0.05 + 0.2} />
          </group>
      ))}

      {/* Intruder Stack (2x) */}
      <group ref={intruderGroupRef} position={[12, 0, 0]} scale={[0,0,0]}>
          <UnitBlock type="intruder" position={[0, 0, 0]} />
          <UnitBlock type="intruder" position={[0, 0.9, 0]} />
          {/* Label for intruder */}
          <group position={[0, 2.5, 0]}>
              <mesh><boxGeometry args={[1.5, 0.8, 0.1]}/><meshStandardMaterial color="#4c1d95" transparent opacity={0.8}/></mesh>
              <Text position={[0, 0, 0.1]} fontSize={0.5} color="#c4b5fd">2x</Text>
          </group>
      </group>

      <CounterNode step={step} coefA={coefA} coefB={coefB} />
      <MisconceptionHighlight step={step} targetY={(coefA + coefB) * 0.45} />

    </group>
  );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function AddingLikeTermsSimulation() {
  const [step, setStep] = useState(0);
  
  // Sandbox State
  const [coefA, setCoefA] = useState(3);
  const [coefB, setCoefB] = useState(5);

  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`3x^2 \\quad \\text{and} \\quad 5x^2`} />;
    if (step === 1) return <BlockMath math={`3x^2 + 5x^2 = 8x^2`} />;
    if (step === 2) return <BlockMath math={`3x^2 + 5x^2 \\neq 8x^4`} />;
    if (step === 3) return <BlockMath math={`8x^2 + 2x = 8x^2 + 2x \\quad \\text{(Cannot Simplify)}`} />;
    if (step === 4) {
       return <BlockMath math={`${coefA}x^2 + ${coefB}x^2 = ${coefA + coefB}x^2`} />;
    }
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Identical Structures", icon: <MoveRight size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>We have two separate groups of blocks. The left group has <span className="font-mono">3</span> blocks, and the right group has <span className="font-mono">5</span> blocks. Crucially, every single block is stamped with the exact same identity: <span className="font-mono">x²</span>.</> };
    if (step === 1) return { title: "Combining Like Terms", icon: <Combine size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>Because their identity stamps match perfectly, they can be stacked together into a single column. The coefficients simply add together: <span className="font-mono">3 + 5 = 8</span> total blocks.</> };
    if (step === 2) return { title: "Exponent Conservation", icon: <AlertTriangle size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> Notice the stamps on the 8 combined blocks. They still say <span className="font-mono">x²</span>, NOT <span className="font-mono">x⁴</span>. When adding like terms, you are simply counting how many of that specific term you have. The exponent <b>never</b> changes.</> };
    if (step === 3) return { title: "Shape Mismatch Bounce", icon: <ShieldAlert size={18}/>, color: "border-l-yellow-500 text-yellow-300 bg-yellow-900/40", body: <>A new term <span className="font-mono">2x</span> tries to join the stack. But look at its shape! It is physically incompatible with the <span className="font-mono">x²</span> blocks. Unlike terms cannot be combined into a single stack; they remain separate.</> };
    if (step === 4) return { title: "Sandbox Engine", icon: <SlidersHorizontal size={18}/>, color: "border-l-slate-500 text-slate-300 bg-slate-800/40", body: <>Adjust the initial coefficients for the two <span className="font-mono">x²</span> groups. Watch them combine and recalculate. The shape and the stamp will always remain preserved.</> };
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
              Adding Like Terms
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Stacking identical variables and preserving exponents.
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
           <Canvas shadows camera={{ position: [0, 5, 16], fov: 45 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <Scene step={step} coefA={coefA} coefB={coefB} />
              
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
                 <span className="text-blue-400">Setup</span>
                 <span className="text-emerald-400">Merge</span>
                 <span className="text-rose-400">Exponent</span>
                 <span className="text-yellow-400">Bounce</span>
                 <span className="text-slate-300">Sandbox</span>
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

           {/* Sandbox Controls */}
           {step === 4 && (
              <div className="flex flex-col gap-3 mb-4">
                 <div className="grid grid-cols-2 gap-4">
                    <div>
                       <label className="text-[10px] uppercase text-slate-500 mb-1 block">Left Coef (x²)</label>
                       <input 
                           type="range" min="1" max="8" 
                           value={coefA} onChange={e=>setCoefA(parseInt(e.target.value))} 
                           className="w-full accent-blue-500" 
                        />
                       <div className="text-xs text-center text-slate-400">{coefA}</div>
                    </div>
                    <div>
                       <label className="text-[10px] uppercase text-slate-500 mb-1 block">Right Coef (x²)</label>
                       <input 
                           type="range" min="1" max="8" 
                           value={coefB} onChange={e=>setCoefB(parseInt(e.target.value))} 
                           className="w-full accent-emerald-500" 
                        />
                       <div className="text-xs text-center text-slate-400">{coefB}</div>
                    </div>
                 </div>
              </div>
           )}

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-auto">
             <button 
                onClick={handleNextStep} 
                disabled={step === 4} 
                className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm ${step === 4 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
             >
                {step === 0 ? "Combine Columns" : 
                 step === 1 ? "Verify Labels" : 
                 step === 2 ? "Test 2x Intruder" : 
                 step === 3 ? "Enter Sandbox" : "Completed"} 
                {step < 4 && <MoveRight size={16} />}
             </button>
             <button 
                onClick={() => { setStep(0); }} 
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
