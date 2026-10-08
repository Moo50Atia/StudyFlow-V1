import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, RoundedBox, Line } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, Scale as ScaleIcon, AlertTriangle, CheckCircle2, RotateCcw, ShieldAlert, Zap } from 'lucide-react';

// ---------------------------------------------------------
// CONSTANTS
// ---------------------------------------------------------
const FULCRUM_Y = 1.5;
const BEAM_LENGTH = 7;
const PAN_DROP = 2.5;
const PAN_HALF_WIDTH = 1.5;

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function AnimBlock({ position, isVisible, type = 'weight', delay = 0 }: any) {
    const groupRef = useRef<THREE.Group>(null);
    const initialY = position[1];

    useEffect(() => {
        if (!groupRef.current) return;
        
        if (isVisible) {
            gsap.to(groupRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: "back.out(1.5)", delay });
            gsap.to(groupRef.current.position, { y: initialY, duration: 0.5, ease: "power2.out", delay });
        } else {
            gsap.to(groupRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.4, ease: "power2.in", delay });
            gsap.to(groupRef.current.position, { y: initialY + 2, duration: 0.4, ease: "power2.in", delay });
        }
    }, [isVisible, initialY, delay]);

    if (type === 'x') {
        return (
            <group ref={groupRef} position={[position[0], initialY + 2, position[2]]} scale={[0,0,0]}>
                <RoundedBox args={[0.7, 0.9, 0.7]} radius={0.1} castShadow>
                    <meshStandardMaterial color="#3b82f6" roughness={0.6} metalness={0.1} />
                </RoundedBox>
                <Text position={[0, 0, 0.36]} fontSize={0.5} color="#ffffff" fontStyle="italic" fontWeight="bold">
                    x
                </Text>
            </group>
        );
    }

    return (
        <group ref={groupRef} position={[position[0], initialY + 2, position[2]]} scale={[0,0,0]}>
            <RoundedBox args={[0.4, 0.4, 0.4]} radius={0.05} castShadow>
                <meshStandardMaterial color="#f59e0b" roughness={0.3} metalness={0.4} />
            </RoundedBox>
            <Text position={[0, 0, 0.21]} fontSize={0.25} color="#ffffff" fontWeight="bold">
                1
            </Text>
        </group>
    );
}

