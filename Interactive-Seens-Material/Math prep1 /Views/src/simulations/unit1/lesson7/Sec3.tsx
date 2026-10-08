import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, X, Divide, AlertTriangle, SlidersHorizontal, RotateCcw, Combine, RefreshCcw } from 'lucide-react';

// ---------------------------------------------------------
// MATH UTILS
// ---------------------------------------------------------
function getGCD(a: number, b: number): number {
  a = Math.abs(a);
  b = Math.abs(b);
  if (b === 0) return a;
  return getGCD(b, a % b);
}

// ---------------------------------------------------------
// 3D COMPONENTS - MULTIPLICATION (GRID)
// ---------------------------------------------------------
function GridMatrix({ n1, d1, n2, d2, step, position }: { n1: number, d1: number, n2: number, d2: number, step: number, position: [number, number, number] }) {
  const W = 6;
  const H = 6;
  const cw = W / d1;
  const ch = H / d2;
  
  const cellsRef = useRef<THREE.Mesh[]>([]);
  cellsRef.current = cellsRef.current.slice(0, d1 * d2);

  // Animation triggers based on step
  useEffect(() => {
     cellsRef.current.forEach((mesh, idx) => {
        if (!mesh) return;
        const c = idx % d1;
        const r = Math.floor(idx / d1);
        
        const isCol = c < n1;
        const isRow = r < n2;
        const isIntersection = isCol && isRow;

        const mat = mesh.material as THREE.MeshStandardMaterial;
        let targetColor = new THREE.Color("#1e293b"); // default dark
        let targetOpacity = 0.2;
        let targetZ = 0;

        if (step === 0) { // Show Cols (Fraction 1)
           if (isCol) { targetColor.set("#3b82f6"); targetOpacity = 0.8; targetZ = 0.1; }
        } else if (step === 1) { // Show Rows (Fraction 2) + Intersection
           if (isIntersection) { targetColor.set("#10b981"); targetOpacity = 0.9; targetZ = 0.3; } // Green Overlap
           else if (isCol) { targetColor.set("#3b82f6"); targetOpacity = 0.6; targetZ = 0.1; }
           else if (isRow) { targetColor.set("#eab308"); targetOpacity = 0.6; targetZ = 0.1; } // Yellow Row
        } else if (step === 2) { // Isolate Intersection
           if (isIntersection) { targetColor.set("#10b981"); targetOpacity = 1.0; targetZ = 0.2; }
           else { targetColor.set("#0f172a"); targetOpacity = 0.1; targetZ = 0; }
        } else if (step >= 5) { // Sandbox Mode
           if (isIntersection) { targetColor.set("#10b981"); targetOpacity = 0.9; targetZ = 0.3; }
           else if (isCol) { targetColor.set("#3b82f6"); targetOpacity = 0.6; targetZ = 0.1; }
           else if (isRow) { targetColor.set("#eab308"); targetOpacity = 0.6; targetZ = 0.1; }
        }

        gsap.to(mat.color, { r: targetColor.r, g: targetColor.g, b: targetColor.b, duration: 0.5 });
        gsap.to(mat, { opacity: targetOpacity, duration: 0.5 });
        gsap.to(mesh.position, { z: targetZ, duration: 0.5, ease: "back.out(1.5)" });
     });
  }, [step, n1, d1, n2, d2]);

  return (
    <group position={position}>
       {/* Background Plate */}
       <mesh position={[0, 0, -0.2]}>
          <planeGeometry args={[W + 0.4, H + 0.4]} />
          <meshStandardMaterial color="#020617" />
       </mesh>
       
       {Array.from({ length: d2 }).map((_, r) => (
          Array.from({ length: d1 }).map((_, c) => {
             const idx = r * d1 + c;
             const px = -W/2 + cw/2 + c * cw;
             const py = H/2 - ch/2 - r * ch;
             return (
               <mesh 
                  key={idx} 
                  ref={el => cellsRef.current[idx] = el!} 
                  position={[px, py, 0]}
               >
                  <boxGeometry args={[cw - 0.05, ch - 0.05, 0.2]} />
                  <meshStandardMaterial color="#1e293b" transparent opacity={0.2} roughness={0.3} />
               </mesh>
             )
          })
       ))}

       {/* Labeling */}
       <Text position={[-W/2 - 0.6, 0, 0]} rotation={[0,0,Math.PI/2]} fontSize={0.5} color="#eab308">
          {n2} / {d2}
       </Text>
       <Text position={[0, H/2 + 0.6, 0]} fontSize={0.5} color="#3b82f6">
          {n1} / {d1}
       </Text>
    </group>
  );
}


