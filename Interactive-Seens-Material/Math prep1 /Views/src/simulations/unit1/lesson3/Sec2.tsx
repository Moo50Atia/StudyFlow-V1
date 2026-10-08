import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { AlertCircle, Plus, Filter, ArrowDownToLine, CheckCircle2, RotateCcw, MoveRight } from 'lucide-react';

interface ProcessProps {
  step: number;
  totalAmount: number;
  ratioA: number;
  ratioB: number;
}

function ProcessVisualizer({ step, totalAmount, ratioA, ratioB }: ProcessProps) {
  const bagRef = useRef<THREE.Group>(null);
  const r1Ref = useRef<THREE.Group>(null);
  const r2Ref = useRef<THREE.Group>(null);
  const sumRef = useRef<THREE.Group>(null);
  const funnelRef = useRef<THREE.Mesh>(null);
  const unitRef = useRef<THREE.Group>(null);
  const p1StreamRef = useRef<THREE.Group>(null);
  const p2StreamRef = useRef<THREE.Group>(null);
  
  const totalParts = ratioA + ratioB;
  const unitValue = totalAmount / totalParts;
  const shareA = unitValue * ratioA;
  const shareB = unitValue * ratioB;

  const format = (n: number) => Number.isInteger(n) ? n.toString() : n.toFixed(1);

  useEffect(() => {
     if (!bagRef.current || !r1Ref.current || !r2Ref.current || !sumRef.current || !funnelRef.current || !unitRef.current || !p1StreamRef.current || !p2StreamRef.current) return;
     
     // Reset all tweens
     gsap.killTweensOf([
        bagRef.current.position, bagRef.current.scale,
        r1Ref.current.position, r1Ref.current.scale,
        r2Ref.current.position, r2Ref.current.scale,
        sumRef.current.position, sumRef.current.scale,
        funnelRef.current.rotation,
        unitRef.current.position, unitRef.current.scale,
        p1StreamRef.current.position, p1StreamRef.current.scale,
        p2StreamRef.current.position, p2StreamRef.current.scale
     ]);

     if (step === 0) {
        gsap.set(bagRef.current.position, {x: 0, y: 6, z: 0});
        gsap.set(bagRef.current.scale, {x: 1, y: 1, z: 1});
        gsap.set(r1Ref.current.position, {x: -3, y: 4.5, z: 0});
        gsap.set(r1Ref.current.scale, {x: 1, y: 1, z: 1});
        gsap.set(r2Ref.current.position, {x: 3, y: 4.5, z: 0});
        gsap.set(r2Ref.current.scale, {x: 1, y: 1, z: 1});
        gsap.set(sumRef.current.scale, {x: 0, y: 0, z: 0});
        gsap.set(sumRef.current.position, {x: 0, y: 4.5, z: 0});
        gsap.set(unitRef.current.scale, {x: 0, y: 0, z: 0});
        gsap.set(unitRef.current.position, {x: 0, y: 1.5, z: 0}); 
        gsap.set(p1StreamRef.current.scale, {x: 0, y: 0, z: 0});
        gsap.set(p1StreamRef.current.position, {x: -3.5, y: -3, z: 0});
        gsap.set(p2StreamRef.current.scale, {x: 0, y: 0, z: 0});
        gsap.set(p2StreamRef.current.position, {x: 3.5, y: -3, z: 0});
        gsap.set(funnelRef.current.rotation, {z: 0});
     }
     else if (step === 1) {
        gsap.to(r1Ref.current.position, {x: -0.8, duration: 0.8, ease: 'power2.inOut'});
        gsap.to(r2Ref.current.position, {x: 0.8, duration: 0.8, ease: 'power2.inOut'});
        gsap.to([r1Ref.current.scale, r2Ref.current.scale], {x: 0, y: 0, z: 0, duration: 0.3, delay: 0.8});
        gsap.to(sumRef.current.scale, {x: 1, y: 1, z: 1, duration: 0.5, delay: 1, ease: 'back.out(1.5)'});
     }
     else if (step === 2) {
        gsap.to(bagRef.current.position, {y: 2, duration: 1, ease: 'power2.in'});
        gsap.to(sumRef.current.position, {y: 2, duration: 1, ease: 'power2.in'});
        gsap.to([bagRef.current.scale, sumRef.current.scale], {x: 0, y: 0, z: 0, duration: 0.3, delay: 0.8});
        
        gsap.to(funnelRef.current.rotation, {z: 0.15, duration: 0.08, yoyo: true, repeat: 12, delay: 1.1});
        
        gsap.to(unitRef.current.scale, {x: 1, y: 1, z: 1, duration: 0.5, delay: 1.8, ease: 'back.out(1.5)'});
        gsap.to(unitRef.current.position, {y: -1.5, duration: 0.8, delay: 1.8, ease: 'bounce.out'});
     }
     else if (step === 3) {
        gsap.to(unitRef.current.scale, {x: 0, y: 0, z: 0, duration: 0.4});
        
        gsap.to(p1StreamRef.current.scale, {x: 1, y: 1, z: 1, duration: 0.8, delay: 0.4, ease: 'elastic.out(1, 0.7)'});
        gsap.to(p2StreamRef.current.scale, {x: 1, y: 1, z: 1, duration: 0.8, delay: 0.4, ease: 'elastic.out(1, 0.7)'});
     }
     else if (step === 4) {
        gsap.to([p1StreamRef.current.position, p2StreamRef.current.position], {y: -2.5, duration: 0.4, yoyo: true, repeat: 3});
     }
  }, [step, totalAmount, ratioA, ratioB]);

  return (
     <group>
        {/* Money Bag */}
        <group ref={bagRef}>
           <mesh castShadow receiveShadow>
              <cylinderGeometry args={[1.2, 1.4, 1.8, 32]} />
              <meshStandardMaterial color="#10b981" roughness={0.4} />
           </mesh>
           <Text position={[0, 0, 1.3]} fontSize={0.4} color="white" outlineWidth={0.02} outlineColor="#000">
              {totalAmount} LE
           </Text>
        </group>

        {/* Ratio Nodes */}
        <group ref={r1Ref}>
           <mesh castShadow receiveShadow>
              <sphereGeometry args={[0.7, 32, 32]} />
              <meshStandardMaterial color="#3b82f6" />
           </mesh>
           <Text position={[0, 0, 0.8]} fontSize={0.5} color="white" outlineWidth={0.02} outlineColor="#000">{ratioA}</Text>
        </group>
        
        <group ref={r2Ref}>
           <mesh castShadow receiveShadow>
              <sphereGeometry args={[0.7, 32, 32]} />
              <meshStandardMaterial color="#f97316" />
           </mesh>
           <Text position={[0, 0, 0.8]} fontSize={0.5} color="white" outlineWidth={0.02} outlineColor="#000">{ratioB}</Text>
        </group>

        {/* Sum Node */}
        <group ref={sumRef}>
           <mesh castShadow receiveShadow>
              <sphereGeometry args={[0.9, 32, 32]} />
              <meshStandardMaterial color="#8b5cf6" />
           </mesh>
           <Text position={[0, 0, 1]} fontSize={0.4} color="white" outlineWidth={0.02} outlineColor="#000">{totalParts} Parts</Text>
        </group>

        {/* Division Funnel */}
        <mesh ref={funnelRef} position={[0, 1.5, 0]} castShadow receiveShadow>
           <cylinderGeometry args={[2.5, 0.6, 2.5, 32]} />
           <meshStandardMaterial color="#cbd5e1" transparent opacity={0.3} metalness={0.9} roughness={0.1} />
        </mesh>
        {/* Divider inside funnel */}
        <mesh position={[0, 1.5, 0]}>
           <boxGeometry args={[4.5, 0.05, 4.5]} />
           <meshStandardMaterial color="#ffffff" transparent opacity={0.2} />
        </mesh>

        {/* Unit Block */}
        <group ref={unitRef}>
           <mesh castShadow receiveShadow>
              <boxGeometry args={[2, 1.2, 1.2]} />
              <meshStandardMaterial color="#fbbf24" roughness={0.2} metalness={0.3} />
           </mesh>
           <Text position={[0, 0, 0.65]} fontSize={0.35} color="black" outlineWidth={0.01} outlineColor="#ffffff">
              1 Unit = {format(unitValue)}
           </Text>
        </group>

        {/* Output Slots Bases */}
        <mesh position={[-3.5, -4, 0]} receiveShadow>
           <cylinderGeometry args={[2, 2.2, 0.2, 32]} />
           <meshStandardMaterial color="#1e293b" />
        </mesh>
        <mesh position={[3.5, -4, 0]} receiveShadow>
           <cylinderGeometry args={[2, 2.2, 0.2, 32]} />
           <meshStandardMaterial color="#1e293b" />
        </mesh>

        {/* Slot Values */}
        <group ref={p1StreamRef}>
           <mesh castShadow receiveShadow>
              <boxGeometry args={[2.5, 1.8, 1.8]} />
              <meshStandardMaterial color="#3b82f6" roughness={0.3} />
           </mesh>
           <Text position={[0, 0.4, 0.95]} fontSize={0.3} color="white" outlineWidth={0.02} outlineColor="#000">Person A</Text>
           <Text position={[0, -0.2, 0.95]} fontSize={0.6} color="#bfdbfe" outlineWidth={0.02} outlineColor="#000">{format(shareA)} LE</Text>
        </group>
        
        <group ref={p2StreamRef}>
           <mesh castShadow receiveShadow>
              <boxGeometry args={[2.5, 1.8, 1.8]} />
              <meshStandardMaterial color="#f97316" roughness={0.3} />
           </mesh>
           <Text position={[0, 0.4, 0.95]} fontSize={0.3} color="white" outlineWidth={0.02} outlineColor="#000">Person B</Text>
           <Text position={[0, -0.2, 0.95]} fontSize={0.6} color="#ffedd5" outlineWidth={0.02} outlineColor="#000">{format(shareB)} LE</Text>
        </group>

     </group>
  )
}

