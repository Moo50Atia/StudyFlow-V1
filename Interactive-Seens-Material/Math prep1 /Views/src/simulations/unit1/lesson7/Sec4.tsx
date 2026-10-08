import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, ZoomIn, Scale, AlertTriangle, SlidersHorizontal, RotateCcw, Maximize2 } from 'lucide-react';

interface ProcessProps {
  step: number;
  zoomVal: number;
}

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function NumberLineGroup({ step, zoomVal }: ProcessProps) {
    const midMarkerRef = useRef<THREE.Group>(null);
    const denseGroupRef = useRef<THREE.Group>(null);
    
    const P1 = -10;
    const P2 = 10;
    
    let L1 = "1/3";
    let L2 = "2/3";
    if (step === 1) { L1 = "2/6"; L2 = "4/6"; }
    if (step >= 2) { L1 = "20/60"; L2 = "40/60"; }

    useEffect(() => {
        if (step === 0) {
            gsap.to(midMarkerRef.current!.scale, { y: 0, duration: 0.5 });
            gsap.to(denseGroupRef.current!.scale, { y: 0, duration: 0.5 });
        } else if (step === 1) {
            gsap.to(midMarkerRef.current!.scale, { y: 1, duration: 0.5, ease: "back.out(1.5)" });
            gsap.to(denseGroupRef.current!.scale, { y: 0, duration: 0.5 });
        } else if (step === 2 || step === 3) {
            gsap.to(midMarkerRef.current!.scale, { y: 1, duration: 0.5 });
            gsap.to(denseGroupRef.current!.scale, { y: 1, duration: 0.5, ease: "back.out(1.2)" });
        }
    }, [step]);

    useEffect(() => {
        if (step === 4 && denseGroupRef.current && midMarkerRef.current) {
            // zoomVal: 0 (macro) -> 100 (micro)
            const s = Math.min(1, Math.max(0, (zoomVal - 40) / 30));
            gsap.to(denseGroupRef.current.scale, { y: s, duration: 0.1 });
            
            const midS = Math.min(1, Math.max(0, zoomVal / 30));
            gsap.to(midMarkerRef.current.scale, { y: midS, duration: 0.1 });
        }
    }, [zoomVal, step]);

    const denseMarkers = [1,2,3,4,6,7,8,9].map(i => { // Skip 5 (midpoint 30/60)
        const val = 20 + i * 2;
        const px = -10 + i * 2;
        return (
            <group key={i} position={[px, 0, 0]}>
                <mesh position={[0, -0.4, 0]}><boxGeometry args={[0.08, 0.8, 0.08]}/><meshStandardMaterial color="#eab308"/></mesh>
                <Text position={[0, -1.2, 0]} fontSize={0.4} color="#fde047">{val}/60</Text>
            </group>
        );
    });

    return (
        <group position={[0, 0, 0]}>
            {/* Main Axis */}
            <mesh position={[0, 0, 0]} castShadow>
                <boxGeometry args={[30, 0.1, 0.1]} />
                <meshStandardMaterial color="#94a3b8" />
            </mesh>

            {/* Boundary 1/3 */}
            <group position={[P1, 0, 0]}>
                <mesh position={[0, -0.5, 0]} castShadow><boxGeometry args={[0.2, 1, 0.2]}/><meshStandardMaterial color="#3b82f6"/></mesh>
                <Text position={[0, -1.6, 0]} fontSize={0.8} color="#60a5fa">{L1}</Text>
            </group>

            {/* Boundary 2/3 */}
            <group position={[P2, 0, 0]}>
                <mesh position={[0, -0.5, 0]} castShadow><boxGeometry args={[0.2, 1, 0.2]}/><meshStandardMaterial color="#3b82f6"/></mesh>
                <Text position={[0, -1.6, 0]} fontSize={0.8} color="#60a5fa">{L2}</Text>
            </group>

            {/* Midpoint 3/6 */}
            <group ref={midMarkerRef} position={[0, 0, 0]} scale={[1,0,1]}>
                <mesh position={[0, -0.5, 0]} castShadow><boxGeometry args={[0.15, 1, 0.15]}/><meshStandardMaterial color="#10b981"/></mesh>
                <Text position={[0, -1.5, 0]} fontSize={0.6} color="#34d399">3/6 (1/2)</Text>
            </group>

            {/* Dense Markers Base 60 */}
            <group ref={denseGroupRef} scale={[1,0,1]}>
                {denseMarkers}
            </group>
        </group>
    );
}

