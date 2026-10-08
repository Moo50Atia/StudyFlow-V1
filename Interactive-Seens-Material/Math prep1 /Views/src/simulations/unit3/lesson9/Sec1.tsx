import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Text, Line, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, CheckCircle, Settings2, PencilRuler, Layers } from 'lucide-react';

// ---------------------------------------------------------
// HELPER COMPONENTS & MATH
// ---------------------------------------------------------

const Arc = ({ center, r, startAngle, endAngle, pVal }: { center: THREE.Vector3, r: number, startAngle: number, endAngle: number, pVal: number }) => {
    if (pVal <= 0) return null;
    const steps = 32;
    const currentEnd = startAngle + (endAngle - startAngle) * pVal;
    
    // We must generate points inside the component because they change every frame
    const pts = [];
    for (let i = 0; i <= steps; i++) {
        const t = i / steps;
        const angle = startAngle + (currentEnd - startAngle) * t;
        pts.push(new THREE.Vector3(center.x + r * Math.cos(angle), center.y + r * Math.sin(angle), 0));
    }
    return <Line points={pts} color="#a855f7" lineWidth={3} />;
};

function getArcAngles(mode: 'topA' | 'botA' | 'topB' | 'botB') {
    const d2r = Math.PI / 180;
    if (mode === 'topA') return [15 * d2r, 75 * d2r];
    if (mode === 'botA') return [-15 * d2r, -75 * d2r];
    if (mode === 'topB') return [165 * d2r, 105 * d2r];
    if (mode === 'botB') return [195 * d2r, 255 * d2r];
    return [0, 0];
}

// ---------------------------------------------------------
// 3D SCENE COMPONENT
// ---------------------------------------------------------

