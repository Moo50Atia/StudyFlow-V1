import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere, Line } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Hexagon, Triangle, Calculator } from 'lucide-react';

// ---------------------------------------------------------
// COLOR PALETTE FOR TRIANGLES
// ---------------------------------------------------------
const TRIANGLE_COLORS = [
    "#3b82f6", // Blue
    "#10b981", // Emerald
    "#f59e0b", // Amber
    "#ef4444", // Red
    "#8b5cf6", // Violet
    "#ec4899", // Pink
    "#14b8a6", // Teal
    "#f97316", // Orange
];

// ---------------------------------------------------------
// 3D SCENE COMPONENT
// ---------------------------------------------------------

function PolygonPartitionScene({ n }: { n: number }) {
    const groupRef = useRef<THREE.Group>(null);
    const R = 4.5; // Radius of the polygon

    // Animate the polygon "pop" when n changes
    useEffect(() => {
        if (groupRef.current) {
            gsap.fromTo(groupRef.current.scale, 
                { x: 0.8, y: 0.8, z: 0.8 }, 
                { x: 1, y: 1, z: 1, duration: 0.6, ease: "back.out(1.5)" }
            );
        }
    }, [n]);

    // 1. Calculate Vertices
    // We anchor Vertex A at the bottom (-PI/2) so the fan opens upwards symmetrically
    const vertices = useMemo(() => {
        const pts: THREE.Vector3[] = [];
        for (let i = 0; i < n; i++) {
            const theta = -Math.PI / 2 + i * (2 * Math.PI / n);
            pts.push(new THREE.Vector3(R * Math.cos(theta), R * Math.sin(theta), 0));
        }
        return pts;
    }, [n, R]);

    // 2. Generate Triangles (from single vertex A = vertices[0])
    const triangles = useMemo(() => {
        const tris = [];
        const vA = vertices[0];
        
        for (let i = 1; i < n - 1; i++) {
            const v1 = vertices[i];
            const v2 = vertices[i + 1];
            
            // Generate ShapeGeometry for the filled triangle
            const shape = new THREE.Shape();
            shape.moveTo(vA.x, vA.y);
            shape.lineTo(v1.x, v1.y);
            shape.lineTo(v2.x, v2.y);
            shape.lineTo(vA.x, vA.y);
            
            const geom = new THREE.ShapeGeometry(shape);
            
            // Calculate centroid for the "180°" text label
            const centroid = new THREE.Vector3()
                .add(vA).add(v1).add(v2).multiplyScalar(1 / 3);
                
            tris.push({ v0: vA, v1, v2, geom, centroid, color: TRIANGLE_COLORS[(i-1) % TRIANGLE_COLORS.length] });
        }
        return tris;
    }, [vertices, n]);

    // Cleanup geometries on unmount/re-render to prevent memory leaks
    useEffect(() => {
        return () => {
            triangles.forEach(t => t.geom.dispose());
        };
    }, [triangles]);

    const borderPts = [...vertices, vertices[0]].map(v => [v.x, v.y, 0] as [number, number, number]);

    return (
        <group ref={groupRef} position={[0, 0.5, 0]}>
            <ambientLight intensity={0.8} />
            <directionalLight position={[0, 10, 10]} intensity={1.5} />
            
            {/* Dark Grid Background */}
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[60, 60]} />
                <meshBasicMaterial color="#020617" />
            </mesh>
            <gridHelper args={[60, 60, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* Render Filled Triangles */}
            {triangles.map((tri, idx) => (
                <group key={`tri-${idx}`}>
                    {/* Triangle Fill */}
                    <mesh geometry={tri.geom} position={[0, 0, 0]}>
                        <meshBasicMaterial color={tri.color} transparent opacity={0.3} side={THREE.DoubleSide} />
                    </mesh>

                    {/* Internal Diagonals (Rays from A) */}
                    {idx > 0 && (
                        <Line points={[[tri.v0.x, tri.v0.y, 0.05], [tri.v1.x, tri.v1.y, 0.05]]} color="#94a3b8" lineWidth={2} dashed dashSize={0.2} gapSize={0.2} />
                    )}

                    {/* 180 Degree Badge at Centroid */}
                    <group position={[tri.centroid.x, tri.centroid.y, 0.1]}>
                        <mesh>
                            <planeGeometry args={[1.6, 0.6]} />
                            <meshBasicMaterial color={tri.color} transparent opacity={0.8} />
                        </mesh>
                        <Text position={[0, 0, 0.05]} fontSize={0.35} color="#ffffff" fontWeight="bold">
                            180°
                        </Text>
                    </group>
                </group>
            ))}

            {/* Polygon Boundary Outline */}
            <Line points={borderPts} color="#ffffff" lineWidth={4} />

            {/* Vertices & Labels */}
            {vertices.map((v, i) => (
                <group key={`vertex-${i}`} position={[v.x, v.y, 0.1]}>
                    <Sphere args={[i === 0 ? 0.25 : 0.15]}>
                        <meshBasicMaterial color={i === 0 ? "#facc15" : "#ffffff"} />
                    </Sphere>
                    {/* Vertex A specific styling */}
                    {i === 0 && (
                        <Text position={[0, -0.6, 0]} fontSize={0.5} color="#facc15" fontWeight="bold">
                            A
                        </Text>
                    )}
                </group>
            ))}
        </group>
    );
}

