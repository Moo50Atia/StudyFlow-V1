import React, { useState, useRef, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Text, Line, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, CheckCircle, Settings2, PencilRuler, Layers, MoveUpRight } from 'lucide-react';

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

const AngleSector = ({ center, radius, startAngle, endAngle, color, opacity }: { center: THREE.Vector3, radius: number, startAngle: number, endAngle: number, color: string, opacity: number }) => {
    const geom = useMemo(() => {
        const shape = new THREE.Shape();
        shape.moveTo(0, 0);
        shape.arc(0, 0, radius, startAngle, endAngle, false);
        shape.lineTo(0, 0);
        return new THREE.ShapeGeometry(shape);
    }, [radius, startAngle, endAngle]);

    if (opacity <= 0) return null;

    return (
        <mesh geometry={geom} position={[center.x, center.y, -0.05]}>
            <meshBasicMaterial color={color} transparent opacity={opacity} side={THREE.DoubleSide} />
        </mesh>
    );
};

const ParallelChevrons = ({ position, opacity }: { position: [number, number, number], opacity: number }) => {
    if (opacity <= 0) return null;
    return (
        <group position={position}>
            <Line points={[[-0.4, 0.4, 0], [0.2, 0, 0], [-0.4, -0.4, 0]]} color="#facc15" lineWidth={4} transparent opacity={opacity} />
            <Line points={[[0.2, 0.4, 0], [0.8, 0, 0], [0.2, -0.4, 0]]} color="#facc15" lineWidth={4} transparent opacity={opacity} />
        </group>
    );
};

// ---------------------------------------------------------
// 3D SCENE COMPONENT
// ---------------------------------------------------------