function ConstructionScene({ progress, radius }: { progress: number, radius: number }) {
    const [renderTick, setRenderTick] = useState(0);
    const proxy = useRef({ p: progress, r: radius }).current;

    useFrame(() => {
        proxy.p = THREE.MathUtils.lerp(proxy.p, progress, 0.1);
        proxy.r = THREE.MathUtils.lerp(proxy.r, radius, 0.15);
        if (Math.abs(proxy.p - progress) > 0.01 || Math.abs(proxy.r - radius) > 0.01) {
            setRenderTick(t => t + 1);
        }
    });

    const p = proxy.p;
    const r = proxy.r;
    
    // Sequential Animation Mapping
    const pTopA = THREE.MathUtils.clamp((p - 0) / 12.5, 0, 1);
    const pBotA = THREE.MathUtils.clamp((p - 12.5) / 12.5, 0, 1);
    const pTopB = THREE.MathUtils.clamp((p - 25) / 12.5, 0, 1);
    const pBotB = THREE.MathUtils.clamp((p - 37.5) / 12.5, 0, 1);
    const pLine = THREE.MathUtils.clamp((p - 50) / 25, 0, 1);
    const pProp = THREE.MathUtils.clamp((p - 75) / 25, 0, 1);

    const isValid = r > 4.05; // Slightly above 4 to ensure clean intersection rendering
    const yInt = isValid ? Math.sqrt(r * r - 16) : 0;

    const A = new THREE.Vector3(-4, 0, 0);
    const B = new THREE.Vector3(4, 0, 0);

    // Compass Visualization Logic
    let compassCenter = null;
    let compassEnd = null;

    if (pTopA > 0 && pTopA < 1) {
        compassCenter = A;
        const [s, e] = getArcAngles('topA');
        const angle = s + (e - s) * pTopA;
        compassEnd = new THREE.Vector3(A.x + r * Math.cos(angle), A.y + r * Math.sin(angle), 0);
    } else if (pBotA > 0 && pBotA < 1) {
        compassCenter = A;
        const [s, e] = getArcAngles('botA');
        const angle = s + (e - s) * pBotA;
        compassEnd = new THREE.Vector3(A.x + r * Math.cos(angle), A.y + r * Math.sin(angle), 0);
    } else if (pTopB > 0 && pTopB < 1) {
        compassCenter = B;
        const [s, e] = getArcAngles('topB');
        const angle = s + (e - s) * pTopB;
        compassEnd = new THREE.Vector3(B.x + r * Math.cos(angle), B.y + r * Math.sin(angle), 0);
    } else if (pBotB > 0 && pBotB < 1) {
        compassCenter = B;
        const [s, e] = getArcAngles('botB');
        const angle = s + (e - s) * pBotB;
        compassEnd = new THREE.Vector3(B.x + r * Math.cos(angle), B.y + r * Math.sin(angle), 0);
    }

    return (
        <group position={[0, 0, 0]}>
            {/* Base Segment AB */}
            <Line points={[A, B]} color="#3b82f6" lineWidth={5} />
            <Sphere position={A} args={[0.15]}><meshBasicMaterial color="#3b82f6"/></Sphere>
            <Sphere position={B} args={[0.15]}><meshBasicMaterial color="#3b82f6"/></Sphere>
            <Text position={[-4.4, -0.5, 0]} fontSize={0.6} color="#3b82f6" fontWeight="bold">A</Text>
            <Text position={[4.4, -0.5, 0]} fontSize={0.6} color="#3b82f6" fontWeight="bold">B</Text>
            <Text position={[0, -0.5, 0]} fontSize={0.4} color="#64748b" fontWeight="bold">8 cm</Text>

            {/* Arcs */}
            <Arc center={A} r={r} startAngle={getArcAngles('topA')[0]} endAngle={getArcAngles('topA')[1]} pVal={pTopA} />
            <Arc center={A} r={r} startAngle={getArcAngles('botA')[0]} endAngle={getArcAngles('botA')[1]} pVal={pBotA} />
            <Arc center={B} r={r} startAngle={getArcAngles('topB')[0]} endAngle={getArcAngles('topB')[1]} pVal={pTopB} />
            <Arc center={B} r={r} startAngle={getArcAngles('botB')[0]} endAngle={getArcAngles('botB')[1]} pVal={pBotB} />

            {/* Compass Overlay */}
            {compassCenter && compassEnd && (
                <group>
                    <Line points={[compassCenter, compassEnd]} color="#facc15" lineWidth={3} dashed dashSize={0.2} gapSize={0.2} />
                    <Sphere position={compassEnd} args={[0.12]}><meshBasicMaterial color="#facc15" /></Sphere>
                    <Text 
                        position={[
                            (compassCenter.x + compassEnd.x)/2 - 0.5,
                            (compassCenter.y + compassEnd.y)/2 + 0.4,
                            0
                        ]} 
                        fontSize={0.4} color="#facc15" fontWeight="bold"
                    >
                        r = {r.toFixed(1)}
                    </Text>
                </group>
            )}

            {/* Intersection Points P and Q */}
            {isValid && pTopB === 1 && (
                <group>
                    <Sphere position={[0, yInt, 0]} args={[0.15]}><meshBasicMaterial color="#22c55e"/></Sphere>
                    <Text position={[0.5, yInt + 0.5, 0]} fontSize={0.5} color="#22c55e" fontWeight="bold">P</Text>
                </group>
            )}
            {isValid && pBotB === 1 && (
                <group>
                    <Sphere position={[0, -yInt, 0]} args={[0.15]}><meshBasicMaterial color="#22c55e"/></Sphere>
                    <Text position={[0.5, -yInt - 0.5, 0]} fontSize={0.5} color="#22c55e" fontWeight="bold">Q</Text>
                </group>
            )}

            {/* Perpendicular Bisector Line */}
            {isValid && pLine > 0 && (
                <Line 
                    points={[
                        new THREE.Vector3(0, yInt + 1.5, 0), 
                        new THREE.Vector3().lerpVectors(new THREE.Vector3(0, yInt + 1.5, 0), new THREE.Vector3(0, -yInt - 1.5, 0), pLine)
                    ]} 
                    color="#22c55e" 
                    lineWidth={4} 
                />
            )}

            {/* Properties Reveal (90 deg, ticks, M) */}
            {isValid && pProp > 0 && (
                <group>
                    <Line points={[[0, 0.5, 0], [0.5, 0.5, 0], [0.5, 0, 0]]} color="#22c55e" lineWidth={4} transparent opacity={pProp} />
                    
                    <Line points={[[-2, 0.3, 0], [-2, -0.3, 0]]} color="#3b82f6" lineWidth={4} transparent opacity={pProp} />
                    <Line points={[[2, 0.3, 0], [2, -0.3, 0]]} color="#3b82f6" lineWidth={4} transparent opacity={pProp} />
                    
                    <Text position={[-2, 0.7, 0]} fontSize={0.4} color="#3b82f6" fillOpacity={pProp} fontWeight="bold">4 cm</Text>
                    <Text position={[2, 0.7, 0]} fontSize={0.4} color="#3b82f6" fillOpacity={pProp} fontWeight="bold">4 cm</Text>
                    
                    <Sphere position={[0,0,0]} args={[0.12]}><meshBasicMaterial color="#22c55e" transparent opacity={pProp}/></Sphere>
                    <Text position={[-0.5, -0.5, 0]} fontSize={0.5} color="#22c55e" fillOpacity={pProp} fontWeight="bold">M</Text>
                </group>
            )}
        </group>
    );
}

// ---------------------------------------------------------
// MAIN UI COMPONENT
// ---------------------------------------------------------

