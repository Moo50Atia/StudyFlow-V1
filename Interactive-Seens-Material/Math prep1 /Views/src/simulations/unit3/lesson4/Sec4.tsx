import React, { useState, useMemo, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, ArrowRight, Shapes } from 'lucide-react';

// ---------------------------------------------------------
// 3D HELPERS
// ---------------------------------------------------------

const Edge = ({ p1, p2, color = "#94a3b8" }: { p1: THREE.Vector3, p2: THREE.Vector3, color?: string }) => {
    const dist = p1.distanceTo(p2);
    const mid = new THREE.Vector3().addVectors(p1, p2).multiplyScalar(0.5);
    const quaternion = new THREE.Quaternion().setFromUnitVectors(new THREE.Vector3(0, 1, 0), p2.clone().sub(p1).normalize());
    
    return (
        <mesh position={mid} quaternion={quaternion} castShadow>
            <cylinderGeometry args={[0.04, 0.04, dist]} />
            <meshStandardMaterial color={color} roughness={0.3} metalness={0.2} />
        </mesh>
    );
};

const Vertex = ({ p, label, offset = [0, 0.5, 0] }: { p: THREE.Vector3, label: string, offset?: [number, number, number] }) => (
    <group position={p}>
        <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
        <Text position={offset} fontSize={0.4} color="#ffffff" fontWeight="bold">{label}</Text>
    </group>
);

const StaticArc = ({ startDeg, endDeg, radius, p, color }: { startDeg: number, endDeg: number, radius: number, p: THREE.Vector3, color: string }) => {
    const geom = useMemo(() => {
        const g = new THREE.BufferGeometry();
        g.setAttribute('position', new THREE.BufferAttribute(new Float32Array(65 * 3), 3));
        const positions = g.attributes.position.array as Float32Array;
        let s = startDeg * Math.PI / 180;
        let e = endDeg * Math.PI / 180;
        if (e < s) e += Math.PI * 2;
        
        const delta = e - s;
        for (let i = 0; i <= 64; i++) {
            const theta = s + (i / 64) * delta;
            positions[i * 3] = Math.cos(theta) * radius;
            positions[i * 3 + 1] = Math.sin(theta) * radius;
            positions[i * 3 + 2] = 0;
        }
        g.setDrawRange(0, 65);
        return g;
    }, [startDeg, endDeg, radius]);

    return (
        <line geometry={geom} position={[p.x, p.y, 0.05]}>
            <lineBasicMaterial color={color} linewidth={5} transparent opacity={0.9} />
        </line>
    );
};

const ArcText = ({ startDeg, endDeg, radius, p, text, color }: { startDeg: number, endDeg: number, radius: number, p: THREE.Vector3, text: string, color: string }) => {
    let s = startDeg * Math.PI / 180;
    let e = endDeg * Math.PI / 180;
    if (e < s) e += Math.PI * 2;
    const mid = (s + e) / 2;
    const tx = p.x + Math.cos(mid) * radius;
    const ty = p.y + Math.sin(mid) * radius;
    return (
        <Text position={[tx, ty, 0.05]} fontSize={0.45} color={color} fontWeight="bold" anchorX="center" anchorY="middle">
            {text}
        </Text>
    );
};

// ---------------------------------------------------------
// MAIN SCENE
// ---------------------------------------------------------

