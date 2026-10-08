import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, RoundedBox } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, Ruler, Sigma, Equal, CheckCircle2, ShieldAlert, Maximize, RotateCcw } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function RectangleWireframe({ w, l, step }: { w: number, l: number, step: number }) {
    const scaleFactor = 0.4; // Visual scaling to fit camera
    const visW = w * scaleFactor;
    const visL = l * scaleFactor;
    const borderThick = 0.15;

    // Refs for animating meshes
    const topRef = useRef<THREE.Mesh>(null);
    const botRef = useRef<THREE.Mesh>(null);
    const leftRef = useRef<THREE.Mesh>(null);
    const rightRef = useRef<THREE.Mesh>(null);

    const textTopRef = useRef<THREE.Group>(null);
    const textBotRef = useRef<THREE.Group>(null);
    const textLeftRef = useRef<THREE.Group>(null);
    const textRightRef = useRef<THREE.Group>(null);

    // Dynamic Text Values
    const labelL = step < 3 ? "2x" : `${l} cm`;
    const labelW = step < 3 ? "x" : `${w} cm`;

    // Colors
    const colorL = "#3b82f6"; // blue-500
    const colorW = "#10b981"; // emerald-500

    useEffect(() => {
        // Animate geometry positions and scales
        gsap.to(topRef.current!.position, { y: visW / 2, duration: 0.6, ease: "power2.out" });
        gsap.to(topRef.current!.scale, { x: visL + borderThick, duration: 0.6, ease: "power2.out" });

        gsap.to(botRef.current!.position, { y: -visW / 2, duration: 0.6, ease: "power2.out" });
        gsap.to(botRef.current!.scale, { x: visL + borderThick, duration: 0.6, ease: "power2.out" });

        gsap.to(leftRef.current!.position, { x: -visL / 2, duration: 0.6, ease: "power2.out" });
        gsap.to(leftRef.current!.scale, { y: visW + borderThick, duration: 0.6, ease: "power2.out" });

        gsap.to(rightRef.current!.position, { x: visL / 2, duration: 0.6, ease: "power2.out" });
        gsap.to(rightRef.current!.scale, { y: visW + borderThick, duration: 0.6, ease: "power2.out" });

        // Animate text positions
        gsap.to(textTopRef.current!.position, { y: visW / 2 + 0.6, duration: 0.6, ease: "power2.out" });
        gsap.to(textBotRef.current!.position, { y: -visW / 2 - 0.6, duration: 0.6, ease: "power2.out" });
        gsap.to(textLeftRef.current!.position, { x: -visL / 2 - 0.8, duration: 0.6, ease: "power2.out" });
        gsap.to(textRightRef.current!.position, { x: visL / 2 + 0.8, duration: 0.6, ease: "power2.out" });
        
        // Pulse animation for Step 1 (Summation)
        if (step === 1) {
            const mats = [
                topRef.current!.material, botRef.current!.material, 
                leftRef.current!.material, rightRef.current!.material
            ] as THREE.MeshStandardMaterial[];
            
            gsap.to(mats, {
                emissiveIntensity: 1.5,
                emissive: "#facc15",
                duration: 0.5,
                yoyo: true,
                repeat: 3
            });
        } else {
            // Reset colors
            gsap.to((topRef.current!.material as any), { emissiveIntensity: 0, color: colorL, duration: 0.3 });
            gsap.to((botRef.current!.material as any), { emissiveIntensity: 0, color: colorL, duration: 0.3 });
            gsap.to((leftRef.current!.material as any), { emissiveIntensity: 0, color: colorW, duration: 0.3 });
            gsap.to((rightRef.current!.material as any), { emissiveIntensity: 0, color: colorW, duration: 0.3 });
        }

    }, [visW, visL, step]);

    return (
        <group position={[0, -0.5, 0]}>
            {/* Borders */}
            <mesh ref={topRef} castShadow>
                <boxGeometry args={[1, borderThick, borderThick]} />
                <meshStandardMaterial color={colorL} roughness={0.3} metalness={0.5} />
            </mesh>
            <mesh ref={botRef} castShadow>
                <boxGeometry args={[1, borderThick, borderThick]} />
                <meshStandardMaterial color={colorL} roughness={0.3} metalness={0.5} />
            </mesh>
            <mesh ref={leftRef} castShadow>
                <boxGeometry args={[borderThick, 1, borderThick]} />
                <meshStandardMaterial color={colorW} roughness={0.3} metalness={0.5} />
            </mesh>
            <mesh ref={rightRef} castShadow>
                <boxGeometry args={[borderThick, 1, borderThick]} />
                <meshStandardMaterial color={colorW} roughness={0.3} metalness={0.5} />
            </mesh>

            {/* Ghost inner plane */}
            <mesh position={[0,0,-0.1]}>
                <planeGeometry args={[visL, visW]} />
                <meshBasicMaterial color="#334155" transparent opacity={0.1} />
            </mesh>

            {/* Labels */}
            <group ref={textTopRef}>
                <Text fontSize={0.5} color={step >= 3 ? "#ffffff" : colorL} fontWeight="bold">{labelL}</Text>
            </group>
            <group ref={textBotRef}>
                <Text fontSize={0.5} color={step >= 3 ? "#ffffff" : colorL} fontWeight="bold">{labelL}</Text>
            </group>
            <group ref={textLeftRef}>
                <Text fontSize={0.5} color={step >= 3 ? "#ffffff" : colorW} fontWeight="bold">{labelW}</Text>
            </group>
            <group ref={textRightRef}>
                <Text fontSize={0.5} color={step >= 3 ? "#ffffff" : colorW} fontWeight="bold">{labelW}</Text>
            </group>
        </group>
    );
}

