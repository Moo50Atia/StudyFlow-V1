import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, Grid3X3, Combine, AlertTriangle, ArrowDownUp, CheckCircle2 } from 'lucide-react';

interface ProcessProps {
  step: number;
  op: '+' | '-';
}

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function PieBase({ position, radius = 2.1, subdivisions, color = "#1e293b", targetOpacity = 0.2 }: { position: [number, number, number], radius?: number, subdivisions: number, color?: string, targetOpacity?: number }) {
  const meshRef = useRef<THREE.Mesh>(null);
  const torusRef = useRef<THREE.Mesh>(null);
  const linesGroup = useRef<THREE.Group>(null);
  
  useEffect(() => {
     if (meshRef.current) gsap.to((meshRef.current.material as THREE.Material), { opacity: targetOpacity, duration: 0.5 });
     if (torusRef.current) gsap.to((torusRef.current.material as THREE.Material), { opacity: targetOpacity > 0 ? 0.8 : 0, duration: 0.5 });
     if (linesGroup.current) {
        linesGroup.current.children.forEach(child => {
           gsap.to((child as THREE.Mesh).material as THREE.Material, { opacity: targetOpacity > 0 ? 0.6 : 0, duration: 0.5 });
        });
     }
  }, [targetOpacity, subdivisions]);

  const theta = (Math.PI * 2) / subdivisions;
  const lines = Array.from({ length: subdivisions }).map((_, i) => {
     const angle = i * theta;
     return (
        <mesh key={i} position={[Math.cos(angle)*(radius/2), 0.05, -Math.sin(angle)*(radius/2)]} rotation={[0, angle, 0]}>
           <boxGeometry args={[radius, 0.02, 0.02]} />
           <meshBasicMaterial color="#94a3b8" transparent opacity={targetOpacity > 0 ? 0.6 : 0} />
        </mesh>
     );
  });

  return (
    <group position={position}>
       <mesh ref={meshRef} rotation={[-Math.PI/2, 0, 0]}>
         <circleGeometry args={[radius, 64]} />
         <meshStandardMaterial color={color} transparent opacity={targetOpacity} depthWrite={false} />
       </mesh>
       <mesh ref={torusRef} rotation={[-Math.PI/2, 0, 0]} position={[0, 0.05, 0]}>
         <torusGeometry args={[radius, 0.04, 16, 64]} />
         <meshStandardMaterial color="#64748b" transparent opacity={targetOpacity > 0 ? 0.8 : 0} />
       </mesh>
       <group ref={linesGroup}>
         {lines}
       </group>
       
       <Text position={[0, -0.5, 2.5]} rotation={[-Math.PI/4, 0, 0]} fontSize={0.6} color="#cbd5e1" fillOpacity={targetOpacity}>
          Grid: {subdivisions}
       </Text>
    </group>
  );
}

