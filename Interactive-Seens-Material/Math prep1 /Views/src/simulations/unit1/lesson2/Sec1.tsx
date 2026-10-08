import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Html, Environment, Text } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { InlineMath } from 'react-katex';
import { Maximize, Minimize, AlertCircle, Search, Bug, Mountain } from 'lucide-react';

const primaryColor = '#3b82f6';
const goldColor = '#eab308';
const cyanColor = '#06b6d4';

interface InsectProps {
  scaleState: 'real' | 'drawing';
  isFocused: boolean;
}

function Insect({ scaleState, isFocused }: InsectProps) {
  const groupRef = useRef<THREE.Group>(null);
  const textOpacity = isFocused ? 1 : 0.2;
  
  useEffect(() => {
    if (!groupRef.current) return;
    const targetScale = scaleState === 'real' ? 0.25 : 2.5; // Visual sizes to show drastic growth
    
    gsap.to(groupRef.current.scale, {
      x: targetScale,
      y: targetScale,
      z: targetScale,
      duration: 1.5,
      ease: "power3.inOut"
    });
  }, [scaleState]);

  return (
    <group position={[-4, 0, 0]}>
      {/* The 3D Object Group */}
      <group ref={groupRef}>
        <mesh position={[0, 0.4, 0]} castShadow>
          <sphereGeometry args={[0.2, 32, 32]} />
          <meshStandardMaterial color={goldColor} roughness={0.4} metalness={0.6} />
        </mesh>
        <mesh position={[0, -0.2, 0]} castShadow>
          <capsuleGeometry args={[0.3, 0.6, 16, 32]} />
          <meshStandardMaterial color={goldColor} roughness={0.4} metalness={0.6} />
        </mesh>
        <mesh position={[-0.15, 0.7, 0]} rotation={[0, 0, 0.5]} castShadow>
           <cylinderGeometry args={[0.02, 0.02, 0.5]} />
           <meshStandardMaterial color={goldColor} />
        </mesh>
        <mesh position={[0.15, 0.7, 0]} rotation={[0, 0, -0.5]} castShadow>
           <cylinderGeometry args={[0.02, 0.02, 0.5]} />
           <meshStandardMaterial color={goldColor} />
        </mesh>
      </group>

      {/* HTML Overlay anchored above the insect */}
      <Html position={[0, 3, 0]} center className="pointer-events-none transition-opacity duration-500" style={{ opacity: textOpacity }}>
        <div className="bg-slate-900/90 backdrop-blur-md border border-yellow-500/50 p-4 rounded-2xl shadow-2xl flex flex-col items-center w-56 transform transition-all">
          <div className="text-yellow-400 font-bold mb-2 flex items-center gap-2 text-lg">
            <Maximize size={20} /> Magnification 
          </div>
          <div className="w-full h-px bg-yellow-900/50 mb-3" />
          
          <div className="text-white text-sm w-full space-y-2">
            <div className="flex justify-between items-center bg-slate-800 p-2 rounded-lg">
              <span className="text-slate-400 text-xs">Real Size</span>
              <span className="font-mono text-yellow-100">2.5 mm</span>
            </div>
            <div className="flex justify-between items-center bg-slate-800 p-2 rounded-lg">
              <span className="text-slate-400 text-xs">Drawing</span>
              <span className="font-mono text-yellow-100">75 mm</span>
            </div>
          </div>
          
          <div className="mt-3 bg-black/60 p-2 rounded-lg text-white w-full text-center border border-yellow-700/30">
            <InlineMath math={`\\text{Scale} = \\frac{75}{2.5} = 30`} />
            <div className="text-yellow-400 text-xs mt-1 font-bold tracking-wider">Ratio 30 : 1</div>
          </div>
          
          <div className="mt-2 text-xs font-semibold text-emerald-400 bg-emerald-900/30 px-3 py-1 rounded-full border border-emerald-800/50">
            Scale &gt; 1
          </div>
        </div>
      </Html>
    </group>
  );
}

interface PyramidProps {
  scaleState: 'real' | 'drawing';
  isFocused: boolean;
}

