import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, RefreshCcw, Scale, AlertTriangle, RotateCcw, CheckCircle2 } from 'lucide-react';

interface ProcessProps {
  step: number;
}

// ---------------------------------------------------------
// REUSABLE 3D COMPONENTS
// ---------------------------------------------------------

// A block that has a front and back face, perfect for flipping animations
function FlippableBlock({ 
    frontText, backText, frontColor, backColor, position, groupRef 
  }: { 
    frontText: string, backText: string, frontColor: string, backColor: string, position: [number, number, number], groupRef: React.RefObject<THREE.Group> 
  }) {
  return (
    <group ref={groupRef} position={position}>
      {/* Front Face */}
      <mesh position={[0, 0, 0.25]}>
        <boxGeometry args={[1.5, 1.5, 0.5]} />
        <meshStandardMaterial color={frontColor} roughness={0.3} metalness={0.2} />
      </mesh>
      <Text position={[0, 0, 0.51]} fontSize={0.8} color="#ffffff" outlineWidth={0.04} outlineColor="#000">
        {frontText}
      </Text>

      {/* Back Face (Rotated 180 on Y) */}
      <mesh position={[0, 0, -0.25]} rotation={[0, Math.PI, 0]}>
        <boxGeometry args={[1.5, 1.5, 0.5]} />
        <meshStandardMaterial color={backColor} roughness={0.3} metalness={0.2} />
      </mesh>
      <Text position={[0, 0, -0.51]} rotation={[0, Math.PI, 0]} fontSize={0.8} color="#ffffff" outlineWidth={0.04} outlineColor="#000">
        {backText}
      </Text>
    </group>
  );
}

// ---------------------------------------------------------
// SCENE ZONES
// ---------------------------------------------------------

