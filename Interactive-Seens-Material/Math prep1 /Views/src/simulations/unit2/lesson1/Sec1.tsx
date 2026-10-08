import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Float } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, Scan, Plus, Minus, AlertTriangle, SlidersHorizontal, RotateCcw, Combine } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function Block({ 
   text, subText = "", exp = "", color, position, width = 1.4, isOp = false, opacity = 1
}: { 
   text: string, subText?: string, exp?: string | number, color: string, position: [number, number, number], width?: number, isOp?: boolean, opacity?: number
}) {
   const groupRef = useRef<THREE.Group>(null);
   const meshRef = useRef<THREE.Mesh>(null);
   
   // Handle dynamic opacity
   useEffect(() => {
      if (meshRef.current) {
         gsap.to(meshRef.current.material as any, { opacity, duration: 0.4 });
      }
   }, [opacity]);

   return (
      <group position={position} ref={groupRef}>
         <mesh ref={meshRef} castShadow>
            {isOp ? <sphereGeometry args={[0.6, 32, 32]} /> : <boxGeometry args={[width, 1.4, 0.4]} />}
            <meshStandardMaterial color={color} transparent opacity={opacity} roughness={0.3} />
         </mesh>
         <Text position={[0, 0, 0.21]} fontSize={isOp ? 0.8 : 0.7} color="#ffffff" outlineWidth={0.05} outlineColor="#000">
            {text}
         </Text>
         {subText && (
            <Text position={[0, -0.4, 0.22]} fontSize={0.25} color="#cbd5e1" outlineWidth={0.02} outlineColor="#000">
               {subText}
            </Text>
         )}
         {exp !== "" && exp !== null && opacity > 0 && (
            <group position={[width/2 - 0.2, 0.6, 0.25]}>
               <mesh>
                  <circleGeometry args={[0.3, 32]} />
                  <meshStandardMaterial color="#eab308" emissive="#eab308" emissiveIntensity={0.2} transparent opacity={opacity} />
               </mesh>
               <Text position={[0, 0, 0.05]} fontSize={0.4} color="#000" fontStyle="italic">
                  {exp.toString()}
               </Text>
            </group>
         )}
      </group>
   );
}