function ParallelScene({ progress, angleDeg }: { progress: number, angleDeg: number }) {
    const proxy = useRef({ p: progress, theta: angleDeg * (Math.PI / 180) }).current;
    const [, setRenderTick] = useState(0);

    useFrame(() => {
        proxy.p = THREE.MathUtils.lerp(proxy.p, progress, 0.1);
        proxy.theta = THREE.MathUtils.lerp(proxy.theta, angleDeg * (Math.PI / 180), 0.1);
        if (Math.abs(proxy.p - progress) > 0.01 || Math.abs(proxy.theta - angleDeg * (Math.PI / 180)) > 0.001) {
            setRenderTick(t => t + 1);
        }
    });

    const p = proxy.p;
    const theta = proxy.theta;
    
    // Sequential Animation Mapping
    const t1 = THREE.MathUtils.clamp((p - 0) / 15, 0, 1);    // Transversal T
    const t2 = THREE.MathUtils.clamp((p - 15) / 15, 0, 1);   // Arc at A
    const t3 = THREE.MathUtils.clamp((p - 30) / 15, 0, 1);   // Arc at P
    const t4 = THREE.MathUtils.clamp((p - 45) / 15, 0, 1);   // Measure & Arc at M'
    const t5 = THREE.MathUtils.clamp((p - 60) / 15, 0, 1);   // Line M
    const t6 = THREE.MathUtils.clamp((p - 75) / 25, 0, 1);   // Z-Shape & Badges

    // Geometries
    const R = 3.5;
    
    const L_y = -2;
    const P_y = 2;
    const P = new THREE.Vector3(0, P_y, 0);
    
    // A is the intersection of transversal and Line L
    // P is at (0, 2). Line goes through P with angle theta.
    // L is at y = -2.
    // tan(theta) = (P_y - L_y) / (P_x - A_x) => tan(theta) = 4 / (0 - A_x) => A_x = -4 / tan(theta)
    const A_x = -4 / Math.tan(theta);
    const A = new THREE.Vector3(A_x, L_y, 0);
    
    // Transversal Endpoints (extended for visuals)
    const extT_start = new THREE.Vector3(P.x + 3 * Math.cos(theta), P.y + 3 * Math.sin(theta), 0);
    const extT_end = new THREE.Vector3(A.x - 3 * Math.cos(theta), A.y - 3 * Math.sin(theta), 0);
    const currentT = new THREE.Vector3().lerpVectors(extT_start, extT_end, t1);

    // Intersections on Transversal and Line L (Arc A)
    const X = new THREE.Vector3(A.x + R, A.y, 0); // On Line L
    const Y = new THREE.Vector3(A.x + R * Math.cos(theta), A.y + R * Math.sin(theta), 0); // On Transversal AP
    const distXY = X.distanceTo(Y);
    
    // Intersections for Alternate Angle at P (Arc P)
    // To copy angle 1 (at A) to alternate interior angle 2 (at P):
    // The angle at P is measured from PA down to the left parallel.
    // PA vector has angle theta + Math.PI. Parallel left has angle Math.PI.
    const Mprime = new THREE.Vector3(P.x - R * Math.cos(theta), P.y - R * Math.sin(theta), 0); // On PA
    const N = new THREE.Vector3(P.x - R, P.y, 0); // Final point on Line M

    const angleMprimeToN = Math.atan2(N.y - Mprime.y, N.x - Mprime.x);

    // Line M extensions
    const lineM_left = new THREE.Vector3(P.x - 8, P.y, 0);
    const lineM_right = new THREE.Vector3(P.x + 8, P.y, 0);
    const curM_left = new THREE.Vector3().lerpVectors(P, lineM_left, t5);
    const curM_right = new THREE.Vector3().lerpVectors(P, lineM_right, t5);

    // Dynamic Compass Overlay
    let compassCenter = null;
    let compassEnd = null;
    let compassLabel = "";

    if (t2 > 0 && t2 < 1) {
        compassCenter = A;
        const curA = 0 + (theta - 0) * t2;
        compassEnd = new THREE.Vector3(A.x + R * Math.cos(curA), A.y + R * Math.sin(curA), 0);
        compassLabel = "Radius R";
    } else if (t3 > 0 && t3 < 1) {
        compassCenter = P;
        const startAng = Math.PI + theta;
        const endAng = Math.PI;
        const curA = startAng + (endAng - startAng) * t3;
        compassEnd = new THREE.Vector3(P.x + R * Math.cos(curA), P.y + R * Math.sin(curA), 0);
        compassLabel = "Radius R";
    } else if (t4 > 0 && t4 < 1) {
        compassCenter = Mprime;
        const startAng = angleMprimeToN + 0.4;
        const endAng = angleMprimeToN - 0.4;
        const curA = startAng + (endAng - startAng) * t4;
        compassEnd = new THREE.Vector3(Mprime.x + distXY * Math.cos(curA), Mprime.y + distXY * Math.sin(curA), 0);
        compassLabel = "Radius XY";
    }

    return (
        <group position={[0, 0, 0]}>
            {/* Base Line L and Point P */}
            <Line points={[[-10, L_y, 0], [10, L_y, 0]]} color="#3b82f6" lineWidth={5} />
            <Text position={[9, L_y + 0.5, 0]} fontSize={0.6} color="#3b82f6" fontWeight="bold">L</Text>
            
            <Sphere position={P} args={[0.15]}><meshBasicMaterial color="#a855f7"/></Sphere>
            <Text position={[P.x + 0.4, P.y + 0.4, 0]} fontSize={0.6} color="#a855f7" fontWeight="bold">P</Text>

            {/* Transversal T */}
            {t1 > 0 && (
                <group>
                    <Line points={[extT_start, currentT]} color="#64748b" lineWidth={4} />
                    {t1 === 1 && (
                        <group>
                            <Sphere position={A} args={[0.15]}><meshBasicMaterial color="#64748b"/></Sphere>
                            <Text position={[A.x - 0.4, A.y - 0.5, 0]} fontSize={0.5} color="#64748b" fontWeight="bold">A</Text>
                            <Text position={[extT_start.x, extT_start.y + 0.5, 0]} fontSize={0.5} color="#64748b" fontWeight="bold">T</Text>
                        </group>
                    )}
                </group>
            )}

            {/* Arc at A */}
            <ProgressArc center={A} r={R} startAngle={0} endAngle={theta} pVal={t2} color="#a855f7" />
            {t2 === 1 && (
                <group>
                    <Sphere position={X} args={[0.12]}><meshBasicMaterial color="#a855f7"/></Sphere>
                    <Text position={[X.x + 0.3, X.y - 0.4, 0]} fontSize={0.4} color="#a855f7" fontWeight="bold">X</Text>
                    <Sphere position={Y} args={[0.12]}><meshBasicMaterial color="#a855f7"/></Sphere>
                    <Text position={[Y.x - 0.4, Y.y + 0.3, 0]} fontSize={0.4} color="#a855f7" fontWeight="bold">Y</Text>
                </group>
            )}

            {/* Arc at P */}
            <ProgressArc center={P} r={R} startAngle={Math.PI + theta} endAngle={Math.PI} pVal={t3} color="#a855f7" />
            {t3 === 1 && (
                <group>
                    <Sphere position={Mprime} args={[0.12]}><meshBasicMaterial color="#a855f7"/></Sphere>
                    <Text position={[Mprime.x + 0.4, Mprime.y - 0.2, 0]} fontSize={0.4} color="#a855f7" fontWeight="bold">M'</Text>
                </group>
            )}

            {/* Measuring XY & Arc at M' */}
            {t4 > 0 && (
                <Line points={[X, Y]} color="#facc15" lineWidth={3} dashed dashSize={0.2} gapSize={0.2} transparent opacity={0.5} />
            )}
            <ProgressArc center={Mprime} r={distXY} startAngle={angleMprimeToN + 0.4} endAngle={angleMprimeToN - 0.4} pVal={t4} color="#ec4899" />
            {t4 === 1 && (
                <group>
                    <Sphere position={N} args={[0.12]}><meshBasicMaterial color="#ec4899"/></Sphere>
                    <Text position={[N.x - 0.4, N.y + 0.3, 0]} fontSize={0.4} color="#ec4899" fontWeight="bold">N</Text>
                </group>
            )}

            {/* Line M (Parallel) */}
            {t5 > 0 && (
                <group>
                    <Line points={[P, curM_left]} color="#22c55e" lineWidth={5} />
                    <Line points={[P, curM_right]} color="#22c55e" lineWidth={5} />
                    <Text position={[-9, P.y + 0.5, 0]} fontSize={0.6} color="#22c55e" fontWeight="bold">M</Text>
                </group>
            )}

            {/* Global Compass Overlay */}
            {compassCenter && compassEnd && (
                <group>
                    <Line points={[compassCenter, compassEnd]} color="#facc15" lineWidth={3} dashed dashSize={0.2} gapSize={0.2} />
                    <Sphere position={compassEnd} args={[0.12]}><meshBasicMaterial color="#facc15" /></Sphere>
                    <Text 
                        position={[
                            (compassCenter.x + compassEnd.x)/2,
                            (compassCenter.y + compassEnd.y)/2 + 0.4,
                            0
                        ]} 
                        fontSize={0.4} color="#facc15" fontWeight="bold"
                    >
                        {compassLabel}
                    </Text>
                </group>
            )}

            {/* Final Reveal: Z-Shape and Parallel Markers */}
            {t6 > 0 && (
                <group>
                    {/* Angle Sectors */}
                    <AngleSector center={A} radius={1.5} startAngle={0} endAngle={theta} color="#3b82f6" opacity={0.4 * t6} />
                    <AngleSector center={P} radius={1.5} startAngle={Math.PI} endAngle={Math.PI + theta} color="#22c55e" opacity={0.4 * t6} />

                    {/* Angle Labels */}
                    <Text position={[A.x + 2.2 * Math.cos(theta/2), A.y + 2.2 * Math.sin(theta/2), 0.1]} fontSize={0.4} color="#60a5fa" fillOpacity={t6} fontWeight="extrabold">∠1</Text>
                    <Text position={[P.x + 2.2 * Math.cos(Math.PI + theta/2), P.y + 2.2 * Math.sin(Math.PI + theta/2), 0.1]} fontSize={0.4} color="#4ade80" fillOpacity={t6} fontWeight="extrabold">∠2</Text>

                    {/* Yellow Z-Shape Wireframe */}
                    <Line 
                        points={[
                            [P.x - 6, P.y, 0],
                            [P.x, P.y, 0],
                            [A.x, A.y, 0],
                            [A.x + 6, A.y, 0]
                        ]} 
                        color="#facc15" 
                        lineWidth={8} 
                        transparent 
                        opacity={0.6 * t6} 
                    />

                    {/* Parallel Arrow Markers */}
                    <ParallelChevrons position={[A.x + 4.5, L_y, 0.1]} opacity={t6} />
                    <ParallelChevrons position={[P.x - 4.5, P_y, 0.1]} opacity={t6} />
                </group>
            )}
        </group>
    );
}