function Pyramid({ scaleState, isFocused }: PyramidProps) {
  const groupRef = useRef<THREE.Group>(null);
  const textOpacity = isFocused ? 1 : 0.2;
  
  useEffect(() => {
    if (!groupRef.current) return;
    const targetScale = scaleState === 'real' ? 3.5 : 0.35; // Visual sizes to show drastic compression
    
    gsap.to(groupRef.current.scale, {
      x: targetScale,
      y: targetScale,
      z: targetScale,
      duration: 1.5,
      ease: "power3.inOut"
    });
  }, [scaleState]);

  return (
    <group position={[4, -1, 0]}>
      <group ref={groupRef}>
        <mesh position={[0, 1, 0]} castShadow receiveShadow>
          <coneGeometry args={[1.5, 2, 4]} />
          <meshStandardMaterial color={cyanColor} roughness={0.9} metalness={0.1} flatShading />
        </mesh>
      </group>
      
      <Html position={[0, 4, 0]} center className="pointer-events-none transition-opacity duration-500" style={{ opacity: textOpacity }}>
        <div className="bg-slate-900/90 backdrop-blur-md border border-cyan-500/50 p-4 rounded-2xl shadow-2xl flex flex-col items-center w-56 transform transition-all">
          <div className="text-cyan-400 font-bold mb-2 flex items-center gap-2 text-lg">
            <Minimize size={20} /> Minimization 
          </div>
          <div className="w-full h-px bg-cyan-900/50 mb-3" />
          
          <div className="text-white text-sm w-full space-y-2">
            <div className="flex justify-between items-center bg-slate-800 p-2 rounded-lg">
              <span className="text-slate-400 text-xs">Real Size</span>
              <span className="font-mono text-cyan-100">187 m</span>
            </div>
            <div className="flex justify-between items-center bg-slate-800 p-2 rounded-lg">
              <span className="text-slate-400 text-xs">Drawing</span>
              <span className="font-mono text-cyan-100">9.35 cm</span>
            </div>
          </div>
          
          <div className="mt-3 bg-black/60 p-2 rounded-lg text-white w-full text-center border border-cyan-700/30">
            <InlineMath math={`\\text{Scale} = \\frac{0.0935}{187} = \\frac{1}{2000}`} />
            <div className="text-cyan-400 text-xs mt-1 font-bold tracking-wider">Ratio 1 : 2000</div>
          </div>
          
          <div className="mt-2 text-xs font-semibold text-rose-400 bg-rose-900/30 px-3 py-1 rounded-full border border-rose-800/50">
            Scale &lt; 1
          </div>
        </div>
      </Html>
    </group>
  );
}

interface SceneProps {
  scaleState: 'real' | 'drawing';
  focusMode: 'both' | 'magnification' | 'minimization';
}