function MainTerm({ step, coef, expX, expY }: { step: number, coef: number, expX: number, expY: number }) {
   const cRef = useRef<THREE.Group>(null);
   const xRef = useRef<THREE.Group>(null);
   const yRef = useRef<THREE.Group>(null);
   
   const scanLineRef = useRef<THREE.Mesh>(null);
   const scanGroupRef = useRef<THREE.Group>(null);

   const degreeBadgeRef = useRef<THREE.Group>(null);

   useEffect(() => {
      // Base positions
      if (step === 0) {
         gsap.to(cRef.current!.position, { x: -3.5, y: 0, z: 0, duration: 0.8, ease: "back.out(1.2)" });
         gsap.to(xRef.current!.position, { x: 0, y: 0, z: 0, duration: 0.8, ease: "back.out(1.2)" });
         gsap.to(yRef.current!.position, { x: 3.5, y: 0, z: 0, duration: 0.8, ease: "back.out(1.2)" });
         
         gsap.to(scanGroupRef.current!.scale, { x: 0, y:0, z:0, duration: 0.3 });
         gsap.to(degreeBadgeRef.current!.scale, { x: 0, y:0, z:0, duration: 0.3 });
         
      } else if (step === 1) {
         // Assemble
         gsap.to(cRef.current!.position, { x: -1.5, y: 0, z: 0, duration: 0.8, ease: "back.out(1.5)" });
         gsap.to(xRef.current!.position, { x: 0, y: 0, z: 0, duration: 0.8, ease: "back.out(1.5)" });
         gsap.to(yRef.current!.position, { x: 1.5, y: 0, z: 0, duration: 0.8, ease: "back.out(1.5)" });
         
         gsap.to(scanGroupRef.current!.scale, { x: 0, y:0, z:0, duration: 0.3 });
         gsap.to(degreeBadgeRef.current!.scale, { x: 0, y:0, z:0, duration: 0.3 });

      } else if (step === 2) {
         // Scan Exponents
         gsap.to(scanGroupRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.3 });
         
         // Scanner Sweep
         gsap.fromTo(scanLineRef.current!.position, 
            { x: -2.5 }, 
            { x: 2.5, duration: 2, ease: "linear", repeat: -1, yoyo: true }
         );

         gsap.to(degreeBadgeRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.6, delay: 0.5, ease: "elastic.out(1, 0.5)" });

         // Shift slightly to center
         gsap.to(cRef.current!.position, { x: -1.5, y: 0, z: 0, duration: 0.5 });
         gsap.to(xRef.current!.position, { x: 0, y: 0, z: 0, duration: 0.5 });
         gsap.to(yRef.current!.position, { x: 1.5, y: 0, z: 0, duration: 0.5 });

      } else if (step >= 3) {
         // Shift left to make room for expression
         const offset = step === 5 ? 0 : -5.5; // Back to center in step 5
         gsap.to(cRef.current!.position, { x: -1.5 + offset, duration: 0.8, ease: "power2.out" });
         gsap.to(xRef.current!.position, { x: 0 + offset, duration: 0.8, ease: "power2.out" });
         gsap.to(yRef.current!.position, { x: 1.5 + offset, duration: 0.8, ease: "power2.out" });
         
         gsap.to(scanGroupRef.current!.position, { x: offset, duration: 0.8, ease: "power2.out" });
         gsap.to(degreeBadgeRef.current!.position, { x: offset, y: 2.5, duration: 0.8, ease: "power2.out" });
         
         if (step === 4) {
             gsap.to(scanGroupRef.current!.scale, { x: 0, y:0, z:0, duration: 0.3 });
             gsap.to(degreeBadgeRef.current!.scale, { x: 0, y:0, z:0, duration: 0.3 });
         } else {
             gsap.to(scanGroupRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.3 });
             gsap.to(degreeBadgeRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.3 });
         }
      }
   }, [step]);

   return (
      <group>
         <group ref={cRef}><Block text={coef.toString()} subText="Coefficient" color="#3b82f6" position={[0,0,0]} width={1.4} /></group>
         <group ref={xRef}><Block text="x" subText="Variable" exp={step > 0 ? expX : ""} color="#8b5cf6" position={[0,0,0]} width={1.4} /></group>
         <group ref={yRef}><Block text="y" subText="Variable" exp={expY} color="#8b5cf6" position={[0,0,0]} width={1.4} /></group>
         
         {/* Scanner Effect */}
         <group ref={scanGroupRef} scale={[0,0,0]}>
            <mesh ref={scanLineRef} position={[0, 0, 0.5]}>
               <boxGeometry args={[0.1, 2, 0.1]} />
               <meshBasicMaterial color="#38bdf8" transparent opacity={0.8} />
            </mesh>
            <pointLight position={[0, 0, 1]} distance={4} intensity={0.5} color="#38bdf8" />
         </group>

         {/* Degree Badge */}
         <group ref={degreeBadgeRef} position={[0, 2.5, 0]} scale={[0,0,0]}>
             <mesh>
                 <planeGeometry args={[4, 1.2]} />
                 <meshStandardMaterial color="#0f172a" transparent opacity={0.8} />
             </mesh>
             <Text position={[0, 0.2, 0.1]} fontSize={0.4} color="#eab308">
                 Degree = {expX + expY}
             </Text>
             <Text position={[0, -0.25, 0.1]} fontSize={0.25} color="#94a3b8">
                 ( {expX} + {expY} )
             </Text>
             {/* Connecting line */}
             <mesh position={[0, -1, 0]}>
                 <boxGeometry args={[0.05, 1, 0.05]} />
                 <meshBasicMaterial color="#eab308" transparent opacity={0.5} />
             </mesh>
         </group>
      </group>
   );
}

