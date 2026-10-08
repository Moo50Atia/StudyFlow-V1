import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { AlertCircle, Plus, Scissors, Maximize, CheckCircle2, RotateCcw, MoveRight } from 'lucide-react';

interface EdgeProps {
  pStart: THREE.Vector3;
  pEnd: THREE.Vector3;
  color: string;
  label: string;
  lengthValue: number;
  showLabel: boolean;
  isShortest: boolean;
  step: number;
}

function DynamicEdge({ pStart, pEnd, color, label, lengthValue, showLabel, isShortest, step }: EdgeProps) {
  const meshRef = useRef<THREE.Mesh>(null);
  const textRef = useRef<THREE.Group>(null);
  const materialRef = useRef<THREE.MeshStandardMaterial>(null);
  
  // Create vectors once to avoid garbage collection overhead in useFrame
  const vecDir = useMemo(() => new THREE.Vector3(), []);
  const vecMid = useMemo(() => new THREE.Vector3(), []);
  const upVec = useMemo(() => new THREE.Vector3(0, 1, 0), []);
  const quaternion = useMemo(() => new THREE.Quaternion(), []);

  useFrame(() => {
    if (!meshRef.current) return;
    
    // Calculate distance
    const dist = pStart.distanceTo(pEnd);
    
    // Calculate midpoint
    vecMid.addVectors(pStart, pEnd).multiplyScalar(0.5);
    
    // Calculate rotation
    vecDir.subVectors(pEnd, pStart).normalize();
    quaternion.setFromUnitVectors(upVec, vecDir);
    
    // Apply transforms
    meshRef.current.position.copy(vecMid);
    meshRef.current.quaternion.copy(quaternion);
    
    // Scale the cylinder to the exact length
    meshRef.current.scale.set(1, dist, 1);
    
    // Position label slightly offset from midpoint
    if (textRef.current) {
       textRef.current.position.copy(vecMid);
       // Offset label depending on orientation to avoid clipping
       const offset = new THREE.Vector3(-vecDir.y, vecDir.x, 0.5).normalize().multiplyScalar(0.8);
       textRef.current.position.add(offset);
    }
  });

  useEffect(() => {
    if (!materialRef.current) return;
    
    // Highlight shortest side in step 4
    if (step === 4 && isShortest) {
      gsap.to(materialRef.current, {
        emissiveIntensity: 0.8,
        roughness: 0.1,
        duration: 0.5,
        yoyo: true,
        repeat: 3
      });
      materialRef.current.emissive = new THREE.Color('#fbbf24');
    } else {
      gsap.to(materialRef.current, {
        emissiveIntensity: 0,
        roughness: 0.3,
        duration: 0.5
      });
      materialRef.current.emissive = new THREE.Color('#000000');
    }
  }, [step, isShortest]);

  return (
    <group>
      <mesh ref={meshRef} castShadow receiveShadow>
        <cylinderGeometry args={[0.2, 0.2, 1, 32]} />
        <meshStandardMaterial ref={materialRef} color={color} roughness={0.3} metalness={0.2} />
      </mesh>
      <group ref={textRef}>
         <Html center className={`transition-all duration-500 pointer-events-none ${showLabel ? 'opacity-100 scale-100' : 'opacity-0 scale-50'}`}>
            <div className="bg-slate-900/90 border border-slate-700 text-white px-2 py-1 rounded-lg shadow-xl flex flex-col items-center backdrop-blur-md">
               <span className="text-[9px] uppercase font-bold tracking-widest text-slate-400">{label}</span>
               <span className="font-mono font-bold text-sm" style={{ color: color }}>{lengthValue} cm</span>
            </div>
         </Html>
      </group>
    </group>
  );
}

interface ProcessProps {
  step: number;
  perimeter: number;
  rA: number;
  rB: number;
  rC: number;
}

