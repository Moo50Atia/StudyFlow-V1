import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { OrbitControls, Environment, Text, Grid } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { AlertCircle, RotateCcw, PenTool, Map, MoveRight } from 'lucide-react';

// --- 3D Components ---

function Ray({ start, end }: { start: [number, number, number], end: [number, number, number] }) {
    const vStart = new THREE.Vector3(...start);
    const vEnd = new THREE.Vector3(...end);
    const distance = vStart.distanceTo(vEnd);
    const position = vStart.clone().lerp(vEnd, 0.5);
    const quaternion = new THREE.Quaternion().setFromUnitVectors(
        new THREE.Vector3(0, 1, 0), 
        vEnd.clone().sub(vStart).normalize()
    );

    return (
        <mesh position={position} quaternion={quaternion}>
            <cylinderGeometry args={[0.06, 0.06, distance, 16]} />
            <meshStandardMaterial color="#fbbf24" emissive="#f59e0b" emissiveIntensity={2} />
        </mesh>
    );
}

const ValueBlock = React.forwardRef<THREE.Group, any>(({ position, text, color, visible = true, scale = 1, textColor = "white" }, ref) => (
    <group ref={ref} position={position} visible={visible} scale={scale}>
      <mesh castShadow receiveShadow>
        <boxGeometry args={[3.8, 1.4, 0.5]} />
        <meshStandardMaterial color={color} roughness={0.3} metalness={0.2} />
      </mesh>
      <Text position={[0, 0, 0.26]} fontSize={0.35} color={textColor} anchorX="center" anchorY="middle" outlineWidth={0.01} outlineColor="#000" whiteSpace="nowrap">
        {text}
      </Text>
    </group>
));
ValueBlock.displayName = 'ValueBlock';

interface VisualizerProps {
  step: number;
  mode: 'findReal' | 'findDrawing';
  scaleValue: number;
  drawVal: number;
  realVal: number;
}