function ExpressionChain({ step }: { step: number }) {
    const chainRef = useRef<THREE.Group>(null);
    const constGhostRef = useRef<THREE.Group>(null);
    const constDegreeRef = useRef<THREE.Group>(null);
    
    const isVisible = step === 3 || step === 4;

    useEffect(() => {
        if (isVisible) {
            gsap.to(chainRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.6, ease: "back.out(1.2)" });
        } else {
            gsap.to(chainRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.4 });
        }

        if (step === 4) {
            gsap.to(constGhostRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.6, delay: 0.5, ease: "back.out(1.5)" });
            gsap.to(constDegreeRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.6, delay: 1, ease: "elastic.out(1, 0.5)" });
        } else {
            gsap.to(constGhostRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
            gsap.to(constDegreeRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
        }
    }, [step, isVisible]);

    return (
        <group ref={chainRef} scale={[0,0,0]}>
            {/* Operator + */}
            <Block text="+" color="#475569" position={[-1.5, 0, 0]} isOp={true} />
            
            {/* Term 2: 2x */}
            <Block text="2" color="#3b82f6" position={[0.5, 0, 0]} />
            <Block text="x" exp="1" color="#8b5cf6" position={[1.9, 0, 0]} />
            
            {/* Operator - */}
            <Block text="-" color="#475569" position={[3.9, 0, 0]} isOp={true} />
            
            {/* Term 3: 7 (Constant) */}
            <Block text="7" subText="Constant" color="#f43f5e" position={[5.9, 0, 0]} />
            
            {/* Hidden x^0 for Constant Misconception */}
            <group ref={constGhostRef} position={[7.3, 0, 0]} scale={[0,0,0]}>
                <Block text="x" exp="0" color="#8b5cf6" position={[0, 0, 0]} opacity={0.6} />
                <Text position={[0, -0.9, 0]} fontSize={0.3} color="#fde047" outlineWidth={0.02} outlineColor="#000">
                    x⁰ = 1
                </Text>
            </group>

            {/* Degree Badge for Constant */}
            <group ref={constDegreeRef} position={[5.9, 2.5, 0]} scale={[0,0,0]}>
                 <mesh>
                     <planeGeometry args={[3, 1.2]} />
                     <meshStandardMaterial color="#4c1d95" transparent opacity={0.8} />
                 </mesh>
                 <Text position={[0, 0.1, 0.1]} fontSize={0.4} color="#fde047">
                     Degree = 0
                 </Text>
                 <Text position={[0, -0.3, 0.1]} fontSize={0.25} color="#c4b5fd">
                     (No variables = x⁰)
                 </Text>
                 <mesh position={[0, -1, 0]}>
                     <boxGeometry args={[0.05, 1, 0.05]} />
                     <meshBasicMaterial color="#fde047" transparent opacity={0.5} />
                 </mesh>
            </group>
        </group>
    );
}