function ScaleSystem({ step }: { step: number }) {
    const beamRef = useRef<THREE.Group>(null);
    const leftPanRef = useRef<THREE.Group>(null);
    const rightPanRef = useRef<THREE.Group>(null);
    
    const eqMatRef = useRef<any>(null);
    const eqTextRef = useRef<any>(null);

    const tiltAngle = step === 1 ? -0.15 : 0; // -0.15 means left pan is higher (lighter)

    useFrame(() => {
        if (beamRef.current && leftPanRef.current && rightPanRef.current) {
            beamRef.current.rotation.z = THREE.MathUtils.lerp(beamRef.current.rotation.z, tiltAngle, 0.1);
            leftPanRef.current.rotation.z = -beamRef.current.rotation.z;
            rightPanRef.current.rotation.z = -beamRef.current.rotation.z;
        }
    });

    useEffect(() => {
        if (!eqMatRef.current || !eqTextRef.current) return;
        
        if (step === 1) {
            gsap.to(eqMatRef.current.color, { r: 0.93, g: 0.26, b: 0.26, duration: 0.5 }); // Red
            eqTextRef.current.text = "≠";
        } else {
            gsap.to(eqMatRef.current.color, { r: 0.06, g: 0.72, b: 0.5, duration: 0.5 }); // Green
            eqTextRef.current.text = "=";
        }
    }, [step]);

    return (
        <group position={[0, -1, 0]}>
            {/* Base & Pillar */}
            <mesh position={[0, -3, 0]} castShadow>
                <cylinderGeometry args={[1.5, 2, 0.2, 32]} />
                <meshStandardMaterial color="#1e293b" />
            </mesh>
            <mesh position={[0, -0.75, 0]} castShadow>
                <cylinderGeometry args={[0.2, 0.3, 4.5, 16]} />
                <meshStandardMaterial color="#334155" metalness={0.5} />
            </mesh>

            {/* Fulcrum */}
            <mesh position={[0, FULCRUM_Y, 0]} rotation={[Math.PI / 2, 0, 0]}>
                <cylinderGeometry args={[0.3, 0.3, 0.6, 32]} />
                <meshStandardMaterial color="#0f172a" />
            </mesh>
            
            <mesh position={[0, FULCRUM_Y, 0.31]}>
                <planeGeometry args={[0.6, 0.6]} />
                <meshBasicMaterial ref={eqMatRef} color="#10b981" />
            </mesh>
            <Text ref={eqTextRef} position={[0, FULCRUM_Y - 0.02, 0.32]} fontSize={0.4} color="#ffffff" fontWeight="bold">
                =
            </Text>

            {/* Beam Group (Rotates) */}
            <group position={[0, FULCRUM_Y, 0]} ref={beamRef}>
                <mesh castShadow>
                    <boxGeometry args={[BEAM_LENGTH, 0.2, 0.4]} />
                    <meshStandardMaterial color="#475569" metalness={0.6} />
                </mesh>

                {/* Left Pan Attachment */}
                <group position={[-BEAM_LENGTH / 2, 0, 0]}>
                    <group ref={leftPanRef}>
                        <Line points={[[0, 0, 0], [-PAN_HALF_WIDTH, -PAN_DROP, 0]]} color="#94a3b8" lineWidth={2} />
                        <Line points={[[0, 0, 0], [PAN_HALF_WIDTH, -PAN_DROP, 0]]} color="#94a3b8" lineWidth={2} />
                        <Line points={[[0, 0, 0], [0, -PAN_DROP, -PAN_HALF_WIDTH]]} color="#94a3b8" lineWidth={2} />
                        <Line points={[[0, 0, 0], [0, -PAN_DROP, PAN_HALF_WIDTH]]} color="#94a3b8" lineWidth={2} />
                        
                        <mesh position={[0, -PAN_DROP, 0]} castShadow receiveShadow>
                            <cylinderGeometry args={[PAN_HALF_WIDTH + 0.2, PAN_HALF_WIDTH, 0.1, 32]} />
                            <meshStandardMaterial color="#1e293b" />
                        </mesh>
                        
                        {/* Left Pan Contents */}
                        <group position={[0, -PAN_DROP, 0]}>
                            {/* X Bags */}
                            <AnimBlock type="x" position={[-0.4, 0.45, 0]} isVisible={true} delay={0} />
                            <AnimBlock type="x" position={[0.4, 0.45, 0]} isVisible={step === 3} delay={0.2} />
                            
                            {/* 4 Weights (Removed in step 1) */}
                            <AnimBlock type="weight" position={[0.4, 0.2, 0.5]} isVisible={step === 0} delay={0} />
                            <AnimBlock type="weight" position={[0.4, 0.2, -0.5]} isVisible={step === 0} delay={0.1} />
                            <AnimBlock type="weight" position={[0.9, 0.2, 0.5]} isVisible={step === 0} delay={0.2} />
                            <AnimBlock type="weight" position={[0.9, 0.2, -0.5]} isVisible={step === 0} delay={0.3} />
                        </group>
                    </group>
                </group>

                {/* Right Pan Attachment */}
                <group position={[BEAM_LENGTH / 2, 0, 0]}>
                    <group ref={rightPanRef}>
                        <Line points={[[0, 0, 0], [-PAN_HALF_WIDTH, -PAN_DROP, 0]]} color="#94a3b8" lineWidth={2} />
                        <Line points={[[0, 0, 0], [PAN_HALF_WIDTH, -PAN_DROP, 0]]} color="#94a3b8" lineWidth={2} />
                        <Line points={[[0, 0, 0], [0, -PAN_DROP, -PAN_HALF_WIDTH]]} color="#94a3b8" lineWidth={2} />
                        <Line points={[[0, 0, 0], [0, -PAN_DROP, PAN_HALF_WIDTH]]} color="#94a3b8" lineWidth={2} />
                        
                        <mesh position={[0, -PAN_DROP, 0]} castShadow receiveShadow>
                            <cylinderGeometry args={[PAN_HALF_WIDTH + 0.2, PAN_HALF_WIDTH, 0.1, 32]} />
                            <meshStandardMaterial color="#1e293b" />
                        </mesh>

                        {/* Right Pan Contents */}
                        <group position={[0, -PAN_DROP, 0]}>
                            {/* 5 Permanent Weights */}
                            <AnimBlock type="weight" position={[0, 0.2, 0]} isVisible={true} delay={0} />
                            <AnimBlock type="weight" position={[0.6, 0.2, 0]} isVisible={true} delay={0} />
                            <AnimBlock type="weight" position={[-0.6, 0.2, 0]} isVisible={true} delay={0} />
                            <AnimBlock type="weight" position={[0, 0.2, 0.6]} isVisible={true} delay={0} />
                            <AnimBlock type="weight" position={[0, 0.2, -0.6]} isVisible={true} delay={0} />
                            
                            {/* 4 Removable Weights (Removed in step 2) */}
                            <AnimBlock type="weight" position={[0.6, 0.2, 0.6]} isVisible={step < 2} delay={0.1} />
                            <AnimBlock type="weight" position={[0.6, 0.2, -0.6]} isVisible={step < 2} delay={0.2} />
                            <AnimBlock type="weight" position={[-0.6, 0.2, 0.6]} isVisible={step < 2} delay={0.3} />
                            <AnimBlock type="weight" position={[-0.6, 0.2, -0.6]} isVisible={step < 2} delay={0.4} />

                            {/* 5 Multiplied Weights (Added in step 3) */}
                            <AnimBlock type="weight" position={[0, 0.6, 0]} isVisible={step === 3} delay={0.4} />
                            <AnimBlock type="weight" position={[0.6, 0.6, 0]} isVisible={step === 3} delay={0.5} />
                            <AnimBlock type="weight" position={[-0.6, 0.6, 0]} isVisible={step === 3} delay={0.6} />
                            <AnimBlock type="weight" position={[0, 0.6, 0.6]} isVisible={step === 3} delay={0.7} />
                            <AnimBlock type="weight" position={[0, 0.6, -0.6]} isVisible={step === 3} delay={0.8} />
                        </group>
                    </group>
                </group>
            </group>
        </group>
    );
}