function FractionScene({ nA, dA, nB, dB, lcd, step, op }: { nA: number, dA: number, nB: number, dB: number, lcd: number, step: number, op: '+' | '-' }) {
  const slicesA = nA * (lcd / dA);
  const slicesB = nB * (lcd / dB);
  const thetaLength = (Math.PI * 2) / lcd;

  const aGroupRefs = useRef<THREE.Group[]>([]);
  const aMeshRefs = useRef<THREE.Mesh[]>([]);
  const bGroupRefs = useRef<THREE.Group[]>([]);
  const bMeshRefs = useRef<THREE.Mesh[]>([]);

  const shape = useMemo(() => {
     const s = new THREE.Shape();
     s.moveTo(0,0);
     s.arc(0, 0, 2, 0, thetaLength, false);
     s.lineTo(0,0);
     return s;
  }, [thetaLength]);

  const extrudeSettings = { depth: 0.2, bevelEnabled: true, bevelSegments: 2, steps: 1, bevelSize: 0.02, bevelThickness: 0.02, curveSegments: 32 };

  // Sync refs array size
  aGroupRefs.current = aGroupRefs.current.slice(0, slicesA);
  aMeshRefs.current = aMeshRefs.current.slice(0, slicesA);
  bGroupRefs.current = bGroupRefs.current.slice(0, slicesB);
  bMeshRefs.current = bMeshRefs.current.slice(0, slicesB);

  // Animations
  useEffect(() => {
    const combined = step % 3 === 2; // step 2 or 5
    
    // Reset or Apply Combine
    if (!combined) {
       aGroupRefs.current.forEach((g, i) => {
          if (g) gsap.to(g.position, { x: -4, y: 0, z: 0, duration: 1, ease: 'power2.out' });
       });
       aMeshRefs.current.forEach((m, i) => {
          if (m) {
            gsap.to(m.rotation, { z: i * thetaLength, duration: 1 });
            gsap.to((m.material as THREE.Material), { opacity: 1, duration: 0.5 });
          }
       });

       bGroupRefs.current.forEach((g, i) => {
          if (g) gsap.to(g.position, { x: 4, y: 0, z: 0, duration: 1, ease: 'power2.out' });
       });
       bMeshRefs.current.forEach((m, i) => {
          if (m) {
            gsap.to(m.rotation, { z: i * thetaLength, duration: 1 });
            gsap.to((m.material as THREE.Material), { opacity: 1, duration: 0.5 });
            gsap.to((m.material as any).color, { r: 139/255, g: 92/255, b: 246/255, duration: 0.5 }); // Purple
          }
       });
    } else {
       // COMBINE
       if (op === '+') {
          aGroupRefs.current.forEach((g, i) => {
             if (g) gsap.to(g.position, { x: 0, y: 0, z: 0, duration: 1, ease: 'power2.inOut' });
          });
          bGroupRefs.current.forEach((g, i) => {
             if (g) gsap.to(g.position, { x: 0, y: 0, z: 0, duration: 1, ease: 'power2.inOut', delay: 0.2 });
          });
          bMeshRefs.current.forEach((m, i) => {
             if (m) gsap.to(m.rotation, { z: (slicesA + i) * thetaLength, duration: 1, ease: 'power2.inOut', delay: 0.2 });
          });
       } else if (op === '-') {
          aGroupRefs.current.forEach((g, i) => {
             if (g) gsap.to(g.position, { x: 0, y: 0, z: 0, duration: 1, ease: 'power2.inOut' });
          });
          bGroupRefs.current.forEach((g, i) => {
             if (g) {
                const tl = gsap.timeline();
                tl.to(g.position, { x: 0, y: 0.21, z: 0, duration: 1, ease: 'power2.inOut', delay: 0.2 }); // slightly above
             }
          });
          bMeshRefs.current.forEach((m, i) => {
             const overlapIndex = slicesA - slicesB + i;
             if (m) {
                const tl = gsap.timeline({ delay: 0.2 });
                tl.to(m.rotation, { z: overlapIndex * thetaLength, duration: 1, ease: 'power2.inOut' }, 0);
                tl.to((m.material as any).color, { r: 239/255, g: 68/255, b: 68/255, duration: 0.3 }, 1); // Red
                tl.to((m.material as THREE.Material), { opacity: 0, duration: 0.5 }, 1.5);
                if (aMeshRefs.current[overlapIndex]) {
                   gsap.to((aMeshRefs.current[overlapIndex].material as THREE.Material), { opacity: 0, duration: 0.5, delay: 0.2 + 1.5 });
                }
             }
          });
       }
    }
  }, [step, op, lcd, slicesA, slicesB, thetaLength]);

  const showLCD = step % 3 >= 1;
  const combined = step % 3 === 2;

  return (
    <group>
       <PieBase position={[-4, -0.1, 0]} radius={2.1} subdivisions={showLCD ? lcd : dA} targetOpacity={combined ? 0 : 0.2} />
       <PieBase position={[4, -0.1, 0]} radius={2.1} subdivisions={showLCD ? lcd : dB} targetOpacity={combined ? 0 : 0.2} />
       <PieBase position={[0, -0.1, 0]} radius={2.1} subdivisions={lcd} targetOpacity={combined ? 0.3 : 0} color="#10b981" />

       {Array.from({ length: slicesA }).map((_, i) => (
         <group key={`a-${i}`} ref={el => aGroupRefs.current[i] = el!} position={[-4, 0, 0]}>
            <mesh ref={el => aMeshRefs.current[i] = el!} rotation={[-Math.PI/2, 0, i * thetaLength]} castShadow>
               <extrudeGeometry args={[shape, extrudeSettings]} />
               <meshStandardMaterial color="#3b82f6" roughness={0.3} transparent opacity={1} />
            </mesh>
         </group>
       ))}

       {Array.from({ length: slicesB }).map((_, i) => (
         <group key={`b-${i}`} ref={el => bGroupRefs.current[i] = el!} position={[4, 0, 0]}>
            <mesh ref={el => bMeshRefs.current[i] = el!} rotation={[-Math.PI/2, 0, i * thetaLength]} castShadow>
               <extrudeGeometry args={[shape, extrudeSettings]} />
               <meshStandardMaterial color="#8b5cf6" roughness={0.3} transparent opacity={1} />
            </mesh>
         </group>
       ))}
    </group>
  );
}