// ---------------------------------------------------------
// MAIN UI COMPONENT
// ---------------------------------------------------------

export default function ParallelConstructionSim() {
    const [angle, setAngle] = useState(60);
    const [progress, setProgress] = useState(0);

    const isComplete = progress === 100;

    const getAlertConfig = () => {
        if (progress < 15) {
            return {
                title: "The Transversal Baseline",
                desc: "We start by drawing a transversal line T through point P down to our base line L. This creates an intersection at A, giving us our first reference angle (∠1).",
                colorClass: "bg-slate-900/40 border-l-slate-500 border-slate-800 text-slate-100",
                icon: <Settings2 size={18} className="text-slate-400" />
            };
        } else if (progress < 45) {
            return {
                title: "Duplicating the Angle Arc",
                desc: "To create a parallel line, we must perfectly copy ∠1. We sweep an identical radius arc at A and at P. This creates corresponding geometric anchors.",
                colorClass: "bg-purple-900/40 border-l-purple-500 border-purple-800 text-purple-100",
                icon: <Layers size={18} className="text-purple-400" />
            };
        } else if (progress < 75) {
            return {
                title: "Transferring the Chord",
                desc: "By measuring the distance XY and sweeping that exact radius from M', we find point N. This forces the new angle at P to be completely identical to ∠1.",
                colorClass: "bg-pink-900/40 border-l-pink-500 border-pink-800 text-pink-100",
                icon: <PencilRuler size={18} className="text-pink-400" />
            };
        } else {
            return {
                title: "Parallel Geometry Locked",
                desc: `SUCCESS: Because Alternate Interior Angles are perfectly equal (∠1 ≅ ∠2), the Alternate Interior Angles Converse Theorem absolutely guarantees that Line M is parallel to Line L. Freehanding is a guess; construction is proof.`,
                colorClass: "bg-green-900/40 border-l-green-500 border-green-800 text-green-100",
                icon: <CheckCircle size={18} className="text-green-400" />
            };
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
                                Parallel Line Construction
                            </h1>
                            <p className="text-slate-400 text-sm mt-1">
                                Constructing perfectly parallel lines via alternate interior angles.
                            </p>
                        </div>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl w-full flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-colors duration-500 ${isComplete ? 'border-green-500/50' : 'border-slate-700'}`}>
                        <div className={`text-sm md:text-lg px-4 flex items-center justify-center h-full min-h-[3.5rem] w-full ${isComplete ? 'text-green-400' : 'text-slate-300'}`}>
                            <BlockMath math={`\\angle 1 \\cong \\angle 2 \\implies \\text{Line } M \\parallel \\text{Line } L`} />
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
                        
                        <ParallelScene progress={progress} angleDeg={angle} />
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
                            <MoveUpRight size={14} /> Transversal Angle (∠1)
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className="text-sky-400">{angle}°</span>
                        </div>
                        <input 
                            type="range" min="45" max="135" step="1" 
                            value={angle} onChange={(e) => setAngle(parseFloat(e.target.value))} 
                            className="w-full h-2 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 accent-sky-500"
                        />
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
