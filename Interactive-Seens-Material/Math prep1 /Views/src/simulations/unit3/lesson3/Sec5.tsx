import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Maximize2, Crosshair, ArrowRight } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function Scene({ t2TiltDeg, step }: { t2TiltDeg: number, step: number }) {
    const { camera } = useThree();
    
    // Animation Proxy
    const proxy = useRef({ 
        t2TiltRad: t2TiltDeg * Math.PI / 180,
        solveProgress: 0
    }).current;
    
    // Geometric constants
    const ySpacing = 2.5; // Distance between parallel lines (y=2.5, y=0, y=-2.5)
    
    const y1 = ySpacing;
    const y2 = 0;
    const y3 = -ySpacing;

    // Transversal 1 (T1) is fixed and vertical for simplicity of "AB = BC = 5"
    // Wait, if T1 is vertical, the distance is exactly ySpacing = 2.5. We can scale the "cm" display, e.g. 1 unit = 2 cm.
    // Let's set T1 to be fixed at x = -4, completely vertical.
    const t1X = -4;

    // Refs
    const t2Ref = useRef<THREE.Group>(null);
    const caliperDERef = useRef<THREE.Group>(null);
    const caliperEFRef = useRef<THREE.Group>(null);

    // Points on T2
    const ptD = useRef<THREE.Group>(null);
    const ptE = useRef<THREE.Group>(null);
    const ptF = useRef<THREE.Group>(null);

    const textDERef = useRef<any>(null);
    const textEFRef = useRef<any>(null);

    // Equation texts
    const eq1GroupRef = useRef<THREE.Group>(null);
    const eq2GroupRef = useRef<THREE.Group>(null);
    const eq3GroupRef = useRef<THREE.Group>(null);
    const eq1TextRef = useRef<any>(null);
    const eq2TextRef = useRef<any>(null);
    const eq3TextRef = useRef<any>(null);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 0, z: 15, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    useEffect(() => {
        // Animate solve progress (0 to 1) when step changes
        gsap.to(proxy, {
            solveProgress: step > 0 ? 1 : 0,
            duration: 1.0,
            ease: "power2.out"
        });
    }, [step, proxy]);

    useFrame(() => {
        proxy.t2TiltRad = THREE.MathUtils.lerp(proxy.t2TiltRad, t2TiltDeg * Math.PI / 180, 0.1);
        
        const theta = proxy.t2TiltRad;
        const tanTheta = Math.tan(theta);
        const sinTheta = Math.sin(theta);
        
        // T2 crosses y=0 at x=4
        const t2BaseX = 4;
        
        // Calculate intersection x coordinates for T2
        // Line eq: (x - t2BaseX) = (y - 0) / tan(theta) => x = y / tan(theta) + t2BaseX
        const xd = y1 / tanTheta + t2BaseX;
        const xe = y2 / tanTheta + t2BaseX;
        const xf = y3 / tanTheta + t2BaseX;

        // Position Points
        if (ptD.current) ptD.current.position.set(xd, y1, 0);
        if (ptE.current) ptE.current.position.set(xe, y2, 0);
        if (ptF.current) ptF.current.position.set(xf, y3, 0);
        
        // Position T2 Transversal
        if (t2Ref.current) {
            t2Ref.current.position.set(t2BaseX, 0, 0);
            t2Ref.current.rotation.z = theta - Math.PI/2;
        }

        // Calculate segment lengths
        // DE distance = ySpacing / sin(theta)
        const dist = ySpacing / Math.abs(sinTheta);
        // Scale to match specification "8.2 cm" roughly? 
        // We know AB = BC = 5cm. Here AB = 2.5 units. So 1 unit = 2 cm.
        // Therefore DE cm = dist * 2
        const distCm = (dist * 2).toFixed(1);

        // Position Calipers (Bracket markers)
        // Caliper DE
        if (caliperDERef.current) {
            const midXD = (xd + xe) / 2;
            const midYD = (y1 + y2) / 2;
            caliperDERef.current.position.set(midXD, midYD, 0.05);
            caliperDERef.current.rotation.z = theta - Math.PI/2;
            caliperDERef.current.scale.y = dist;
        }

        // Caliper EF
        if (caliperEFRef.current) {
            const midXE = (xe + xf) / 2;
            const midYE = (y2 + y3) / 2;
            caliperEFRef.current.position.set(midXE, midYE, 0.05);
            caliperEFRef.current.rotation.z = theta - Math.PI/2;
            caliperEFRef.current.scale.y = dist;
        }

        // Texts
        if (textDERef.current) {
            if (step === 0) {
                textDERef.current.text = `3X - 2`;
            } else if (step === 1) {
                textDERef.current.text = `13 cm`;
            } else {
                textDERef.current.text = `${distCm} cm`;
            }
        }
        if (textEFRef.current) {
            if (step === 0 || step === 1) {
                textEFRef.current.text = `13 cm`;
            } else {
                textEFRef.current.text = `${distCm} cm`;
            }
        }

        // Opacity of equation steps based on step and proxy
        if (eq1GroupRef.current) eq1GroupRef.current.position.y = 5 - (proxy.solveProgress * 0);
        if (eq2GroupRef.current) {
            eq2GroupRef.current.position.y = 3.5 - (proxy.solveProgress * 0);
            if (eq2TextRef.current) eq2TextRef.current.fillOpacity = step >= 1 ? proxy.solveProgress : 0;
        }
        if (eq3GroupRef.current) {
            eq3GroupRef.current.position.y = 2 - (proxy.solveProgress * 0);
            if (eq3TextRef.current) eq3TextRef.current.fillOpacity = step >= 1 ? proxy.solveProgress : 0;
        }
    });

    return (
        <group position={[0, 0, 0]}>
            <ambientLight intensity={0.6} />
            <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow />
            <Environment preset="city" opacity={0.3} />
            
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[50, 50]} />
                <meshStandardMaterial color="#020617" />
            </mesh>
            <gridHelper args={[50, 50, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* Parallel Tracks L1, L2, L3 */}
            <group position={[0, y1, 0]}>
                <mesh rotation={[0, 0, -Math.PI/2]} castShadow>
                    <cylinderGeometry args={[0.04, 0.04, 25]} />
                    <meshStandardMaterial color="#94a3b8" roughness={0.3} metalness={0.2} />
                </mesh>
                <Text position={[-12, 0.4, 0]} fontSize={0.5} color="#94a3b8" fontWeight="bold">L1</Text>
            </group>
            <group position={[0, y2, 0]}>
                <mesh rotation={[0, 0, -Math.PI/2]} castShadow>
                    <cylinderGeometry args={[0.04, 0.04, 25]} />
                    <meshStandardMaterial color="#94a3b8" roughness={0.3} metalness={0.2} />
                </mesh>
                <Text position={[-12, 0.4, 0]} fontSize={0.5} color="#94a3b8" fontWeight="bold">L2</Text>
            </group>
            <group position={[0, y3, 0]}>
                <mesh rotation={[0, 0, -Math.PI/2]} castShadow>
                    <cylinderGeometry args={[0.04, 0.04, 25]} />
                    <meshStandardMaterial color="#94a3b8" roughness={0.3} metalness={0.2} />
                </mesh>
                <Text position={[-12, 0.4, 0]} fontSize={0.5} color="#94a3b8" fontWeight="bold">L3</Text>
            </group>

            {/* Transversal T1 (Fixed) */}
            <group position={[t1X, 0, 0]}>
                <mesh castShadow>
                    <cylinderGeometry args={[0.04, 0.04, 12]} />
                    <meshStandardMaterial color="#3b82f6" roughness={0.3} metalness={0.2} />
                </mesh>
                <Text position={[0, 6.5, 0]} fontSize={0.5} color="#3b82f6" fontWeight="bold">T1</Text>

                {/* Vertices A, B, C */}
                <Sphere args={[0.15, 32, 32]} position={[0, y1, 0]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.5, y1, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">A</Text>
                
                <Sphere args={[0.15, 32, 32]} position={[0, y2, 0]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.5, y2, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">B</Text>
                
                <Sphere args={[0.15, 32, 32]} position={[0, y3, 0]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.5, y3, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">C</Text>

                {/* Equal Segment Markers (Tick marks) */}
                <mesh position={[0, (y1+y2)/2, 0.05]} rotation={[0,0,Math.PI/2]}>
                    <boxGeometry args={[0.1, 0.6, 0.1]} />
                    <meshStandardMaterial color="#22c55e" emissive="#22c55e" emissiveIntensity={1} toneMapped={false} />
                </mesh>
                <mesh position={[0, (y2+y3)/2, 0.05]} rotation={[0,0,Math.PI/2]}>
                    <boxGeometry args={[0.1, 0.6, 0.1]} />
                    <meshStandardMaterial color="#22c55e" emissive="#22c55e" emissiveIntensity={1} toneMapped={false} />
                </mesh>
                
                <Text position={[-1.2, (y1+y2)/2, 0]} fontSize={0.4} color="#22c55e" fontWeight="bold">5 cm</Text>
                <Text position={[-1.2, (y2+y3)/2, 0]} fontSize={0.4} color="#22c55e" fontWeight="bold">5 cm</Text>
            </group>

            {/* Transversal T2 (Dynamic) */}
            <group ref={t2Ref}>
                <mesh castShadow>
                    <cylinderGeometry args={[0.04, 0.04, 15]} />
                    <meshStandardMaterial color="#f97316" roughness={0.3} metalness={0.2} />
                </mesh>
                <Text position={[0, 7.5, 0]} rotation={[0,0,0]} fontSize={0.5} color="#f97316" fontWeight="bold">T2</Text>
            </group>

            {/* Vertices D, E, F */}
            <group ref={ptD}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0.5, 0.4, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">D</Text>
            </group>

            <group ref={ptE}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0.5, 0.4, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">E</Text>
            </group>

            <group ref={ptF}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0.5, 0.4, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">F</Text>
            </group>

            {/* Calipers (Brackets) for DE and EF */}
            <group ref={caliperDERef}>
                {/* Visual line along the segment, offset slightly right */}
                <mesh position={[0.3, 0, 0]}>
                    <cylinderGeometry args={[0.02, 0.02, 1]} />
                    <meshStandardMaterial color="#facc15" emissive="#facc15" emissiveIntensity={1.5} toneMapped={false} />
                </mesh>
                {/* Top hook */}
                <mesh position={[0.15, 0.5, 0]} rotation={[0,0,Math.PI/2]}>
                    <cylinderGeometry args={[0.02, 0.02, 0.3]} />
                    <meshStandardMaterial color="#facc15" emissive="#facc15" emissiveIntensity={1.5} toneMapped={false} />
                </mesh>
                {/* Bottom hook */}
                <mesh position={[0.15, -0.5, 0]} rotation={[0,0,Math.PI/2]}>
                    <cylinderGeometry args={[0.02, 0.02, 0.3]} />
                    <meshStandardMaterial color="#facc15" emissive="#facc15" emissiveIntensity={1.5} toneMapped={false} />
                </mesh>
                
                {/* Equality tick mark on T2 */}
                <mesh position={[0, 0, 0]} rotation={[0,0,Math.PI/2]}>
                    <boxGeometry args={[0.08, 0.5, 0.08]} />
                    <meshStandardMaterial color="#facc15" emissive="#facc15" emissiveIntensity={1.5} toneMapped={false} />
                </mesh>
                
                {/* Text needs to stay upright, so we counter-rotate it in useFrame? 
                    Actually, it's easier to just put the text outside the rotated group, 
                    but we'll just rotate the text back. */}
                <group position={[1.5, 0, 0]}>
                    <Text ref={textDERef} fontSize={0.4} color="#facc15" fontWeight="bold" anchorX="center" anchorY="middle" rotation={[0,0,0]} />
                </group>
            </group>

            <group ref={caliperEFRef}>
                <mesh position={[0.3, 0, 0]}>
                    <cylinderGeometry args={[0.02, 0.02, 1]} />
                    <meshStandardMaterial color="#facc15" emissive="#facc15" emissiveIntensity={1.5} toneMapped={false} />
                </mesh>
                <mesh position={[0.15, 0.5, 0]} rotation={[0,0,Math.PI/2]}>
                    <cylinderGeometry args={[0.02, 0.02, 0.3]} />
                    <meshStandardMaterial color="#facc15" emissive="#facc15" emissiveIntensity={1.5} toneMapped={false} />
                </mesh>
                <mesh position={[0.15, -0.5, 0]} rotation={[0,0,Math.PI/2]}>
                    <cylinderGeometry args={[0.02, 0.02, 0.3]} />
                    <meshStandardMaterial color="#facc15" emissive="#facc15" emissiveIntensity={1.5} toneMapped={false} />
                </mesh>

                <mesh position={[0, 0, 0]} rotation={[0,0,Math.PI/2]}>
                    <boxGeometry args={[0.08, 0.5, 0.08]} />
                    <meshStandardMaterial color="#facc15" emissive="#facc15" emissiveIntensity={1.5} toneMapped={false} />
                </mesh>
                
                <group position={[1.5, 0, 0]}>
                    <Text ref={textEFRef} fontSize={0.4} color="#facc15" fontWeight="bold" anchorX="center" anchorY="middle" rotation={[0,0,0]} />
                </group>
            </group>

            {/* 3D Equation Solver Panel (Left side) */}
            {step < 2 && (
                <group position={[-9, 0, 0]}>
                    <mesh position={[0, 3.5, -0.1]}>
                        <planeGeometry args={[8, 6]} />
                        <meshStandardMaterial color="#0f172a" transparent opacity={0.8} />
                    </mesh>
                    <lineSegments position={[0, 3.5, -0.09]}>
                        <edgesGeometry args={[new THREE.PlaneGeometry(8, 6)]} />
                        <lineBasicMaterial color="#3b82f6" transparent opacity={0.5} />
                    </lineSegments>

                    <group ref={eq1GroupRef} position={[0, 5, 0]}>
                        <Text ref={eq1TextRef} fontSize={0.5} color="#ffffff" fontWeight="bold">DE = EF</Text>
                    </group>
                    <group ref={eq2GroupRef} position={[0, 3.5, 0]}>
                        <Text ref={eq2TextRef} fontSize={0.5} color="#ef4444" fontWeight="bold" transparent fillOpacity={0}>3X - 2 = 13</Text>
                    </group>
                    <group ref={eq3GroupRef} position={[0, 2, 0]}>
                        <Text ref={eq3TextRef} fontSize={0.6} color="#22c55e" fontWeight="bold" transparent fillOpacity={0}>X = 5</Text>
                    </group>
                </group>
            )}
            
        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function EqualInterceptTheorem() {
    const [t2TiltDeg, setT2TiltDeg] = useState(38); // Specific angle yielding approx 8.2 cm distance
    const [step, setStep] = useState(0); // 0: Question, 1: Solving, 2: Interactive

    const handleSolve = () => {
        setStep(1);
        setTimeout(() => setStep(2), 2000);
    };

    const renderMath = () => {
        if (step === 0) {
            return <BlockMath math={`AB = BC \\implies DE = EF`} />;
        }
        if (step === 1) {
            return <BlockMath math={`3X - 2 = 13 \\implies 3X = 15 \\implies X = 5`} />;
        }
        return <BlockMath math={`\\text{If } L_1 \\parallel L_2 \\parallel L_3 \\text{ and } AB=BC, \\text{ then } DE=EF`} />;
    };

    const alertContent = { 
        title: "Equal Intercept Theorem", 
        icon: <ShieldAlert size={18}/>, 
        color: "border-l-rose-500 text-rose-300 bg-rose-900/40", 
        body: <><b>CRITICAL MISCONCEPTION:</b> Students often assume that because AB = BC = 5 cm, the intercepts on Transversal 2 must also equal 5 cm. <b>This is false.</b> The theorem states that if intercepts are equal on <i>one</i> transversal, they are equal to <i>each other</i> on any other transversal (DE = EF), but their actual length depends entirely on the angle of that specific transversal.</> 
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            Equal Intercept Theorem
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Parallel lines cutting transversals proportionally.
                        </p>
                    </div>
                    <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[340px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-all duration-500">
                        <div className="text-white text-lg px-4 flex items-center justify-center h-full min-h-[4rem]">
                            {renderMath()}
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas shadows>
                        <OrbitControls enablePan={true} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={Math.PI / 4} minAzimuthAngle={-Math.PI / 4} />
                        <Scene t2TiltDeg={t2TiltDeg} step={step} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                <div className={`flex-1 pointer-events-auto max-w-md w-full transition-all duration-500`}>
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
                    
                    {step < 2 ? (
                        <div className="flex flex-col gap-4">
                            <div className="bg-slate-950 p-4 rounded-xl border border-slate-800">
                                <h4 className="text-sm font-bold text-slate-300 mb-2">Solve for X:</h4>
                                <ul className="text-xs text-slate-400 space-y-1">
                                    <li>Given: <span className="text-green-400 font-mono">AB = BC = 5 cm</span></li>
                                    <li>Therefore: <span className="text-yellow-400 font-mono">DE = EF</span></li>
                                    <li>Equation: <span className="text-red-400 font-mono">3X - 2 = 13</span></li>
                                </ul>
                            </div>
                            <button 
                                onClick={handleSolve} 
                                disabled={step > 0} 
                                className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                    ${step > 0 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                            >
                                <Crosshair size={16} /> Evaluate Equation
                            </button>
                        </div>
                    ) : (
                        <div className="flex flex-col gap-4 animate-in fade-in zoom-in duration-500">
                            <div className="bg-green-900/20 p-4 rounded-xl border border-green-900/50">
                                <h4 className="text-sm font-bold text-green-400 flex items-center gap-2">
                                    <ArrowRight size={14} /> Equality Confirmed
                                </h4>
                                <p className="text-xs text-slate-400 mt-1">
                                    X = 5. Both segments equal 13 cm at this specific angle. Try tilting T2 to see how the lengths change while remaining strictly equal to each other.
                                </p>
                            </div>
                            
                            <div>
                                <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                                    <Maximize2 size={14} /> Transversal T2 Tilt
                                </label>
                                <div className="flex justify-between text-xs font-bold mb-1">
                                    <span className="text-orange-400">Angle = {t2TiltDeg}°</span>
                                </div>
                                <input 
                                    type="range" 
                                    min="20" 
                                    max="90" 
                                    step="1" 
                                    value={t2TiltDeg} 
                                    onChange={(e) => setT2TiltDeg(parseInt(e.target.value))} 
                                    className="w-full accent-orange-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer mt-2"
                                />
                            </div>
                        </div>
                    )}
                    
                </div>
            </div>
        </div>
    );
}
