import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Text, Line, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, CheckCircle, Settings2, PencilRuler, Layers, MoveDown } from 'lucide-react';

// ---------------------------------------------------------
// HELPER COMPONENTS
// ---------------------------------------------------------

const ProgressArc = ({ center, r, startAngle, endAngle, pVal, color, dash = false }: { center: THREE.Vector3, r: number, startAngle: number, endAngle: number, pVal: number, color: string, dash?: boolean }) => {
    if (pVal <= 0) return null;
    const steps = 32;
    const currentEnd = startAngle + (endAngle - startAngle) * pVal;
    
    const pts = [];
    for (let i = 0; i <= steps; i++) {
        const t = i / steps;
        const angle = startAngle + (currentEnd - startAngle) * t;
        pts.push(new THREE.Vector3(center.x + r * Math.cos(angle), center.y + r * Math.sin(angle), 0));
    }
    
    return <Line points={pts} color={color} lineWidth={4} dashed={dash} dashSize={0.2} gapSize={0.2} />;
};

// ---------------------------------------------------------
// 3D SCENE COMPONENT
// ---------------------------------------------------------

function PerpendicularScene({ progress, mode }: { progress: number, mode: 'outside' | 'online' }) {
    const [renderTick, setRenderTick] = useState(0);
    const proxy = useRef({ p: progress }).current;

    useFrame(() => {
        proxy.p = THREE.MathUtils.lerp(proxy.p, progress, 0.1);
        if (Math.abs(proxy.p - progress) > 0.01) {
            setRenderTick(t => t + 1);
        }
    });

    const p = proxy.p;
    
    // Sequential Animation Mapping
    const t1 = THREE.MathUtils.clamp((p - 0) / 25, 0, 1);    // Arcs from P
    const t2 = THREE.MathUtils.clamp((p - 25) / 25, 0, 1);   // Arc from X
    const t3 = THREE.MathUtils.clamp((p - 50) / 25, 0, 1);   // Arc from Y, Reveal Q
    const t4 = THREE.MathUtils.clamp((p - 75) / 15, 0, 1);   // Line PQ
    const t5 = THREE.MathUtils.clamp((p - 90) / 10, 0, 1);   // Badges, 90 deg, Sloped comparison

    // Geometries
    const L_start = new THREE.Vector3(-8, 0, 0);
    const L_end = new THREE.Vector3(8, 0, 0);
    
    const isOutside = mode === 'outside';
    
    const P = isOutside ? new THREE.Vector3(0, 4, 0) : new THREE.Vector3(0, 0, 0);
    const X = new THREE.Vector3(-3, 0, 0);
    const Y = new THREE.Vector3(3, 0, 0);
    const Q = isOutside ? new THREE.Vector3(0, -4, 0) : new THREE.Vector3(0, 4, 0);
    const M = new THREE.Vector3(0, 0, 0);
    
    const R1 = isOutside ? 5 : 3;
    const R2 = 5;

    // PQ line logic
    const pqTarget = isOutside ? new THREE.Vector3(0, -5, 0) : new THREE.Vector3(0, 5, 0);
    const currentPQEnd = new THREE.Vector3().lerpVectors(P, pqTarget, t4);
    
    // Compass Overlay Logic
    let compassCenter = null;
    let compassEnd = null;
    
    if (t1 > 0 && t1 < 1) {
        compassCenter = P;
        if (isOutside) {
            const startA = -2.4; const endA = -0.7;
            const curA = startA + (endA - startA) * t1;
            compassEnd = new THREE.Vector3(P.x + R1 * Math.cos(curA), P.y + R1 * Math.sin(curA), 0);
        } else {
            const curA = -0.4 + 0.8 * t1;
            compassEnd = new THREE.Vector3(P.x + R1 * Math.cos(curA), P.y + R1 * Math.sin(curA), 0);
        }
    } else if (t2 > 0 && t2 < 1) {
        compassCenter = X;
        const startA = isOutside ? -0.4 : 0.4;
        const endA = isOutside ? -1.4 : 1.4;
        const curA = startA + (endA - startA) * t2;
        compassEnd = new THREE.Vector3(X.x + R2 * Math.cos(curA), X.y + R2 * Math.sin(curA), 0);
    } else if (t3 > 0 && t3 < 1) {
        compassCenter = Y;
        const startA = isOutside ? -2.7 : 2.7;
        const endA = isOutside ? -1.7 : 1.7;
        const curA = startA + (endA - startA) * t3;
        compassEnd = new THREE.Vector3(Y.x + R2 * Math.cos(curA), Y.y + R2 * Math.sin(curA), 0);
    }

    return (
        <group position={[0, -0.5, 0]}>
            {/* Line L */}
            <Line points={[L_start, L_end]} color="#3b82f6" lineWidth={5} />
            <Text position={[7.5, 0.5, 0]} fontSize={0.6} color="#3b82f6" fontWeight="bold">L</Text>
            
            {/* Point P */}
            <Sphere position={P} args={[0.15]}><meshBasicMaterial color="#a855f7"/></Sphere>
            <Text position={[P.x + 0.4, P.y + 0.4, 0]} fontSize={0.6} color="#a855f7" fontWeight="bold">P</Text>

            {/* Arcs from P */}
            {isOutside ? (
                <ProgressArc center={P} r={R1} startAngle={-2.4} endAngle={-0.7} pVal={t1} color="#a855f7" />
            ) : (
                <group>
                    <ProgressArc center={P} r={R1} startAngle={Math.PI - 0.4} endAngle={Math.PI + 0.4} pVal={t1} color="#a855f7" />
                    <ProgressArc center={P} r={R1} startAngle={-0.4} endAngle={0.4} pVal={t1} color="#a855f7" />
                </group>
            )}

            {/* Intersection X and Y */}
            {t1 === 1 && (
                <group>
                    <Sphere position={X} args={[0.12]}><meshBasicMaterial color="#ec4899"/></Sphere>
                    <Text position={[X.x - 0.3, X.y - 0.5, 0]} fontSize={0.5} color="#ec4899" fontWeight="bold">X</Text>
                    
                    <Sphere position={Y} args={[0.12]}><meshBasicMaterial color="#ec4899"/></Sphere>
                    <Text position={[Y.x + 0.3, Y.y - 0.5, 0]} fontSize={0.5} color="#ec4899" fontWeight="bold">Y</Text>
                </group>
            )}

            {/* Arcs from X and Y */}
            {isOutside ? (
                <group>
                    <ProgressArc center={X} r={R2} startAngle={-0.4} endAngle={-1.4} pVal={t2} color="#ec4899" />
                    <ProgressArc center={Y} r={R2} startAngle={-2.7} endAngle={-1.7} pVal={t3} color="#ec4899" />
                </group>
            ) : (
                <group>
                    <ProgressArc center={X} r={R2} startAngle={0.4} endAngle={1.4} pVal={t2} color="#ec4899" />
                    <ProgressArc center={Y} r={R2} startAngle={2.7} endAngle={1.7} pVal={t3} color="#ec4899" />
                </group>
            )}

            {/* Compass Visualization */}
            {compassCenter && compassEnd && (
                <group>
                    <Line points={[compassCenter, compassEnd]} color="#facc15" lineWidth={3} dashed dashSize={0.2} gapSize={0.2} />
                    <Sphere position={compassEnd} args={[0.12]}><meshBasicMaterial color="#facc15" /></Sphere>
                </group>
            )}

            {/* Point Q */}
            {t3 === 1 && (
                <group>
                    <Sphere position={Q} args={[0.15]}><meshBasicMaterial color="#22c55e"/></Sphere>
                    <Text position={[Q.x + 0.4, Q.y + (isOutside ? -0.4 : 0.4), 0]} fontSize={0.5} color="#22c55e" fontWeight="bold">Q</Text>
                </group>
            )}

            {/* Perpendicular Line PQ */}
            {t4 > 0 && (
                <Line points={[P, currentPQEnd]} color="#22c55e" lineWidth={5} />
            )}
            
            {/* Extended ray for outside mode upwards for visual completeness */}
            {isOutside && t4 > 0 && (
                 <Line points={[P, new THREE.Vector3().lerpVectors(P, new THREE.Vector3(0, 6, 0), t4)]} color="#22c55e" lineWidth={5} />
            )}

            {/* Final Reveal */}
            {t5 > 0 && (
                <group>
                    {/* 90 deg box */}
                    <Line points={[
                        [0, 0.6, 0],
                        [0.6, 0.6, 0],
                        [0.6, 0, 0]
                    ]} color="#22c55e" lineWidth={3} transparent opacity={t5} />
                    
                    <Sphere position={M} args={[0.15]}><meshBasicMaterial color="#22c55e" transparent opacity={t5}/></Sphere>
                    <Text position={[-0.5, -0.5, 0]} fontSize={0.6} color="#22c55e" fillOpacity={t5} fontWeight="bold">M</Text>

                    {/* Shortest Distance vs Sloped Line comparison (only outside mode) */}
                    {isOutside && (
                        <group>
                            {/* The PM segment highlight */}
                            <Line points={[P, M]} color="#22c55e" lineWidth={10} transparent opacity={0.4 * t5} />
                            <Text position={[-1.2, 2, 0]} fontSize={0.45} color="#22c55e" fillOpacity={t5} fontWeight="extrabold">4 cm</Text>
                            
                            <group position={[-1.2, 1.4, 0]}>
                                <mesh><planeGeometry args={[2.5, 0.6]}/><meshBasicMaterial color="#064e3b" transparent opacity={t5} /></mesh>
                                <Text position={[0, 0, 0.1]} fontSize={0.3} color="#4ade80" fillOpacity={t5} fontWeight="bold">SHORTEST</Text>
                            </group>

                            {/* Sloped line PY */}
                            <Line points={[P, Y]} color="#ef4444" lineWidth={4} dashed dashSize={0.3} gapSize={0.3} transparent opacity={t5} />
                            <Text position={[2, 2.3, 0]} fontSize={0.45} color="#ef4444" fillOpacity={t5} fontWeight="extrabold">5 cm</Text>
                        </group>
                    )}
                </group>
            )}
        </group>
    );
}