function ProcessVisualizer({ step, perimeter, rA, rB, rC }: ProcessProps) {
  // Math
  const totalParts = rA + rB + rC;
  const unitValue = perimeter / totalParts;
  const lA = rA * unitValue;
  const lB = rB * unitValue;
  const lC = rC * unitValue;
  
  // Shortest side check
  const minL = Math.min(lA, lB, lC);

  // Visual scaling (map total perimeter to 20 visual units so it fits on screen)
  const visualScale = 20 / perimeter;
  const vA = lA * visualScale;
  const vB = lB * visualScale;
  const vC = lC * visualScale;

  // React refs for the 4 control points
  const p0 = useRef(new THREE.Vector3()).current;
  const p1 = useRef(new THREE.Vector3()).current;
  const p2 = useRef(new THREE.Vector3()).current;
  const p3 = useRef(new THREE.Vector3()).current;
  
  // Marker/Ticks refs
  const tickGrpRef = useRef<THREE.Group>(null);
  
  useEffect(() => {
    // 1. Calculate Linear Layout Points
    const halfTotal = 10; // since total visual length is 20
    const linY = -2;
    const linP0 = new THREE.Vector3(-halfTotal, linY, 0);
    const linP1 = new THREE.Vector3(-halfTotal + vA, linY, 0);
    const linP2 = new THREE.Vector3(-halfTotal + vA + vB, linY, 0);
    const linP3 = new THREE.Vector3(-halfTotal + vA + vB + vC, linY, 0); // (10, -2, 0)

    // 2. Calculate Triangle Layout Points
    // Using law of cosines / distance intersection
    // P0 -> P1 has length vA
    // P1 -> P2 has length vB
    // P2 -> P0 has length vC
    const a = vA;
    const b = vB;
    const c = vC;
    
    let triP0 = new THREE.Vector3(0, 0, 0);
    let triP1 = new THREE.Vector3(a, 0, 0);
    let triP2 = new THREE.Vector3(0, 0, 0);
    
    // Check if triangle is valid, otherwise fallback to flat
    if (a + b > c && a + c > b && b + c > a) {
       const x = (a*a + c*c - b*b) / (2*a);
       const y = Math.sqrt(Math.max(0, c*c - x*x)); // Math.max prevents tiny negative floats due to precision
       triP2.set(x, y, 0);
    } else {
       // Invalid triangle fallback (shouldn't happen due to UI clamps, but just in case)
       triP2.set(a - b, 0, 0); 
    }
    
    const triP3 = new THREE.Vector3(0, 0, 0); // P3 must connect back to P0

    // Center the triangle points
    const centroid = new THREE.Vector3(
      (triP0.x + triP1.x + triP2.x) / 3,
      (triP0.y + triP1.y + triP2.y) / 3,
      0
    );
    triP0.sub(centroid);
    triP1.sub(centroid);
    triP2.sub(centroid);
    triP3.sub(centroid);
    
    // Add Y offset for presentation
    const triYOffset = 1;
    triP0.y += triYOffset;
    triP1.y += triYOffset;
    triP2.y += triYOffset;
    triP3.y += triYOffset;

    // Apply Transitions
    if (step < 4) {
      // Linear layout
      gsap.to(p0, { x: linP0.x, y: linP0.y, z: linP0.z, duration: 1.2, ease: 'power2.inOut' });
      gsap.to(p1, { x: linP1.x, y: linP1.y, z: linP1.z, duration: 1.2, ease: 'power2.inOut' });
      gsap.to(p2, { x: linP2.x, y: linP2.y, z: linP2.z, duration: 1.2, ease: 'power2.inOut' });
      gsap.to(p3, { x: linP3.x, y: linP3.y, z: linP3.z, duration: 1.2, ease: 'power2.inOut' });
    } else {
      // Triangle layout
      gsap.to(p0, { x: triP0.x, y: triP0.y, z: triP0.z, duration: 1.2, ease: 'power2.inOut' });
      gsap.to(p1, { x: triP1.x, y: triP1.y, z: triP1.z, duration: 1.2, ease: 'power2.inOut' });
      gsap.to(p2, { x: triP2.x, y: triP2.y, z: triP2.z, duration: 1.2, ease: 'power2.inOut' });
      gsap.to(p3, { x: triP3.x, y: triP3.y, z: triP3.z, duration: 1.2, ease: 'power2.inOut' });
    }

    // Ticks visibility (Step 1 and 2)
    if (tickGrpRef.current) {
      if (step === 1 || step === 2) {
         tickGrpRef.current.visible = true;
         gsap.to(tickGrpRef.current.scale, { y: 1, duration: 0.5 });
      } else {
         gsap.to(tickGrpRef.current.scale, { y: 0, duration: 0.5, onComplete: () => {
             if (tickGrpRef.current) tickGrpRef.current.visible = false;
         }});
      }
    }

  }, [step, vA, vB, vC, p0, p1, p2, p3]);

  // Generate Ticks (Parts)
  const ticks = [];
  const tickSpacing = 20 / totalParts;
  for (let i = 1; i < totalParts; i++) {
     const tX = -10 + i * tickSpacing;
     const isFirstUnit = i === 1;
     ticks.push(
       <mesh key={i} position={[tX, -2, 0]}>
          <boxGeometry args={[0.05, 1, 0.4]} />
          <meshStandardMaterial color={isFirstUnit && step === 2 ? "#fbbf24" : "#ffffff"} emissive={isFirstUnit && step === 2 ? "#fbbf24" : "#000000"} emissiveIntensity={step === 2 ? 1 : 0} />
       </mesh>
     );
  }

  return (
    <group>
      <DynamicEdge pStart={p0} pEnd={p1} color="#3b82f6" label={`Side A (${rA} parts)`} lengthValue={lA} showLabel={step >= 3} isShortest={lA === minL} step={step} />
      <DynamicEdge pStart={p1} pEnd={p2} color="#f97316" label={`Side B (${rB} parts)`} lengthValue={lB} showLabel={step >= 3} isShortest={lB === minL} step={step} />
      <DynamicEdge pStart={p2} pEnd={p3} color="#06b6d4" label={`Side C (${rC} parts)`} lengthValue={lC} showLabel={step >= 3} isShortest={lC === minL} step={step} />
      
      <group ref={tickGrpRef} scale={[1, 0, 1]} visible={false}>
         {ticks}
         
         {/* Unit length label in step 2 */}
         <Html position={[-10 + tickSpacing/2, -3.2, 0]} center className={`transition-all duration-500 pointer-events-none ${step === 2 ? 'opacity-100 translate-y-0' : 'opacity-0 -translate-y-4'}`}>
            <div className="bg-yellow-900/90 border border-yellow-500 text-yellow-100 px-3 py-1.5 rounded-xl shadow-xl flex flex-col items-center backdrop-blur-md whitespace-nowrap">
               <span className="text-[10px] uppercase font-bold tracking-widest text-yellow-300">Unit Part</span>
               <span className="font-mono font-bold text-base">{unitValue} cm</span>
            </div>
         </Html>
      </group>

      {/* Perimeter Label */}
      <Html position={[0, -4.5, 0]} center className={`transition-opacity duration-500 pointer-events-none ${step < 4 ? 'opacity-100' : 'opacity-0'}`}>
          <div className="bg-slate-800/90 border border-slate-600 text-white px-6 py-2 rounded-xl shadow-xl flex flex-col items-center whitespace-nowrap backdrop-blur-md">
             <span className="text-xs uppercase tracking-widest font-bold text-slate-400">Total Perimeter</span>
             <span className="text-xl font-mono font-bold text-emerald-400">{perimeter} cm</span>
          </div>
      </Html>
    </group>
  );
}