export default function GeometricConstructionsSim() {
    const [radius, setRadius] = useState(5.5);
    const [progress, setProgress] = useState(0);

    const isValid = radius > 4.0;

    let alertConfig = { title: "", desc: "", colorClass: "", icon: <ShieldAlert size={18} /> };

    if (!isValid) {
        alertConfig = {
            title: "Invalid Compass Radius",
            desc: "CRITICAL MISCONCEPTION: If the compass radius is not strictly greater than half the segment length (r > 4 cm), the arcs will never intersect. Increase the radius!",
            colorClass: "bg-rose-900/40 border-l-rose-500 border-rose-800 text-rose-100",
            icon: <ShieldAlert size={18} className="text-rose-400" />
        };
    } else if (progress < 100) {
        alertConfig = {
            title: "Constructing the Bisector",
            desc: "Drawing arcs of equal radius from both endpoints guarantees that the intersection points P and Q are exactly equidistant from A and B.",
            colorClass: "bg-sky-900/40 border-l-sky-500 border-sky-800 text-sky-100",
            icon: <Settings2 size={18} className="text-sky-400" />
        };
    } else {
        alertConfig = {
            title: "Perpendicular Bisector Locked",
            desc: "SUCCESS: The line PQ perfectly splits AB into two 4 cm segments AND meets it exactly at 90°. This line is the true axis of symmetry for segment AB.",
            colorClass: "bg-green-900/40 border-l-green-500 border-green-800 text-green-100",
            icon: <CheckCircle size={18} className="text-green-400" />
        };
    }

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex flex-col gap-4 max-w-7xl mx-auto">
                    <div className="flex justify-between items-start md:items-center w-full">
                        <div>
                            <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                                Perpendicular Bisector Construction
                            </h1>
                            <p className="text-slate-400 text-sm mt-1">
                                Using a compass to find the true axis of symmetry.
                            </p>
                        </div>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl w-full flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-colors duration-500 ${!isValid ? 'border-rose-500/50' : (progress === 100 ? 'border-green-500/50' : 'border-slate-700')}`}>
                        <div className={`text-sm md:text-lg px-4 flex items-center justify-center h-full min-h-[3.5rem] w-full ${!isValid ? 'text-rose-400' : (progress === 100 ? 'text-green-400' : 'text-slate-300')}`}>
                            <BlockMath math={`\\text{Radius } (r) = ${radius.toFixed(1)} \\text{ cm} \\quad ${isValid ? '\\implies \\text{Valid Intersections (P, Q)}' : '\\implies \\text{No Intersections}'}`} />
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas camera={{ position: [0, 0, 14] }}>
                        <OrbitControls enablePan={false} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <ambientLight intensity={0.8} />
                        <mesh position={[0, 0, -2]}><planeGeometry args={[80, 80]} /><meshBasicMaterial color="#020617" /></mesh>
                        <gridHelper args={[80, 80, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />
                        
                        <ConstructionScene progress={progress} radius={radius} />
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
                    
                    <div className="mb-4 border-b border-slate-800 pb-4">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <PencilRuler size={14} /> Compass Radius (r)
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className={isValid ? "text-sky-400" : "text-rose-400"}>{radius.toFixed(1)} cm</span>
                            <span className="text-slate-500">Must be &gt; 4.0 cm</span>
                        </div>
                        <input 
                            type="range" min="3.0" max="7.0" step="0.1" 
                            value={radius} onChange={(e) => setRadius(parseFloat(e.target.value))} 
                            className={`w-full h-2 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 ${isValid ? 'accent-sky-500' : 'accent-rose-500'}`}
                        />
                    </div>

                    <div className="mb-6 border-b border-slate-800 pb-5">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <Settings2 size={14} /> Construction Progress
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className={progress === 100 ? "text-green-400" : "text-purple-400"}>{progress}% Complete</span>
                        </div>
                        <input 
                            type="range" min="0" max="100" step="1" 
                            value={progress} onChange={(e) => setProgress(parseFloat(e.target.value))} 
                            className={`w-full h-2 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 ${progress === 100 ? 'accent-green-500' : 'accent-purple-500'}`}
                        />
                    </div>

                    <button 
                        onClick={() => setProgress(progress === 100 ? 0 : 100)} 
                        className={`p-4 rounded-xl flex items-center justify-center gap-2 transition-all border text-sm font-bold shadow-sm ${
                            progress === 100 ? 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700' : 'bg-slate-800 border-slate-600 text-white hover:bg-slate-700'
                        }`}
                    >
                        <Layers size={18} /> {progress === 100 ? 'Reset Construction' : 'Auto-Complete Drawing'}
                    </button>
                    
                </div>
            </div>
        </div>
    );
}