function ZoomLens({ step }: { step: number }) {
    const lensRef = useRef<THREE.Group>(null);
    useEffect(() => {
        if (step === 1 || step === 2) {
            gsap.to(lensRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.6, ease: "back.out(1.2)" });
            gsap.to(lensRef.current!.rotation, { z: step === 2 ? Math.PI : 0, duration: 1 });
        } else {
            gsap.to(lensRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.4, ease: "power2.in" });
        }
    }, [step]);
    
    return (
        <group ref={lensRef} position={[0, 0, 2]} scale={[0,0,0]}>
            <mesh castShadow>
               <torusGeometry args={[12, 0.2, 16, 100]} />
               <meshStandardMaterial color="#38bdf8" emissive="#38bdf8" emissiveIntensity={0.6} transparent opacity={0.6} />
            </mesh>
            <mesh>
               <circleGeometry args={[11.8, 64]} />
               <meshPhysicalMaterial color="#38bdf8" transparent opacity={0.1} transmission={0.9} roughness={0} />
            </mesh>
            <Text position={[0, -12.5, 0]} fontSize={0.8} color="#38bdf8">Infinity Lens Active</Text>
        </group>
    );
}

function BalanceScale({ position, leftText, rightText, title }: { position: [number, number, number], leftText: string, rightText: string, title: string }) {
    return (
        <group position={position}>
            {/* Fulcrum */}
            <mesh position={[0, -2, 0]} castShadow>
               <cylinderGeometry args={[0, 1, 2, 4]} />
               <meshStandardMaterial color="#475569" />
            </mesh>
            {/* Beam */}
            <mesh position={[0, -1, 0]} castShadow>
               <boxGeometry args={[9, 0.2, 0.5]} />
               <meshStandardMaterial color="#94a3b8" />
            </mesh>
            {/* Left Pan */}
            <group position={[-4, -1, 0]}>
                <mesh position={[0, -1, 0]}><boxGeometry args={[2.5, 0.1, 1]}/><meshStandardMaterial color="#64748b"/></mesh>
                <mesh position={[0, -0.5, 0]}><boxGeometry args={[2.2, 0.8, 0.4]}/><meshStandardMaterial color="#3b82f6"/></mesh>
                <Text position={[0, -0.5, 0.21]} fontSize={0.4} color="#fff">{leftText}</Text>
            </group>
            {/* Right Pan */}
            <group position={[4, -1, 0]}>
                <mesh position={[0, -1, 0]}><boxGeometry args={[2.5, 0.1, 1]}/><meshStandardMaterial color="#64748b"/></mesh>
                <mesh position={[0, -0.5, 0]}><boxGeometry args={[2.2, 0.8, 0.4]}/><meshStandardMaterial color="#8b5cf6"/></mesh>
                <Text position={[0, -0.5, 0.21]} fontSize={0.4} color="#fff">{rightText}</Text>
            </group>
            <Text position={[0, 1.5, 0]} fontSize={0.8} color="#10b981">{title}</Text>
        </group>
    );
}

function PropertiesScales({ step }: { step: number }) {
    const scalesRef = useRef<THREE.Group>(null);
    useEffect(() => {
        if (step === 3) {
            gsap.to(scalesRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.6, ease: "back.out(1.2)" });
        } else {
            gsap.to(scalesRef.current!.scale, { x: 0.5, y: 0.5, z: 0.5, duration: 0.5 });
        }
    }, [step]);
    return (
        <group ref={scalesRef} position={[0, -15, 0]} scale={[0.5,0.5,0.5]}>
            <BalanceScale position={[-6, 0, 0]} leftText="1/2 + 1/3" rightText="1/3 + 1/2" title="Commutative (+)" />
            <BalanceScale position={[6, 0, 0]} leftText="1/2(1/3 + 1/4)" rightText="1/6 + 1/8" title="Distributive (×)" />
        </group>
    );
}

