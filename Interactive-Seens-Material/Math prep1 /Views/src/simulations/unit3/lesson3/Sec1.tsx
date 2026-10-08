import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Layers, Maximize2, RotateCcw } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function Scene({ tiltDeg }: { tiltDeg: number }) {
    const { camera } = useThree();
    
    // Animation Proxy
    const proxy = useRef({ 
        tiltRad: tiltDeg * Math.PI / 180
    }).current;
    
    // Geometric constants
    const d = 2.0; // Half distance between parallel lines (L1 at y=2, L2 at y=-2)
    const arcRadius = 0.8;
    const textRadius = 1.35;

    // Refs for Intersections & Transversal
    const transRef = useRef<THREE.Group>(null);
    const pointA = useRef<THREE.Group>(null);
    const pointB = useRef<THREE.Group>(null);
    
    // Refs for Arcs (1 to 8)
    const arcsRef = useRef<THREE.Line[]>([]);
    const textsGroupRef = useRef<THREE.Group[]>([]);
    const textsRef = useRef<any[]>([]);

    // Geometries
    const makeGeom = () => {
        const geom = new THREE.BufferGeometry();
        geom.setAttribute('position', new THREE.BufferAttribute(new Float32Array(50 * 3), 3));
        return geom;
    };

    const arcGeometries = useMemo(() => Array.from({ length: 8 }).map(makeGeom), []);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 0, z: 12, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    const updateArc = (geom: THREE.BufferGeometry, start: number, end: number, r: number, segments: number = 40) => {
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
        proxy.tiltRad = THREE.MathUtils.lerp(proxy.tiltRad, tiltDeg * Math.PI / 180, 0.1);
        
        const theta = proxy.tiltRad;
        
        // Calculate intersection x coordinates
        // T equation: y = tan(theta) * x => x = y / tan(theta)
        const tanTheta = Math.tan(theta);
        const xa = d / tanTheta;
        const xb = -d / tanTheta;

        if (transRef.current) {
            transRef.current.rotation.z = theta;
        }

        if (pointA.current) pointA.current.position.set(xa, d, 0);
        if (pointB.current) pointB.current.position.set(xb, -d, 0);

        // Calculate arc bounds (0 is right, pi/2 is up, pi is left, 3pi/2 is down)
        // Adjust for theta to ensure start < end properly
        let angleT = theta;
        if (angleT < 0) angleT += Math.PI;

        const bounds = [
            { start: 0, end: angleT }, // 1 (Top Right)
            { start: angleT, end: Math.PI }, // 2 (Top Left)
            { start: Math.PI, end: Math.PI + angleT }, // 3 (Bottom Left)
            { start: Math.PI + angleT, end: Math.PI * 2 } // 4 (Bottom Right)
        ];

        // Assign to the 8 arcs (4 at A, 4 at B)
        for (let i = 0; i < 4; i++) {
            // Vertex A (Angles 1-4)
            if (arcsRef.current[i]) {
                updateArc(arcsRef.current[i].geometry, bounds[i].start, bounds[i].end, arcRadius);
            }
            if (textsGroupRef.current[i] && textsRef.current[i]) {
                const mid = bounds[i].start + (bounds[i].end - bounds[i].start) / 2;
                textsGroupRef.current[i].position.set(xa + Math.cos(mid) * textRadius, d + Math.sin(mid) * textRadius, 0);
                textsRef.current[i].text = `${i + 1}`;
            }

            // Vertex B (Angles 5-8) -> mapped to index i+4
            if (arcsRef.current[i + 4]) {
                updateArc(arcsRef.current[i + 4].geometry, bounds[i].start, bounds[i].end, arcRadius);
            }
            if (textsGroupRef.current[i + 4] && textsRef.current[i + 4]) {
                const mid = bounds[i].start + (bounds[i].end - bounds[i].start) / 2;
                textsGroupRef.current[i + 4].position.set(xb + Math.cos(mid) * textRadius, -d + Math.sin(mid) * textRadius, 0);
                textsRef.current[i + 4].text = `${i + 5}`;
            }
        }
    });

    const arcColors = [
        "#06b6d4", // 1 (Cyan)
        "#facc15", // 2 (Yellow)
        "#06b6d4", // 3 (Cyan)
        "#facc15", // 4 (Yellow)
        "#06b6d4", // 5 (Cyan)
        "#facc15", // 6 (Yellow)
        "#06b6d4", // 7 (Cyan)
        "#facc15"  // 8 (Yellow)
    ];

    return (
        <group position={[0, 0, 0]}>
            <ambientLight intensity={0.6} />
            <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow />
            <Environment preset="city" opacity={0.3} />
            
            {/* Background Grid */}
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[50, 50]} />
                <meshStandardMaterial color="#020617" />
            </mesh>
            <gridHelper args={[50, 50, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* Visual Coplanar Bounding Glass */}
            <mesh position={[0, 0, -0.2]}>
                <planeGeometry args={[16, 8]} />
                <meshStandardMaterial color="#3b82f6" transparent opacity={0.05} depthWrite={false} />
            </mesh>
            <lineSegments position={[0, 0, -0.19]}>
                <edgesGeometry args={[new THREE.PlaneGeometry(16, 8)]} />
                <lineBasicMaterial color="#3b82f6" transparent opacity={0.3} />
            </lineSegments>
            <group position={[-7.5, 3.5, 0]}>
                <Text fontSize={0.4} color="#3b82f6" fontWeight="bold">Plane P (Coplanar)</Text>
            </group>

            {/* Parallel Track L1 */}
            <group position={[0, d, 0]}>
                <mesh rotation={[0, 0, -Math.PI/2]} castShadow>
                    <cylinderGeometry args={[0.06, 0.06, 20]} />
                    <meshStandardMaterial color="#94a3b8" roughness={0.3} metalness={0.2} />
                </mesh>
                <Text position={[-8, 0.4, 0]} fontSize={0.5} color="#94a3b8" fontWeight="bold">L1</Text>
                {/* Arrowheads */}
                <mesh position={[-9.8, 0, 0]} rotation={[0, 0, Math.PI/2]}><coneGeometry args={[0.2, 0.4]} /><meshStandardMaterial color="#94a3b8" /></mesh>
                <mesh position={[9.8, 0, 0]} rotation={[0, 0, -Math.PI/2]}><coneGeometry args={[0.2, 0.4]} /><meshStandardMaterial color="#94a3b8" /></mesh>
            </group>

            {/* Parallel Track L2 */}
            <group position={[0, -d, 0]}>
                <mesh rotation={[0, 0, -Math.PI/2]} castShadow>
                    <cylinderGeometry args={[0.06, 0.06, 20]} />
                    <meshStandardMaterial color="#94a3b8" roughness={0.3} metalness={0.2} />
                </mesh>
                <Text position={[-8, 0.4, 0]} fontSize={0.5} color="#94a3b8" fontWeight="bold">L2</Text>
                {/* Arrowheads */}
                <mesh position={[-9.8, 0, 0]} rotation={[0, 0, Math.PI/2]}><coneGeometry args={[0.2, 0.4]} /><meshStandardMaterial color="#94a3b8" /></mesh>
                <mesh position={[9.8, 0, 0]} rotation={[0, 0, -Math.PI/2]}><coneGeometry args={[0.2, 0.4]} /><meshStandardMaterial color="#94a3b8" /></mesh>
            </group>

            {/* Transversal Track T */}
            <group ref={transRef}>
                <mesh rotation={[0, 0, -Math.PI/2]} castShadow>
                    <cylinderGeometry args={[0.08, 0.08, 22]} />
                    <meshStandardMaterial color="#f97316" roughness={0.3} metalness={0.2} emissive="#f97316" emissiveIntensity={0.2} />
                </mesh>
                <Text position={[9, 0.6, 0]} fontSize={0.5} color="#f97316" fontWeight="bold">Transversal T</Text>
                <mesh position={[-10.8, 0, 0]} rotation={[0, 0, Math.PI/2]}><coneGeometry args={[0.25, 0.5]} /><meshStandardMaterial color="#f97316" /></mesh>
                <mesh position={[10.8, 0, 0]} rotation={[0, 0, -Math.PI/2]}><coneGeometry args={[0.25, 0.5]} /><meshStandardMaterial color="#f97316" /></mesh>
            </group>

            {/* Vertices A & B */}
            <group ref={pointA}>
                <Sphere args={[0.15, 32, 32]}>
                    <meshStandardMaterial color="#ffffff" roughness={0.2} metalness={0.5} />
                </Sphere>
                <Text position={[-0.4, -0.4, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">A</Text>
            </group>

            <group ref={pointB}>
                <Sphere args={[0.15, 32, 32]}>
                    <meshStandardMaterial color="#ffffff" roughness={0.2} metalness={0.5} />
                </Sphere>
                <Text position={[-0.4, -0.4, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">B</Text>
            </group>

            {/* Dynamic Arcs and Texts */}
            {arcGeometries.map((geom, i) => (
                <group key={`angle-group-${i}`}>
                    <line ref={el => arcsRef.current[i] = el!} geometry={geom} position={[0,0,0.05]}>
                        <lineBasicMaterial color={arcColors[i]} linewidth={4} transparent opacity={0.8} />
                    </line>
                    <group ref={el => textsGroupRef.current[i] = el!}>
                        <Text ref={el => textsRef.current[i] = el!} fontSize={0.4} color={arcColors[i]} fontWeight="bold" anchorX="center" anchorY="middle">
                            {i + 1}
                        </Text>
                    </group>
                </group>
            ))}

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function ParallelismAndTransversals() {
    const [tiltDeg, setTiltDeg] = useState(65);

    const handleReset = () => {
        setTiltDeg(65);
    };

    const renderMath = () => {
        return (
            <BlockMath math={`L_1 \\parallel L_2 \\iff \\text{Coplanar and } L_1 \\cap L_2 = \\emptyset`} />
        );
    };

    const alertContent = { 
        title: "Coplanar Requirement for Parallelism", 
        icon: <ShieldAlert size={18}/>, 
        color: "border-l-rose-500 text-rose-300 bg-rose-900/40", 
        body: <><b>CRITICAL MISCONCEPTION:</b> Students often define parallel lines merely as "lines that never intersect". However, <b>Skew Lines</b> (lines in different 3D planes) also never intersect, but they are NOT parallel. Parallel lines MUST be perfectly flat within the <b>same 2D Plane</b>.</> 
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            Parallelism & Transversals
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Lines L1 and L2 intersected by Transversal T.
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
                        <OrbitControls enablePan={true} enableZoom={true} maxPolarAngle={Math.PI / 1.8} minPolarAngle={Math.PI / 4} maxAzimuthAngle={Math.PI / 4} minAzimuthAngle={-Math.PI / 4} />
                        <Scene tiltDeg={tiltDeg} />
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
                    
                    <div className="mb-6">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <Maximize2 size={14} /> Transversal Tilt (Angle)
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className="text-orange-400">Tilt = {tiltDeg}°</span>
                        </div>
                        <input 
                            type="range" 
                            min="30" 
                            max="150" 
                            step="1" 
                            value={tiltDeg} 
                            onChange={(e) => setTiltDeg(parseInt(e.target.value))} 
                            className="w-full accent-orange-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer mt-2"
                        />
                    </div>

                    <div className="flex flex-col gap-2 mb-4 bg-slate-950/50 p-3 rounded-lg border border-slate-800">
                        <div className="text-xs font-semibold text-slate-300 mb-1 flex items-center gap-2">
                            <Layers size={14} className="text-blue-400"/> Angle Relationships
                        </div>
                        <div className="flex justify-between text-[11px] text-slate-400 font-mono">
                            <span className="text-cyan-400">∠1, ∠3, ∠5, ∠7 (Equal)</span>
                            <span className="text-yellow-400">∠2, ∠4, ∠6, ∠8 (Equal)</span>
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="mt-auto">
                        <button 
                            onClick={handleReset} 
                            className="w-full bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
                        >
                            <RotateCcw size={16} /> Reset Transversal
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
