import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, RoundedBox, Float, Line } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { Scale as ScaleIcon, Search, AlertTriangle, CheckCircle2, XCircle, Beaker, MoveRight } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function XBag({ position, scale = 1, showExponent = false }: any) {
    const exponentRef = useRef<THREE.Group>(null);

    useEffect(() => {
        if (showExponent) {
            gsap.to(exponentRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: "back.out(1.5)" });
        } else {
            gsap.to(exponentRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
        }
    }, [showExponent]);

    return (
        <group position={position} scale={[scale, scale, scale]}>
            <RoundedBox args={[0.7, 0.9, 0.7]} radius={0.1} castShadow>
                <meshStandardMaterial color="#3b82f6" roughness={0.6} metalness={0.1} />
            </RoundedBox>
            <Text position={[0, 0, 0.36]} fontSize={0.5} color="#ffffff" fontStyle="italic" fontWeight="bold">
                x
            </Text>
            <group ref={exponentRef} position={[0.3, 0.3, 0.36]} scale={[0,0,0]}>
                <Text fontSize={0.3} color="#fde047" fontStyle="normal" fontWeight="bold">
                    1
                </Text>
            </group>
        </group>
    );
}

function WeightBlock({ position }: { position: [number, number, number] }) {
    return (
        <group position={position}>
            <RoundedBox args={[0.4, 0.4, 0.4]} radius={0.05} castShadow>
                <meshStandardMaterial color="#f59e0b" roughness={0.3} metalness={0.4} />
            </RoundedBox>
            <Text position={[0, 0, 0.21]} fontSize={0.25} color="#ffffff" fontWeight="bold">
                1
            </Text>
        </group>
    );
}

function BalanceScale({ isVisible, scanStep }: { isVisible: boolean, scanStep: boolean }) {
    const groupRef = useRef<THREE.Group>(null);
    const equalSignRef = useRef<THREE.Mesh>(null);
    const scannerRef = useRef<THREE.Group>(null);

    useEffect(() => {
        if (isVisible) {
            gsap.to(groupRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.6, ease: "back.out(1.1)" });
            gsap.to((equalSignRef.current!.material as any).emissiveIntensity, { value: 1, duration: 1, yoyo: true, repeat: -1 });
        } else {
            gsap.to(groupRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.4 });
        }

        if (scanStep) {
            gsap.to(scannerRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.3 });
            gsap.fromTo(scannerRef.current!.position, { y: 2 }, { y: 0.2, duration: 1.5, ease: "power1.inOut" });
        } else {
            gsap.to(scannerRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
        }
    }, [isVisible, scanStep]);

    // Layout left pan (2X + 7)
    // Left Pan Center: [-3, 0, 0] relative to scale base
    const leftPanItems = (
        <group position={[-3, -0.4, 0]}>
            <XBag position={[-0.4, 0.45, -0.2]} showExponent={scanStep} />
            <XBag position={[0.4, 0.45, 0.2]} />
            
            {/* 7 Weights */}
            <WeightBlock position={[-0.6, 0.2, 0.6]} />
            <WeightBlock position={[-0.2, 0.2, 0.7]} />
            <WeightBlock position={[0.2, 0.2, 0.6]} />
            <WeightBlock position={[0.6, 0.2, -0.4]} />
            <WeightBlock position={[-0.8, 0.2, 0.2]} />
            <WeightBlock position={[0.8, 0.2, 0]} />
            <WeightBlock position={[0, 0.6, 0.6]} />
        </group>
    );

    // Layout right pan (13)
    // Right Pan Center: [3, 0, 0] relative to scale base
    const rightPanItems = (
        <group position={[3, -0.4, 0]}>
             {/* 13 Weights arranged loosely */}
             {[...Array(13)].map((_, i) => {
                 const angle = (i / 13) * Math.PI * 2 * 2;
                 const radius = i < 5 ? 0.2 : (i < 10 ? 0.6 : 1.0);
                 const x = Math.cos(angle) * radius;
                 const z = Math.sin(angle) * radius;
                 const y = i % 2 === 0 ? 0.2 : 0.6; // Stack some
                 return <WeightBlock key={i} position={[x, y, z]} />;
             })}
        </group>
    );

    return (
        <group ref={groupRef} position={[0, -1.5, 0]}>
            {/* Base */}
            <mesh position={[0, 0, 0]} castShadow>
                <cylinderGeometry args={[1.5, 2, 0.2, 32]} />
                <meshStandardMaterial color="#1e293b" />
            </mesh>
            
            {/* Pillar */}
            <mesh position={[0, 2, 0]} castShadow>
                <cylinderGeometry args={[0.15, 0.2, 4, 16]} />
                <meshStandardMaterial color="#334155" metalness={0.5} />
            </mesh>
            
            {/* Fulcrum & Equal Sign */}
            <group position={[0, 4, 0]}>
                <mesh rotation={[Math.PI/2, 0, 0]}>
                    <cylinderGeometry args={[0.3, 0.3, 0.6, 32]} />
                    <meshStandardMaterial color="#0f172a" />
                </mesh>
                <mesh ref={equalSignRef as any} position={[0, 0, 0.31]}>
                    <planeGeometry args={[0.5, 0.5]} />
                    <meshBasicMaterial color="#10b981" />
                </mesh>
                <Text position={[0, 0, 0.32]} fontSize={0.4} color="#ffffff" fontWeight="bold">=</Text>
            </group>
            
            {/* Beam */}
            <mesh position={[0, 4, 0]} castShadow>
                <boxGeometry args={[6.4, 0.2, 0.4]} />
                <meshStandardMaterial color="#475569" metalness={0.6} />
            </mesh>

            {/* Left Pan */}
            <group position={[-3, 4, 0]}>
                <Line points={[[0,0,0], [-1, -2.5, 0]]} color="#94a3b8" lineWidth={2} />
                <Line points={[[0,0,0], [1, -2.5, 0]]} color="#94a3b8" lineWidth={2} />
                <Line points={[[0,0,0], [0, -2.5, -1]]} color="#94a3b8" lineWidth={2} />
                <Line points={[[0,0,0], [0, -2.5, 1]]} color="#94a3b8" lineWidth={2} />
                <mesh position={[0, -2.5, 0]} castShadow receiveShadow>
                    <cylinderGeometry args={[1.5, 1.2, 0.1, 32]} />
                    <meshStandardMaterial color="#1e293b" />
                </mesh>
            </group>

            {/* Right Pan */}
            <group position={[3, 4, 0]}>
                <Line points={[[0,0,0], [-1, -2.5, 0]]} color="#94a3b8" lineWidth={2} />
                <Line points={[[0,0,0], [1, -2.5, 0]]} color="#94a3b8" lineWidth={2} />
                <Line points={[[0,0,0], [0, -2.5, -1]]} color="#94a3b8" lineWidth={2} />
                <Line points={[[0,0,0], [0, -2.5, 1]]} color="#94a3b8" lineWidth={2} />
                <mesh position={[0, -2.5, 0]} castShadow receiveShadow>
                    <cylinderGeometry args={[1.5, 1.2, 0.1, 32]} />
                    <meshStandardMaterial color="#1e293b" />
                </mesh>
            </group>

            {/* Items */}
            {leftPanItems}
            {rightPanItems}

            {/* Scanner Laser */}
            <group ref={scannerRef} position={[-3.4, 2, -0.2]} scale={[0,0,0]}>
                <mesh rotation={[Math.PI/2, 0, 0]}>
                    <cylinderGeometry args={[0.02, 0.5, 1, 16]} />
                    <meshBasicMaterial color="#38bdf8" transparent opacity={0.3} side={THREE.DoubleSide} />
                </mesh>
                <mesh position={[0, 0.5, 0]}>
                    <boxGeometry args={[0.2, 0.1, 0.1]} />
                    <meshBasicMaterial color="#38bdf8" />
                </mesh>
            </group>

        </group>
    );
}