// ---------------------------------------------------------
// MAIN UI COMPONENT
// ---------------------------------------------------------

export default function PolygonAngleSum() {
    // State: Number of sides (n)
    const [sides, setSides] = useState(5);

    const numTriangles = sides - 2;
    const totalSum = numTriangles * 180;

    const renderMath = () => {
        return (
            <BlockMath math={`S = (n - 2) \\times 180^\\circ \\implies (${sides} - 2) \\times 180^\\circ = ${totalSum}^\\circ`} />
        );
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col lg:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            Polygon Interior Angle Sum
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Deriving the formula via single-vertex triangulation.
                        </p>
                    </div>
                    <div className="bg-slate-950/80 border border-blue-500/50 p-3 rounded-xl min-w-[360px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto">
                        <div className="text-lg px-4 flex items-center justify-center h-full min-h-[3.5rem] text-blue-400">
                            {renderMath()}
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas camera={{ position: [0, 0, 13] }}>
                        <OrbitControls enablePan={false} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <PolygonPartitionScene n={sides} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                {/* Dynamic Alert Panel */}
                <div className="flex-1 pointer-events-auto max-w-md w-full">
                    <div className="border-l-4 border-t border-r border-b p-5 rounded-2xl shadow-2xl backdrop-blur-md bg-rose-900/40 border-l-rose-500 border-rose-800 text-rose-200">
                        <h3 className="font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide">
                            <ShieldAlert size={18}/> The <span className="font-mono text-rose-300">n × 180°</span> Fallacy
                        </h3>
                        <p className="text-sm leading-relaxed drop-shadow-md text-slate-300">
                            <b>CRITICAL MISCONCEPTION:</b> Students often multiply the number of sides (<span className="font-mono text-white">n = {sides}</span>) directly by 180°, incorrectly guessing this polygon has {sides * 180}°. <br/><br/>
                            Look at the visual partition! By drawing diagonal rays from a <b>single vertex (A)</b> to all non-adjacent corners, the polygon is sliced into exactly <span className="font-bold text-white text-base bg-rose-800/50 px-2 py-0.5 rounded">n - 2 = {numTriangles}</span> internal triangles. Each triangle contributes 180°.
                        </p>
                    </div>
                </div>

                {/* Control Panel */}
                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
                    
                    <div className="flex justify-between items-center mb-6 border-b border-slate-800 pb-4">
                        <div className="flex items-center gap-3">
                            <div className="bg-blue-500/20 p-2 rounded-lg text-blue-400">
                                <Hexagon size={24} />
                            </div>
                            <div>
                                <div className="text-xs text-slate-400 font-bold uppercase tracking-wider">Side Count (n)</div>
                                <div className="text-xl font-bold text-white">{sides} Sides</div>
                            </div>
                        </div>
                        <div className="flex items-center gap-3 text-right">
                            <div>
                                <div className="text-xs text-slate-400 font-bold uppercase tracking-wider">Internal Triangles</div>
                                <div className="text-xl font-bold text-emerald-400">{numTriangles} <Triangle size={16} className="inline mb-1" /></div>
                            </div>
                            <div className="bg-emerald-500/20 p-2 rounded-lg text-emerald-400">
                                <Calculator size={24} />
                            </div>
                        </div>
                    </div>

                    <div className="mb-2">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            Adjust Polygon Shape
                        </label>
                        <input 
                            type="range" 
                            min="3" max="10" step="1" 
                            value={sides} onChange={(e) => setSides(parseInt(e.target.value))} 
                            className="w-full h-2 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 accent-blue-500"
                        />
                        <div className="flex justify-between text-[10px] text-slate-500 font-bold mt-2 px-1">
                            <span>Triangle (3)</span>
                            <span>Decagon (10)</span>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