// ---------------------------------------------------------
// MAIN SCENE CONTROLLER
// ---------------------------------------------------------
function Scene({ step, op }: ProcessProps) {
  const { camera } = useThree();
  const controlsRef = useRef<any>(null);

  useEffect(() => {
    let t = { camX: 0, camY: 6, camZ: 8, tarX: 0, tarY: 0 }; // default wide
    const combined = step % 3 === 2;
    if (combined) {
        t = { camX: 0, camY: 5, camZ: 6, tarX: 0, tarY: 0 };
    }

    gsap.to(camera.position, { x: t.camX, y: t.camY, z: t.camZ, duration: 1.5, ease: 'power3.inOut' });
    if (controlsRef.current) {
      gsap.to(controlsRef.current.target, { x: t.tarX, y: t.tarY, z: 0, duration: 1.5, ease: 'power3.inOut' });
    }
  }, [step, camera]);

  const isEx1 = step < 3;

  return (
    <group>
      <OrbitControls 
        ref={controlsRef} 
        enablePan={false} 
        enableZoom={false}
        maxPolarAngle={Math.PI / 2 - 0.2}
        minPolarAngle={0.2}
        maxAzimuthAngle={0.4}
        minAzimuthAngle={-0.4}
      />
      
      {/* Floor */}
      <mesh position={[0, -2, 0]} receiveShadow>
         <boxGeometry args={[30, 0.2, 10]} />
         <meshStandardMaterial color="#0f172a" />
      </mesh>

      {/* Dynamic Example Switching using React Key to hard-reset GSAP state */}
      <FractionScene 
         key={isEx1 ? 'ex1' : 'ex2'} 
         nA={1} dA={isEx1 ? 4 : 3} 
         nB={1} dB={isEx1 ? 2 : 4} 
         lcd={isEx1 ? 4 : 12} 
         step={step} 
         op={isEx1 ? '+' : op} 
      />

    </group>
  );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function LCDSimulation() {
  const [step, setStep] = useState(0);
  const [op, setOp] = useState<'+' | '-'>('+');

  const handleNextStep = () => {
    if (step < 5) setStep(s => s + 1);
  };

  const toggleOp = () => {
    setOp(prev => prev === '+' ? '-' : '+');
    // If we are on the result step (5), re-trigger animation by bouncing to step 4 briefly
    if (step === 5) {
        setStep(4);
        setTimeout(() => setStep(5), 100);
    }
  };

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`\\frac{1}{4} + \\frac{1}{2}`} />;
    if (step === 1) return <BlockMath math={`\\frac{1}{4} + \\frac{2}{4}`} />;
    if (step === 2) return <BlockMath math={`\\frac{3}{4}`} />;
    
    if (step === 3) return <BlockMath math={`\\frac{1}{3} ${op} \\frac{1}{4}`} />;
    if (step === 4) return <BlockMath math={`\\frac{4}{12} ${op} \\frac{3}{12}`} />;
    if (step === 5) {
       if (op === '+') return <BlockMath math={`\\frac{7}{12}`} />;
       if (op === '-') return <BlockMath math={`\\frac{1}{12}`} />;
    }
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Unlike Denominators", icon: <MoveRight size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>We have two fractions with different base grids: <span className="font-mono">1/4</span> and <span className="font-mono">1/2</span>. Because their slice sizes differ, we cannot directly combine them.</> };
    if (step === 1) return { title: "Common Denominator (LCD)", icon: <AlertTriangle size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> Never add denominators directly ($1/4 + 1/2 \\neq 2/6$). Instead, we apply a Lowest Common Denominator (LCD). The <span className="font-mono">1/2</span> pie splits to match the <span className="font-mono">4</span>-grid, becoming <span className="font-mono text-rose-400">2/4</span>.</> };
    if (step === 2) return { title: "Physical Combination", icon: <Combine size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>Now that both pies are sliced into quarters (Grid: 4), we physically merge them. <span className="font-mono">1/4</span> plus <span className="font-mono">2/4</span> combine perfectly into a unified <span className="font-mono text-emerald-400">3/4</span> pie.</> };
    if (step === 3) return { title: "Base 3 and 4", icon: <Grid3X3 size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>Let's try a harder example: <span className="font-mono">1/3</span> and <span className="font-mono">1/4</span>. Use the toggle below to switch between Addition and Subtraction before continuing.</> };
    if (step === 4) return { title: "Grid Expansion (LCD = 12)", icon: <Grid3X3 size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>Both grids must be standardized to their LCD, which is 12. The <span className="font-mono">1/3</span> becomes <span className="font-mono">4/12</span>, and the <span className="font-mono">1/4</span> becomes <span className="font-mono">3/12</span>.</> };
    if (step === 5) {
       if (op === '+') return { title: "Addition Result", icon: <Combine size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>The slices merge seamlessly. <span className="font-mono">4/12</span> plus <span className="font-mono">3/12</span> fills exactly <span className="font-mono text-emerald-400">7/12</span> of the standardized grid.</> };
       if (op === '-') return { title: "Subtraction Result", icon: <ArrowDownUp size={18}/>, color: "border-l-yellow-500 text-yellow-300 bg-yellow-900/40", body: <>Subtraction causes cancellation. The <span className="font-mono">3</span> purple slices overlap and annihilate <span className="font-mono">3</span> of the blue slices, leaving only <span className="font-mono text-yellow-400">1/12</span> remaining.</> };
    }
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
              Adding & Subtracting Fractions
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Common denominators (LCD) and physical grid standardization.
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
           <Canvas shadows camera={{ position: [0, 6, 8], fov: 45 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <Scene step={step} op={op} />
              
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
                 <span>Ex 1</span>
                 <span className="text-blue-400">LCD(4)</span>
                 <span className="text-emerald-400">Combine</span>
                 <span className="text-purple-400">Ex 2</span>
                 <span className="text-blue-400">LCD(12)</span>
                 <span className="text-emerald-400">Result</span>
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

           {/* Toggle (Only active for Ex 2) */}
           <div className="flex flex-col gap-2 mb-4">
               <button
                  onClick={toggleOp}
                  disabled={step < 3}
                  className={`w-full border font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-md ${step < 3 ? 'bg-slate-800 border-slate-700 text-slate-600 cursor-not-allowed' : 'bg-slate-700 hover:bg-slate-600 border-slate-500 text-white'}`}
               >
                  <ArrowDownUp size={16} className={op === '+' ? "text-emerald-400" : "text-rose-400"} />
                  {op === '+' ? "Operation: Addition (+)" : "Operation: Subtraction (-)"}
               </button>
           </div>

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3">
             <button 
                onClick={handleNextStep} 
                disabled={step === 5} 
                className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm ${step === 5 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
             >
                {step === 0 ? "Apply LCD" : 
                 step === 1 ? "Combine Grid" : 
                 step === 2 ? "Load Example 2" : 
                 step === 3 ? "Apply LCD" : 
                 step === 4 ? (op === '+' ? "Merge Slices" : "Subtract Slices") : 
                 "Completed"} 
                {step < 5 && <MoveRight size={16} />}
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
