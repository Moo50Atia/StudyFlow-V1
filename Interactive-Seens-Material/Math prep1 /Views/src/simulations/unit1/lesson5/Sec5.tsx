import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Html } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { Combine, Scaling, Settings2, Split, CheckCircle2, RotateCcw, AlertTriangle } from 'lucide-react';

interface ProcessProps {
  step: number;
}

// ---------------------------------------------------------
// REUSABLE COMPONENTS
// ---------------------------------------------------------
function Particle({ text, position, groupRef, matRef, textMatRef }: { text: string, position: [number, number, number], groupRef: React.Ref<THREE.Group>, matRef: React.Ref<THREE.MeshStandardMaterial>, textMatRef: React.Ref<any> }) {
  return (
    <group ref={groupRef} position={position}>
      <mesh castShadow receiveShadow>
        <sphereGeometry args={[0.4, 32, 32]} />
        <meshStandardMaterial ref={matRef} color="#1e293b" roughness={0.3} metalness={0.2} transparent />
      </mesh>
      <Text position={[0, 0, 0.41]} fontSize={0.5} outlineWidth={0.03} outlineColor="#000">
        <meshBasicMaterial ref={textMatRef} color="#ffffff" transparent />
        {text}
      </Text>
    </group>
  );
}

// ---------------------------------------------------------
// MAIN SCENE
// ---------------------------------------------------------
function Scene({ step }: ProcessProps) {
  const { camera } = useThree();
  const controlsRef = useRef<any>(null);

  // Group Refs for moving sets
  const circleXGroup = useRef<THREE.Group>(null);
  const circleYGroup = useRef<THREE.Group>(null);

  // Material Refs for animations
  const matXRef = useRef<THREE.MeshStandardMaterial>(null);
  const borderXRef = useRef<THREE.MeshStandardMaterial>(null);
  
  const matYRef = useRef<THREE.MeshStandardMaterial>(null);
  const borderYRef = useRef<THREE.MeshStandardMaterial>(null);

  const matLensRef = useRef<THREE.MeshStandardMaterial>(null);
  const lensGroupRef = useRef<THREE.Group>(null);

  // Particle Refs
  const p1Group = useRef<THREE.Group>(null); const p1Mat = useRef<THREE.MeshStandardMaterial>(null); const p1Text = useRef<any>(null);
  const p3Group = useRef<THREE.Group>(null); const p3Mat = useRef<THREE.MeshStandardMaterial>(null); const p3Text = useRef<any>(null);
  const p5Group = useRef<THREE.Group>(null); const p5Mat = useRef<THREE.MeshStandardMaterial>(null); const p5Text = useRef<any>(null);
  const p7Group = useRef<THREE.Group>(null); const p7Mat = useRef<THREE.MeshStandardMaterial>(null); const p7Text = useRef<any>(null);

  // Mathematical exact lens geometry
  const d = 2.4;
  const R = 2.2;
  const lensGeometry = useMemo(() => {
    const theta = Math.acos((d / 2) / R);
    const shape = new THREE.Shape();
    shape.absarc(-d / 2, 0, R, -theta, theta, false);
    shape.absarc(d / 2, 0, R, Math.PI - theta, Math.PI + theta, false);
    return new THREE.ShapeGeometry(shape);
  }, [d, R]);

  // Camera Management
  useEffect(() => {
    gsap.to(camera.position, { x: 0, y: 8, z: 8, duration: 1.5, ease: 'power3.inOut' });
    if (controlsRef.current) {
      gsap.to(controlsRef.current.target, { x: 0, y: 0, z: 0, duration: 1.5, ease: 'power3.inOut' });
    }
  }, [camera]);

  // Color Constants
  const colors = {
    blue: new THREE.Color('#3b82f6'),
    purple: new THREE.Color('#8b5cf6'),
    green: new THREE.Color('#10b981'),
    yellow: new THREE.Color('#eab308'),
    dark: new THREE.Color('#1e293b')
  };

  // State Animations
  useEffect(() => {
    const dur = 1.0;

    const setParticleState = (mat: any, text: any, col: THREE.Color, opac: number) => {
       gsap.to(mat.color, { r: col.r, g: col.g, b: col.b, duration: dur });
       gsap.to(mat, { opacity: opac, duration: dur });
       gsap.to(text, { opacity: opac, duration: dur });
    };

    if (step === 0) {
      // BASE VIEW
      gsap.to(circleXGroup.current!.position, { x: -d/2, duration: dur });
      gsap.to(circleYGroup.current!.position, { x: d/2, duration: dur });
      
      gsap.to(matXRef.current!.color, { r: colors.blue.r, g: colors.blue.g, b: colors.blue.b, duration: dur });
      gsap.to(matYRef.current!.color, { r: colors.purple.r, g: colors.purple.g, b: colors.purple.b, duration: dur });
      gsap.to(matXRef.current!, { opacity: 0.15, duration: dur });
      gsap.to(matYRef.current!, { opacity: 0.15, duration: dur });
      
      gsap.to(borderXRef.current!.color, { r: colors.blue.r, g: colors.blue.g, b: colors.blue.b, duration: dur });
      gsap.to(borderYRef.current!.color, { r: colors.purple.r, g: colors.purple.g, b: colors.purple.b, duration: dur });
      gsap.to(borderXRef.current!, { opacity: 0.8, duration: dur });
      gsap.to(borderYRef.current!, { opacity: 0.8, duration: dur });

      gsap.to(matLensRef.current!, { opacity: 0, duration: dur });

      // Particles back to neutral
      setParticleState(p1Mat.current, p1Text.current, colors.dark, 1);
      setParticleState(p3Mat.current, p3Text.current, colors.dark, 1);
      setParticleState(p5Mat.current, p5Text.current, colors.dark, 1);
      setParticleState(p7Mat.current, p7Text.current, colors.dark, 1);

      // Particle positions
      gsap.to(p1Group.current!.position, { x: -2.2, z: 0, duration: dur });
      gsap.to(p3Group.current!.position, { x: 0, z: -0.7, duration: dur });
      gsap.to(p5Group.current!.position, { x: 0, z: 0.7, duration: dur });
      gsap.to(p7Group.current!.position, { x: 2.2, z: 0, duration: dur });

    } else if (step === 1) {
      // INTERSECTION ∩
      gsap.to(circleXGroup.current!.position, { x: -d/2, duration: dur });
      gsap.to(circleYGroup.current!.position, { x: d/2, duration: dur });

      gsap.to(matXRef.current!, { opacity: 0.02, duration: dur });
      gsap.to(matYRef.current!, { opacity: 0.02, duration: dur });
      gsap.to(borderXRef.current!, { opacity: 0.2, duration: dur });
      gsap.to(borderYRef.current!, { opacity: 0.2, duration: dur });

      // Lens glows green
      gsap.to(matLensRef.current!.color, { r: colors.green.r, g: colors.green.g, b: colors.green.b, duration: dur });
      gsap.to(matLensRef.current!, { opacity: 0.5, duration: dur });

      // Particle dims except shared
      setParticleState(p1Mat.current, p1Text.current, colors.dark, 0.2);
      setParticleState(p7Mat.current, p7Text.current, colors.dark, 0.2);
      
      setParticleState(p3Mat.current, p3Text.current, colors.green, 1);
      setParticleState(p5Mat.current, p5Text.current, colors.green, 1);

      gsap.to(p1Group.current!.position, { x: -2.2, z: 0, duration: dur });
      gsap.to(p3Group.current!.position, { x: 0, z: -0.7, duration: dur });
      gsap.to(p5Group.current!.position, { x: 0, z: 0.7, duration: dur });
      gsap.to(p7Group.current!.position, { x: 2.2, z: 0, duration: dur });

    } else if (step === 2) {
      // UNION ∪
      gsap.to(circleXGroup.current!.position, { x: -d/2, duration: dur });
      gsap.to(circleYGroup.current!.position, { x: d/2, duration: dur });

      // Whole footprint yellow
      gsap.to(matXRef.current!.color, { r: colors.yellow.r, g: colors.yellow.g, b: colors.yellow.b, duration: dur });
      gsap.to(matYRef.current!.color, { r: colors.yellow.r, g: colors.yellow.g, b: colors.yellow.b, duration: dur });
      gsap.to(matXRef.current!, { opacity: 0.3, duration: dur });
      gsap.to(matYRef.current!, { opacity: 0.3, duration: dur });

      gsap.to(borderXRef.current!.color, { r: colors.yellow.r, g: colors.yellow.g, b: colors.yellow.b, duration: dur });
      gsap.to(borderYRef.current!.color, { r: colors.yellow.r, g: colors.yellow.g, b: colors.yellow.b, duration: dur });
      gsap.to(borderXRef.current!, { opacity: 0.8, duration: dur });
      gsap.to(borderYRef.current!, { opacity: 0.8, duration: dur });

      // Lens yellow to match
      gsap.to(matLensRef.current!.color, { r: colors.yellow.r, g: colors.yellow.g, b: colors.yellow.b, duration: dur });
      gsap.to(matLensRef.current!, { opacity: 0.3, duration: dur });

      // All particles active
      setParticleState(p1Mat.current, p1Text.current, colors.yellow, 1);
      setParticleState(p3Mat.current, p3Text.current, colors.yellow, 1);
      setParticleState(p5Mat.current, p5Text.current, colors.yellow, 1);
      setParticleState(p7Mat.current, p7Text.current, colors.yellow, 1);

      gsap.to(p1Group.current!.position, { x: -2.2, z: 0, duration: dur });
      gsap.to(p3Group.current!.position, { x: 0, z: -0.7, duration: dur });
      gsap.to(p5Group.current!.position, { x: 0, z: 0.7, duration: dur });
      gsap.to(p7Group.current!.position, { x: 2.2, z: 0, duration: dur });

    } else if (step === 3) {
      // DISJOINT
      const disjointD = 5.2;
      gsap.to(circleXGroup.current!.position, { x: -disjointD/2, duration: dur });
      gsap.to(circleYGroup.current!.position, { x: disjointD/2, duration: dur });

      gsap.to(matXRef.current!.color, { r: colors.blue.r, g: colors.blue.g, b: colors.blue.b, duration: dur });
      gsap.to(matYRef.current!.color, { r: colors.purple.r, g: colors.purple.g, b: colors.purple.b, duration: dur });
      gsap.to(matXRef.current!, { opacity: 0.15, duration: dur });
      gsap.to(matYRef.current!, { opacity: 0.15, duration: dur });

      gsap.to(borderXRef.current!.color, { r: colors.blue.r, g: colors.blue.g, b: colors.blue.b, duration: dur });
      gsap.to(borderYRef.current!.color, { r: colors.purple.r, g: colors.purple.g, b: colors.purple.b, duration: dur });
      gsap.to(borderXRef.current!, { opacity: 0.8, duration: dur });
      gsap.to(borderYRef.current!, { opacity: 0.8, duration: dur });

      gsap.to(matLensRef.current!, { opacity: 0, duration: dur });

      // Particles adapt to split
      setParticleState(p1Mat.current, p1Text.current, colors.dark, 1);
      setParticleState(p3Mat.current, p3Text.current, colors.dark, 1);
      setParticleState(p5Mat.current, p5Text.current, colors.dark, 1);
      setParticleState(p7Mat.current, p7Text.current, colors.dark, 1);

      // Shared particles must migrate
      gsap.to(p1Group.current!.position, { x: -disjointD/2 - 1, z: 0, duration: dur });
      gsap.to(p3Group.current!.position, { x: -disjointD/2 + 0.8, z: 0, duration: dur });
      
      gsap.to(p5Group.current!.position, { x: disjointD/2 - 0.8, z: 0, duration: dur });
      gsap.to(p7Group.current!.position, { x: disjointD/2 + 1, z: 0, duration: dur });
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
        maxAzimuthAngle={0.3}
        minAzimuthAngle={-0.3}
      />

      {/* Circle X (Left) */}
      <group ref={circleXGroup} position={[-d/2, 0, 0]}>
        <mesh rotation={[-Math.PI / 2, 0, 0]} position={[0, 0.01, 0]}>
          <circleGeometry args={[R, 64]} />
          <meshStandardMaterial ref={matXRef} color="#3b82f6" transparent opacity={0.15} depthWrite={false} />
        </mesh>
        <mesh rotation={[-Math.PI / 2, 0, 0]} position={[0, 0.05, 0]}>
          <torusGeometry args={[R, 0.06, 16, 64]} />
          <meshStandardMaterial ref={borderXRef} color="#3b82f6" transparent emissive="#3b82f6" emissiveIntensity={0.5} />
        </mesh>
        <Text position={[0, 0.1, R + 0.6]} fontSize={0.8} color="#3b82f6" fontStyle="italic" outlineWidth={0.02} outlineColor="#000">
          Set X
        </Text>
      </group>

      {/* Circle Y (Right) */}
      <group ref={circleYGroup} position={[d/2, 0, 0]}>
        <mesh rotation={[-Math.PI / 2, 0, 0]} position={[0, 0.01, 0]}>
          <circleGeometry args={[R, 64]} />
          <meshStandardMaterial ref={matYRef} color="#8b5cf6" transparent opacity={0.15} depthWrite={false} />
        </mesh>
        <mesh rotation={[-Math.PI / 2, 0, 0]} position={[0, 0.05, 0]}>
          <torusGeometry args={[R, 0.06, 16, 64]} />
          <meshStandardMaterial ref={borderYRef} color="#8b5cf6" transparent emissive="#8b5cf6" emissiveIntensity={0.5} />
        </mesh>
        <Text position={[0, 0.1, R + 0.6]} fontSize={0.8} color="#8b5cf6" fontStyle="italic" outlineWidth={0.02} outlineColor="#000">
          Set Y
        </Text>
      </group>

      {/* Central Intersection Lens */}
      <group ref={lensGroupRef} position={[0, 0.02, 0]}>
         <mesh rotation={[-Math.PI / 2, 0, 0]} geometry={lensGeometry}>
           <meshStandardMaterial ref={matLensRef} color="#10b981" transparent opacity={0} depthWrite={false} emissive="#10b981" emissiveIntensity={0.6} />
         </mesh>
      </group>

      {/* Particles */}
      <Particle groupRef={p1Group} matRef={p1Mat} textMatRef={p1Text} text="1" position={[-2.2, 0, 0]} />
      <Particle groupRef={p3Group} matRef={p3Mat} textMatRef={p3Text} text="3" position={[0, 0, -0.7]} />
      <Particle groupRef={p5Group} matRef={p5Mat} textMatRef={p5Text} text="5" position={[0, 0, 0.7]} />
      <Particle groupRef={p7Group} matRef={p7Mat} textMatRef={p7Text} text="7" position={[2.2, 0, 0]} />

    </group>
  );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function SetOperationsSimulation() {
  const [step, setStep] = useState(0);
  
  const renderMath = () => {
    if (step === 0) return <BlockMath math={`X = \\{1, 3, 5\\} \\quad Y = \\{3, 5, 7\\}`} />;
    if (step === 1) return <BlockMath math={`X \\cap Y = \\{3, 5\\}`} />;
    if (step === 2) return <BlockMath math={`X \\cup Y = \\{1, 3, 5, 7\\}`} />;
    if (step === 3) return <BlockMath math={`X \\cap Y = \\emptyset`} />;
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Overlapping Sets", icon: <Settings2 size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>We have two sets: <b>X</b> and <b>Y</b>. Because they share common elements, their boundaries physically overlap, creating a shared central zone.</> };
    if (step === 1) return { title: "Intersection (∩)", icon: <Scaling size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>The <b>Intersection</b> is strictly the overlapping lens. It contains ONLY the elements that exist in BOTH X and Y simultaneously: <span className="font-mono text-emerald-400">3</span> and <span className="font-mono text-emerald-400">5</span>.</> };
    if (step === 2) return { title: "Union (∪) Misconception", icon: <AlertTriangle size={18}/>, color: "border-l-yellow-500 text-yellow-300 bg-yellow-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> The <b>Union</b> combines all elements, but shared elements (3 and 5) are <b>NOT duplicated</b>. You write them exactly once. <span className="font-mono text-yellow-400">X ∪ Y = {'{1, 3, 5, 7}'}</span>, NOT {'{1, 3, 5, 3, 5, 7}'}.</> };
    if (step === 3) return { title: "Disjoint Sets", icon: <Split size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <>If sets are pulled apart and share ZERO elements, they are <b>disjoint</b>. The intersection lens collapses completely, resulting in the Empty Set (<span className="font-bold text-rose-400">∅</span>).</> };
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
              Operations: Union & Intersection
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Visualizing set overlaps, shared elements, and boundaries.
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
           <Canvas shadows camera={{ position: [0, 8, 8], fov: 45 }}>
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
                 <span>Base</span>
                 <span className="text-emerald-400">Intersection</span>
                 <span className="text-yellow-400">Union</span>
                 <span className="text-rose-400">Disjoint</span>
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
                onClick={() => setStep(s => Math.min(3, s + 1))} 
                disabled={step === 3} 
                className="bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-blue-900/20 text-sm"
             >
                {step === 0 ? "Show Intersection" : 
                 step === 1 ? "Show Union" : 
                 step === 2 ? "Make Disjoint" : 
                 "Completed"} 
                {step < 3 && <Combine size={16} />}
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
