import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { AlertCircle, Divide, Coins, Scale, CheckCircle2, TrendingUp, TrendingDown, MoveRight, RotateCcw } from 'lucide-react';

interface ProcessProps {
  step: number;
  mode: 'profit' | 'loss';
  totalAmount: number;
}

const format = (n: number) => n.toLocaleString();

function ProcessVisualizer({ step, mode, totalAmount }: ProcessProps) {
  const capA = 250000;
  const capB = 200000;
  const capC = 300000;
  
  const factor = 50000;
  const rA = capA / factor; // 5
  const rB = capB / factor; // 4
  const rC = capC / factor; // 6
  const totalParts = rA + rB + rC; // 15
  
  const unitValue = totalAmount / totalParts;
  const shareA = unitValue * rA;
  const shareB = unitValue * rB;
  const shareC = unitValue * rC;

  // Refs
  const ratioGrpA = useRef<THREE.Group>(null);
  const ratioGrpB = useRef<THREE.Group>(null);
  const ratioGrpC = useRef<THREE.Group>(null);
  const factorRef = useRef<THREE.Group>(null);
  
  const centralStackRef = useRef<THREE.Group>(null);
  const bundleA = useRef<THREE.Group>(null);
  const bundleB = useRef<THREE.Group>(null);
  const bundleC = useRef<THREE.Group>(null);

  const scaleA = useRef<THREE.Group>(null);
  const scaleB = useRef<THREE.Group>(null);
  const scaleC = useRef<THREE.Group>(null);

  const mainColor = mode === 'profit' ? '#10b981' : '#ef4444'; // emerald vs red
  const particleColor = mode === 'profit' ? '#34d399' : '#f87171';
  
  useEffect(() => {
    if (!ratioGrpA.current || !ratioGrpB.current || !ratioGrpC.current || !factorRef.current || 
        !centralStackRef.current || !bundleA.current || !bundleB.current || !bundleC.current ||
        !scaleA.current || !scaleB.current || !scaleC.current) return;

    // Reset all tweens
    gsap.killTweensOf([
      ratioGrpA.current.scale, ratioGrpB.current.scale, ratioGrpC.current.scale,
      factorRef.current.scale, factorRef.current.position,
      centralStackRef.current.scale, centralStackRef.current.position,
      bundleA.current.position, bundleB.current.position, bundleC.current.position,
      bundleA.current.scale, bundleB.current.scale, bundleC.current.scale,
      scaleA.current.position, scaleB.current.position, scaleC.current.position
    ]);

    if (step === 0) {
      gsap.set([ratioGrpA.current.scale, ratioGrpB.current.scale, ratioGrpC.current.scale], {x:0, y:0, z:0});
      gsap.set(factorRef.current.scale, {x:0, y:0, z:0});
      gsap.set(centralStackRef.current.scale, {x:0, y:0, z:0});
      gsap.set([bundleA.current.scale, bundleB.current.scale, bundleC.current.scale], {x:0, y:0, z:0});
      gsap.set(bundleA.current.position, {x:0, y:0, z:2});
      gsap.set(bundleB.current.position, {x:0, y:0, z:2});
      gsap.set(bundleC.current.position, {x:0, y:0, z:2});
      gsap.set([scaleA.current.position, scaleB.current.position, scaleC.current.position], {y: -3});
    }
    else if (step === 1) {
      // Simplification factor pops up
      gsap.to(factorRef.current.scale, {x:1, y:1, z:1, duration: 0.5, ease: 'back.out(1.5)'});
      gsap.to([ratioGrpA.current.scale, ratioGrpB.current.scale, ratioGrpC.current.scale], {
        x:1, y:1, z:1, duration: 0.6, delay: 0.5, ease: 'elastic.out(1, 0.7)'
      });
    }
    else if (step === 2) {
      gsap.to(centralStackRef.current.scale, {x:1, y:1, z:1, duration: 0.8, ease: 'power2.out'});
      gsap.to(centralStackRef.current.position, {y:0, duration: 0.8, ease: 'power2.out'});
    }
    else if (step === 3) {
      // Hide central stack, show bundles
      gsap.to(centralStackRef.current.scale, {x:0, y:0, z:0, duration: 0.3});
      gsap.to([bundleA.current.scale, bundleB.current.scale, bundleC.current.scale], {x:1, y:1, z:1, duration: 0.5, delay: 0.3});
      
      // Separate bundles
      gsap.to(bundleA.current.position, {x:-4, y: 0, duration: 0.8, delay: 0.6, ease: 'power2.inOut'});
      gsap.to(bundleB.current.position, {x:0, y: 0, duration: 0.8, delay: 0.6, ease: 'power2.inOut'});
      gsap.to(bundleC.current.position, {x:4, y: 0, duration: 0.8, delay: 0.6, ease: 'power2.inOut'});
    }
    else if (step === 4) {
      // Deposit into scales
      gsap.to(bundleA.current.position, {y: -2.5, duration: 0.6, ease: 'power2.in'});
      gsap.to(bundleB.current.position, {y: -2.5, duration: 0.6, ease: 'power2.in'});
      gsap.to(bundleC.current.position, {y: -2.5, duration: 0.6, ease: 'power2.in'});
      
      // Scales react (weight down)
      // The heavier the share, the more it might sink, but let's just do a uniform catch animation
      gsap.to(scaleA.current.position, {y: -3.2, duration: 0.2, delay: 0.6, yoyo: true, repeat: 1});
      gsap.to(scaleB.current.position, {y: -3.2, duration: 0.2, delay: 0.6, yoyo: true, repeat: 1});
      gsap.to(scaleC.current.position, {y: -3.2, duration: 0.2, delay: 0.6, yoyo: true, repeat: 1});
    }

  }, [step, mode, totalAmount]);

  return (
    <group>
      {/* Vaults (Initial Capital) */}
      <group position={[-4, 3, -1]}>
        <mesh castShadow receiveShadow>
           <boxGeometry args={[2.5, 1.5, 1]} />
           <meshStandardMaterial color="#1e293b" metalness={0.5} roughness={0.2} />
        </mesh>
        <Text position={[0, 0, 0.51]} fontSize={0.3} color="#94a3b8">Partner A</Text>
        <Text position={[0, -0.35, 0.51]} fontSize={0.4} color="#f8fafc">{format(capA)} LE</Text>
      </group>

      <group position={[0, 3, -1]}>
        <mesh castShadow receiveShadow>
           <boxGeometry args={[2.5, 1.5, 1]} />
           <meshStandardMaterial color="#1e293b" metalness={0.5} roughness={0.2} />
        </mesh>
        <Text position={[0, 0, 0.51]} fontSize={0.3} color="#94a3b8">Partner B</Text>
        <Text position={[0, -0.35, 0.51]} fontSize={0.4} color="#f8fafc">{format(capB)} LE</Text>
      </group>

      <group position={[4, 3, -1]}>
        <mesh castShadow receiveShadow>
           <boxGeometry args={[2.5, 1.5, 1]} />
           <meshStandardMaterial color="#1e293b" metalness={0.5} roughness={0.2} />
        </mesh>
        <Text position={[0, 0, 0.51]} fontSize={0.3} color="#94a3b8">Partner C</Text>
        <Text position={[0, -0.35, 0.51]} fontSize={0.4} color="#f8fafc">{format(capC)} LE</Text>
      </group>

      {/* Simplification Factor */}
      <group ref={factorRef} position={[0, 1.5, -1]}>
        <mesh>
          <sphereGeometry args={[0.6, 32, 32]} />
          <meshStandardMaterial color="#8b5cf6" emissive="#7c3aed" emissiveIntensity={0.5} />
        </mesh>
        <Text position={[0, 0, 0.61]} fontSize={0.25} color="white">÷ 50,000</Text>
      </group>

      {/* Simplified Ratio Blocks */}
      <group ref={ratioGrpA} position={[-4, 1.5, -1]}>
         <mesh castShadow><boxGeometry args={[1,1,1]} /><meshStandardMaterial color="#3b82f6" /></mesh>
         <Text position={[0,0,0.51]} fontSize={0.5} color="white">{rA}</Text>
      </group>
      <group ref={ratioGrpB} position={[0, 1.5, -1]}>
         <mesh castShadow><boxGeometry args={[1,1,1]} /><meshStandardMaterial color="#f97316" /></mesh>
         <Text position={[0,0,0.51]} fontSize={0.5} color="white">{rB}</Text>
      </group>
      <group ref={ratioGrpC} position={[4, 1.5, -1]}>
         <mesh castShadow><boxGeometry args={[1,1,1]} /><meshStandardMaterial color="#06b6d4" /></mesh>
         <Text position={[0,0,0.51]} fontSize={0.5} color="white">{rC}</Text>
      </group>

      {/* Central Stack */}
      <group ref={centralStackRef} position={[0, -1, 2]}>
        <mesh castShadow receiveShadow>
           <cylinderGeometry args={[1.5, 1.5, 0.8, 32]} />
           <meshStandardMaterial color={mainColor} roughness={0.2} metalness={0.1} />
        </mesh>
        <Text position={[0, 0.5, 0]} fontSize={0.4} color="white" rotation={[-Math.PI/2, 0, 0]}>
           {mode === 'profit' ? 'Profit' : 'Loss'}: {format(totalAmount)} LE
        </Text>
        <Text position={[0, -0.6, 1]} fontSize={0.3} color={particleColor}>
           Unit: {format(unitValue)} LE
        </Text>
      </group>

      {/* Distribution Bundles */}
      <group ref={bundleA} position={[0, 0, 2]}>
         <mesh castShadow><cylinderGeometry args={[0.8, 0.8, 0.4, 32]} /><meshStandardMaterial color={mainColor} /></mesh>
         <Text position={[0, 0.3, 0]} fontSize={0.3} color="white" rotation={[-Math.PI/2, 0, 0]}>{rA} Units</Text>
      </group>
      <group ref={bundleB} position={[0, 0, 2]}>
         <mesh castShadow><cylinderGeometry args={[0.8, 0.8, 0.4, 32]} /><meshStandardMaterial color={mainColor} /></mesh>
         <Text position={[0, 0.3, 0]} fontSize={0.3} color="white" rotation={[-Math.PI/2, 0, 0]}>{rB} Units</Text>
      </group>
      <group ref={bundleC} position={[0, 0, 2]}>
         <mesh castShadow><cylinderGeometry args={[0.8, 0.8, 0.4, 32]} /><meshStandardMaterial color={mainColor} /></mesh>
         <Text position={[0, 0.3, 0]} fontSize={0.3} color="white" rotation={[-Math.PI/2, 0, 0]}>{rC} Units</Text>
      </group>

      {/* Partner Scales / Output */}
      <group ref={scaleA} position={[-4, -3, 2]}>
         <mesh receiveShadow><cylinderGeometry args={[1.2, 1.2, 0.2, 32]} /><meshStandardMaterial color="#3b82f6" /></mesh>
         <Html position={[0, -0.5, 0]} center className={`transition-opacity duration-500 ${step === 4 ? 'opacity-100' : 'opacity-0'}`}>
            <div className={`px-3 py-1 rounded-full font-mono font-bold text-white shadow-lg ${mode === 'profit' ? 'bg-emerald-600' : 'bg-rose-600'}`}>
               {format(shareA)} LE
            </div>
         </Html>
      </group>

      <group ref={scaleB} position={[0, -3, 2]}>
         <mesh receiveShadow><cylinderGeometry args={[1.2, 1.2, 0.2, 32]} /><meshStandardMaterial color="#f97316" /></mesh>
         <Html position={[0, -0.5, 0]} center className={`transition-opacity duration-500 ${step === 4 ? 'opacity-100' : 'opacity-0'}`}>
            <div className={`px-3 py-1 rounded-full font-mono font-bold text-white shadow-lg ${mode === 'profit' ? 'bg-emerald-600' : 'bg-rose-600'}`}>
               {format(shareB)} LE
            </div>
         </Html>
      </group>

      <group ref={scaleC} position={[4, -3, 2]}>
         <mesh receiveShadow><cylinderGeometry args={[1.2, 1.2, 0.2, 32]} /><meshStandardMaterial color="#06b6d4" /></mesh>
         <Html position={[0, -0.5, 0]} center className={`transition-opacity duration-500 ${step === 4 ? 'opacity-100' : 'opacity-0'}`}>
            <div className={`px-3 py-1 rounded-full font-mono font-bold text-white shadow-lg ${mode === 'profit' ? 'bg-emerald-600' : 'bg-rose-600'}`}>
               {format(shareC)} LE
            </div>
         </Html>
      </group>
      
    </group>
  );
}