// ---------------------------------------------------------
// MAIN UI COMPONENT
// ---------------------------------------------------------

export default function PerpendicularConstructionSim() {
    const [mode, setMode] = useState<'outside'|'online'>('outside');
    const [progress, setProgress] = useState(0);

    useEffect(() => {
        setProgress(0);
    }, [mode]);

    const isComplete = progress === 100;

    const getAlertConfig = () => {
        if (progress < 25) {
            return {
                title: "Finding Equidistant Anchors",
                desc: "We start by drawing an arc from point P across line L. This guarantees that intersection points X and Y are exactly the same distance from P.",
                colorClass: "bg-purple-900/40 border-l-purple-500 border-purple-800 text-purple-100",
                icon: <Settings2 size={18} className="text-purple-400" />
            };
        } else if (progress < 75) {
            return {
                title: "Intersecting Arcs",
                desc: "By keeping the compass radius fixed and drawing arcs from X and Y, we find point Q. The symmetry perfectly mirrors the geometry across the horizontal axis.",
                colorClass: "bg-pink-900/40 border-l-pink-500 border-pink-800 text-pink-100",
                icon: <PencilRuler size={18} className="text-pink-400" />
            };
        } else if (progress < 100) {
            return {
                title: "Constructing the Perpendicular",
                desc: "Connecting P and Q creates a line that perfectly splits the angle, forcing the intersection at line L to be exactly 90 degrees.",
                colorClass: "bg-sky-900/40 border-l-sky-500 border-sky-800 text-sky-100",
                icon: <MoveDown size={18} className="text-sky-400" />
            };
        } else {
            if (mode === 'outside') {
                return {
                    title: "The Shortest Distance Fallacy",
                    desc: "CRITICAL MISCONCEPTION: A sloped line from P to Y measures 5 cm. But the perpendicular drop (PM) measures exactly 4 cm. A 90° perpendicular is ALWAYS the shortest possible path from a point to a line.",
                    colorClass: "bg-rose-900/40 border-l-rose-500 border-rose-800 text-rose-100",
                    icon: <ShieldAlert size={18} className="text-rose-400" />
                };
            } else {
                return {
                    title: "Perpendicular Locked",
                    desc: "SUCCESS: By creating points X and Y perfectly mirrored around P, the resulting intersection Q constructs a flawless 90° angle precisely at point P on the line.",
                    colorClass: "bg-green-900/40 border-l-green-500 border-green-800 text-green-100",
                    icon: <CheckCircle size={18} className="text-green-400" />
                };
            }
        }
    };

    const alertConfig = getAlertConfig();

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex flex-col gap-4 max-w-7xl mx-auto">
                    <div className="flex justify-between items-start md:items-center w-full">
                        <div>
                            <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                                Perpendicular from a Point
                            </h1>
                            <p className="text-slate-400 text-sm mt-1">
                                Constructing flawless 90° intersections using compass symmetry.
                            </p>
                        </div>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl w-full flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-colors duration-500 ${isComplete ? 'border-green-500/50' : 'border-slate-700'}`}>
                        <div className={`text-sm md:text-lg px-4 flex items-center justify-center h-full min-h-[3.5rem] w-full ${isComplete ? 'text-green-400' : 'text-slate-300'}`}>
                            {mode === 'outside' 
                                ? <BlockMath math={`\\text{Line } PM \\perp \\text{Line } L \\implies PM \\text{ is the absolute minimum distance.}`} />
                                : <BlockMath math={`\\text{Line } PQ \\perp \\text{Line } L \\text{ exactly at point } P`} />
                            }
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas camera={{ position: [0, 0, 15] }}>
                        <OrbitControls enablePan={false} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <ambientLight intensity={0.8} />
                        <mesh position={[0, 0, -2]}><planeGeometry args={[80, 80]} /><meshBasicMaterial color="#020617" /></mesh>
                        <gridHelper args={[80, 80, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />
                        
                        <PerpendicularScene progress={progress} mode={mode} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                {/* Dynamic Alert Panel */}
                <div className="flex-1 pointer-events-auto max-w-md w-full">
                    <div className={`border-l-4 border-t border-r border-b p-5 rounded-2xl shadow-2xl backdrop-blur-md transition-all duration-500 ${alertConfig.colorClass}`}>
                        <h3 className="font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide">
                            {alertConfig.icon} 
                            {alertConfig.title}
                        </h3>
                        <p className="text-sm leading-relaxed drop-shadow-md">
                            {alertConfig.desc}
                        </p>
                    </div>
                </div>

                {/* Control Panel */}
                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
                    
                    <div className="flex bg-slate-950 p-1 rounded-lg mb-6 border border-slate-800">
                        <button 
                            onClick={() => setMode('outside')} 
                            className={`flex-1 py-2 text-xs font-bold rounded-md transition-colors ${mode === 'outside' ? 'bg-indigo-600/80 text-white shadow-sm' : 'text-slate-400 hover:bg-slate-800'}`}
                        >
                            External Point (P off L)
                        </button>
                        <button 
                            onClick={() => setMode('online')} 
                            className={`flex-1 py-2 text-xs font-bold rounded-md transition-colors ${mode === 'online' ? 'bg-cyan-600/80 text-white shadow-sm' : 'text-slate-400 hover:bg-slate-800'}`}
                        >
                            Internal Point (P on L)
                        </button>
                    </div>

                    <div className="mb-6 border-b border-slate-800 pb-5">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <PencilRuler size={14} /> Construction Progress
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className={isComplete ? "text-green-400" : "text-purple-400"}>{progress}% Complete</span>
                        </div>
                        <input 
                            type="range" min="0" max="100" step="1" 
                            value={progress} onChange={(e) => setProgress(parseFloat(e.target.value))} 
                            className={`w-full h-2 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 ${isComplete ? 'accent-green-500' : 'accent-purple-500'}`}
                        />
                    </div>

                    <button 
                        onClick={() => setProgress(isComplete ? 0 : 100)} 
                        className={`p-4 rounded-xl flex items-center justify-center gap-2 transition-all border text-sm font-bold shadow-sm ${
                            isComplete ? 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700' : 'bg-slate-800 border-slate-600 text-white hover:bg-slate-700'
                        }`}
                    >
                        <Layers size={18} /> {isComplete ? 'Reset Construction' : 'Auto-Complete Drawing'}
                    </button>
                    
                </div>
            </div>
        </div>
    );
}