// ---------------------------------------------------------
// 3D COMPONENTS - DIVISION (RECIPROCAL CHANNEL)
// ---------------------------------------------------------
function FlippableFraction({ n, d, flipped, position }: { n: number, d: number, flipped: boolean, position: [number, number, number] }) {
   const groupRef = useRef<THREE.Group>(null);
   const nRef = useRef<THREE.Group>(null);
   const dRef = useRef<THREE.Group>(null);
   const barRef = useRef<THREE.Mesh>(null);
 
   useEffect(() => {
     if (flipped) {
        // numerator goes down, denominator goes up
        gsap.to(nRef.current!.position, { y: -1, z: 0.4, duration: 0.3, ease: "power2.in" })
            .then(()=> gsap.to(nRef.current!.position, { z: 0, duration: 0.2, ease: "power2.out" }));
        gsap.to(dRef.current!.position, { y: 1, z: -0.4, duration: 0.3, ease: "power2.in" })
            .then(()=> gsap.to(dRef.current!.position, { z: 0, duration: 0.2, ease: "power2.out" }));
        
        // Color flip highlight
        gsap.to((nRef.current!.children[0] as THREE.Mesh).material as any, { emissiveIntensity: 0.5, duration: 0.2, yoyo: true, repeat: 1 });
        gsap.to((dRef.current!.children[0] as THREE.Mesh).material as any, { emissiveIntensity: 0.5, duration: 0.2, yoyo: true, repeat: 1 });
        gsap.to((barRef.current!.material as any), { color: "#10b981", duration: 0.5 }); // turns green when flipped
     } else {
        // standard
        gsap.to(nRef.current!.position, { y: 1, z: 0, duration: 0.5, ease: "back.out" });
        gsap.to(dRef.current!.position, { y: -1, z: 0, duration: 0.5, ease: "back.out" });
        gsap.to((barRef.current!.material as any), { color: "#94a3b8", duration: 0.5 });
     }
   }, [flipped, n, d]);
 
   return (
     <group ref={groupRef} position={position}>
       {/* Numerator (Top initially) */}
       <group ref={nRef} position={[0, 1, 0]}>
          <mesh castShadow>
             <boxGeometry args={[1.6, 1.4, 0.4]} />
             <meshStandardMaterial color="#8b5cf6" roughness={0.3} emissive="#8b5cf6" emissiveIntensity={0} />
          </mesh>
          <Text position={[0, 0, 0.21]} fontSize={0.9} color="#ffffff" outlineWidth={0.05} outlineColor="#000">{n}</Text>
       </group>
 
       {/* Fraction Bar */}
       <mesh ref={barRef} position={[0, 0, 0]} castShadow>
          <boxGeometry args={[2, 0.15, 0.4]} />
          <meshStandardMaterial color="#94a3b8" />
       </mesh>
 
       {/* Denominator (Bottom initially) */}
       <group ref={dRef} position={[0, -1, 0]}>
          <mesh castShadow>
             <boxGeometry args={[1.6, 1.4, 0.4]} />
             <meshStandardMaterial color="#f43f5e" roughness={0.3} emissive="#f43f5e" emissiveIntensity={0} />
          </mesh>
          <Text position={[0, 0, 0.21]} fontSize={0.9} color="#ffffff" outlineWidth={0.05} outlineColor="#000">{d}</Text>
       </group>
     </group>
   );
 }

 function FlippableOperator({ flipped, position }: { flipped: boolean, position: [number, number, number] }) {
   const groupRef = useRef<THREE.Group>(null);
   const meshRef = useRef<THREE.Mesh>(null);

   useEffect(() => {
      if (flipped) {
         gsap.to(groupRef.current!.rotation, { z: -Math.PI / 2, duration: 0.6, ease: "back.out(1.5)" });
         gsap.to((meshRef.current!.material as any), { color: "#10b981", duration: 0.6 });
      } else {
         gsap.to(groupRef.current!.rotation, { z: 0, duration: 0.6, ease: "back.out(1.5)" });
         gsap.to((meshRef.current!.material as any), { color: "#38bdf8", duration: 0.6 });
      }
   }, [flipped]);

   return (
      <group position={position}>
         <group ref={groupRef}>
            <mesh ref={meshRef}>
               <boxGeometry args={[1.2, 1.2, 0.2]} />
               <meshStandardMaterial color="#38bdf8" />
            </mesh>
            {/* Division Side */}
            <group position={[0, 0, 0.11]}>
               <mesh position={[0, 0, 0]}><planeGeometry args={[0.8, 0.15]} /><meshBasicMaterial color="#fff" /></mesh>
               <mesh position={[0, 0.35, 0]}><circleGeometry args={[0.12, 16]} /><meshBasicMaterial color="#fff" /></mesh>
               <mesh position={[0, -0.35, 0]}><circleGeometry args={[0.12, 16]} /><meshBasicMaterial color="#fff" /></mesh>
            </group>
            {/* Multiplication Side (Since it rotates on Z, it just spins to look like an X. Wait, division rotated 90deg isn't X. 
                Oh, wait! A division sign rotated 90 deg doesn't become X. 
                Instead of Z rotation, let's rotate on Y axis so we flip from front (÷) to back (×) ! */}
         </group>
      </group>
   );
 }

 function OperatorYFlip({ flipped, position }: { flipped: boolean, position: [number, number, number] }) {
    const groupRef = useRef<THREE.Group>(null);
    const meshRef = useRef<THREE.Mesh>(null);
 
    useEffect(() => {
       if (flipped) {
          gsap.to(groupRef.current!.rotation, { y: Math.PI, duration: 0.6, ease: "back.out(1.5)" });
          gsap.to((meshRef.current!.material as any), { color: "#10b981", duration: 0.6 });
       } else {
          gsap.to(groupRef.current!.rotation, { y: 0, duration: 0.6, ease: "back.out(1.5)" });
          gsap.to((meshRef.current!.material as any), { color: "#38bdf8", duration: 0.6 });
       }
    }, [flipped]);
 
    return (
       <group position={position}>
          <group ref={groupRef}>
             <mesh ref={meshRef}>
                <cylinderGeometry args={[0.9, 0.9, 0.2, 32]} rotation={[Math.PI/2, 0, 0]} />
                <meshStandardMaterial color="#38bdf8" />
             </mesh>
             
             {/* Front: Division ÷ */}
             <group position={[0, 0, 0.11]}>
                <mesh position={[0, 0, 0]}><planeGeometry args={[0.8, 0.15]} /><meshBasicMaterial color="#fff" /></mesh>
                <mesh position={[0, 0.35, 0]}><circleGeometry args={[0.12, 16]} /><meshBasicMaterial color="#fff" /></mesh>
                <mesh position={[0, -0.35, 0]}><circleGeometry args={[0.12, 16]} /><meshBasicMaterial color="#fff" /></mesh>
             </group>
 
             {/* Back: Multiplication × (Needs to be rotated Y 180 to face correctly when flipped) */}
             <group position={[0, 0, -0.11]} rotation={[0, Math.PI, 0]}>
                <Text fontSize={1.2} color="#fff" position={[0, -0.05, 0]}>×</Text>
             </group>
          </group>
       </group>
    );
  }