export default function BusinessProportionsSimulation() {
  const [step, setStep] = useState(0);
  const [mode, setMode] = useState<'profit' | 'loss'>('profit');
  const [totalAmount, setTotalAmount] = useState(150000);

  const capA = 250000, capB = 200000, capC = 300000;
  const factor = 50000;
  const rA = capA / factor, rB = capB / factor, rC = capC / factor;
  const totalParts = rA + rB + rC;
  const unitValue = totalAmount / totalParts;

  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`\\text{Capitals: } ${format(capA)} : ${format(capB)} : ${format(capC)}`} />;
    if (step === 1) return <BlockMath math={`\\text{Ratio} = \\frac{${format(capA)}}{50k} : \\frac{${format(capB)}}{50k} : \\frac{${format(capC)}}{50k} = ${rA}:${rB}:${rC}`} />;
    if (step === 2) return <BlockMath math={`\\text{Unit} = \\frac{${format(totalAmount)}}{${rA}+${rB}+${rC}} = \\frac{${format(totalAmount)}}{${totalParts}} = ${format(unitValue)}`} />;
    if (step === 3) return <BlockMath math={`\\text{Shares} = (${rA} \\times U) : (${rB} \\times U) : (${rC} \\times U)`} />;
    if (step === 4) return (
       <div className="text-sm">
         <BlockMath math={`A = ${format(rA * unitValue)}, \\quad B = ${format(rB * unitValue)}, \\quad C = ${format(rC * unitValue)}`} />
       </div>
    );
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Unequal Investments", icon: <AlertCircle size={18}/>, color: "border-l-rose-500 text-rose-400 bg-rose-900/10", body: <>A common mistake is dividing the annual profits equally by 3. But the partners invested <span className="font-bold text-rose-300">unequal</span> capital! We must distribute proportionally.</> };
    if (step === 1) return { title: "Ratio Simplification", icon: <Divide size={18}/>, color: "border-l-blue-500 text-blue-400 bg-blue-900/10", body: <>Divide all capital amounts by their Greatest Common Divisor (50,000) to find the simplified ratio: {rA} : {rB} : {rC}.</> };
    if (step === 2) return { title: "Unit Part Value", icon: <Coins size={18}/>, color: "border-l-yellow-500 text-yellow-400 bg-yellow-900/10", body: <>Divide the total {mode} ({format(totalAmount)}) by the total parts ({totalParts}) to find the value of one single unit.</> };
    if (step === 3) return { title: "Splitting the Stack", icon: <Scale size={18}/>, color: "border-l-purple-500 text-purple-400 bg-purple-900/10", body: <>The total amount splits into bundles representing each partner's ratio parts.</> };
    if (step === 4) return { title: "Proportional Distribution", icon: <CheckCircle2 size={18}/>, color: "border-l-emerald-500 text-emerald-400 bg-emerald-900/10", body: <>Each partner receives their perfectly fair share. Notice how Partner C, who invested the most, absorbs the largest share of the {mode}.</> };
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
              Business Proportions
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Distributing Profit or Loss proportionally to Capital.
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
           <Canvas shadows camera={{ position: [0, -2, 12], fov: 55 }}>
              <color attach="background" args={['#0f172a']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[10, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <ProcessVisualizer 
                 step={step} 
                 mode={mode}
                 totalAmount={totalAmount}
              />
              
              <OrbitControls 
                enablePan={false} 
                enableZoom={false}
                maxPolarAngle={Math.PI / 2 + 0.1}
                minPolarAngle={Math.PI / 2 - 0.2}
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
           
           {/* Mode Toggle */}
           <div className="grid grid-cols-2 gap-2 bg-slate-950 p-1.5 rounded-xl border border-slate-800">
              <button 
                 className={`flex items-center justify-center gap-2 px-2 py-2 rounded-lg text-sm font-bold transition-all duration-300 ${mode === 'profit' ? 'bg-emerald-600 text-white shadow-lg' : 'text-slate-400 hover:text-white hover:bg-slate-800'}`}
                 onClick={() => { setMode('profit'); setStep(0); }}
              >
                 <TrendingUp size={16}/> Profit Distribution
              </button>
              <button 
                 className={`flex items-center justify-center gap-2 px-2 py-2 rounded-lg text-sm font-bold transition-all duration-300 ${mode === 'loss' ? 'bg-rose-600 text-white shadow-lg' : 'text-slate-400 hover:text-white hover:bg-slate-800'}`}
                 onClick={() => { setMode('loss'); setStep(0); }}
              >
                 <TrendingDown size={16}/> Loss Distribution
              </button>
           </div>

           {/* Progress Indicator */}
           <div className="flex gap-1.5">
             {[0, 1, 2, 3, 4].map(s => (
                <div key={s} className={`h-1 flex-1 rounded-full transition-colors duration-500 ${s <= step ? 'bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.6)]' : 'bg-slate-700'}`} />
             ))}
           </div>

           {/* Amount Slider */}
           <div className="flex flex-col gap-2">
              <div className="flex justify-between items-center text-slate-300">
                <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total {mode === 'profit' ? 'Profit' : 'Loss'} Amount</label>
                <span className={`font-mono font-bold text-xs bg-slate-950 px-2 py-0.5 rounded border border-slate-800 ${mode === 'profit' ? 'text-emerald-400' : 'text-rose-400'}`}>
                   {format(totalAmount)} LE
                </span>
              </div>
              <input 
                type="range" min="75000" max="300000" step="15000" value={totalAmount} 
                onChange={e => {setTotalAmount(Number(e.target.value)); setStep(0);}} 
                className={`w-full h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer ${mode === 'profit' ? 'accent-emerald-500' : 'accent-rose-500'}`} 
              />
           </div>

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-1">
             <button 
                onClick={handleNextStep} 
                disabled={step === 4} 
                className="bg-blue-600 hover:bg-blue-500 text-white font-bold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-blue-900/20 text-sm"
             >
                {step === 0 ? "Simplify Ratio" : step === 4 ? "Distributed" : "Next Step"} 
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
