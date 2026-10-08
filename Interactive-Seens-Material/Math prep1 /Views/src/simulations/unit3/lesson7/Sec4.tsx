import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { OrbitControls, Text, Line, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Hexagon, PieChart, Activity } from 'lucide-react';

// ---------------------------------------------------------
// CONSTANTS & COLORS
// ---------------------------------------------------------
const WEDGE_COLORS = [
    "#ef4444", "#f97316", "#facc15", "#22c55e", "#0ea5e9", 
    "#6366f1", "#a855f7", "#ec4899", "#14b8a6", "#84cc16", 
    "#f43f5e", "#06b6d4"
];

// ---------------------------------------------------------
// WEDGE COMPONENT
// ---------------------------------------------------------
function Wedge({ radius, startAngle, endAngle, color, pos }: { radius: number, startAngle: number, endAngle: number, color: string, pos: [number, number, number] }) {
    const geom = useMemo(() => {
        const shape = new THREE.Shape();
        shape.moveTo(0, 0);
        shape.arc(0, 0, radius, startAngle, endAngle, false);
        shape.lineTo(0, 0);
        return new THREE.ShapeGeometry(shape);
    }, [radius, startAngle, endAngle]);

    useEffect(() => {
        return () => geom.dispose();
    }, [geom]);

    const arcPts = useMemo(() => {
        const pts = [];
        pts.push(new THREE.Vector3(0,0,0));
        const segments = 24;
        for(let i=0; i<=segments; i++){
            const t = startAngle + (endAngle - startAngle) * (i/segments);
            pts.push(new THREE.Vector3(radius * Math.cos(t), radius * Math.sin(t), 0));
        }
        pts.push(new THREE.Vector3(0,0,0));
        return pts;
    }, [radius, startAngle, endAngle]);

    return (
        <group position={pos}>
            <mesh geometry={geom} position={[0,0,-0.01]}>
                <meshBasicMaterial color={color} transparent opacity={0.6} side={THREE.DoubleSide} />
            </mesh>
            <Line points={arcPts} color={color} lineWidth={3} />
        </group>
    );
}