function FloatingExpression({ isVisible }: { isVisible: boolean }) {
    const groupRef = useRef<THREE.Group>(null);

    useEffect(() => {
        if (isVisible) {
            gsap.to(groupRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.6, ease: "back.out(1.1)", delay: 0.2 });
        } else {
            gsap.to(groupRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.4 });
        }
    }, [isVisible]);

    return (
        <group ref={groupRef} position={[0, 0, 0]} scale={[0,0,0]}>
            <Float floatIntensity={2} rotationIntensity={0.5} speed={2}>
                <group position={[-2.5, 0, 0]}>
                    <RoundedBox args={[1.8, 1.2, 0.3]} radius={0.15}>
                        <meshStandardMaterial color="#64748b" transparent opacity={0.8} />
                    </RoundedBox>
                    <Text position={[0,0,0.16]} fontSize={0.6} color="#ffffff" fontStyle="italic">2x</Text>
                </group>
            </Float>
            <Float floatIntensity={1.5} rotationIntensity={0.8} speed={1.5}>
                <group position={[0, 0.5, 0]}>
                    <RoundedBox args={[1.5, 1.2, 0.3]} radius={0.15}>
                        <meshStandardMaterial color="#10b981" transparent opacity={0.8} />
                    </RoundedBox>
                    <Text position={[0,0,0.16]} fontSize={0.6} color="#ffffff" fontWeight="bold">+ 4</Text>
                </group>
            </Float>
            <Float floatIntensity={2.5} rotationIntensity={0.3} speed={2.5}>
                <group position={[2.5, -0.5, 0]}>
                    <RoundedBox args={[1.5, 1.2, 0.3]} radius={0.15}>
                        <meshStandardMaterial color="#ef4444" transparent opacity={0.8} />
                    </RoundedBox>
                    <Text position={[0,0,0.16]} fontSize={0.6} color="#ffffff" fontStyle="italic">- y</Text>
                </group>
            </Float>
        </group>
    );
}