function ProcessVisualizer({ step, mode, scaleValue, drawVal, realVal }: VisualizerProps) {
  const operatorRef = useRef<THREE.Group>(null);
  const resultBoxRef = useRef<THREE.Group>(null);
  const trRef = useRef<THREE.Group>(null);
  const brRef = useRef<THREE.Group>(null);
  const blRef = useRef<THREE.Group>(null);

  const [display, setDisplay] = useState({
     trText: '', brText: '',
     trColor: '#334155', brColor: '#334155', blColor: '#334155',
     resultBoxText: '', resultBoxColor: '#334155',
     operatorText: '',
     rayVisible: false, rayStart: [0,0,0] as [number, number, number], rayEnd: [0,0,0] as [number, number, number]
  });

  // Step 0: Initial Setup & Reset
  useEffect(() => {
     if (step === 0) {
         gsap.killTweensOf([
             operatorRef.current?.position, operatorRef.current?.scale,
             resultBoxRef.current?.position, resultBoxRef.current?.scale,
             trRef.current?.scale, brRef.current?.scale, blRef.current?.scale
         ]);
         
         if (operatorRef.current) {
            operatorRef.current.visible = false;
            operatorRef.current.scale.set(0, 0, 0);
         }
         if (resultBoxRef.current) {
            resultBoxRef.current.visible = false;
            resultBoxRef.current.scale.set(1, 1, 1);
            if (mode === 'findReal') {
               resultBoxRef.current.position.set(2.5, -3.5, 0.1);
            } else {
               resultBoxRef.current.position.set(2.5, 3.5, 0.1);
            }
         }
         
         if (mode === 'findReal') {
             setDisplay({
                 trText: `${drawVal} cm`,
                 brText: `? km`,
                 trColor: '#06b6d4', 
                 brColor: '#475569', 
                 blColor: '#3b82f6', 
                 resultBoxText: '',
                 resultBoxColor: '#f59e0b',
                 operatorText: '',
                 rayVisible: false,
                 rayStart: [-2.5, -1.5, 0],
                 rayEnd: [2.5, 1.5, 0]
             });
         } else {
             setDisplay({
                 trText: `? cm`,
                 brText: `${realVal} km`,
                 trColor: '#475569',
                 brColor: '#10b981', 
                 blColor: '#3b82f6',
                 resultBoxText: '',
                 resultBoxColor: '#06b6d4',
                 operatorText: '',
                 rayVisible: false,
                 rayStart: [2.5, -1.5, 0],
                 rayEnd: [-2.5, -1.5, 0]
             });
         }
     }
  }, [step, mode, scaleValue, drawVal, realVal]);

  // Step Animations
  useEffect(() => {
      if (mode === 'findReal') {
          if (step === 1) {
              setDisplay(prev => ({ ...prev, blColor: '#eab308', trColor: '#eab308', rayVisible: true }));
              gsap.to([blRef.current?.scale, trRef.current?.scale], { x: 1.1, y: 1.1, z: 1.1, duration: 0.3, yoyo: true, repeat: 1 });
          }
          if (step === 2) {
              setDisplay(prev => ({ 
                  ...prev, 
                  rayVisible: false, blColor: '#3b82f6', trColor: '#06b6d4',
                  resultBoxText: `${(drawVal * scaleValue).toLocaleString()} cm`,
              }));
              if (resultBoxRef.current) {
                  resultBoxRef.current.visible = true;
                  resultBoxRef.current.position.set(2.5, -3.5, 0.1);
                  gsap.fromTo(resultBoxRef.current.scale, { x: 0, y: 0, z: 0 }, { x: 1, y: 1, z: 1, duration: 0.5, ease: 'back.out(1.5)' });
              }
          }
          if (step === 3) {
              setDisplay(prev => ({ ...prev, operatorText: '÷ 100,000' }));
              if (operatorRef.current && resultBoxRef.current) {
                  operatorRef.current.visible = true;
                  operatorRef.current.position.set(5.5, -3.5, 0);
                  gsap.to(operatorRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.3 });
                  
                  gsap.to(operatorRef.current.position, { 
                      x: 2.5, duration: 0.8, ease: 'power2.in', delay: 0.5,
                      onComplete: () => {
                          operatorRef.current!.visible = false;
                          setDisplay(prev => ({ 
                              ...prev, 
                              resultBoxText: `${((drawVal * scaleValue) / 100000).toLocaleString()} km`,
                              resultBoxColor: '#10b981'
                          }));
                          gsap.to(resultBoxRef.current!.scale, { x: 1.1, y: 1.1, z: 1.1, duration: 0.2, yoyo: true, repeat: 1 });
                      }
                  });
              }
          }
          if (step === 4) {
              if (resultBoxRef.current) {
                  gsap.to(resultBoxRef.current.position, {
                      y: -1.5, duration: 0.6, ease: 'power2.inOut',
                      onComplete: () => {
                          resultBoxRef.current!.visible = false;
                          setDisplay(prev => ({
                              ...prev,
                              brText: `${((drawVal * scaleValue) / 100000).toLocaleString()} km`,
                              brColor: '#10b981'
                          }));
                      }
                  });
              }
          }
      } else {
          if (step === 1) {
              setDisplay(prev => ({ ...prev, operatorText: '× 100,000' }));
              if (operatorRef.current && brRef.current) {
                  operatorRef.current.visible = true;
                  operatorRef.current.position.set(5.5, -1.5, 0); 
                  gsap.to(operatorRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.3 });
                  
                  gsap.to(operatorRef.current.position, {
                      x: 2.5, duration: 0.8, ease: 'power2.in', delay: 0.3,
                      onComplete: () => {
                          operatorRef.current!.visible = false;
                          setDisplay(prev => ({
                              ...prev,
                              brText: `${(realVal * 100000).toLocaleString()} cm`,
                              brColor: '#eab308' 
                          }));
                          gsap.to(brRef.current!.scale, { x: 1.1, y: 1.1, z: 1.1, duration: 0.2, yoyo: true, repeat: 1 });
                      }
                  });
              }
          }
          if (step === 2) {
              setDisplay(prev => ({ ...prev, rayVisible: true, blColor: '#eab308' }));
              gsap.to([brRef.current?.scale, blRef.current?.scale], { x: 1.1, y: 1.1, z: 1.1, duration: 0.3, yoyo: true, repeat: 1 });
          }
          if (step === 3) {
              setDisplay(prev => ({ 
                  ...prev, 
                  rayVisible: false, blColor: '#3b82f6', brColor: '#10b981', 
                  resultBoxText: `${((realVal * 100000) / scaleValue).toLocaleString(undefined, {maximumFractionDigits: 2})} cm`,
                  resultBoxColor: '#06b6d4'
              }));
              if (resultBoxRef.current) {
                  resultBoxRef.current.visible = true;
                  resultBoxRef.current.position.set(2.5, 3.5, 0.1);
                  gsap.fromTo(resultBoxRef.current.scale, { x: 0, y: 0, z: 0 }, { x: 1, y: 1, z: 1, duration: 0.5, ease: 'back.out(1.5)' });
              }
          }
          if (step === 4) {
              if (resultBoxRef.current) {
                  gsap.to(resultBoxRef.current.position, {
                      y: 1.5, duration: 0.6, ease: 'power2.inOut',
                      onComplete: () => {
                          resultBoxRef.current!.visible = false;
                          setDisplay(prev => ({
                              ...prev,
                              trText: `${((realVal * 100000) / scaleValue).toLocaleString(undefined, {maximumFractionDigits: 2})} cm`,
                              trColor: '#06b6d4'
                          }));
                      }
                  });
              }
          }
      }
  }, [step, mode, scaleValue, drawVal, realVal]);

  return (
     <group>
        <ValueBlock ref={trRef} position={[2.5, 1.5, 0]} text={display.trText} color={display.trColor} />
        <ValueBlock ref={brRef} position={[2.5, -1.5, 0]} text={display.brText} color={display.brColor} />
        
        <ValueBlock position={[-2.5, 1.5, 0]} text="1" color="#334155" />
        <ValueBlock ref={blRef} position={[-2.5, -1.5, 0]} text={scaleValue.toLocaleString()} color={display.blColor} />

        {/* Dividers */}
        <mesh position={[-2.5, 0, 0]}>
            <boxGeometry args={[4, 0.1, 0.1]} />
            <meshStandardMaterial color="#ffffff" />
        </mesh>
        <mesh position={[2.5, 0, 0]}>
            <boxGeometry args={[4, 0.1, 0.1]} />
            <meshStandardMaterial color="#ffffff" />
        </mesh>

        {/* Equals Sign */}
        <Text position={[0, 0, 0]} fontSize={1} color="#ffffff" outlineWidth={0.02} outlineColor="#000">=</Text>

        {/* Highlight Ray */}
        {display.rayVisible && <Ray start={display.rayStart} end={display.rayEnd} />}

        {/* Dynamic Result Box */}
        <ValueBlock 
           ref={resultBoxRef} 
           position={[2.5, -3.5, 0.1]} 
           text={display.resultBoxText} 
           color={display.resultBoxColor} 
           visible={false} 
        />

        {/* Operator Particle */}
        <group ref={operatorRef} visible={false}>
           <mesh>
              <sphereGeometry args={[0.5, 32, 32]} />
              <meshStandardMaterial color="#a78bfa" emissive="#8b5cf6" emissiveIntensity={1} />
           </mesh>
           <Text position={[0, 1, 0]} fontSize={0.4} color="#a78bfa" outlineWidth={0.02} outlineColor="#000" whiteSpace="nowrap">
              {display.operatorText}
           </Text>
        </group>
     </group>
  )
}