// ---------------------------------------------------------
// 3D SCENE COMPONENT
// ---------------------------------------------------------
function ParallelFlowScene({ n }: { n: number }) {
    const R = 3.8;
    const group1Ref = useRef<THREE.Group>(null);
    const group2Ref = useRef<THREE.Group>(null);
    
    // GSAP Pop Animation on n change
    useEffect(() => {
        if (group1Ref.current) gsap.fromTo(group1Ref.current.scale, {x: 0.8, y: 0.8, z: 0.8}, {x: 1, y: 1, z: 1, duration: 0.6, ease: "back.out(1.5)"});
        if (group2Ref.current) gsap.fromTo(group2Ref.current.scale, {x: 0.8, y: 0.8, z: 0.8}, {x: 1, y: 1, z: 1, duration: 0.6, ease: "back.out(1.5)", delay: 0.1});
    }, [n]);

    // Generate CCW Polygon Vertices
    const vertices = useMemo(() => {
        const pts = [];
        for(let i=0; i<n; i++) {
            const theta = (i * 2 * Math.PI) / n + Math.PI / 2;
            pts.push(new THREE.Vector3(R * Math.cos(theta), R * Math.sin(theta), 0));
        }
        return pts;
    }, [n, R]);

    // Calculate non-adjacent Diagonals
    const diagonals = useMemo(() => {
        const lines = [];
        for(let i=0; i<n; i++) {
            for(let j=i+2; j<n; j++) {
                if (i === 0 && j === n-1) continue; // Skip final closing edge
                lines.push([vertices[i], vertices[j]]);
            }
        }
        return lines;
    }, [vertices, n]);

    return (
        <group position={[0, 1.0, 0]}>
            <ambientLight intensity={0.8} />
            
            {/* Dark Grid Background */}
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[80, 80]} />
                <meshBasicMaterial color="#020617" />
            </mesh>
            <gridHelper args={[80, 80, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* LEFT SIDE: Polygon + Diagonals + Extended Rays */}
            <group ref={group1Ref} position={[-6, 0, 0]}>
                
                {/* Polygon Border */}
                <Line points={[...vertices, vertices[0]]} color="#ffffff" lineWidth={4} />
               
                {/* Inner Diagonals Network */}
                {diagonals.map((pts, i) => (
                    <Line key={`diag-${i}`} points={pts} color="#0ea5e9" lineWidth={1} transparent opacity={0.6} />
                ))}

                {/* Extended Exterior Rays and Wedges */}
                {vertices.map((v, i) => {
                    const vCurr = vertices[(i+1)%n];
                    const vPrev = vertices[i];
                    
                    const dir1 = vCurr.clone().sub(vPrev).normalize();
                    const rayEnd = vCurr.clone().add(dir1.clone().multiplyScalar(2.5));
                    
                    const startA = Math.atan2(dir1.y, dir1.x);
                    const endA = startA + (2 * Math.PI / n); // Strictly valid for regular convex

                    return (
                        <group key={`ext-${i}`}>
                            <Line points={[vCurr, rayEnd]} color="#94a3b8" lineWidth={2} dashed dashSize={0.2} gapSize={0.2} />
                            <Wedge 
                                radius={1.2} 
                                startAngle={startA} 
                                endAngle={endA} 
                                color={WEDGE_COLORS[i % WEDGE_COLORS.length]} 
                                pos={[vCurr.x, vCurr.y, 0.05]} 
                            />
                        </group>
                    );
                })}

                {/* Vertices */}
                {vertices.map((v, i) => (
                    <Sphere key={`v-${i}`} position={[v.x, v.y, 0.1]} args={[0.15]}>
                        <meshBasicMaterial color="#ffffff" />
                    </Sphere>
                ))}
               
                {/* Caption */}
                <Text position={[0, -R - 1.8, 0]} fontSize={0.7} color="#0ea5e9" fontWeight="bold">
                    Diagonals: {diagonals.length}
                </Text>
            </group>

            {/* RIGHT SIDE: Exterior Angle Circle Gauge */}
            <group ref={group2Ref} position={[6, 0, 0]}>
                {/* Assembled Wedges forming 360 deg */}
                {vertices.map((_, i) => {
                    const startAngle = i * (2 * Math.PI / n) + Math.PI/2;
                    const endAngle = (i + 1) * (2 * Math.PI / n) + Math.PI/2;
                    return (
                        <Wedge 
                            key={`circle-wedge-${i}`}
                            radius={4.0}
                            startAngle={startAngle}
                            endAngle={endAngle}
                            color={WEDGE_COLORS[i % WEDGE_COLORS.length]}
                            pos={[0,0,0]}
                        />
                    );
                })}
                
                {/* Center Badge Hub */}
                <mesh position={[0,0,0.1]}>
                    <circleGeometry args={[1.3, 32]} />
                    <meshBasicMaterial color="#0f172a" />
                </mesh>
                <mesh position={[0,0,0.05]}>
                    <circleGeometry args={[1.4, 32]} />
                    <meshBasicMaterial color="#facc15" />
                </mesh>
                <Text position={[0, 0.2, 0.15]} fontSize={0.8} color="#ffffff" fontWeight="bold">
                    360°
                </Text>
                <Text position={[0, -0.4, 0.15]} fontSize={0.3} color="#94a3b8" fontWeight="bold">
                    CONSTANT
                </Text>

                {/* Caption */}
                <Text position={[0, -R - 1.8, 0]} fontSize={0.7} color="#facc15" fontWeight="bold">
                    Exterior Sum: 360°
                </Text>
            </group>
        </group>
    );
}