function ConduitZone({ step }: { step: number }) {
  const operatorRef = useRef<THREE.Group>(null);
  const operandRef = useRef<THREE.Group>(null);
  const resultRef = useRef<THREE.Group>(null);
  
  useEffect(() => {
    const dur = 1.0;

    if (step === 0) {
      // Reset State
      gsap.to(operatorRef.current!.rotation, { y: 0, duration: dur, ease: "power2.inOut" });
      gsap.to(operandRef.current!.rotation, { y: 0, duration: dur, ease: "power2.inOut" });
      gsap.to(resultRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
    } 
    else if (step === 1) {
      // Flip Operator (Keep-Change-Change)
      gsap.to(operatorRef.current!.rotation, { y: Math.PI, duration: dur, ease: "back.out(1.5)" });
      gsap.to(operandRef.current!.rotation, { y: 0, duration: dur });
      gsap.to(resultRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
    }
    else if (step === 2) {
      // Flip Operand (Additive Inverse) & Show Result
      gsap.to(operatorRef.current!.rotation, { y: Math.PI, duration: dur });
      gsap.to(operandRef.current!.rotation, { y: Math.PI, duration: dur, ease: "back.out(1.5)", delay: 0.2 });
      gsap.to(resultRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.6, ease: "elastic.out(1, 0.5)", delay: 1.0 });
    }
  }, [step]);

  return (
    <group position={[-10, 0, 0]}>
      {/* Conduit Base/Platform */}
      <mesh position={[0, -1.2, 0]} receiveShadow>
        <boxGeometry args={[10, 0.2, 3]} />
        <meshStandardMaterial color="#1e293b" />
      </mesh>
      
      {/* Base Value: 5 */}
      <group position={[-3, 0, 0]}>
         <mesh castShadow>
           <boxGeometry args={[1.5, 1.5, 1]} />
           <meshStandardMaterial color="#3b82f6" roughness={0.3} />
         </mesh>
         <Text position={[0, 0, 0.51]} fontSize={0.8} color="#ffffff" outlineWidth={0.04} outlineColor="#000">5</Text>
      </group>

      {/* Operator: - to + */}
      <FlippableBlock 
         groupRef={operatorRef} 
         position={[-1, 0, 0]} 
         frontText="-" backText="+" 
         frontColor="#ef4444" backColor="#10b981" 
      />

      {/* Operand: -3 to +3 */}
      <FlippableBlock 
         groupRef={operandRef} 
         position={[1, 0, 0]} 
         frontText="(-3)" backText="3" 
         frontColor="#ef4444" backColor="#3b82f6" 
      />

      {/* Result: = 8 */}
      <group ref={resultRef} position={[3.5, 0, 0]} scale={[0,0,0]}>
         <Text position={[-1, 0, 0]} fontSize={0.8} color="#cbd5e1" outlineWidth={0.02} outlineColor="#000">=</Text>
         <mesh castShadow position={[0.5, 0, 0]}>
           <boxGeometry args={[1.5, 1.5, 1]} />
           <meshStandardMaterial color="#fbbf24" roughness={0.3} />
         </mesh>
         <Text position={[0.5, 0, 0.51]} fontSize={0.8} color="#ffffff" outlineWidth={0.04} outlineColor="#000">8</Text>
         <Html position={[0.5, 1.2, 0]} center className="pointer-events-none">
            <div className="bg-yellow-900/90 border border-yellow-500 text-yellow-300 px-2 py-1 rounded text-xs font-bold whitespace-nowrap shadow-lg">
               Equivalent Sum
            </div>
         </Html>
      </group>
    </group>
  );
}

function CommutativeScaleZone({ step }: { step: number }) {
  const beamRef = useRef<THREE.Group>(null);
  const leftPanRef = useRef<THREE.Group>(null);
  const rightPanRef = useRef<THREE.Group>(null);
  const alertRef = useRef<THREE.Group>(null);

  useEffect(() => {
    const dur = 1.2;

    if (step < 4) {
      // Balanced
      gsap.to(beamRef.current!.rotation, { z: 0, duration: dur, ease: "power2.out" });
      gsap.to(leftPanRef.current!.rotation, { z: 0, duration: dur, ease: "power2.out" });
      gsap.to(rightPanRef.current!.rotation, { z: 0, duration: dur, ease: "power2.out" });
      gsap.to(alertRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
    } else if (step === 4) {
      // Tilted (Left side is 3, Right side is -3 -> Left is heavier mathematically for visualization, or just tip violently to show inequality)
      const angle = 0.4;
      gsap.to(beamRef.current!.rotation, { z: angle, duration: dur, ease: "bounce.out" });
      // Counter-rotate pans to keep them upright
      gsap.to(leftPanRef.current!.rotation, { z: -angle, duration: dur, ease: "bounce.out" });
      gsap.to(rightPanRef.current!.rotation, { z: -angle, duration: dur, ease: "bounce.out" });
      
      gsap.to(alertRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.6, ease: "back.out(1.5)", delay: 0.5 });
    }
  }, [step]);

  return (
    <group position={[10, 0, 0]}>
      
      {/* Fulcrum */}
      <mesh position={[0, -2, 0]} castShadow>
        <cylinderGeometry args={[0, 1, 2, 4]} />
        <meshStandardMaterial color="#475569" />
      </mesh>

      {/* Beam Assembly */}
      <group ref={beamRef} position={[0, -1, 0]}>
         {/* Main Beam */}
         <mesh castShadow>
           <boxGeometry args={[8, 0.2, 0.5]} />
           <meshStandardMaterial color="#94a3b8" />
         </mesh>

         {/* Left Pan: 7 - 4 = 3 */}
         <group ref={leftPanRef} position={[-3.5, 0, 0]}>
            <mesh position={[0, -1, 0]}>
               <boxGeometry args={[2, 0.1, 1]} />
               <meshStandardMaterial color="#64748b" />
            </mesh>
            <mesh position={[0, -0.5, 0]}>
               <cylinderGeometry args={[0.02, 0.02, 1]} />
               <meshStandardMaterial color="#cbd5e1" />
            </mesh>
            {/* The Math Block */}
            <mesh position={[0, -0.5, 0.2]} castShadow>
               <boxGeometry args={[1.6, 0.8, 0.4]} />
               <meshStandardMaterial color="#3b82f6" />
            </mesh>
            <Text position={[0, -0.5, 0.41]} fontSize={0.4} color="#ffffff">7 - 4 = 3</Text>
         </group>

         {/* Right Pan: 4 - 7 = -3 */}
         <group ref={rightPanRef} position={[3.5, 0, 0]}>
            <mesh position={[0, -1, 0]}>
               <boxGeometry args={[2, 0.1, 1]} />
               <meshStandardMaterial color="#64748b" />
            </mesh>
            <mesh position={[0, -0.5, 0]}>
               <cylinderGeometry args={[0.02, 0.02, 1]} />
               <meshStandardMaterial color="#cbd5e1" />
            </mesh>
            {/* The Math Block */}
            <mesh position={[0, -0.5, 0.2]} castShadow>
               <boxGeometry args={[1.8, 0.8, 0.4]} />
               <meshStandardMaterial color="#ef4444" />
            </mesh>
            <Text position={[0, -0.5, 0.41]} fontSize={0.4} color="#ffffff">4 - 7 = -3</Text>
         </group>
      </group>

      {/* Non-commutative Alert Badge */}
      <group ref={alertRef} position={[0, 1.5, 0]} scale={[0,0,0]}>
         <Html center className="pointer-events-none">
            <div className="bg-rose-950/90 border-2 border-rose-500 p-4 rounded-xl shadow-[0_0_20px_rgba(239,68,68,0.6)] backdrop-blur-md flex flex-col items-center min-w-[200px]">
               <div className="flex items-center gap-2 text-rose-400 mb-2">
                  <AlertTriangle size={20} />
                  <span className="font-bold uppercase tracking-widest text-xs">Error: Not Equal</span>
               </div>
               <div className="text-2xl text-rose-100 font-bold">
                  <BlockMath math={`3 \\neq -3`} />
               </div>
            </div>
         </Html>
      </group>

    </group>
  );
}

// ---------------------------------------------------------
// MAIN SCENE CONTROLLER
// ---------------------------------------------------------
function Scene({ step }: ProcessProps) {
  const { camera } = useThree();
  const controlsRef = useRef<any>(null);

  useEffect(() => {
    const targets = [
      { camX: -10, camY: 3, camZ: 10, tarX: -10, tarY: 0 }, // 0: Start Conduit
      { camX: -10, camY: 3, camZ: 10, tarX: -10, tarY: 0 }, // 1: Conduit Op Flip
      { camX: -10, camY: 3, camZ: 10, tarX: -10, tarY: 0 }, // 2: Conduit Sign Flip
      { camX: 10,  camY: 3, camZ: 12, tarX: 10,  tarY: -1 }, // 3: View Scale (Balanced)
      { camX: 10,  camY: 3, camZ: 12, tarX: 10,  tarY: -1 }, // 4: Scale Tips (Error)
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
        maxPolarAngle={Math.PI / 2}
        minPolarAngle={Math.PI / 2 - 0.6}
        maxAzimuthAngle={0.4}
        minAzimuthAngle={-0.4}
      />
      <ConduitZone step={step} />
      <CommutativeScaleZone step={step} />
    </group>
  );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function SubtractionSimulation() {
  const [step, setStep] = useState(0);
  
  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`5 - (-3)`} />;
    if (step === 1) return <BlockMath math={`5 + (-3)`} />;
    if (step === 2) return <BlockMath math={`5 + 3 = 8`} />;
    if (step === 3 || step === 4) return <BlockMath math={`7 - 4 \\quad \\text{vs} \\quad 4 - 7`} />;
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Subtraction Conduit", icon: <MoveRight size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>We are tasked with subtracting a negative number: <span className="font-mono">5 - (-3)</span>. Let's send it through the transformation conduit.</> };
    if (step === 1) return { title: "Keep-Change-Change (Part 1)", icon: <RefreshCcw size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>First, we change the subtraction operator (<span className="text-red-400 font-bold">-</span>) into addition (<span className="text-emerald-400 font-bold">+</span>).</> };
    if (step === 2) return { title: "Additive Inverse (Part 2)", icon: <CheckCircle2 size={18}/>, color: "border-l-yellow-500 text-yellow-300 bg-yellow-900/40", body: <>Next, we immediately flip the negative term <span className="font-mono">(-3)</span> to its additive inverse <span className="font-mono">3</span>. Subtracting a negative is exactly identical to <b>adding a positive</b>.</> };
    if (step === 3) return { title: "The Commutative Test", icon: <Scale size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>Addition is commutative (<span className="font-mono">a + b = b + a</span>). Let's test if subtraction works the same way using <span className="font-mono">7 - 4</span> and <span className="font-mono">4 - 7</span>.</> };
    if (step === 4) return { title: "Non-Commutative Alert", icon: <AlertTriangle size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> Subtraction is <b>NOT</b> commutative! Reversing the order yields completely different results (<span className="font-mono">3</span> vs <span className="font-mono">-3</span>). The scale violently tips to prove the inequality.</> };
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
              Integer Subtraction
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Adding the opposite and testing non-commutativity.
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
           <Canvas shadows camera={{ position: [-10, 3, 10], fov: 45 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
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

        <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
           
           {/* Steps Map */}
           <div className="flex flex-col gap-2 mb-4">
              <div className="flex justify-between text-[10px] font-bold uppercase tracking-wider text-slate-400 px-1">
                 <span>Inspect</span>
                 <span className="text-emerald-400">Op Flip</span>
                 <span className="text-yellow-400">Sign Flip</span>
                 <span className="text-purple-400">Scale</span>
                 <span className="text-rose-400">Inequality</span>
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
                className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm ${step === 4 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
             >
                {step === 0 ? "Transform Operator" : 
                 step === 1 ? "Transform Sign" : 
                 step === 2 ? "Test Commutativity" : 
                 step === 3 ? "Run Balance Test" : 
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