function Scene({ step }: { step: number }) {
    // Twin Triangles Coordinates
    const a = useMemo(() => new THREE.Vector3(-3.66, 6.18, 0), []);
    const b = useMemo(() => new THREE.Vector3(-5.5, 3, 0), []);
    const c = useMemo(() => new THREE.Vector3(-2.5, 3, 0), []);

    const d = useMemo(() => new THREE.Vector3(-3.61, 2.14, 0), []);
    const e = useMemo(() => new THREE.Vector3(-6, -2, 0), []);
    const f = useMemo(() => new THREE.Vector3(-2.1, -2, 0), []);

    // Equilateral Triangle Coordinates
    const g = useMemo(() => new THREE.Vector3(4, 2.464, 0), []);
    const h = useMemo(() => new THREE.Vector3(2, -1, 0), []);
    const i = useMemo(() => new THREE.Vector3(6, -1, 0), []);
    const j = useMemo(() => new THREE.Vector3(8.5, -1, 0), []);

    return (
        <group position={[0, -1.5, 0]}>
            <ambientLight intensity={0.6} />
            <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow />
            <Environment preset="city" opacity={0.3} />
            
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[50, 50]} />
                <meshStandardMaterial color="#020617" />
            </mesh>
            <gridHelper args={[50, 50, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* Edges ABC */}
            <Edge p1={a} p2={b} />
            <Edge p1={b} p2={c} />
            <Edge p1={c} p2={a} />
            
            {/* Edges DEF */}
            <Edge p1={d} p2={e} />
            <Edge p1={e} p2={f} />
            <Edge p1={f} p2={d} />

            {/* Edges GHI */}
            <Edge p1={g} p2={h} />
            <Edge p1={h} p2={i} />
            <Edge p1={i} p2={g} />
            <Edge p1={i} p2={j} color="#facc15" /> {/* Extended base */}

            {/* Vertices ABC */}
            <Vertex p={a} label="A" offset={[0, 0.5, 0]} />
            <Vertex p={b} label="B" offset={[-0.5, -0.5, 0]} />
            <Vertex p={c} label="C" offset={[0.5, -0.5, 0]} />

            {/* Vertices DEF */}
            <Vertex p={d} label="D" offset={[0, 0.5, 0]} />
            <Vertex p={e} label="E" offset={[-0.5, -0.5, 0]} />
            <Vertex p={f} label="F" offset={[0.5, -0.5, 0]} />

            {/* Vertices GHI */}
            <Vertex p={g} label="G" offset={[0, 0.5, 0]} />
            <Vertex p={h} label="H" offset={[-0.5, -0.5, 0]} />
            <Vertex p={i} label="I" offset={[0, -0.6, 0]} />

            {/* Arcs */}
            <StaticArc startDeg={240} endDeg={290} radius={1.0} p={a} color="#3b82f6" />
            <StaticArc startDeg={0} endDeg={60} radius={1.0} p={b} color="#22c55e" />
            <StaticArc startDeg={110} endDeg={180} radius={1.0} p={c} color="#f97316" />

            <StaticArc startDeg={240} endDeg={290} radius={1.0} p={d} color="#3b82f6" />
            <StaticArc startDeg={0} endDeg={60} radius={1.0} p={e} color="#22c55e" />
            <StaticArc startDeg={110} endDeg={180} radius={1.0} p={f} color="#f97316" />

            <StaticArc startDeg={240} endDeg={300} radius={1.0} p={g} color="#22c55e" />
            <StaticArc startDeg={0} endDeg={60} radius={1.0} p={h} color="#22c55e" />
            <StaticArc startDeg={120} endDeg={180} radius={1.0} p={i} color="#22c55e" />

            <StaticArc startDeg={0} endDeg={120} radius={1.2} p={i} color="#facc15" />

            {/* Texts */}
            <ArcText startDeg={240} endDeg={290} radius={1.6} p={a} text="50°" color="#3b82f6" />
            <ArcText startDeg={0} endDeg={60} radius={1.6} p={b} text="60°" color="#22c55e" />
            <ArcText startDeg={110} endDeg={180} radius={1.6} p={c} text={step >= 1 ? "70°" : "?"} color="#f97316" />

            <ArcText startDeg={240} endDeg={290} radius={1.6} p={d} text="50°" color="#3b82f6" />
            <ArcText startDeg={0} endDeg={60} radius={1.6} p={e} text="60°" color="#22c55e" />
            <ArcText startDeg={110} endDeg={180} radius={1.6} p={f} text={step >= 1 ? "70°" : "?"} color="#f97316" />

            <ArcText startDeg={240} endDeg={300} radius={1.6} p={g} text="60°" color="#22c55e" />
            <ArcText startDeg={0} endDeg={60} radius={1.6} p={h} text="60°" color="#22c55e" />
            <ArcText startDeg={120} endDeg={180} radius={1.6} p={i} text="60°" color="#22c55e" />

            <ArcText startDeg={0} endDeg={120} radius={1.8} p={i} text={step >= 2 ? "120°" : "?"} color="#facc15" />

            {/* Equality Badge (Between Twin Triangles) */}
            {step >= 1 && (
                <group position={[-4.0, 2.5, 0]}>
                    <mesh>
                        <planeGeometry args={[3, 1]} />
                        <meshStandardMaterial color="#f97316" transparent opacity={0.2} />
                    </mesh>
                    <Text position={[0, 0, 0.01]} fontSize={0.4} color="#f97316" fontWeight="bold">
                        m∠C = m∠F
                    </Text>
                </group>
            )}

            {/* Ext Equation Badge (Above Exterior Angle) */}
            {step >= 2 && (
                <group position={[6.5, 1.5, 0]}>
                    <mesh>
                        <planeGeometry args={[3.5, 1]} />
                        <meshStandardMaterial color="#facc15" transparent opacity={0.2} />
                    </mesh>
                    <Text position={[0, 0, 0.01]} fontSize={0.4} color="#facc15" fontWeight="bold">
                        60° + 60° = 120°
                    </Text>
                </group>
            )}
        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function SpecialCases() {
    const [step, setStep] = useState(0); 

    const handleNext = () => {
        if (step < 2) setStep(prev => prev + 1);
    };

    const renderMath = () => {
        if (step === 0) {
            return <BlockMath math={`m\\angle A = m\\angle D = 50^\\circ \\quad m\\angle B = m\\angle E = 60^\\circ`} />;
        }
        if (step === 1) {
            return <BlockMath math={`180^\\circ - (50^\\circ + 60^\\circ) = 70^\\circ \\implies m\\angle C = m\\angle F`} />;
        }
        return <BlockMath math={`m\\angle C = m\\angle F \\quad \\text{Ext} = 60^\\circ + 60^\\circ = 120^\\circ`} />;
    };

    const getAlertContent = () => {
        if (step < 2) {
            return { 
                title: "Third Angle Theorem", 
                icon: <Shapes size={18}/>, 
                color: "border-l-blue-500 text-blue-300 bg-blue-900/40", 
                body: <>If two angles of one triangle are congruent to two angles of another triangle, then the third angles must also be exactly congruent, because both sets pull from the same 180° total.</> 
            };
        }
        return { 
            title: "Equilateral Exterior Misconception", 
            icon: <ShieldAlert size={18}/>, 
            color: "border-l-rose-500 text-rose-300 bg-rose-900/40", 
            body: <><b>CRITICAL MISCONCEPTION:</b> Students often assume the exterior angle of an equilateral triangle is 60° (matching its interior). However, the exterior angle theorem dictates it must equal the sum of the two remote interiors (60° + 60° = 120°).</> 
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
                            Special Cases & Corollaries
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Third Angle equality and Equilateral exteriors.
                        </p>
                    </div>
                    <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[400px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-all duration-500">
                        <div className="text-white text-lg px-4 flex items-center justify-center h-full min-h-[4rem]">
                            {renderMath()}
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas shadows camera={{ position: [1, 1, 14] }}>
                        <OrbitControls enablePan={false} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <Scene step={step} />
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
                    
                    <div className="flex flex-col gap-4 mb-4">
                        <div className="bg-slate-950 p-4 rounded-xl border border-slate-800">
                            <h4 className="text-sm font-bold text-slate-300 mb-2">Evaluation Nodes:</h4>
                            <ul className="text-xs text-slate-400 space-y-2">
                                <li className={`flex justify-between items-center ${step >= 1 ? 'text-orange-400' : ''}`}>
                                    <span>1. Twin Triangle Unknowns (∠C, ∠F)</span>
                                    <span>{step >= 1 ? '70°' : '?'}</span>
                                </li>
                                <li className={`flex justify-between items-center ${step >= 2 ? 'text-yellow-400' : ''}`}>
                                    <span>2. Equilateral Exterior Angle</span>
                                    <span>{step >= 2 ? '120°' : '?'}</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="grid grid-cols-1 gap-3 mt-auto">
                        <button 
                            onClick={handleNext} 
                            disabled={step >= 2} 
                            className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                ${step >= 2 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                        >
                            {step === 0 ? "Evaluate Third Angles" : step === 1 ? "Evaluate Exterior Angle" : "Evaluation Complete"} 
                            {step < 2 && <ArrowRight size={16} />}
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