// ---------------------------------------------------------
// MAIN UI COMPONENT
// ---------------------------------------------------------
export default function ExteriorDiagonalsComparison() {
    const [sides, setSides] = useState(5);
    const numDiagonals = (sides * (sides - 3)) / 2;

    const renderMath = () => {
        return (
            <div className="flex items-center justify-center gap-4 lg:gap-8 w-full">
                <div className="flex-1 flex justify-end">
                    <BlockMath math={`D = \\frac{${sides}(${sides} - 3)}{2} = ${numDiagonals}`} />
                </div>
                <div className="w-px h-10 bg-slate-700/80 mx-2"></div>
                <div className="flex-1 flex justify-start">
                    <BlockMath math={`\\sum \\theta_{ext} = 360^\\circ \\; (\\text{Constant})`} />
                </div>
            </div>
        );
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex flex-col gap-4 max-w-7xl mx-auto">
                    <div className="flex justify-between items-start md:items-center w-full">
                        <div>
                            <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                                Exterior Angles & Diagonals
                            </h1>
                            <p className="text-slate-400 text-sm mt-1">
                                Observing the limits of polygon property growth.
                            </p>
                        </div>
                    </div>
                    <div className="bg-slate-950/80 border border-slate-700 p-3 rounded-xl w-full flex justify-center shadow-inner overflow-x-auto pointer-events-auto">
                        <div className="text-sm md:text-lg px-4 flex items-center justify-center h-full min-h-[3.5rem] text-slate-300 w-full">
                            {renderMath()}
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas camera={{ position: [0, 0, 18] }}>
                        <OrbitControls enablePan={true} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <ParallelFlowScene n={sides} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                {/* Dynamic Alert Panel */}
                <div className="flex-1 pointer-events-auto max-w-md w-full">
                    <div className="border-l-4 border-t border-r border-b p-5 rounded-2xl shadow-2xl backdrop-blur-md bg-slate-900/90 border-l-amber-500 border-amber-800 text-amber-100">
                        <h3 className="font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide text-amber-400">
                            <ShieldAlert size={18}/> The Exterior Sum Myth
                        </h3>
                        <p className="text-sm leading-relaxed drop-shadow-md">
                            <b>CRITICAL MISCONCEPTION:</b> It is highly intuitive to think that adding more sides to a polygon will increase the sum of its exterior angles. However, as the side count <b>n = {sides}</b> increases, each individual exterior wedge gets strictly smaller in perfect proportion! <br/><br/>
                            Look at the right dial: The detached exterior wedges slide together to complete exactly <b>one full rotation (360°)</b>, no matter the polygon's size. Meanwhile, the internal diagonal network grows quadratically!
                        </p>
                    </div>
                </div>

                {/* Control Panel */}
                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
                    
                    <div className="flex justify-between items-center mb-6 border-b border-slate-800 pb-4">
                        <div className="flex items-center gap-3">
                            <div className="bg-blue-500/20 p-2 rounded-lg text-blue-400">
                                <Activity size={24} />
                            </div>
                            <div>
                                <div className="text-xs text-slate-400 font-bold uppercase tracking-wider">Side Count (n)</div>
                                <div className="text-xl font-bold text-white">{sides} Sides</div>
                            </div>
                        </div>
                        <div className="flex items-center gap-3 text-right">
                            <div>
                                <div className="text-xs text-slate-400 font-bold uppercase tracking-wider">Total Diagonals</div>
                                <div className="text-xl font-bold text-sky-400">{numDiagonals} <Hexagon size={16} className="inline mb-1" /></div>
                            </div>
                        </div>
                    </div>

                    <div className="mb-2">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <PieChart size={14} /> Adjust Polygon Size
                        </label>
                        <input 
                            type="range" 
                            min="4" max="12" step="1" 
                            value={sides} onChange={(e) => setSides(parseInt(e.target.value))} 
                            className="w-full h-2 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 accent-amber-500"
                        />
                        <div className="flex justify-between text-[10px] text-slate-500 font-bold mt-2 px-1">
                            <span>Square (4)</span>
                            <span>Dodecagon (12)</span>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
