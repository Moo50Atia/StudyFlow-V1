import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, CircleDashed, RotateCcw, Crosshair } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function Ray({ color, position = [0,0,0], rotation = [0,0,0], length = 5 }: any) {
    return (
        <group position={position} rotation={rotation}>
            <mesh position={[length / 2, 0, 0]} rotation={[0, 0, -Math.PI/2]} castShadow>
                <cylinderGeometry args={[0.06, 0.06, length]} />
                <meshStandardMaterial color={color} roughness={0.3} metalness={0.2} />
            </mesh>
            <mesh position={[length, 0, 0]} rotation={[0, 0, -Math.PI/2]} castShadow>
                <coneGeometry args={[0.25, 0.5]} />
                <meshStandardMaterial color={color} roughness={0.3} metalness={0.2} />
            </mesh>
        </group>
    );
}

function Scene({ a1, a2, a3, step }: { a1: number, a2: number, a3: number, step: number }) {
    const { camera } = useThree();
    
    // Animation Proxy
    const proxy = useRef({ 
        a1: a1 * Math.PI / 180,
        a2: a2 * Math.PI / 180,
        a3: a3 * Math.PI / 180,
        xSweep: 0
    }).current;
    
    // Refs for Rays
    const ray1Ref = useRef<THREE.Group>(null);
    const ray2Ref = useRef<THREE.Group>(null);
    const ray3Ref = useRef<THREE.Group>(null);
    const ray4Ref = useRef<THREE.Group>(null);
    
    // Refs for Arcs
    const arc1Ref = useRef<THREE.Line>(null);
    const arc2Ref = useRef<THREE.Line>(null);
    const arc3Ref = useRef<THREE.Line>(null);
    const arcXRef = useRef<THREE.Line>(null);
    const outerRingRef = useRef<THREE.Line>(null);

    // Refs for Texts
    const text1GroupRef = useRef<THREE.Group>(null);
    const text2GroupRef = useRef<THREE.Group>(null);
    const text3GroupRef = useRef<THREE.Group>(null);
    const textXGroupRef = useRef<THREE.Group>(null);
    const text1Ref = useRef<any>(null);
    const text2Ref = useRef<any>(null);
    const text3Ref = useRef<any>(null);
    const textXRef = useRef<any>(null);

    // Material Refs
    const arcXMatRef = useRef<THREE.LineBasicMaterial>(null);

    // Geometries
    const makeGeom = () => {
        const geom = new THREE.BufferGeometry();
        geom.setAttribute('position', new THREE.BufferAttribute(new Float32Array(100 * 3), 3));
        return geom;
    };

    const arc1Geom = useMemo(makeGeom, []);
    const arc2Geom = useMemo(makeGeom, []);
    const arc3Geom = useMemo(makeGeom, []);
    const arcXGeom = useMemo(makeGeom, []);
    const outerRingGeom = useMemo(makeGeom, []);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 0, z: 12, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    useEffect(() => {
        // Animation for the final solve step
        if (step === 1) {
            gsap.to(proxy, {
                xSweep: 1, // 0 to 1 multiplier for the X sector fill
                duration: 1.0,
                ease: "power2.out"
            });
        } else {
            proxy.xSweep = 0;
        }
    }, [step, proxy]);

    const updateArc = (geom: THREE.BufferGeometry, start: number, end: number, r: number, segments: number = 64) => {
        const positions = geom.attributes.position.array as Float32Array;
        const delta = end - start;
        for (let i = 0; i <= segments; i++) {
            const theta = start + (i / segments) * delta;
            positions[i * 3] = Math.cos(theta) * r;
            positions[i * 3 + 1] = Math.sin(theta) * r;
            positions[i * 3 + 2] = 0;
        }
        geom.setDrawRange(0, segments + 1);
        geom.attributes.position.needsUpdate = true;
    };

    useFrame(() => {
        // Smooth target tracking
        proxy.a1 = THREE.MathUtils.lerp(proxy.a1, a1 * Math.PI / 180, 0.1);
        proxy.a2 = THREE.MathUtils.lerp(proxy.a2, a2 * Math.PI / 180, 0.1);
        proxy.a3 = THREE.MathUtils.lerp(proxy.a3, a3 * Math.PI / 180, 0.1);
        
        const r1 = 0;
        const r2 = proxy.a1;
        const r3 = proxy.a1 + proxy.a2;
        const r4 = proxy.a1 + proxy.a2 + proxy.a3;
        const totalKnown = r4;
        const xVal = 2 * Math.PI - totalKnown;

        if (ray1Ref.current) ray1Ref.current.rotation.z = r1;
        if (ray2Ref.current) ray2Ref.current.rotation.z = r2;
        if (ray3Ref.current) ray3Ref.current.rotation.z = r3;
        if (ray4Ref.current) ray4Ref.current.rotation.z = r4;

        const arcRadius = 2.5;

        // Draw Arcs
        if (arc1Ref.current) updateArc(arc1Ref.current.geometry, r1, r2, arcRadius);
        if (arc2Ref.current) updateArc(arc2Ref.current.geometry, r2, r3, arcRadius);
        if (arc3Ref.current) updateArc(arc3Ref.current.geometry, r3, r4, arcRadius);
        
        // X Sector Sweep
        const currentXSweep = step === 1 ? proxy.xSweep : (step === 2 ? 1 : 0);
        if (arcXRef.current) updateArc(arcXRef.current.geometry, r4, r4 + (xVal * currentXSweep), arcRadius);
        
        // Outer Ring
        if (outerRingRef.current) updateArc(outerRingRef.current.geometry, 0, Math.PI * 2, arcRadius + 0.3, 100);

        // Update Texts
        const textRadius = 3.5;
        if (text1GroupRef.current && text1Ref.current) {
            const mid = r1 + proxy.a1 / 2;
            text1GroupRef.current.position.set(Math.cos(mid) * textRadius, Math.sin(mid) * textRadius, 0);
            text1Ref.current.text = `${Math.round(proxy.a1 * 180 / Math.PI)}°`;
        }
        
        if (text2GroupRef.current && text2Ref.current) {
            const mid = r2 + proxy.a2 / 2;
            text2GroupRef.current.position.set(Math.cos(mid) * textRadius, Math.sin(mid) * textRadius, 0);
            text2Ref.current.text = `${Math.round(proxy.a2 * 180 / Math.PI)}°`;
        }

        if (text3GroupRef.current && text3Ref.current) {
            const mid = r3 + proxy.a3 / 2;
            text3GroupRef.current.position.set(Math.cos(mid) * textRadius, Math.sin(mid) * textRadius, 0);
            text3Ref.current.text = `${Math.round(proxy.a3 * 180 / Math.PI)}°`;
        }

        if (textXGroupRef.current && textXRef.current) {
            const mid = r4 + xVal / 2;
            textXGroupRef.current.position.set(Math.cos(mid) * textRadius, Math.sin(mid) * textRadius, 0);
            
            if (step === 0) {
                textXRef.current.text = `X°`;
            } else if (step === 1) {
                textXRef.current.text = `360 - ...`;
            } else {
                textXRef.current.text = `${Math.round(xVal * 180 / Math.PI)}°`;
            }
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

            {/* Vertex M */}
            <group position={[0,0,0.1]}>
                <Sphere args={[0.2, 32, 32]}>
                    <meshStandardMaterial color="#f8fafc" roughness={0.2} metalness={0.5} />
                </Sphere>
                <Text position={[-0.5, 0.2, 0]} fontSize={0.5} color="#ffffff" fontWeight="bold">M</Text>
            </group>

            {/* Rays */}
            <Ray color="#94a3b8" ref={ray1Ref} />
            <Ray color="#94a3b8" ref={ray2Ref} />
            <Ray color="#94a3b8" ref={ray3Ref} />
            <Ray color="#94a3b8" ref={ray4Ref} />

            {/* Outer Gauge Ring */}
            <line ref={outerRingRef as any} geometry={outerRingGeom} position={[0,0,0.02]}>
                <lineBasicMaterial color="#ffffff" linewidth={2} transparent opacity={0.2} />
            </line>

            {/* Arcs */}
            <line ref={arc1Ref as any} geometry={arc1Geom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#3b82f6" linewidth={5} transparent opacity={0.9} /> {/* Blue */}
            </line>
            
            <line ref={arc2Ref as any} geometry={arc2Geom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#10b981" linewidth={5} transparent opacity={0.9} /> {/* Green */}
            </line>

            <line ref={arc3Ref as any} geometry={arc3Geom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#f97316" linewidth={5} transparent opacity={0.9} /> {/* Orange */}
            </line>

            <line ref={arcXRef as any} geometry={arcXGeom} position={[0,0,0.05]}>
                <lineBasicMaterial ref={arcXMatRef} color="#ef4444" linewidth={5} transparent opacity={0.9} /> {/* Red */}
            </line>

            {/* Texts */}
            <group ref={text1GroupRef}>
                <Text ref={text1Ref} fontSize={0.5} color="#3b82f6" fontWeight="bold" anchorX="center" anchorY="middle">A1</Text>
            </group>
            
            <group ref={text2GroupRef}>
                <Text ref={text2Ref} fontSize={0.5} color="#10b981" fontWeight="bold" anchorX="center" anchorY="middle">A2</Text>
            </group>

            <group ref={text3GroupRef}>
                <Text ref={text3Ref} fontSize={0.5} color="#f97316" fontWeight="bold" anchorX="center" anchorY="middle">A3</Text>
            </group>

            <group ref={textXGroupRef}>
                <Text ref={textXRef} fontSize={0.5} color="#ef4444" fontWeight="bold" anchorX="center" anchorY="middle">X</Text>
            </group>

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function AnglesAroundPointSimulation() {
    // Initial known angles
    const [a1, setA1] = useState(140);
    const [a2, setA2] = useState(100);
    const [a3, setA3] = useState(50);
    const [step, setStep] = useState(0); // 0: Question, 1: Solving/Sweeping, 2: Solved

    // X is always whatever is left of 360
    const knownSum = a1 + a2 + a3;
    const xVal = 360 - knownSum;

    // Safety constraints so sliders don't exceed 360 combined
    // Each slider's max depends on the current values of the other two, minus a minimum for X (e.g., 10 deg)
    const MIN_X = 10;
    
    const handleA1Change = (val: number) => {
        if (step !== 0) setStep(0);
        const maxAllowed = 360 - (a2 + a3) - MIN_X;
        setA1(Math.min(val, maxAllowed));
    };

    const handleA2Change = (val: number) => {
        if (step !== 0) setStep(0);
        const maxAllowed = 360 - (a1 + a3) - MIN_X;
        setA2(Math.min(val, maxAllowed));
    };

    const handleA3Change = (val: number) => {
        if (step !== 0) setStep(0);
        const maxAllowed = 360 - (a1 + a2) - MIN_X;
        setA3(Math.min(val, maxAllowed));
    };

    const handleSolve = () => {
        setStep(1);
        setTimeout(() => setStep(2), 1000); // Transitions to 'Solved' state after animation
    };

    const renderMath = () => {
        if (step === 0) {
            return <BlockMath math={`\\text{Solve for } \\color{#ef4444}X^\\circ`} />;
        }
        if (step === 1) {
            return <BlockMath math={`X = 360^\\circ - (\\color{#3b82f6}${a1} \\color{white}+ \\color{#10b981}${a2} \\color{white}+ \\color{#f97316}${a3}\\color{white})`} />;
        }
        return <BlockMath math={`\\color{#ef4444}X = ${xVal}^\\circ`} />;
    };

    const getAlertContent = () => {
        if (step === 0) {
            return { 
                title: "Angles Around a Point", 
                icon: <ShieldAlert size={18}/>, 
                color: "border-l-rose-500 text-rose-300 bg-rose-900/40", 
                body: <><b>CRITICAL MISCONCEPTION:</b> Students sometimes confuse angles around a point with angles on a straight line. Angles around a single vertex do NOT sum to 180°—they form a complete circle.</> 
            };
        }
        return { 
            title: "360° Circular Gauge", 
            icon: <CircleDashed size={18}/>, 
            color: "border-l-purple-500 text-purple-300 bg-purple-900/40", 
            body: <>The sum of all non-overlapping adjacent angles around a central point is always exactly <b>360°</b>. Therefore, the missing sector X must be 360 minus the sum of the known parts.</> 
        };
    };

    const alertContent = getAlertContent();

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            Angles Around a Point
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Completing the 360-degree circle sum.
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
                        <OrbitControls enablePan={false} enableZoom={false} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <Scene a1={a1} a2={a2} a3={a3} step={step} />
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
                    
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        {/* A1 Slider */}
                        <div>
                            <div className="flex justify-between text-xs font-bold mb-1">
                                <span className="text-slate-400">Sector 1</span>
                                <span className="text-blue-400">{a1}°</span>
                            </div>
                            <input 
                                type="range" 
                                min="10" 
                                max="180" 
                                step="1" 
                                value={a1} 
                                onChange={(e) => handleA1Change(parseInt(e.target.value))} 
                                className="w-full accent-blue-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer"
                            />
                        </div>

                        {/* A2 Slider */}
                        <div>
                            <div className="flex justify-between text-xs font-bold mb-1">
                                <span className="text-slate-400">Sector 2</span>
                                <span className="text-green-400">{a2}°</span>
                            </div>
                            <input 
                                type="range" 
                                min="10" 
                                max="180" 
                                step="1" 
                                value={a2} 
                                onChange={(e) => handleA2Change(parseInt(e.target.value))} 
                                className="w-full accent-green-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer"
                            />
                        </div>

                        {/* A3 Slider */}
                        <div>
                            <div className="flex justify-between text-xs font-bold mb-1">
                                <span className="text-slate-400">Sector 3</span>
                                <span className="text-orange-400">{a3}°</span>
                            </div>
                            <input 
                                type="range" 
                                min="10" 
                                max="180" 
                                step="1" 
                                value={a3} 
                                onChange={(e) => handleA3Change(parseInt(e.target.value))} 
                                className="w-full accent-orange-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer"
                            />
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={handleSolve} 
                            disabled={step > 0} 
                            className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                ${step > 0 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-red-600 hover:bg-red-500 text-white shadow-red-900/20'}`}
                        >
                            <Crosshair size={16} /> {step > 0 ? "Solved" : "Evaluate X"}
                        </button>
                        <button 
                            onClick={() => { setA1(140); setA2(100); setA3(50); setStep(0); }} 
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
