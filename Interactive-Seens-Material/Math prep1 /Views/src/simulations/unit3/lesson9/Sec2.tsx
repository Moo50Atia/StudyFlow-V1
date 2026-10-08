import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Text, Line, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, CheckCircle, Settings2, PencilRuler, Layers, Star } from 'lucide-react';

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

const AngleSector = ({ radius, startAngle, endAngle, color, opacity }: { radius: number, startAngle: number, endAngle: number, color: string, opacity: number }) => {
    const geom = useMemo(() => {
        const shape = new THREE.Shape();
        shape.moveTo(0, 0);
        shape.arc(0, 0, radius, startAngle, endAngle, false);
        shape.lineTo(0, 0);
        return new THREE.ShapeGeometry(shape);
    }, [radius, startAngle, endAngle]);

    if (opacity <= 0) return null;

    return (
        <mesh geometry={geom} position={[0,0,-0.05]}>
            <meshBasicMaterial color={color} transparent opacity={opacity} side={THREE.DoubleSide} />
        </mesh>
    );
};

// ---------------------------------------------------------
// 3D SCENE COMPONENT
// ---------------------------------------------------------

function BisectorScene({ progress, angleDeg }: { progress: number, angleDeg: number }) {
    const [renderTick, setRenderTick] = useState(0);
    const proxy = useRef({ p: progress, theta: angleDeg * (Math.PI / 180) }).current;

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
    const t1 = THREE.MathUtils.clamp((p - 0) / 25, 0, 1);    // Base Arc
    const t2 = THREE.MathUtils.clamp((p - 25) / 25, 0, 1);   // Arc from Y
    const t3 = THREE.MathUtils.clamp((p - 50) / 25, 0, 1);   // Arc from X
    const t4 = THREE.MathUtils.clamp((p - 75) / 15, 0, 1);   // Line BD
    const t5 = THREE.MathUtils.clamp((p - 90) / 10, 0, 1);   // Reveal

    // Constants
    const R_base = 4;
    const rayLen = 8;
    const B = new THREE.Vector3(0, 0, 0);
    const C = new THREE.Vector3(rayLen, 0, 0);
    const A = new THREE.Vector3(rayLen * Math.cos(theta), rayLen * Math.sin(theta), 0);
    
    // Intersection Points
    const Y = new THREE.Vector3(R_base, 0, 0); // on BC
    const X = new THREE.Vector3(R_base * Math.cos(theta), R_base * Math.sin(theta), 0); // on BA
    const D = new THREE.Vector3(Y.x + X.x, Y.y + X.y, 0); // Intersection of arcs from X and Y

    // Compass Overlay Logic
    let compassCenter = null;
    let compassEnd = null;
    const sweepRange = 0.4; // +/- radians for interior arcs

    if (t1 > 0 && t1 < 1) {
        compassCenter = B;
        const currentAngle = theta * t1;
        compassEnd = new THREE.Vector3(R_base * Math.cos(currentAngle), R_base * Math.sin(currentAngle), 0);
    } else if (t2 > 0 && t2 < 1) {
        compassCenter = Y;
        // Arc from Y needs to cross D. Vector Y->D is exactly X. Angle is theta.
        const sAngle = theta - sweepRange;
        const eAngle = theta + sweepRange;
        const currentAngle = sAngle + (eAngle - sAngle) * t2;
        compassEnd = new THREE.Vector3(Y.x + R_base * Math.cos(currentAngle), Y.y + R_base * Math.sin(currentAngle), 0);
    } else if (t3 > 0 && t3 < 1) {
        compassCenter = X;
        // Arc from X needs to cross D. Vector X->D is exactly Y. Angle is 0.
        const sAngle = -sweepRange;
        const eAngle = sweepRange;
        const currentAngle = sAngle + (eAngle - sAngle) * t3;
        compassEnd = new THREE.Vector3(X.x + R_base * Math.cos(currentAngle), X.y + R_base * Math.sin(currentAngle), 0);
    }

    // Ray BD ending point (normalized to rayLen to look good at any angle)
    const rayBDEnd = new THREE.Vector3(rayLen * Math.cos(theta/2), rayLen * Math.sin(theta/2), 0);
    const currentBDEnd = new THREE.Vector3().lerpVectors(B, rayBDEnd, t4);

    return (
        <group position={[-2, -1.5, 0]}>
            {/* Base Angle Rays */}
            <Line points={[B, C]} color="#3b82f6" lineWidth={5} />
            <Line points={[B, A]} color="#3b82f6" lineWidth={5} />
            <Sphere position={B} args={[0.15]}><meshBasicMaterial color="#3b82f6"/></Sphere>
            <Text position={[-0.5, -0.5, 0]} fontSize={0.6} color="#3b82f6" fontWeight="bold">B</Text>
            
            {/* Base Arc (from B) */}
            <ProgressArc center={B} r={R_base} startAngle={0} endAngle={theta} pVal={t1} color="#a855f7" />
            
            {/* Points X and Y */}
            {t1 === 1 && (
                <group>
                    <Sphere position={Y} args={[0.15]}><meshBasicMaterial color="#a855f7"/></Sphere>
                    <Text position={[Y.x + 0.2, Y.y - 0.5, 0]} fontSize={0.5} color="#a855f7" fontWeight="bold">Y</Text>
                    <Sphere position={X} args={[0.15]}><meshBasicMaterial color="#a855f7"/></Sphere>
                    <Text position={[X.x - 0.4, X.y + 0.4, 0]} fontSize={0.5} color="#a855f7" fontWeight="bold">X</Text>
                </group>
            )}

            {/* Interior Arcs */}
            <ProgressArc center={Y} r={R_base} startAngle={theta - sweepRange} endAngle={theta + sweepRange} pVal={t2} color="#ec4899" />
            <ProgressArc center={X} r={R_base} startAngle={-sweepRange} endAngle={sweepRange} pVal={t3} color="#ec4899" />

            {/* Compass Visualization */}
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
                        r (fixed)
                    </Text>
                </group>
            )}

            {/* Intersection D */}
            {t3 === 1 && (
                <group>
                    <Sphere position={D} args={[0.15]}><meshBasicMaterial color="#22c55e"/></Sphere>
                    <Text position={[D.x + 0.4, D.y + 0.2, 0]} fontSize={0.5} color="#22c55e" fontWeight="bold">D</Text>
                </group>
            )}

            {/* Bisector Ray */}
            {t4 > 0 && (
                <Line points={[B, currentBDEnd]} color="#22c55e" lineWidth={5} />
            )}

            {/* Final Reveal - Sectors & Badges */}
            <AngleSector radius={2.5} startAngle={0} endAngle={theta/2} color="#22c55e" opacity={0.3 * t5} />
            <AngleSector radius={2.5} startAngle={theta/2} endAngle={theta} color="#3b82f6" opacity={0.3 * t5} />
            
            {t5 > 0.5 && (
                <group>
                    {/* Badge 1 */}
                    <group position={[2.8 * Math.cos(theta/4), 2.8 * Math.sin(theta/4), 0]}>
                        <mesh><planeGeometry args={[1.5, 0.6]} /><meshBasicMaterial color="#0f172a" /></mesh>
                        <Text position={[0, 0, 0.1]} fontSize={0.35} color="#22c55e" fontWeight="extrabold">
                            {(angleDeg / 2).toFixed(1)}°
                        </Text>
                    </group>
                    
                    {/* Badge 2 */}
                    <group position={[2.8 * Math.cos(theta * 0.75), 2.8 * Math.sin(theta * 0.75), 0]}>
                        <mesh><planeGeometry args={[1.5, 0.6]} /><meshBasicMaterial color="#0f172a" /></mesh>
                        <Text position={[0, 0, 0.1]} fontSize={0.35} color="#3b82f6" fontWeight="extrabold">
                            {(angleDeg / 2).toFixed(1)}°
                        </Text>
                    </group>

                    <Line points={[
                        [2 * Math.cos(theta/4), 2 * Math.sin(theta/4), 0],
                        [2.3 * Math.cos(theta/4), 2 * Math.sin(theta/4), 0]
                    ]} color="#22c55e" lineWidth={3} />
                    
                    <Line points={[
                        [2 * Math.cos(theta * 0.75), 2 * Math.sin(theta * 0.75), 0],
                        [2.3 * Math.cos(theta * 0.75), 2 * Math.sin(theta * 0.75), 0]
                    ]} color="#3b82f6" lineWidth={3} />
                </group>
            )}
        </group>
    );
}