export default function UnitValueSimulation() {
  const [step, setStep] = useState(0);
  
  const [totalAmount, setTotalAmount] = useState(1200);
  const [ratioA, setRatioA] = useState(2);
  const [ratioB, setRatioB] = useState(1);

  const totalParts = ratioA + ratioB;
  const unitValue = totalAmount / totalParts;
  const shareA = unitValue * ratioA;
  const shareB = unitValue * ratioB;

  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const format = (n: number) => Number.isInteger(n) ? n.toString() : n.toFixed(1);

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`\\text{Total} = ${totalAmount}, \\quad \\text{Ratio} = ${ratioA} : ${ratioB}`} />;
    if (step === 1) return <BlockMath math={`\\text{Total Parts} = ${ratioA} + ${ratioB} = ${totalParts}`} />;
    if (step === 2) return <BlockMath math={`\\text{Unit Value} = \\frac{${totalAmount}}{${totalParts}} = ${format(unitValue)}`} />;
    if (step === 3) return (
       <div className="text-sm">
         <BlockMath math={`A = ${ratioA} \\times ${format(unitValue)} = ${format(shareA)}`} />
         <BlockMath math={`B = ${ratioB} \\times ${format(unitValue)} = ${format(shareB)}`} />
       </div>
    );
    if (step === 4) return <BlockMath math={`\\text{Check: } ${format(shareA)} + ${format(shareB)} = ${totalAmount} \\checkmark`} />;
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Common Mistake Trap", icon: <AlertCircle size={18}/>, color: "border-l-rose-500 text-rose-400 bg-rose-900/10", body: <>If you multiply the total <span className="font-bold text-rose-300">{totalAmount} LE</span> directly by {ratioA}, Person A would get {totalAmount * ratioA} LE! That's impossible since the whole bag is only {totalAmount}. We need a different approach.</> };
    if (step === 1) return { title: "Summing Parts", icon: <Plus size={18}/>, color: "border-l-blue-500 text-blue-400 bg-blue-900/10", body: <>Instead of multiplying the total, we first add the ratio terms together to find exactly how many equal blocks the bag must be divided into.</> };
    if (step === 2) return { title: "The Division Funnel", icon: <Filter size={18}/>, color: "border-l-yellow-500 text-yellow-400 bg-yellow-900/10", body: <>We pour the total amount into the funnel and divide it by the total parts. This computes the exact mathematical value of <span className="font-bold text-yellow-300">ONE single unit block</span>.</> };
    if (step === 3) return { title: "Output Distribution", icon: <ArrowDownToLine size={18}/>, color: "border-l-emerald-500 text-emerald-400 bg-emerald-900/10", body: <>Now we simply multiply that one unit block by each person's ratio share. The streams flow to their respective slots.</> };
    if (step === 4) return { title: "Validation Check", icon: <CheckCircle2 size={18}/>, color: "border-l-cyan-500 text-cyan-400 bg-cyan-900/10", body: <>If we add the final shares (<span className="font-mono">{format(shareA)} + {format(shareB)}</span>), we perfectly reconstruct the original <span className="font-bold text-cyan-300">{totalAmount} LE</span> bag. The math is perfectly balanced!</> };
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
              Unit Value Concept
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Visualizing the funnel of proportional division.
            </p>
          </div>
          <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[280px] flex justify-center shadow-inner">
             <div className="text-white text-lg">
               {renderMath()}
             </div>
          </div>
        </div>
      </div>
      
      {/* R3F Canvas */}
      <div className="flex-1 w-full h-full relative z-0 touch-none">
         <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
           <Canvas shadows camera={{ position: [0, 1, 14], fov: 50 }}>
              <color attach="background" args={['#0f172a']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[10, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <ProcessVisualizer 
                 step={step} 
                 totalAmount={totalAmount}
                 ratioA={ratioA}
                 ratioB={ratioB}
              />
              
              <OrbitControls 
                enablePan={false} 
                enableZoom={false}
                maxPolarAngle={Math.PI / 2 + 0.1}
                minPolarAngle={Math.PI / 2 - 0.1}
                maxAzimuthAngle={0.2}
                minAzimuthAngle={-0.2}
              />
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

        <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col gap-4 pointer-events-auto shrink-0 w-full lg:w-[420px]">
           
           {/* Progress Indicator */}
           <div className="flex gap-1.5">
             {[0, 1, 2, 3, 4].map(s => (
                <div key={s} className={`h-1.5 flex-1 rounded-full transition-colors duration-500 ${s <= step ? 'bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.6)]' : 'bg-slate-700'}`} />
             ))}
           </div>

           {/* Sliders */}
           <div className="flex flex-col gap-4 mt-2">
              <div className="flex flex-col gap-2">
                <div className="flex justify-between items-center text-slate-300">
                  <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Money Bag</label>
                  <span className="text-emerald-400 font-mono font-bold text-xs bg-slate-950 px-2 py-0.5 rounded border border-slate-800">{totalAmount} LE</span>
                </div>
                <input 
                  type="range" min="300" max="3000" step="300" value={totalAmount} 
                  onChange={e => {setTotalAmount(Number(e.target.value)); setStep(0);}} 
                  className="w-full accent-emerald-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer" 
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                 <div className="flex flex-col gap-2 border border-blue-900/50 bg-blue-950/30 p-2 rounded-xl">
                   <div className="flex justify-between items-center text-slate-300">
                     <label className="text-[10px] font-bold uppercase tracking-wider text-blue-400">Ratio A</label>
                     <span className="text-blue-400 font-mono font-bold text-xs">{ratioA}</span>
                   </div>
                   <input 
                     type="range" min="1" max="5" step="1" value={ratioA} 
                     onChange={e => {setRatioA(Number(e.target.value)); setStep(0);}} 
                     className="w-full accent-blue-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer" 
                   />
                 </div>
                 
                 <div className="flex flex-col gap-2 border border-orange-900/50 bg-orange-950/30 p-2 rounded-xl">
                   <div className="flex justify-between items-center text-slate-300">
                     <label className="text-[10px] font-bold uppercase tracking-wider text-orange-400">Ratio B</label>
                     <span className="text-orange-400 font-mono font-bold text-xs">{ratioB}</span>
                   </div>
                   <input 
                     type="range" min="1" max="5" step="1" value={ratioB} 
                     onChange={e => {setRatioB(Number(e.target.value)); setStep(0);}} 
                     className="w-full accent-orange-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer" 
                   />
                 </div>
              </div>
           </div>

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-2">
             <button 
                onClick={handleNextStep} 
                disabled={step === 4} 
                className="bg-blue-600 hover:bg-blue-500 text-white font-bold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-blue-900/20 text-sm"
             >
                {step === 0 ? "Combine Parts" : step === 4 ? "Completed" : "Next Step"} 
                {step < 4 && <MoveRight size={16} />}
             </button>
             <button 
                onClick={() => setStep(0)} 
                className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
             >
                <RotateCcw size={16} /> Reset
             </button>
           </div>
           
        </div>
      </div>
    </div>
  );
}
