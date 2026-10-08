import React, { useState, useRef, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Text, Line, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, CheckCircle, Settings2, PencilRuler, Layers, Copy } from 'lucide-react';

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

// ---------------------------------------------------------
// 3D SCENE COMPONENT
// ---------------------------------------------------------

function CopyAngleScene({ progress, angleDeg }: { progress: number, angleDeg: number }) {
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
    const t1 = THREE.MathUtils.clamp((p - 0) / 20, 0, 1);    // Arc at B
    const t2 = THREE.MathUtils.clamp((p - 20) / 20, 0, 1);   // Arc at D
    const t3 = THREE.MathUtils.clamp((p - 40) / 20, 0, 1);   // Measure XY
    const t4 = THREE.MathUtils.clamp((p - 60) / 20, 0, 1);   // Arc at M
    const t5 = THREE.MathUtils.clamp((p - 80) / 20, 0, 1);   // Ray DN & Reveal

    // Geometries
    const rayLen = 5;
    const R = 3.5;
    
    // Source Angle ABC (Left)
    const B = new THREE.Vector3(-6, -2, 0);
    const C = new THREE.Vector3(B.x + rayLen, B.y, 0);
    const A = new THREE.Vector3(B.x + rayLen * Math.cos(theta), B.y + rayLen * Math.sin(theta), 0);
    
    // Target Ray DE (Right)
    const D = new THREE.Vector3(2, -2, 0);
    const E = new THREE.Vector3(D.x + rayLen, D.y, 0);
    
    // Intersections on Source
    const X = new THREE.Vector3(B.x + R, B.y, 0); // On BC
    const Y = new THREE.Vector3(B.x + R * Math.cos(theta), B.y + R * Math.sin(theta), 0); // On BA
    const distXY = X.distanceTo(Y);
    
    // Intersections on Target
    const M = new THREE.Vector3(D.x + R, D.y, 0); // On DE
    const N = new THREE.Vector3(D.x + R * Math.cos(theta), D.y + R * Math.sin(theta), 0); // Final intersection
    
    // Compass Sweep for Target Intersection
    const angleMtoN = Math.atan2(N.y - M.y, N.x - M.x);

    // Dynamic Compass Overlay
    let compassCenter = null;
    let compassEnd = null;
    let compassLabel = "";

    if (t1 > 0 && t1 < 1) {
        compassCenter = B;
        const curA = -0.1 + (theta + 0.2 + 0.1) * t1;
        compassEnd = new THREE.Vector3(B.x + R * Math.cos(curA), B.y + R * Math.sin(curA), 0);
        compassLabel = "Radius R";
    } else if (t2 > 0 && t2 < 1) {
        compassCenter = D;
        const curA = -0.1 + (theta + 0.4 + 0.1) * t2;
        compassEnd = new THREE.Vector3(D.x + R * Math.cos(curA), D.y + R * Math.sin(curA), 0);
        compassLabel = "Radius R";
    } else if (t3 > 0 && t3 < 1) {
        compassCenter = X;
        compassEnd = new THREE.Vector3().lerpVectors(X, Y, t3);
        compassLabel = "Measure XY";
    } else if (t4 > 0 && t4 < 1) {
        compassCenter = M;
        const curA = (angleMtoN - 0.4) + 0.8 * t4;
        compassEnd = new THREE.Vector3(M.x + distXY * Math.cos(curA), M.y + distXY * Math.sin(curA), 0);
        compassLabel = "Radius XY";
    }

    const currentRayDN = new THREE.Vector3().lerpVectors(D, new THREE.Vector3(D.x + rayLen * Math.cos(theta), D.y + rayLen * Math.sin(theta), 0), t5);

    return (
        <group position={[0, 0, 0]}>
            {/* SOURCE ANGLE */}
            <group>
                <Line points={[B, C]} color="#3b82f6" lineWidth={5} />
                <Line points={[B, A]} color="#3b82f6" lineWidth={5} />
                <Sphere position={B} args={[0.15]}><meshBasicMaterial color="#3b82f6"/></Sphere>
                <Text position={[B.x - 0.5, B.y - 0.5, 0]} fontSize={0.6} color="#3b82f6" fontWeight="bold">B</Text>
                
                {/* Arc on Source */}
                <ProgressArc center={B} r={R} startAngle={-0.1} endAngle={theta + 0.2} pVal={t1} color="#a855f7" />
                
                {t1 === 1 && (
                    <group>
                        <Sphere position={X} args={[0.12]}><meshBasicMaterial color="#a855f7"/></Sphere>
                        <Text position={[X.x + 0.3, X.y - 0.5, 0]} fontSize={0.4} color="#a855f7" fontWeight="bold">X</Text>
                        <Sphere position={Y} args={[0.12]}><meshBasicMaterial color="#a855f7"/></Sphere>
                        <Text position={[Y.x - 0.4, Y.y + 0.3, 0]} fontSize={0.4} color="#a855f7" fontWeight="bold">Y</Text>
                    </group>
                )}
                
                {/* Measuring Chord XY */}
                {t3 > 0 && (
                    <Line points={[X, new THREE.Vector3().lerpVectors(X, Y, t3)]} color="#facc15" lineWidth={3} dashed dashSize={0.2} gapSize={0.2} />
                )}
            </group>

            {/* TARGET ANGLE */}
            <group>
                <Line points={[D, E]} color="#3b82f6" lineWidth={5} />
                <Sphere position={D} args={[0.15]}><meshBasicMaterial color="#3b82f6"/></Sphere>
                <Text position={[D.x - 0.5, D.y - 0.5, 0]} fontSize={0.6} color="#3b82f6" fontWeight="bold">D</Text>
                
                {/* Arc on Target (Radius R) */}
                <ProgressArc center={D} r={R} startAngle={-0.1} endAngle={theta + 0.4} pVal={t2} color="#a855f7" />
                
                {t2 === 1 && (
                    <group>
                        <Sphere position={M} args={[0.12]}><meshBasicMaterial color="#a855f7"/></Sphere>
                        <Text position={[M.x + 0.3, M.y - 0.5, 0]} fontSize={0.4} color="#a855f7" fontWeight="bold">M</Text>
                    </group>
                )}

                {/* Arc on Target (Radius XY) */}
                <ProgressArc center={M} r={distXY} startAngle={angleMtoN - 0.4} endAngle={angleMtoN + 0.4} pVal={t4} color="#ec4899" />
                
                {t4 === 1 && (
                    <group>
                        <Sphere position={N} args={[0.12]}><meshBasicMaterial color="#ec4899"/></Sphere>
                        <Text position={[N.x - 0.4, N.y + 0.3, 0]} fontSize={0.4} color="#ec4899" fontWeight="bold">N</Text>
                    </group>
                )}

                {/* Copied Ray DN */}
                {t5 > 0 && (
                    <Line points={[D, currentRayDN]} color="#22c55e" lineWidth={5} />
                )}
            </group>

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

            {/* Final Reveal */}
            {t5 > 0 && (
                <group>
                    <AngleSector center={B} radius={1.5} startAngle={0} endAngle={theta} color="#3b82f6" opacity={0.3 * t5} />
                    <AngleSector center={D} radius={1.5} startAngle={0} endAngle={theta} color="#22c55e" opacity={0.3 * t5} />
                    
                    {t5 > 0.5 && (
                        <group>
                            <group position={[B.x + 2 * Math.cos(theta/2), B.y + 2 * Math.sin(theta/2), 0]}>
                                <mesh><planeGeometry args={[1.5, 0.6]} /><meshBasicMaterial color="#0f172a" /></mesh>
                                <Text position={[0, 0, 0.1]} fontSize={0.35} color="#3b82f6" fontWeight="extrabold">{angleDeg.toFixed(1)}°</Text>
                            </group>
                            
                            <group position={[D.x + 2 * Math.cos(theta/2), D.y + 2 * Math.sin(theta/2), 0]}>
                                <mesh><planeGeometry args={[1.5, 0.6]} /><meshBasicMaterial color="#0f172a" /></mesh>
                                <Text position={[0, 0, 0.1]} fontSize={0.35} color="#22c55e" fontWeight="extrabold">{angleDeg.toFixed(1)}°</Text>
                            </group>
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

export default function CopyAngleSim() {
    const [angle, setAngle] = useState(55);
    const [progress, setProgress] = useState(0);

    const isComplete = progress === 100;

    const getAlertConfig = () => {
        if (progress < 20) {
            return {
                title: "Marking the Source",
                desc: "We begin by drawing an arc with a random radius R from vertex B. This creates two distinct reference points, X and Y, on our original angle.",
                colorClass: "bg-purple-900/40 border-l-purple-500 border-purple-800 text-purple-100",
                icon: <Settings2 size={18} className="text-purple-400" />
            };
        } else if (progress < 40) {
            return {
                title: "Transferring the Radius",
                desc: "Without changing the compass width, we repeat the exact same arc from vertex D on the target ray. This guarantees that point M is the same distance from D as X is from B.",
                colorClass: "bg-indigo-900/40 border-l-indigo-500 border-indigo-800 text-indigo-100",
                icon: <Copy size={18} className="text-indigo-400" />
            };
        } else if (progress < 80) {
            return {
                title: "Measuring the Chord",
                desc: "Instead of measuring degrees, we use the compass to measure the direct distance between X and Y (the chord). We then sweep this exact radius from point M to find N.",
                colorClass: "bg-pink-900/40 border-l-pink-500 border-pink-800 text-pink-100",
                icon: <PencilRuler size={18} className="text-pink-400" />
            };
        } else {
            return {
                title: "Angle Successfully Copied",
                desc: `SUCCESS: The new angle NDE perfectly matches the source angle ABC (${angle}°). We achieved this flawlessly using only lengths and arc intersections, proving that a protractor is completely unnecessary!`,
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
                                Copying an Angle
                            </h1>
                            <p className="text-slate-400 text-sm mt-1">
                                Duplicating angles precisely without a protractor.
                            </p>
                        </div>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl w-full flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-colors duration-500 ${isComplete ? 'border-green-500/50' : 'border-slate-700'}`}>
                        <div className={`text-sm md:text-lg px-4 flex items-center justify-center h-full min-h-[3.5rem] w-full ${isComplete ? 'text-green-400' : 'text-slate-300'}`}>
                            <BlockMath math={`m\\angle ABC = ${angle}^\\circ \\implies \\text{Copying Chord } XY \\implies m\\angle NDE \\cong m\\angle ABC`} />
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas camera={{ position: [0, -2, 14] }}>
                        <OrbitControls enablePan={false} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <ambientLight intensity={0.8} />
                        <mesh position={[0, 0, -2]}><planeGeometry args={[80, 80]} /><meshBasicMaterial color="#020617" /></mesh>
                        <gridHelper args={[80, 80, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />
                        
                        <CopyAngleScene progress={progress} angleDeg={angle} />
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
                            <Settings2 size={14} /> Source Angle (ABC)
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className="text-sky-400">{angle}°</span>
                        </div>
                        <input 
                            type="range" min="20" max="140" step="1" 
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
