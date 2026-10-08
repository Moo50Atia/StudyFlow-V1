import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Equal, ChevronRight, RotateCcw, Shuffle } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function Ray({ color, label, position = [0,0,0], rotation = [0,0,0], length = 5, labelOffset = [0,0,0] }: any) {
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
            <group position={[length + 0.5 + labelOffset[0], labelOffset[1], labelOffset[2]]}>
                <Text fontSize={0.5} color={color} fontWeight="bold">{label}</Text>
            </group>
        </group>
    );
}

function EquationScene({ angleDeg, multiplier, solveStep }: { angleDeg: number, multiplier: number, solveStep: number }) {
    const { camera } = useThree();
    
    // Animation Proxy
    const proxy = useRef({ 
        alpha: angleDeg * Math.PI / 180,
        colorR: 6 / 255,   // Cyan #06b6d4
        colorG: 182 / 255,
        colorB: 212 / 255,
    }).current;
    
    // Refs
    const ray1Ref = useRef<THREE.Group>(null);
    const ray2Ref = useRef<THREE.Group>(null);
    const ray3Ref = useRef<THREE.Group>(null);
    const ray4Ref = useRef<THREE.Group>(null);
    
    const arcTopRef = useRef<THREE.Line>(null);
    const arcBotRef = useRef<THREE.Line>(null);
    const textTopGroupRef = useRef<THREE.Group>(null);
    const textBotGroupRef = useRef<THREE.Group>(null);
    const textTopRef = useRef<any>(null);
    const textBotRef = useRef<any>(null);
    const equalSignRef = useRef<any>(null);

    const arcTopMatRef = useRef<THREE.LineBasicMaterial>(null);
    const arcBotMatRef = useRef<THREE.LineBasicMaterial>(null);

    // Geometries for the arcs
    const arcTopGeometry = useMemo(() => {
        const geom = new THREE.BufferGeometry();
        geom.setAttribute('position', new THREE.BufferAttribute(new Float32Array(65 * 3), 3));
        return geom;
    }, []);

    const arcBotGeometry = useMemo(() => {
        const geom = new THREE.BufferGeometry();
        geom.setAttribute('position', new THREE.BufferAttribute(new Float32Array(65 * 3), 3));
        return geom;
    }, []);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 0, z: 12, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    const updateArc = (geom: THREE.BufferGeometry, start: number, end: number, r: number, segments: number = 64) => {
        const positions = geom.attributes.position.array as Float32Array;
        const delta = end - start;
        for (let i = 0; i <= segments; i++) {
            const theta = start + (i / segments) * delta;
            positions[i * 3] = Math.cos(theta) * r;
            positions[i * 3 + 1] = Math.sin(theta) * r;
            positions[i * 3 + 2] = 0;
        }
        geom.attributes.position.needsUpdate = true;
    };

    useFrame(() => {
        // Smooth target tracking for angle
        const targetRad = angleDeg * Math.PI / 180;
        proxy.alpha = THREE.MathUtils.lerp(proxy.alpha, targetRad, 0.1);
        
        // Target color tracking (Cyan #06b6d4 to Green #22c55e)
        const isSolved = solveStep === 2;
        const targetR = isSolved ? 34 / 255 : 6 / 255;
        const targetG = isSolved ? 197 / 255 : 182 / 255;
        const targetB = isSolved ? 94 / 255 : 212 / 255;
        
        proxy.colorR = THREE.MathUtils.lerp(proxy.colorR, targetR, 0.1);
        proxy.colorG = THREE.MathUtils.lerp(proxy.colorG, targetG, 0.1);
        proxy.colorB = THREE.MathUtils.lerp(proxy.colorB, targetB, 0.1);
        
        const currentColor = new THREE.Color(proxy.colorR, proxy.colorG, proxy.colorB);
        
        if (arcTopMatRef.current) arcTopMatRef.current.color = currentColor;
        if (arcBotMatRef.current) arcBotMatRef.current.color = currentColor;
        if (textTopRef.current) textTopRef.current.color = currentColor;
        if (textBotRef.current) textBotRef.current.color = currentColor;

        const a = proxy.alpha;
        
        // Ray Angles
        // Top sector is centered at 90 degrees (Math.PI/2)
        const rayAngle1 = Math.PI/2 - a/2;
        const rayAngle2 = Math.PI/2 + a/2;
        const rayAngle3 = 3*Math.PI/2 - a/2;
        const rayAngle4 = 3*Math.PI/2 + a/2;

        if (ray1Ref.current) ray1Ref.current.rotation.z = rayAngle1;
        if (ray2Ref.current) ray2Ref.current.rotation.z = rayAngle2;
        if (ray3Ref.current) ray3Ref.current.rotation.z = rayAngle3;
        if (ray4Ref.current) ray4Ref.current.rotation.z = rayAngle4;

        const arcRadius = 2.5;

        // Update Top Arc
        if (arcTopRef.current) updateArc(arcTopRef.current.geometry, rayAngle1, rayAngle2, arcRadius);
        
        // Update Bottom Arc
        if (arcBotRef.current) updateArc(arcBotRef.current.geometry, rayAngle3, rayAngle4, arcRadius);

        // Update Texts
        const textRadius = 3.3;
        if (textTopGroupRef.current && textTopRef.current) {
            textTopGroupRef.current.position.set(0, textRadius, 0);
            textTopRef.current.text = `${Math.round(a * 180 / Math.PI)}°`;
        }
        
        if (textBotGroupRef.current && textBotRef.current) {
            textBotGroupRef.current.position.set(0, -textRadius, 0);
            
            // Dynamic text based on step
            if (solveStep === 0) {
                textBotRef.current.text = `${multiplier}X°`;
            } else if (solveStep === 1) {
                textBotRef.current.text = `${multiplier}X = ${Math.round(a * 180 / Math.PI)}`;
            } else if (solveStep === 2) {
                textBotRef.current.text = `X = ${Math.round((a * 180 / Math.PI) / multiplier)}`;
            }
        }

        // Equal Sign pulsing
        if (equalSignRef.current) {
            const scale = 1 + Math.sin(Date.now() * 0.005) * 0.1;
            equalSignRef.current.scale.set(scale, scale, scale);
            equalSignRef.current.fillOpacity = isSolved ? 0.3 : 1;
        }
    });

    return (
        <group position={[0, -0.5, 0]}>
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

            {/* Central V.O.A Badge */}
            <group position={[0, 0, 0.2]} ref={equalSignRef}>
                <Text fontSize={0.6} color="#facc15" fontWeight="bold" anchorX="center" anchorY="middle">
                    {`= V.O.A =`}
                </Text>
            </group>

            {/* Rays */}
            <Ray color="#94a3b8" label="A" position={[0,0,0]} rotation={[0,0,0]} ref={ray1Ref} />
            <Ray color="#94a3b8" label="C" position={[0,0,0]} rotation={[0,0,0]} ref={ray2Ref} />
            <Ray color="#94a3b8" label="B" position={[0,0,0]} rotation={[0,0,0]} ref={ray3Ref} />
            <Ray color="#94a3b8" label="D" position={[0,0,0]} rotation={[0,0,0]} ref={ray4Ref} />

            {/* Arcs */}
            <line ref={arcTopRef as any} geometry={arcTopGeometry} position={[0,0,0.05]}>
                <lineBasicMaterial ref={arcTopMatRef} color="#06b6d4" linewidth={5} transparent opacity={0.9} />
            </line>
            
            <line ref={arcBotRef as any} geometry={arcBotGeometry} position={[0,0,0.05]}>
                <lineBasicMaterial ref={arcBotMatRef} color="#06b6d4" linewidth={5} transparent opacity={0.9} />
            </line>

            {/* Angle Texts */}
            <group ref={textTopGroupRef}>
                <Text ref={textTopRef} fontSize={0.5} color="#06b6d4" fontWeight="bold" anchorX="center" anchorY="middle">135°</Text>
            </group>
            
            <group ref={textBotGroupRef}>
                <Text ref={textBotRef} fontSize={0.5} color="#06b6d4" fontWeight="bold" anchorX="center" anchorY="middle">3X°</Text>
            </group>

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
const SCENARIOS = [
    { deg: 135, mult: 3 },
    { deg: 120, mult: 4 },
    { deg: 150, mult: 5 },
    { deg: 84, mult: 2 }
];

export default function VOAEquationSolver() {
    const [scenarioIdx, setScenarioIdx] = useState(0);
    const [solveStep, setSolveStep] = useState(0);

    const currentScenario = SCENARIOS[scenarioIdx];
    const { deg, mult } = currentScenario;
    const ans = deg / mult;

    const handleNextStep = () => {
        if (solveStep < 2) setSolveStep(s => s + 1);
    };

    const handleNextScenario = () => {
        setSolveStep(0);
        setScenarioIdx((prev) => (prev + 1) % SCENARIOS.length);
    };

    const renderMath = () => {
        if (solveStep === 0) {
            return <BlockMath math={`\\text{Find X given: } \\color{#06b6d4}${mult}X^\\circ \\text{ and } ${deg}^\\circ`} />;
        }
        if (solveStep === 1) {
            return <BlockMath math={`\\color{#06b6d4}${mult}X \\color{white}= ${deg} \\implies X = \\frac{${deg}}{${mult}}`} />;
        }
        return <BlockMath math={`\\color{#22c55e}X = ${ans}`} />;
    };

    const getAlertContent = () => {
        if (solveStep === 0) {
            return { 
                title: "Identify Relationship", 
                icon: <ShieldAlert size={18}/>, 
                color: "border-l-rose-500 text-rose-300 bg-rose-900/40", 
                body: <><b>CRITICAL MISCONCEPTION:</b> Do not set <code className="bg-rose-950 px-1 rounded">{mult}X + {deg} = 180</code>! These are Vertically Opposite Angles across the vertex, which means they are <b>EQUAL</b> to each other.</> 
            };
        }
        if (solveStep === 1) {
            return { 
                title: "Set Equation", 
                icon: <Equal size={18}/>, 
                color: "border-l-blue-500 text-blue-300 bg-blue-900/40", 
                body: <>Because they are V.O.A, we set the expressions equal: <b>{mult}X = {deg}</b>. Now divide both sides by {mult} to isolate X.</> 
            };
        }
        return { 
            title: "Solved!", 
            icon: <Equal size={18}/>, 
            color: "border-l-green-500 text-green-300 bg-green-900/40", 
            body: <>Correct! <b>X = {ans}</b>. The angles are geometrically proven to be equal, and the variable is resolved.</> 
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
                            V.O.A Equations
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Solving for variables in opposite angles.
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
                    <Canvas shadows>
                        <OrbitControls enablePan={false} enableZoom={false} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <EquationScene angleDeg={deg} multiplier={mult} solveStep={solveStep} />
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
                    
                    {/* Pipeline Map */}
                    <div className="flex flex-col gap-2 mb-6">
                        <div className="flex justify-between text-[10px] font-bold uppercase tracking-wider text-slate-400 px-1">
                            <span className={solveStep >= 0 ? "text-rose-400" : ""}>Analyze</span>
                            <span className={solveStep >= 1 ? "text-blue-400" : ""}>Set Equal</span>
                            <span className={solveStep >= 2 ? "text-green-400" : ""}>Solve</span>
                        </div>
                        <div className="flex gap-1">
                            {[0, 1, 2].map(s => (
                                <div 
                                    key={s} 
                                    className={`h-2 flex-1 rounded-full transition-all duration-500 ${
                                        s === solveStep ? 'bg-white shadow-[0_0_10px_rgba(255,255,255,0.8)] scale-y-125' : 
                                        s < solveStep ? 'bg-slate-500' : 'bg-slate-800'
                                    }`} 
                                />
                            ))}
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={handleNextStep} 
                            disabled={solveStep === 2} 
                            className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                ${solveStep === 2 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                        >
                            {solveStep === 0 ? "Set Equation" : 
                             solveStep === 1 ? "Solve for X" : "Solved"} 
                            {solveStep < 2 && <ChevronRight size={16} />}
                        </button>
                        <button 
                            onClick={handleNextScenario} 
                            className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
                        >
                            <Shuffle size={16} /> New Problem
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