// ---------------------------------------------------------
// MAIN SCENE CONTROLLER
// ---------------------------------------------------------
function Scene({ step, zoomVal }: ProcessProps) {
  const { camera } = useThree();
  const controlsRef = useRef<any>(null);

  useEffect(() => {
    let t = { camX: 0, camY: 0, camZ: 22, tarX: 0, tarY: 0 };
    if (step === 0) t = { camX: 0, camY: 0, camZ: 22, tarX: 0, tarY: 0 };
    if (step === 1) t = { camX: 0, camY: 0, camZ: 16, tarX: 0, tarY: 0 };
    if (step === 2) t = { camX: 0, camY: 0, camZ: 8, tarX: 0, tarY: 0 };
    if (step === 3) t = { camX: 0, camY: -13, camZ: 16, tarX: 0, tarY: -15 };
    if (step === 4) t = { camX: 0, camY: 0, camZ: 6, tarX: 0, tarY: 0 }; // initial transition

    gsap.to(camera.position, { x: t.camX, y: t.camY, duration: 1.5, ease: 'power3.inOut' });
    if (step !== 4) {
       gsap.to(camera.position, { z: t.camZ, duration: 1.5, ease: 'power3.inOut' });
    }
    
    if (controlsRef.current) {
      gsap.to(controlsRef.current.target, { x: t.tarX, y: t.tarY, z: 0, duration: 1.5, ease: 'power3.inOut' });
    }
  }, [step, camera]);

  useFrame(() => {
     if (step === 4) {
         // Smoothly scrub Z based on slider
         const targetZ = 22 - (zoomVal / 100) * 16;
         camera.position.z += (targetZ - camera.position.z) * 0.05;
     }
  });

  return (
    <group>
      <OrbitControls 
        ref={controlsRef} 
        enablePan={false} 
        enableZoom={false}
        maxPolarAngle={Math.PI / 2 + 0.2}
        minPolarAngle={Math.PI / 2 - 0.2}
        maxAzimuthAngle={0.2}
        minAzimuthAngle={-0.2}
      />
      
      {/* Background Grid Plate */}
      <mesh position={[0, 0, -5]}>
         <planeGeometry args={[60, 40]} />
         <meshStandardMaterial color="#020617" />
      </mesh>

      <NumberLineGroup step={step} zoomVal={zoomVal} />
      <ZoomLens step={step} />
      <PropertiesScales step={step} />

    </group>
  );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function DensitySimulation() {
  const [step, setStep] = useState(0);
  const [zoomVal, setZoomVal] = useState(0);

  const handleNextStep = () => {
    if (step < 4) {
       const next = step + 1;
       if (next === 4) setZoomVal(100); // preset to micro view
       setStep(next);
    }
  };

  const renderMath = () => {
    if (step === 0) return <BlockMath math={`[\\frac{1}{3}, \\frac{2}{3}]`} />;
    if (step === 1) return <BlockMath math={`\\frac{2}{6} < \\frac{3}{6} < \\frac{4}{6}`} />;
    if (step === 2 || step === 4) return <BlockMath math={`\\frac{20}{60} < \\frac{21}{60} < \\dots < \\frac{40}{60}`} />;
    if (step === 3) return <BlockMath math={`a + b = b + a \\quad \\vert \\quad a(b+c) = ab+ac`} />;
  };

  const getAlertContent = () => {
    if (step === 0) return { title: "Macro Boundaries", icon: <MoveRight size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>Look at the interval between <span className="font-mono">1/3</span> and <span className="font-mono">2/3</span>. It appears completely empty. Is there a fraction between them?</> };
    if (step === 1) return { title: "Subdivide Grid", icon: <Maximize2 size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>By finding equivalent fractions (<span className="font-mono">2/6</span> and <span className="font-mono">4/6</span>), we instantly reveal a hidden midpoint: <span className="font-mono text-emerald-400">3/6</span> (or 1/2).</> };
    if (step === 2) return { title: "Deep Interval Density", icon: <ZoomIn size={18}/>, color: "border-l-yellow-500 text-yellow-300 bg-yellow-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> Rational numbers are NOT discrete. By converting to base <span className="font-mono">60</span>, we uncover infinitely more rational values strictly contained within the original empty gap.</> };
    if (step === 3) return { title: "Property Validators", icon: <Scale size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>Despite their infinite density, Rational Numbers fully obey the <b>Commutative</b> and <b>Distributive</b> properties perfectly, exactly like integers.</> };
    if (step === 4) return { title: "Infinity Scrub", icon: <SlidersHorizontal size={18}/>, color: "border-l-slate-500 text-slate-300 bg-slate-800/40", body: <>Use the Zoom slider to physically scrub between the macro view and the micro interval view. Watch how density emerges as we magnify space.</> };
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
              Density of Rational Numbers
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Exploring infinite intervals and core properties.
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
           <Canvas shadows camera={{ position: [0, 0, 22], fov: 45 }}>
              <color attach="background" args={['#020617']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <Scene step={step} zoomVal={zoomVal} />
              
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
                 <span className="text-blue-400">Macro</span>
                 <span className="text-emerald-400">Midpoint</span>
                 <span className="text-yellow-400">Density</span>
                 <span className="text-purple-400">Properties</span>
                 <span className="text-slate-300">Scrub Mode</span>
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
                 <div>
                    <label className="text-xs font-bold text-slate-400 flex justify-between mb-2">
                       <span>Macro View</span>
                       <span>Micro View</span>
                    </label>
                    <input 
                       type="range" min="0" max="100" step="1" 
                       value={zoomVal} onChange={(e) => setZoomVal(parseInt(e.target.value))}
                       className="w-full accent-slate-300" 
                    />
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
                {step === 0 ? "Reveal Midpoint" : 
                 step === 1 ? "Deep Zoom" : 
                 step === 2 ? "Verify Properties" : 
                 step === 3 ? "Unlock Zoom" : "Completed"} 
                {step < 4 && <MoveRight size={16} />}
             </button>
             <button 
                onClick={() => { setStep(0); setZoomVal(0); }} 
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