// ---------------------------------------------------------
// MAIN SCENE CONTROLLER
// ---------------------------------------------------------
function Scene({ step, coef, expX, expY }: any) {
  const { camera } = useThree();
  const controlsRef = useRef<any>(null);

  useEffect(() => {
    let t = { camX: 0, camY: 0, camZ: 12, tarX: 0, tarY: 0 };
    
    if (step === 0 || step === 1 || step === 2) {
       t = { camX: 0, camY: 0, camZ: 10, tarX: 0, tarY: 0 };
    } else if (step === 3) {
       t = { camX: 0, camY: 0, camZ: 16, tarX: 0, tarY: 0 };
    } else if (step === 4) {
       t = { camX: 6, camY: 0, camZ: 9, tarX: 6, tarY: 0 }; // Pan to constant
    } else if (step === 5) {
       t = { camX: 0, camY: 0, camZ: 10, tarX: 0, tarY: 0 };
    }

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
        minPolarAngle={Math.PI / 2 - 0.1}
        maxAzimuthAngle={0.3}
        minAzimuthAngle={-0.3}
      />
      
      {/* Background Plate */}
      <mesh position={[0, 0, -3]}>
         <planeGeometry args={[40, 20]} />
         <meshStandardMaterial color="#020617" />
      </mesh>

      <Float speed={2} rotationIntensity={0.05} floatIntensity={0.2}>
         <MainTerm step={step} coef={coef} expX={expX} expY={expY} />
         <ExpressionChain step={step} />
      </Float>

    </group>
  );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function AlgebraicTermsSimulation() {
  const [step, setStep] = useState(0);
  
  // Sandbox State
  const [coef, setCoef] = useState(5);
  const [expX, setExpX] = useState(1);
  const [expY, setExpY] = useState(3);

  const handleNextStep = () => {
    if (step < 5) setStep(s => s + 1);
  };

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`5, \\quad x, \\quad y^3`} />;
    if (step === 1) return <BlockMath math={`5xy^3`} />;
    if (step === 2) return <BlockMath math={`\\text{Degree}(5x^1y^3) = 1 + 3 = 4`} />;
    if (step === 3) return <BlockMath math={`5xy^3 + 2x - 7`} />;
    if (step === 4) return <BlockMath math={`-7 = -7x^0 \\implies \\text{Degree } 0`} />;
    if (step === 5) {
       const deg = expX + expY;
       return <BlockMath math={`\\text{Degree}(${coef}x^{${expX}}y^{${expY}}) = ${expX} + ${expY} = ${deg}`} />;
    }
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Isolated Components", icon: <MoveRight size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>Here we have a number (<span className="font-mono">5</span>) and two variables (<span className="font-mono">x</span> and <span className="font-mono">y³</span>). Standing alone, they are just isolated parts.</> };
    if (step === 1) return { title: "Term Assembly", icon: <Combine size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>When we multiply them together with no plus or minus signs between them, they assemble into a single <b>Algebraic Term</b>: <span className="font-mono">5xy³</span>. Note that <span className="font-mono">x</span> implies <span className="font-mono">x¹</span>.</> };
    if (step === 2) return { title: "Scanning Degree", icon: <Scan size={18}/>, color: "border-l-yellow-500 text-yellow-300 bg-yellow-900/40", body: <>The <b>Degree</b> of a single term is the <i>sum</i> of the exponents of its variables. Here, <span className="font-mono">1 + 3 = 4</span>. This is a 4th-degree term.</> };
    if (step === 3) return { title: "Expression Chain", icon: <Plus size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>When terms are connected by addition or subtraction (<span className="font-mono">+</span>, <span className="font-mono">-</span>), they form an <b>Algebraic Expression</b>. This expression has 3 distinct terms.</> };
    if (step === 4) return { title: "Constant Misconception", icon: <AlertTriangle size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> Do constant terms (like <span className="font-mono">-7</span>) lack a degree? No! They have a degree of <b>0</b>, because mathematically <span className="font-mono">-7 = -7x⁰</span>.</> };
    if (step === 5) return { title: "Sandbox Engine", icon: <SlidersHorizontal size={18}/>, color: "border-l-slate-500 text-slate-300 bg-slate-800/40", body: <>Adjust the coefficient and exponents. Watch how modifying the variables alters the total degree of the term in real-time. Notice what happens when exponents are 0.</> };
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
              Algebraic Terms & Degrees
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Building terms, analyzing exponents, and expression chains.
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
           <Canvas shadows camera={{ position: [0, 0, 12], fov: 45 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <Scene step={step} coef={coef} expX={expX} expY={expY} />
              
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
                 <span className="text-blue-400">Parts</span>
                 <span className="text-emerald-400">Assemble</span>
                 <span className="text-yellow-400">Scan</span>
                 <span className="text-purple-400">Chain</span>
                 <span className="text-rose-400">Constant</span>
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
                 <div className="grid grid-cols-3 gap-4">
                    <div>
                       <label className="text-[10px] uppercase text-slate-500 mb-1 block">Coef (Base)</label>
                       <input type="range" min="-10" max="10" value={coef} onChange={e=>setCoef(parseInt(e.target.value))} className="w-full accent-blue-500" />
                       <div className="text-xs text-center text-slate-400">{coef}</div>
                    </div>
                    <div>
                       <label className="text-[10px] uppercase text-slate-500 mb-1 block">Exp X</label>
                       <input type="range" min="0" max="5" value={expX} onChange={e=>setExpX(parseInt(e.target.value))} className="w-full accent-yellow-500" />
                       <div className="text-xs text-center text-slate-400">{expX}</div>
                    </div>
                    <div>
                       <label className="text-[10px] uppercase text-slate-500 mb-1 block">Exp Y</label>
                       <input type="range" min="0" max="5" value={expY} onChange={e=>setExpY(parseInt(e.target.value))} className="w-full accent-yellow-500" />
                       <div className="text-xs text-center text-slate-400">{expY}</div>
                    </div>
                 </div>
              </div>
           )}

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-auto">
             <button 
                onClick={handleNextStep} 
                disabled={step === 5} 
                className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm ${step === 5 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
             >
                {step === 0 ? "Assemble Term" : 
                 step === 1 ? "Scan Degree" : 
                 step === 2 ? "Add to Chain" : 
                 step === 3 ? "Check Constant" : 
                 step === 4 ? "Sandbox" : "Completed"} 
                {step < 5 && <MoveRight size={16} />}
             </button>
             <button 
                onClick={() => setStep(0)} 
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