function Scene({ step, pVal }: any) {
    const { camera } = useThree();
    
    const x = pVal / 6;
    const l = 2 * x;
    const w = x;

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, 
            y: 0, 
            z: 13, 
            duration: 1.5, 
            ease: "power2.inOut" 
        });
    }, [camera]);

    return (
        <group>
            {/* Background grid */}
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[50, 50]} />
                <meshStandardMaterial color="#0f172a" />
            </mesh>
            <gridHelper args={[50, 50, '#334155', '#1e293b']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            <OrbitControls enablePan={false} enableZoom={false} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
            
            <ambientLight intensity={0.6} />
            <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
            <Environment preset="city" opacity={0.3} />

            <RectangleWireframe w={w} l={l} step={step} />
        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function GeometryTranslationSimulation() {
    const [step, setStep] = useState(0);
    const [pVal, setPVal] = useState(18); // Default Perimeter = 18

    const x = pVal / 6;
    const l = 2 * x;
    const w = x;

    const handleNextStep = () => {
        if (step < 4) setStep(s => s + 1);
    };

    const handleReset = () => {
        setStep(0);
        setPVal(18);
    };

    const renderMath = () => {
        if (step === 0) return <BlockMath math={`\\text{Width} = x, \\text{ Length} = 2x`} />;
        if (step === 1) return <BlockMath math={`P = 2x + x + 2x + x = 6x`} />;
        if (step === 2) return <BlockMath math={`6x = ${pVal}`} />;
        if (step >= 3) return <BlockMath math={`x = ${x} \\implies W = ${w}, L = ${l}`} />;
    };

    const getAlertContent = () => {
        if (step === 0) return { title: "Geometric Translation", icon: <Ruler size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>"The length is double the width". We don't know the exact dimensions yet, so we define the unknown width as <span className="font-mono text-white">x</span> and length as <span className="font-mono text-white">2x</span>.</> };
        if (step === 1) return { title: "Summing the Boundary", icon: <ShieldAlert size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> Perimeter is NOT just <span className="font-mono text-white">Length + Width</span>. A rectangle has FOUR sides! We must trace the entire boundary: <span className="font-mono text-white">2x + x + 2x + x = 6x</span>.</> };
        if (step === 2) return { title: "Equating to Known Value", icon: <Equal size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>The problem states the total perimeter is <b>{pVal} cm</b>. We now equate our algebraic perimeter to the known value: <span className="font-mono text-white">6x = {pVal}</span>.</> };
        if (step === 3) return { title: "Evaluating Dimensions", icon: <CheckCircle2 size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>Solving the equation gives <span className="font-mono text-white">x = {x}</span>. The wireframe labels update: Width is {w} cm, Length is double that at {l} cm.</> };
        if (step === 4) return { title: "Dynamic Sandbox", icon: <Maximize size={18}/>, color: "border-l-amber-500 text-amber-300 bg-amber-900/40", body: <>Use the slider to adjust the target perimeter. Watch how the algebraic evaluation instantly restructures the physical dimensions of the rectangle while maintaining the 2:1 ratio.</> };
        return { title: "", icon: null, color: "", body: <></> };
    };

    const alertContent = getAlertContent();

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            Geometric Translation
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Converting narrative constraints into equations.
                        </p>
                    </div>
                    <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[340px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto">
                        <div className="text-white text-lg px-4 flex items-center justify-center h-full min-h-[4rem]">
                            {renderMath()}
                        </div>
                    </div>
                </div>
            </div>
            
            {/* Context Narrative Card */}
            {step === 0 && (
                <div className="absolute top-32 inset-x-0 flex justify-center pointer-events-none z-10">
                    <div className="bg-slate-800/90 border border-blue-500/30 p-4 rounded-xl shadow-2xl backdrop-blur-md max-w-lg text-center animate-fade-in-down">
                        <p className="text-slate-300 font-serif italic text-lg drop-shadow-md">
                            "A rectangle has a perimeter of 18 cm. Its length is double its width. Find its dimensions."
                        </p>
                    </div>
                </div>
            )}
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas shadows camera={{ position: [0, 0, 13], fov: 45 }}>
                        <color attach="background" args={['#020617']} />
                        <Scene step={step} pVal={pVal} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                <div className={`flex-1 pointer-events-auto max-w-md w-full transition-all duration-500 origin-bottom-left ${step === 0 ? 'scale-100' : 'scale-105'}`}>
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
                            <span className="text-blue-400">Define</span>
                            <span className="text-rose-400">Sum</span>
                            <span className="text-purple-400">Equate</span>
                            <span className="text-emerald-400">Evaluate</span>
                            <span className="text-amber-400">Sandbox</span>
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

                    {/* Sandbox Control */}
                    {step === 4 && (
                        <div className="mb-4">
                            <label className="text-[10px] font-bold uppercase tracking-wider text-amber-400 mb-1 flex justify-between">
                                <span>Adjust Perimeter: {pVal} cm</span>
                                <span>x = {pVal/6}</span>
                            </label>
                            <input 
                                type="range" 
                                min="12" 
                                max="60" 
                                step="6" 
                                value={pVal} 
                                onChange={(e) => setPVal(parseInt(e.target.value))} 
                                className="w-full accent-amber-500"
                            />
                            <div className="flex justify-between text-xs text-slate-500 mt-1">
                                <span>12</span>
                                <span>36</span>
                                <span>60</span>
                            </div>
                        </div>
                    )}

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={handleNextStep} 
                            disabled={step === 4} 
                            className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                ${step === 4 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                        >
                            {step === 0 ? "Sum Perimeter" : 
                             step === 1 ? "Equate to 18" : 
                             step === 2 ? "Solve for x" : 
                             step === 3 ? "Enter Sandbox" : "Completed"} 
                            {step < 4 && <MoveRight size={16} />}
                        </button>
                        <button 
                            onClick={handleReset} 
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