// --- Main Application Component ---

export default function ProportionSolverSimulation() {
  const [mode, setMode] = useState<'findReal' | 'findDrawing'>('findReal');
  const [step, setStep] = useState(0);
  const [scaleValue, setScaleValue] = useState(500000);
  const [drawVal, setDrawVal] = useState(3);
  const [realVal, setRealVal] = useState(15);

  const renderMath = () => {
    if (mode === 'findReal') {
        if (step === 0) return <BlockMath math={`\\frac{1}{${scaleValue.toLocaleString()}} = \\frac{${drawVal}\\text{ cm}}{R}`} />;
        if (step === 1) return <BlockMath math={`R = ${drawVal} \\times ${scaleValue.toLocaleString()}`} />;
        if (step === 2) return <BlockMath math={`R = ${(drawVal * scaleValue).toLocaleString()}\\text{ cm}`} />;
        if (step === 3) return <BlockMath math={`R = ${(drawVal * scaleValue).toLocaleString()} \\div 100,000`} />;
        if (step === 4) return <BlockMath math={`R = ${((drawVal * scaleValue) / 100000).toLocaleString()}\\text{ km}`} />;
    } else {
        if (step === 0) return <BlockMath math={`\\frac{1}{${scaleValue.toLocaleString()}} = \\frac{D}{${realVal}\\text{ km}}`} />;
        if (step === 1) return <BlockMath math={`\\text{Units: } ${realVal}\\text{ km} \\rightarrow ${(realVal * 100000).toLocaleString()}\\text{ cm}`} />;
        if (step === 2) return <BlockMath math={`D = ${(realVal * 100000).toLocaleString()} \\div ${scaleValue.toLocaleString()}`} />;
        if (step >= 3) return <BlockMath math={`D = ${((realVal * 100000) / scaleValue).toLocaleString(undefined, {maximumFractionDigits: 2})}\\text{ cm}`} />;
    }
  };

  const getAlertContent = () => {
    if (mode === 'findReal') {
        if (step === 0) return { title: "Blueprint Setup", icon: <Map size={18}/>, color: "border-l-blue-500 text-blue-400 bg-blue-900/10", body: <>We know the map scale and the drawing distance on the map. We want to find the <span className="font-semibold text-emerald-400">Real Length</span>.</> };
        if (step === 1) return { title: "Cross Multiplication", icon: <MoveRight size={18}/>, color: "border-l-yellow-500 text-yellow-400 bg-yellow-900/10", body: <>Multiply the drawing distance by the scale factor to find the total distance.</> };
        if (step === 2) return { title: "Intermediate Output", icon: <AlertCircle size={18}/>, color: "border-l-rose-500 text-rose-400 bg-rose-900/10", body: <>Wait! The result is <span className="font-bold text-rose-300">{(drawVal * scaleValue).toLocaleString()} cm</span>. Real-world geographical distances shouldn't be measured in millions of centimeters!</> };
        if (step >= 3) return { title: "Unit Reconversion", icon: <RotateCcw size={18}/>, color: "border-l-emerald-500 text-emerald-400 bg-emerald-900/10", body: <>We MUST divide by 100,000 to convert centimeters back to kilometers. Now it's a realistic geographical measurement.</> };
    } else {
        if (step === 0) return { title: "Reverse Solver", icon: <Map size={18}/>, color: "border-l-blue-500 text-blue-400 bg-blue-900/10", body: <>We know the real geographical distance and the scale. We want to find how long to draw it on the <span className="font-semibold text-cyan-400">Map (Drawing Length)</span>.</> };
        if (step === 1) return { title: "Unit Conversion First", icon: <AlertCircle size={18}/>, color: "border-l-rose-500 text-rose-400 bg-rose-900/10", body: <>We cannot directly divide kilometers by the scale! We must first convert {realVal} km to centimeters.</> };
        if (step === 2) return { title: "Scale Division", icon: <MoveRight size={18}/>, color: "border-l-yellow-500 text-yellow-400 bg-yellow-900/10", body: <>Now we divide the unified centimeter distance by the scale factor.</> };
        if (step >= 3) return { title: "Final Drawing Measure", icon: <PenTool size={18}/>, color: "border-l-cyan-500 text-cyan-400 bg-cyan-900/10", body: <>The result is a small, manageable number in centimeters, perfect for a ruler!</> };
    }
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
              Proportion Resolution
            </h1>
            <p className="text-slate-400 text-sm mt-1">
              Visualizing the mathematics of scale blueprints.
            </p>
          </div>
          <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[250px] flex justify-center shadow-inner">
             <div className="text-white text-lg">
               {renderMath()}
             </div>
          </div>
        </div>
      </div>
      
      {/* R3F Canvas */}
      <div className="flex-1 w-full h-full relative z-0 touch-none">
         <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
           <Canvas shadows camera={{ position: [0, 0, 16], fov: 40 }}>
              <color attach="background" args={['#0f172a']} />
              <ambientLight intensity={0.6} />
              <directionalLight position={[10, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
              <Environment preset="city" opacity={0.3} />
              
              <Grid infiniteGrid fadeDistance={40} sectionColor="#1e3a8a" cellColor="#0f214a" />
              
              <ProcessVisualizer 
                 step={step} 
                 mode={mode} 
                 scaleValue={scaleValue} 
                 drawVal={drawVal} 
                 realVal={realVal} 
              />
              
              <OrbitControls 
                enablePan={false} 
                enableZoom={false}
                maxPolarAngle={Math.PI / 2 + 0.3}
                minPolarAngle={Math.PI / 2 - 0.3}
                maxAzimuthAngle={0.4}
                minAzimuthAngle={-0.4}
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
                 className={`px-2 py-2 rounded-lg text-sm font-bold transition-all duration-300 ${mode === 'findReal' ? 'bg-blue-600 text-white shadow-lg' : 'text-slate-400 hover:text-white hover:bg-slate-800'}`}
                 onClick={() => { setMode('findReal'); setStep(0); }}
              >
                 Find Real Length
              </button>
              <button 
                 className={`px-2 py-2 rounded-lg text-sm font-bold transition-all duration-300 ${mode === 'findDrawing' ? 'bg-emerald-600 text-white shadow-lg' : 'text-slate-400 hover:text-white hover:bg-slate-800'}`}
                 onClick={() => { setMode('findDrawing'); setStep(0); }}
              >
                 Find Drawing Length
              </button>
           </div>

           {/* Progress Indicator */}
           <div className="flex gap-1.5">
             {[0, 1, 2, 3, 4].map(s => (
                <div key={s} className={`h-1 flex-1 rounded-full transition-colors duration-500 ${s <= step ? 'bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.6)]' : 'bg-slate-700'}`} />
             ))}
           </div>

           {/* Sliders */}
           <div className="flex flex-col gap-3">
              <div className="flex flex-col gap-1">
                <div className="flex justify-between items-center text-slate-300">
                  <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Scale Factor</label>
                  <span className="text-blue-400 font-mono font-bold text-xs bg-slate-950 px-2 py-0.5 rounded border border-slate-800">1 : {scaleValue.toLocaleString()}</span>
                </div>
                <input type="range" min="100000" max="1000000" step="50000" value={scaleValue} onChange={e => {setScaleValue(Number(e.target.value)); setStep(0);}} className="w-full accent-blue-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer" />
              </div>

              {mode === 'findReal' ? (
                <div className="flex flex-col gap-1">
                   <div className="flex justify-between items-center text-slate-300">
                     <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Drawing Length</label>
                     <span className="text-cyan-400 font-mono font-bold text-xs bg-slate-950 px-2 py-0.5 rounded border border-slate-800">{drawVal} cm</span>
                   </div>
                   <input type="range" min="1" max="20" step="1" value={drawVal} onChange={e => {setDrawVal(Number(e.target.value)); setStep(0);}} className="w-full accent-cyan-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer" />
                </div>
              ) : (
                <div className="flex flex-col gap-1">
                   <div className="flex justify-between items-center text-slate-300">
                     <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Real Length</label>
                     <span className="text-emerald-400 font-mono font-bold text-xs bg-slate-950 px-2 py-0.5 rounded border border-slate-800">{realVal} km</span>
                   </div>
                   <input type="range" min="1" max="50" step="1" value={realVal} onChange={e => {setRealVal(Number(e.target.value)); setStep(0);}} className="w-full accent-emerald-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer" />
                </div>
              )}
           </div>

           {/* Actions */}
           <div className="grid grid-cols-2 gap-3 mt-1">
             <button 
                onClick={() => setStep(s => s < 4 ? s + 1 : s)} 
                disabled={step === 4} 
                className="bg-blue-600 hover:bg-blue-500 text-white font-bold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-blue-900/20 text-sm"
             >
                {step === 0 ? "Start Solver" : step === 4 ? "Resolved" : "Next Step"} 
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