function Scene({ scaleState, focusMode }: SceneProps) {
  const cameraTargetRef = useRef(new THREE.Vector3(0, 0, 0));
  
  useFrame((state) => {
    // Determine target positions based on focus mode
    const targetX = focusMode === 'both' ? 0 : focusMode === 'magnification' ? -4 : 4;
    const targetZ = focusMode === 'both' ? 12 : 8;
    const lookAtX = focusMode === 'both' ? 0 : focusMode === 'magnification' ? -4 : 4;
    
    // Smoothly move the camera
    state.camera.position.x = THREE.MathUtils.lerp(state.camera.position.x, targetX, 0.04);
    state.camera.position.z = THREE.MathUtils.lerp(state.camera.position.z, targetZ, 0.04);
    
    // Smoothly pan the lookAt target
    cameraTargetRef.current.x = THREE.MathUtils.lerp(cameraTargetRef.current.x, lookAtX, 0.04);
    state.camera.lookAt(cameraTargetRef.current);
  });

  return (
    <>
      <color attach="background" args={['#020617']} />
      <ambientLight intensity={0.5} />
      <directionalLight position={[10, 15, 10]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
      <Environment preset="city" opacity={0.2} />
      
      {/* Central Separator & Meter */}
      <group position={[0, 0, -2]}>
        <mesh position={[0, 0, 0]}>
           <boxGeometry args={[0.02, 12, 0.02]} />
           <meshBasicMaterial color="#334155" />
        </mesh>
        
        {/* Central Badge */}
        <Html position={[0, 0, 0]} center className="pointer-events-none transition-opacity duration-300" style={{ opacity: focusMode === 'both' ? 1 : 0 }}>
          <div className="bg-slate-800/90 backdrop-blur text-white px-4 py-2 rounded-full text-sm font-bold whitespace-nowrap border border-slate-600 shadow-2xl flex items-center gap-2">
            <span className="text-slate-400">Boundary Ratio</span> 
            <span className="text-blue-400 bg-blue-900/40 px-2 py-0.5 rounded">1 : 1</span>
          </div>
        </Html>
      </group>

      <Insect scaleState={scaleState} isFocused={focusMode === 'both' || focusMode === 'magnification'} />
      <Pyramid scaleState={scaleState} isFocused={focusMode === 'both' || focusMode === 'minimization'} />
      
      {/* Floor */}
      <mesh rotation={[-Math.PI / 2, 0, 0]} position={[0, -2, 0]} receiveShadow>
        <planeGeometry args={[50, 50]} />
        <meshStandardMaterial color="#0f172a" roughness={0.8} />
      </mesh>
      
      <OrbitControls 
        enablePan={false} 
        enableZoom={false}
        enableRotate={focusMode === 'both'}
        maxPolarAngle={Math.PI / 2}
        minPolarAngle={Math.PI / 3}
      />
    </>
  );
}

export default function ScaleDrawingsSimulation() {
  const [scaleState, setScaleState] = useState<'real' | 'drawing'>('real');
  const [focusMode, setFocusMode] = useState<'both' | 'magnification' | 'minimization'>('both');

  return (
    <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
      
      {/* Header Overlay */}
      <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
        <div className="bg-slate-900/80 backdrop-blur-md border border-slate-700 p-4 rounded-2xl shadow-xl flex justify-between items-center max-w-7xl mx-auto">
          <div>
            <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
              Scale Drawings
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Visualizing the difference between magnification and minimization.
            </p>
          </div>
        </div>
      </div>
      
      {/* R3F Canvas */}
      <div className="flex-1 w-full h-full relative z-0 touch-none">
         <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
           <Canvas shadows camera={{ position: [0, 2, 12], fov: 45 }}>
              <Scene scaleState={scaleState} focusMode={focusMode} />
           </Canvas>
         </Suspense>
      </div>

      {/* Controls Overlay */}
      <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
        
        {/* Misconception Alert */}
        <div className="flex-1 pointer-events-auto max-w-md w-full">
           <div className="bg-slate-900/90 border-l-4 border-l-rose-500 border-t border-r border-b border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md">
             <h3 className="text-rose-400 font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide">
                <AlertCircle size={18} /> Misconception Alert
             </h3>
             <p className="text-slate-300 text-sm leading-relaxed">
               Students often think scale drawings are <span className="text-cyan-400 font-semibold">only</span> used to make large things smaller (like maps). However, they are equally important to <span className="text-yellow-400 font-semibold">magnify</span> microscopic objects to be visible! 
             </p>
           </div>
        </div>

        {/* Interactive Controls Panel */}
        <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col gap-5 pointer-events-auto shrink-0 w-full lg:w-[400px]">
           
           {/* Scale Toggle */}
           <div className="flex flex-col gap-2">
             <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider flex items-center justify-between">
               <span>Visual Scale State</span>
               <span className="text-slate-500 font-normal normal-case">Try clicking between these</span>
             </label>
             <div className="grid grid-cols-2 gap-2 bg-slate-950 p-1.5 rounded-xl border border-slate-800">
               <button 
                 className={`px-3 py-2.5 rounded-lg text-sm font-bold transition-all duration-300 ${scaleState === 'real' ? 'bg-slate-700 text-white shadow-lg' : 'text-slate-400 hover:text-white hover:bg-slate-800'}`}
                 onClick={() => setScaleState('real')}
               >
                 Actual Sizes
               </button>
               <button 
                 className={`px-3 py-2.5 rounded-lg text-sm font-bold transition-all duration-300 ${scaleState === 'drawing' ? 'bg-blue-600 text-white shadow-lg shadow-blue-900/50' : 'text-slate-400 hover:text-white hover:bg-slate-800'}`}
                 onClick={() => setScaleState('drawing')}
               >
                 Drawn Sizes
               </button>
             </div>
           </div>

           {/* Camera Focus Tabs */}
           <div className="flex flex-col gap-2">
             <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Camera Focus</label>
             <div className="grid grid-cols-3 gap-2 bg-slate-950 p-1.5 rounded-xl border border-slate-800">
               <button 
                 className={`flex justify-center items-center py-2.5 rounded-lg text-sm font-semibold transition-all duration-300 ${focusMode === 'magnification' ? 'bg-yellow-600 text-white shadow-lg shadow-yellow-900/30' : 'text-slate-400 hover:text-white hover:bg-slate-800'}`}
                 onClick={() => setFocusMode('magnification')}
                 title="Focus on Insect"
               >
                 <Bug size={18} className="mr-2" /> Insect
               </button>
               <button 
                 className={`flex justify-center items-center py-2.5 rounded-lg text-sm font-semibold transition-all duration-300 ${focusMode === 'both' ? 'bg-slate-700 text-white shadow-lg' : 'text-slate-400 hover:text-white hover:bg-slate-800'}`}
                 onClick={() => setFocusMode('both')}
                 title="Show Both"
               >
                 Compare Both
               </button>
               <button 
                 className={`flex justify-center items-center py-2.5 rounded-lg text-sm font-semibold transition-all duration-300 ${focusMode === 'minimization' ? 'bg-cyan-600 text-white shadow-lg shadow-cyan-900/30' : 'text-slate-400 hover:text-white hover:bg-slate-800'}`}
                 onClick={() => setFocusMode('minimization')}
                 title="Focus on Pyramid"
               >
                 <Mountain size={18} className="mr-2" /> Pyramid
               </button>
             </div>
           </div>

        </div>

      </div>
    </div>
  );
}