function Scene({ step }: { step: number }) {
    const { camera } = useThree();

    useEffect(() => {
        gsap.to(camera.position, { x: 0, y: 1.5, z: 12, duration: 1.5, ease: "power2.inOut" });
    }, [camera]);

    return (
        <group>
            <OrbitControls enablePan={false} enableZoom={false} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
            
            <ambientLight intensity={0.6} />
            <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
            <Environment preset="city" opacity={0.3} />

            <ScaleSystem step={step} />
        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function PropertiesOfEqualitySimulation() {
    const [step, setStep] = useState(0);

    const handleNextStep = () => {
        if (step < 3) setStep(s => s + 1);
    };

    const handleReset = () => {
        setStep(0);
    };

    const renderMath = () => {
        if (step === 0) return <BlockMath math={`x + 4 = 9`} />;
        if (step === 1) return <BlockMath math={`x + 4 - 4 \\neq 9 \\implies x \\neq 9`} />;
        if (step === 2) return <BlockMath math={`x = 9 - 4 \\implies x = 5`} />;
        if (step === 3) return <BlockMath math={`2(x) = 2(5) \\implies 2x = 10`} />;
    };

    const getAlertContent = () => {
        if (step === 0) return { title: "Balanced Equation", icon: <ScaleIcon size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>The scale is perfectly balanced, representing a true equation. To maintain equality, whatever operation we perform to one side must be performed symmetrically to the other.</> };
        if (step === 1) return { title: "CRITICAL MISCONCEPTION", icon: <ShieldAlert size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>One-Sided Operation!</b> By subtracting 4 from ONLY the left side, we broke the equality. The left pan becomes lighter and the scale tilts. The statement is now false: <span className="font-mono text-white">x ≠ 9</span>.</> };
        if (step === 2) return { title: "Symmetric Subtraction", icon: <CheckCircle2 size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>By performing the exact same subtraction (-4) on the right side, balance is restored. We have successfully isolated the variable: <span className="font-mono text-white">x = 5</span>.</> };
        if (step === 3) return { title: "Symmetric Multiplication", icon: <Zap size={18}/>, color: "border-l-amber-500 text-amber-300 bg-amber-900/40", body: <>The Golden Rule of Algebra applies to all operations. By multiplying BOTH sides by 2, we double the weight on both pans simultaneously. The equality is preserved.</> };
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
                            Properties of Equality
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Maintaining mathematical balance symmetrically.
                        </p>
                    </div>
                    <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[340px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-all duration-500">
                        <div className={`text-lg px-4 flex items-center justify-center h-full min-h-[4rem] transition-colors duration-500 ${step === 1 ? 'text-rose-400' : 'text-white'}`}>
                            {renderMath()}
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas shadows camera={{ position: [0, 1.5, 12], fov: 45 }}>
                        <color attach="background" args={['#020617']} />
                        <Scene step={step} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                <div className="flex-1 pointer-events-auto max-w-md w-full transition-all duration-500 origin-bottom-left">
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
                            <span className="text-blue-400">Balance</span>
                            <span className="text-rose-400">Asymmetric</span>
                            <span className="text-emerald-400">Symmetric</span>
                            <span className="text-amber-400">Multiply</span>
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
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={handleNextStep} 
                            disabled={step === 3} 
                            className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                ${step === 3 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 
                                  step === 0 ? 'bg-rose-600 hover:bg-rose-500 text-white shadow-rose-900/20' : 
                                  'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                        >
                            {step === 0 ? "Subtract 4 (Left Only)" : 
                             step === 1 ? "Subtract 4 (Right Side)" : 
                             step === 2 ? "Multiply Both by 2" : "Completed"} 
                            {step < 3 && <MoveRight size={16} />}
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