function ResultBlock({ n, d, visible, position }: { n: number, d: number, visible: boolean, position: [number, number, number] }) {
   const groupRef = useRef<THREE.Group>(null);
   useEffect(() => {
      if (visible) {
         gsap.to(groupRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: "back.out(1.5)", delay: 0.3 });
      } else {
         gsap.to(groupRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
      }
   }, [visible, n, d]);

   return (
      <group ref={groupRef} position={position} scale={[0,0,0]}>
         <Text position={[-2, 0, 0]} fontSize={1} color="#64748b">=</Text>
         <FlippableFraction n={n} d={d} flipped={false} position={[0, 0, 0]} />
         <mesh position={[0,0,-0.3]}>
            <boxGeometry args={[2.5, 3.5, 0.1]} />
            <meshStandardMaterial color="#10b981" transparent opacity={0.3} />
         </mesh>
      </group>
   )
}


// ---------------------------------------------------------
// MAIN SCENE CONTROLLER
// ---------------------------------------------------------
function Scene({ step, mode, mN1, mD1, mN2, mD2, dN1, dD1, dN2, dD2 }: any) {
  const { camera } = useThree();
  const controlsRef = useRef<any>(null);

  // Camera logic based on step/mode
  useEffect(() => {
    let t = { camX: 0, camY: 0, camZ: 14, tarX: 0, tarY: 0 };
    
    if (step <= 2) {
       // Grid Zone
       t = { camX: -10, camY: 0, camZ: 10, tarX: -10, tarY: 0 };
    } else if (step === 3 || step === 4) {
       // Division Zone
       t = { camX: 10, camY: 0, camZ: 10, tarX: 10, tarY: 0 };
    } else if (step === 5) {
       if (mode === 'mult') {
          t = { camX: -10, camY: 0, camZ: 10, tarX: -10, tarY: 0 };
       } else {
          t = { camX: 10, camY: 0, camZ: 11, tarX: 10, tarY: 0 };
       }
    }

    gsap.to(camera.position, { x: t.camX, y: t.camY, z: t.camZ, duration: 1.5, ease: 'power3.inOut' });
    if (controlsRef.current) {
      gsap.to(controlsRef.current.target, { x: t.tarX, y: t.tarY, z: 0, duration: 1.5, ease: 'power3.inOut' });
    }
  }, [step, mode, camera]);

  return (
    <group>
      <OrbitControls 
        ref={controlsRef} 
        enablePan={false} 
        enableZoom={false}
        maxPolarAngle={Math.PI / 2 + 0.1}
        minPolarAngle={Math.PI / 2 - 0.1}
        maxAzimuthAngle={0.4}
        minAzimuthAngle={-0.4}
      />
      
      {/* Zone 1: Multiplication (Grid) */}
      <GridMatrix 
         position={[-10, 0, 0]} 
         n1={mN1} d1={mD1} n2={mN2} d2={mD2} 
         step={step} 
      />

      {/* Zone 2: Division (Channel) */}
      <group position={[10, 0, 0]}>
         {/* Divisor 1 (Unchanged) */}
         <FlippableFraction position={[-3, 0, 0]} n={dN1} d={dD1} flipped={false} />
         
         {/* Operator (Flips ÷ to ×) */}
         <OperatorYFlip position={[-0.5, 0, 0]} flipped={step >= 4} />
         
         {/* Divisor 2 (Flips reciprocal) */}
         <FlippableFraction position={[2, 0, 0]} n={dN2} d={dD2} flipped={step >= 4} />

         {/* Result */}
         <ResultBlock 
            position={[5.5, 0, 0]} 
            n={step >= 4 ? dN1 * dD2 : dN1 / dN2} 
            d={step >= 4 ? dD1 * dN2 : dD1 / dD2} 
            visible={step >= 4 || step === 5} 
         />
      </group>

    </group>
  );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function MultDivSimulation() {
  const [step, setStep] = useState(0);
  const [mode, setMode] = useState<'mult' | 'div'>('mult'); // only for step 5 sandbox

  // Sandbox State
  const [mN1, setMN1] = useState(2);
  const [mD1, setMD1] = useState(3);
  const [mN2, setMN2] = useState(3);
  const [mD2, setMD2] = useState(4);

  const [dN1, setDN1] = useState(3);
  const [dD1, setDD1] = useState(4);
  const [dN2, setDN2] = useState(2);
  const [dD2, setDD2] = useState(5);

  const handleNextStep = () => {
    if (step < 5) {
       setStep(s => s + 1);
       if (step + 1 === 5) setMode('div'); // default to div when arriving at sandbox
    }
  };

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`\\frac{2}{3} \\times \\frac{3}{4}`} />;
    if (step === 1) return <BlockMath math={`\\text{Area} = \\frac{2}{3} \\times \\frac{3}{4}`} />;
    if (step === 2) {
       const gcd = getGCD(6, 12);
       return <BlockMath math={`\\frac{2 \\times 3}{3 \\times 4} = \\frac{6}{12} = \\frac{1}{2}`} />;
    }
    
    if (step === 3) return <BlockMath math={`\\frac{3}{4} \\div \\frac{2}{5}`} />;
    if (step === 4) return <BlockMath math={`\\frac{3}{4} \\times \\frac{5}{2} = \\frac{15}{8}`} />;
    
    if (step === 5) {
       if (mode === 'mult') {
          const resN = mN1 * mN2; const resD = mD1 * mD2;
          const gcd = getGCD(resN, resD);
          return <BlockMath math={`\\frac{${mN1}}{${mD1}} \\times \\frac{${mN2}}{${mD2}} = \\frac{${resN}}{${resD}} ${gcd > 1 ? `= \\frac{${resN/gcd}}{${resD/gcd}}` : ''}`} />;
       } else {
          return <BlockMath math={`\\frac{${dN1}}{${dD1}} \\div \\frac{${dN2}}{${dD2}} \\rightarrow \\frac{${dN1}}{${dD1}} \\times \\frac{${dD2}}{${dN2}} = \\frac{${dN1 * dD2}}{${dD1 * dN2}}`} />;
       }
    }
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Area Model Multiplication", icon: <X size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>When multiplying fractions, we are finding a "fraction of a fraction". First, we shade <span className="font-mono">2/3</span> of the area vertically.</> };
    if (step === 1) return { title: "Overlapping Areas", icon: <Combine size={18}/>, color: "border-l-yellow-500 text-yellow-300 bg-yellow-900/40", body: <>Next, we shade <span className="font-mono">3/4</span> of the area horizontally. The region where the colors intersect represents the product.</> };
    if (step === 2) return { title: "The Intersection Product", icon: <X size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>The intersection covers <span className="font-mono text-emerald-400">6</span> blocks out of the total <span className="font-mono text-emerald-400">12</span> grid blocks. Thus, $2/3 \times 3/4 = 6/12$, which reduces to <span className="font-mono">1/2</span>.</> };
    if (step === 3) return { title: "Division Misconception", icon: <AlertTriangle size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> For division ($3/4 \div 2/5$), many try dividing numerators ($3 \div 2$) and denominators ($4 \div 5$) directly. This creates a messy complex fraction.</> };
    if (step === 4) return { title: "The Reciprocal Channel", icon: <RefreshCcw size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>Instead, we use <b>Keep-Change-Flip</b>. The operator changes to multiplication ($\times$), and the divisor rotates 180° into its reciprocal (<span className="font-mono">5/2</span>). The product is cleanly <span className="font-mono">15/8</span>.</> };
    if (step === 5) {
       return { title: "Sandbox Engine", icon: <SlidersHorizontal size={18}/>, color: "border-l-slate-500 text-slate-300 bg-slate-800/40", body: <>Toggle between multiplication grids and division reciprocal flips. Adjust the values to see the algorithms dynamically resolve.</> };
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
              Multiplication & Division
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Area intersections and reciprocal (Keep-Change-Flip) transformations.
            </p>
          </div>
          <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[340px] flex justify-center shadow-inner overflow-x-auto">
             <div className="text-white text-lg px-2">
               {renderMath()}
             </div>
          </div>
        </div>
      </div>
      
      {/* R3F Canvas */}
      <div className="flex-1 w-full h-full relative z-0 touch-none">
         <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
           <Canvas shadows camera={{ position: [0, 0, 14], fov: 45 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <Scene 
                 step={step} mode={mode}
                 mN1={step<5 ? 2:mN1} mD1={step<5 ? 3:mD1} mN2={step<5 ? 3:mN2} mD2={step<5 ? 4:mD2}
                 dN1={step<5 ? 3:dN1} dD1={step<5 ? 4:dD1} dN2={step<5 ? 2:dN2} dD2={step<5 ? 5:dD2}
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
             <p className="text-sm leading-relaxed drop-shadow-md text-slate-300">
               {alertContent.body}
             </p>
           </div>
        </div>

        <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
           
           {/* Steps Map */}
           <div className="flex flex-col gap-2 mb-4">
              <div className="flex justify-between text-[10px] font-bold uppercase tracking-wider text-slate-400 px-1">
                 <span className="text-blue-400">Cols</span>
                 <span className="text-yellow-400">Rows</span>
                 <span className="text-emerald-400">Area</span>
                 <span className="text-rose-400">Div Setup</span>
                 <span className="text-purple-400">Flip/Solve</span>
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
                 <div className="flex bg-slate-800 p-1 rounded-lg">
                    <button onClick={() => setMode('mult')} className={`flex-1 py-1.5 text-xs font-bold rounded-md transition-all ${mode === 'mult' ? 'bg-emerald-600 text-white shadow-md' : 'text-slate-400 hover:text-white'}`}>Area Multiplication</button>
                    <button onClick={() => setMode('div')} className={`flex-1 py-1.5 text-xs font-bold rounded-md transition-all ${mode === 'div' ? 'bg-purple-600 text-white shadow-md' : 'text-slate-400 hover:text-white'}`}>Reciprocal Division</button>
                 </div>
                 
                 {mode === 'mult' ? (
                    <div className="grid grid-cols-2 gap-4">
                       <div>
                          <label className="text-[10px] uppercase text-slate-500 mb-1 block">Frac 1 (Cols)</label>
                          <input type="range" min="1" max={mD1} value={mN1} onChange={e=>setMN1(parseInt(e.target.value))} className="w-full accent-blue-500" />
                          <input type="range" min="2" max="6" value={mD1} onChange={e=>setMD1(parseInt(e.target.value))} className="w-full accent-blue-300 mt-1" />
                       </div>
                       <div>
                          <label className="text-[10px] uppercase text-slate-500 mb-1 block">Frac 2 (Rows)</label>
                          <input type="range" min="1" max={mD2} value={mN2} onChange={e=>setMN2(parseInt(e.target.value))} className="w-full accent-yellow-500" />
                          <input type="range" min="2" max="6" value={mD2} onChange={e=>setMD2(parseInt(e.target.value))} className="w-full accent-yellow-300 mt-1" />
                       </div>
                    </div>
                 ) : (
                    <div className="grid grid-cols-2 gap-4">
                       <div>
                          <label className="text-[10px] uppercase text-slate-500 mb-1 block">Dividend (Keep)</label>
                          <input type="range" min="1" max="9" value={dN1} onChange={e=>setDN1(parseInt(e.target.value))} className="w-full accent-purple-500" />
                          <input type="range" min="2" max="9" value={dD1} onChange={e=>setDD1(parseInt(e.target.value))} className="w-full accent-purple-300 mt-1" />
                       </div>
                       <div>
                          <label className="text-[10px] uppercase text-slate-500 mb-1 block">Divisor (Flip)</label>
                          <input type="range" min="1" max="9" value={dN2} onChange={e=>setDN2(parseInt(e.target.value))} className="w-full accent-rose-500" />
                          <input type="range" min="2" max="9" value={dD2} onChange={e=>setDD2(parseInt(e.target.value))} className="w-full accent-rose-300 mt-1" />
                       </div>
                    </div>
                 )}
              </div>
           )}

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-auto">
             <button 
                onClick={handleNextStep} 
                disabled={step === 5} 
                className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm ${step === 5 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
             >
                {step === 0 ? "Overlap Area" : 
                 step === 1 ? "Extract Output" : 
                 step === 2 ? "Division Mode" : 
                 step === 3 ? "Execute Flip" : 
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