export default function TriangleProportionsSimulation() {
  const [step, setStep] = useState(0);
  
  const [perimeter, setPerimeter] = useState(144);
  const [rA, setRA] = useState(5);
  const [rB, setRB] = useState(4);
  const [rC, setRC] = useState(3);

  // Check valid triangle: Sum of any two sides must be > third side.
  // In proportion terms, the sum of any two ratio parts must be > third part.
  const isValidTriangle = (a: number, b: number, c: number) => {
    return (a + b > c) && (a + c > b) && (b + c > a);
  };

  const handleRatioChange = (target: 'A' | 'B' | 'C', val: number) => {
    let newA = rA, newB = rB, newC = rC;
    if (target === 'A') newA = val;
    if (target === 'B') newB = val;
    if (target === 'C') newC = val;
    
    // Only apply if it mathematically can form a triangle
    if (isValidTriangle(newA, newB, newC)) {
      setRA(newA);
      setRB(newB);
      setRC(newC);
      setStep(0); // Reset simulation on structural change
    }
  };

  const totalParts = rA + rB + rC;
  const unitValue = perimeter / totalParts;

  const handleNextStep = () => {
    if (step < 4) setStep(s => s + 1);
  };

  const format = (n: number) => Number.isInteger(n) ? n.toString() : n.toFixed(1);

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`P = ${format(perimeter)}\\text{ cm}, \\quad R = ${rA}:${rB}:${rC}`} />;
    if (step === 1) return <BlockMath math={`\\text{Total Parts} = ${rA} + ${rB} + ${rC} = ${totalParts}`} />;
    if (step === 2) return <BlockMath math={`\\text{Unit Part} = \\frac{${format(perimeter)}}{${totalParts}} = ${format(unitValue)}\\text{ cm}`} />;
    if (step === 3) return (
       <div className="text-sm">
         <BlockMath math={`A = ${rA} \\times ${format(unitValue)} = ${format(rA * unitValue)}`} />
         <BlockMath math={`B = ${rB} \\times ${format(unitValue)} = ${format(rB * unitValue)}`} />
         <BlockMath math={`C = ${rC} \\times ${format(unitValue)} = ${format(rC * unitValue)}`} />
       </div>
    );
    if (step === 4) return <BlockMath math={`P = ${format(rA * unitValue)} + ${format(rB * unitValue)} + ${format(rC * unitValue)} = ${format(perimeter)}`} />;
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Geometric Wireframe", icon: <AlertCircle size={18}/>, color: "border-l-rose-500 text-rose-400 bg-rose-900/10", body: <>A common misconception is looking at a ratio like <span className="font-bold text-rose-300">5 : 4 : 3</span> and assuming the sides are exactly 5cm, 4cm, and 3cm long. The ratio represents <i>parts</i>, not absolute length!</> };
    if (step === 1) return { title: "Combining Parts", icon: <Plus size={18}/>, color: "border-l-blue-500 text-blue-400 bg-blue-900/10", body: <>If we sum the ratio components ({rA} + {rB} + {rC}), we discover the total wireframe perimeter is made up of <span className="font-bold text-blue-300">{totalParts} equal parts</span>.</> };
    if (step === 2) return { title: "Unit Part Division", icon: <Scissors size={18}/>, color: "border-l-yellow-500 text-yellow-400 bg-yellow-900/10", body: <>By dividing the total length ({perimeter} cm) by the number of parts ({totalParts}), we find that one single unit part is exactly <span className="font-bold text-yellow-300">{format(unitValue)} cm</span> long.</> };
    if (step === 3) return { title: "Proportional Expansion", icon: <Maximize size={18}/>, color: "border-l-purple-500 text-purple-400 bg-purple-900/10", body: <>Now we multiply that unit length by the ratio parts. The 5-part ratio becomes {format(rA * unitValue)} cm, proving the ratio drives proportions, not absolute linear scales.</> };
    if (step === 4) return { title: "Final Geometry", icon: <CheckCircle2 size={18}/>, color: "border-l-emerald-500 text-emerald-400 bg-emerald-900/10", body: <>The expanded lengths snap together to form the triangle! The shortest side (ratio {Math.min(rA,rB,rC)}) glows in gold, completing the geometry.</> };
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
              Geometric Proportions
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Visualizing how perimeter ratios convert to absolute physical lengths.
            </p>
          </div>
          <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[320px] flex justify-center shadow-inner">
             <div className="text-white text-lg">
               {renderMath()}
             </div>
          </div>
        </div>
      </div>
      
      {/* R3F Canvas */}
      <div className="flex-1 w-full h-full relative z-0 touch-none">
         <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
           <Canvas shadows camera={{ position: [0, 2, 18], fov: 50 }}>
              <color attach="background" args={['#0f172a']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[10, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <ProcessVisualizer 
                 step={step} 
                 perimeter={perimeter}
                 rA={rA}
                 rB={rB}
                 rC={rC}
              />
              
              <OrbitControls 
                enablePan={false} 
                enableZoom={false}
                maxPolarAngle={Math.PI / 2 + 0.1}
                minPolarAngle={Math.PI / 2 - 0.5}
                maxAzimuthAngle={0.3}
                minAzimuthAngle={-0.3}
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

        <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col gap-4 pointer-events-auto shrink-0 w-full lg:w-[480px]">
           
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
                  <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Perimeter</label>
                  <span className="font-mono font-bold text-xs bg-slate-950 px-2 py-0.5 rounded border border-slate-800 text-emerald-400">
                     {perimeter} cm
                  </span>
                </div>
                <input 
                  type="range" min="60" max="300" step="12" value={perimeter} 
                  onChange={e => {setPerimeter(Number(e.target.value)); setStep(0);}} 
                  className="w-full h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer accent-emerald-500" 
                />
              </div>

              <div className="grid grid-cols-3 gap-3">
                 <div className="flex flex-col gap-2 border border-blue-900/50 bg-blue-950/30 p-2 rounded-xl">
                   <div className="flex justify-between items-center text-slate-300">
                     <label className="text-[10px] font-bold uppercase tracking-wider text-blue-400">Side A</label>
                     <span className="text-blue-400 font-mono font-bold text-xs">{rA}</span>
                   </div>
                   <input 
                     type="range" min="2" max="10" step="1" value={rA} 
                     onChange={e => handleRatioChange('A', Number(e.target.value))} 
                     className="w-full accent-blue-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer" 
                   />
                 </div>
                 
                 <div className="flex flex-col gap-2 border border-orange-900/50 bg-orange-950/30 p-2 rounded-xl">
                   <div className="flex justify-between items-center text-slate-300">
                     <label className="text-[10px] font-bold uppercase tracking-wider text-orange-400">Side B</label>
                     <span className="text-orange-400 font-mono font-bold text-xs">{rB}</span>
                   </div>
                   <input 
                     type="range" min="2" max="10" step="1" value={rB} 
                     onChange={e => handleRatioChange('B', Number(e.target.value))} 
                     className="w-full accent-orange-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer" 
                   />
                 </div>

                 <div className="flex flex-col gap-2 border border-cyan-900/50 bg-cyan-950/30 p-2 rounded-xl">
                   <div className="flex justify-between items-center text-slate-300">
                     <label className="text-[10px] font-bold uppercase tracking-wider text-cyan-400">Side C</label>
                     <span className="text-cyan-400 font-mono font-bold text-xs">{rC}</span>
                   </div>
                   <input 
                     type="range" min="2" max="10" step="1" value={rC} 
                     onChange={e => handleRatioChange('C', Number(e.target.value))} 
                     className="w-full accent-cyan-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer" 
                   />
                 </div>
              </div>
              <p className="text-[10px] text-center text-slate-500 font-medium">Sliders are mathematically constrained to prevent invalid triangles.</p>
           </div>

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-1">
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