// ---------------------------------------------------------
// MAIN UI COMPONENT
// ---------------------------------------------------------

export default function AngleBisectorSim() {
    const [angle, setAngle] = useState(70);
    const [progress, setProgress] = useState(0);

    const isComplete = progress === 100;
    const halfAngle = (angle / 2).toFixed(1);

    const getAlertConfig = () => {
        if (progress < 25) {
            return {
                title: "The Protractor Misconception",
                desc: "Many believe you need to measure the angle with a protractor to cut it in half. A compass proves otherwise! First, we draw a base arc from B to establish points X and Y, which are equally distant from B.",
                colorClass: "bg-purple-900/40 border-l-purple-500 border-purple-800 text-purple-100",
                icon: <ShieldAlert size={18} className="text-purple-400" />
            };
        } else if (progress < 75) {
            return {
                title: "Drawing Equidistant Arcs",
                desc: "By keeping the compass radius fixed and drawing arcs from X and Y, we find point D. Because X and Y are equidistant from B, point D is perfectly centered between the two rays.",
                colorClass: "bg-pink-900/40 border-l-pink-500 border-pink-800 text-pink-100",
                icon: <Settings2 size={18} className="text-pink-400" />
            };
        } else if (progress < 100) {
            return {
                title: "Constructing the Bisector Ray",
                desc: "Connecting B to D creates the bisector. No numbers, no measurements—just the pure geometric truth of equidistant points.",
                colorClass: "bg-sky-900/40 border-l-sky-500 border-sky-800 text-sky-100",
                icon: <PencilRuler size={18} className="text-sky-400" />
            };
        } else {
            return {
                title: "Angle Bisector Locked",
                desc: `SUCCESS: The ${angle}° angle has been perfectly split into twin ${halfAngle}° sectors. Notice we never measured ${halfAngle}°—the overlapping circles inherently guarantee absolute symmetry.`,
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
                                Angle Bisector Construction
                            </h1>
                            <p className="text-slate-400 text-sm mt-1">
                                Splitting angles with perfect precision using only a compass.
                            </p>
                        </div>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl w-full flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-colors duration-500 ${isComplete ? 'border-green-500/50' : 'border-slate-700'}`}>
                        <div className={`text-sm md:text-lg px-4 flex items-center justify-center h-full min-h-[3.5rem] w-full ${isComplete ? 'text-green-400' : 'text-slate-300'}`}>
                            <BlockMath math={`m\\angle ABC = ${angle}^\\circ \\implies \\text{Bisected Sectors} = \\frac{${angle}^\\circ}{2} = ${halfAngle}^\\circ`} />
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
                        
                        <BisectorScene progress={progress} angleDeg={angle} />
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
                            <Settings2 size={14} /> Initial Angle (ABC)
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className="text-sky-400">{angle}°</span>
                        </div>
                        <input 
                            type="range" min="30" max="150" step="1" 
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