function Scene({ mode, scanStep }: any) {
    const { camera } = useThree();

    useEffect(() => {
        if (mode === 'equation') {
            gsap.to(camera.position, { x: 0, y: 1, z: 10, duration: 1.5, ease: "power2.inOut" });
        } else {
            gsap.to(camera.position, { x: 0, y: 0, z: 8, duration: 1.5, ease: "power2.inOut" });
        }
    }, [mode, camera]);

    return (
        <group>
            <OrbitControls enablePan={false} enableZoom={false} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
            
            <BalanceScale isVisible={mode === 'equation'} scanStep={scanStep} />
            <FloatingExpression isVisible={mode === 'expression'} />
        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function EquationVsExpressionSimulation() {
    const [mode, setMode] = useState<'equation' | 'expression'>('equation');
    const [scanStep, setScanStep] = useState(false);

    const handleModeSwitch = (newMode: 'equation' | 'expression') => {
        setMode(newMode);
        setScanStep(false);
    };

    const renderMath = () => {
        if (mode === 'equation') return <BlockMath math={`2x + 7 = 13`} />;
        return <BlockMath math={`2x + 4 - y`} />;
    };

    const getAlertContent = () => {
        if (mode === 'expression') {
            return { 
                title: "Mathematical Phrase (Expression)", 
                icon: <XCircle size={18}/>, 
                color: "border-l-rose-500 text-rose-300 bg-rose-900/40", 
                body: <><b>CRITICAL MISCONCEPTION:</b> Many students call this an "equation". This is incorrect. An expression is just a mathematical phrase. It has terms (<span className="font-mono text-white">2x</span>, <span className="font-mono text-white">4</span>, <span className="font-mono text-white">-y</span>) floating without any relationship to another value. It lacks an equal sign.</> 
            };
        }
        if (mode === 'equation' && !scanStep) {
            return { 
                title: "Mathematical Sentence (Equation)", 
                icon: <ScaleIcon size={18}/>, 
                color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", 
                body: <>An equation is a complete mathematical sentence. It states that two expressions are perfectly balanced. Notice the structural fulcrum (<span className="font-mono text-white font-bold">=</span>) locking the left pan (<span className="font-mono text-white">2x + 7</span>) in equilibrium with the right pan (<span className="font-mono text-white">13</span>).</> 
            };
        }
        if (mode === 'equation' && scanStep) {
            return { 
                title: "Linear Equation in One Variable (1st Degree)", 
                icon: <Search size={18}/>, 
                color: "border-l-blue-500 text-blue-300 bg-blue-900/40", 
                body: <>Scanning the variable... The highest exponent on the variable <span className="font-mono text-white">x</span> is an implied <span className="font-mono text-white font-bold">1</span>. Because there is only one type of variable (<span className="font-mono text-white">x</span>) and its highest degree is 1, this is classified as a "Linear Equation in One Variable".</> 
            };
        }
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
                            Equations vs Expressions
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Understanding equality and variable degrees.
                        </p>
                    </div>
                    <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[340px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto">
                        <div className="text-white text-lg px-4 flex items-center justify-center h-full min-h-[4rem]">
                            {renderMath()}
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas shadows camera={{ position: [0, 1, 10], fov: 45 }}>
                        <color attach="background" args={['#020617']} />
                        <ambientLight intensity={0.6} />
                        <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
                        <Environment preset="city" opacity={0.3} />
                        
                        <Scene mode={mode} scanStep={scanStep} />
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
                    
                    {/* Mode Toggle */}
                    <div className="flex bg-slate-950 p-1 rounded-xl mb-4 border border-slate-800">
                        <button
                            onClick={() => handleModeSwitch('expression')}
                            className={`flex-1 py-2 text-sm font-bold rounded-lg transition-all flex items-center justify-center gap-2 ${
                                mode === 'expression' ? 'bg-slate-700 text-white shadow-md' : 'text-slate-500 hover:text-slate-300'
                            }`}
                        >
                            <Beaker size={16} /> Expression
                        </button>
                        <button
                            onClick={() => handleModeSwitch('equation')}
                            className={`flex-1 py-2 text-sm font-bold rounded-lg transition-all flex items-center justify-center gap-2 ${
                                mode === 'equation' ? 'bg-blue-600 text-white shadow-md' : 'text-slate-500 hover:text-slate-300'
                            }`}
                        >
                            <ScaleIcon size={16} /> Equation
                        </button>
                    </div>

                    {/* Contextual Actions */}
                    <div className="mt-auto h-[50px] flex items-center justify-center">
                        {mode === 'expression' ? (
                            <div className="text-sm text-slate-400 italic text-center px-4">
                                Expressions float freely. There is no relationship to solve for.
                            </div>
                        ) : (
                            <button 
                                onClick={() => setScanStep(true)} 
                                disabled={scanStep}
                                className={`w-full font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                    ${scanStep ? 'bg-slate-800 text-slate-500 cursor-not-allowed border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                            >
                                {scanStep ? "Degree Scanned" : "Scan Exponent Degree"}
                                {!scanStep && <Search size={16} />}
                            </button>
                        )}
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
