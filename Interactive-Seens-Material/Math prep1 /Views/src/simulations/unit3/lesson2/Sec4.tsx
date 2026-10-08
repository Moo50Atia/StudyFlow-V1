import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Ruler, Square, AlignEndHorizontal } from 'lucide-react';

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

function Scene({ abd, dbc }: { abd: number, dbc: number }) {
    const { camera } = useThree();
    
    // Animation Proxy
    const proxy = useRef({ 
        abdRad: abd * Math.PI / 180,
        dbcRad: dbc * Math.PI / 180,
        op90: 0,
        op180: 0
    }).current;
    
    // Refs for Rays
    const rayBCRef = useRef<THREE.Group>(null); // fixed at 0
    const rayBDRef = useRef<THREE.Group>(null);
    const rayBARef = useRef<THREE.Group>(null);
    
    // Refs for Arcs
    const arcDBCRef = useRef<THREE.Line>(null);
    const arcABDRef = useRef<THREE.Line>(null);
    const arc180Ref = useRef<THREE.Line>(null);

    // Refs for Materials
    const mat90Ref = useRef<THREE.LineBasicMaterial>(null);
    const mat180Ref = useRef<THREE.LineBasicMaterial>(null);
    const text90Ref = useRef<any>(null);
    const text180Ref = useRef<any>(null);

    // Texts
    const textDBCGroupRef = useRef<THREE.Group>(null);
    const textABDGroupRef = useRef<THREE.Group>(null);
    const textDBCRef = useRef<any>(null);
    const textABDRef = useRef<any>(null);

    // Geometries
    const makeGeom = () => {
        const geom = new THREE.BufferGeometry();
        geom.setAttribute('position', new THREE.BufferAttribute(new Float32Array(100 * 3), 3));
        return geom;
    };

    const arcDBCGeom = useMemo(makeGeom, []);
    const arcABDGeom = useMemo(makeGeom, []);
    const arc180Geom = useMemo(makeGeom, []);

    const squareCornerGeom = useMemo(() => {
        const geom = new THREE.BufferGeometry();
        geom.setAttribute('position', new THREE.BufferAttribute(new Float32Array([
            0.6, 0, 0,
            0.6, 0.6, 0,
            0, 0.6, 0
        ]), 3));
        return geom;
    }, []);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: -2, z: 12, duration: 1.5, ease: "power2.inOut" 
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
        geom.setDrawRange(0, segments + 1);
        geom.attributes.position.needsUpdate = true;
    };

    useFrame(() => {
        // Smooth target tracking
        proxy.abdRad = THREE.MathUtils.lerp(proxy.abdRad, abd * Math.PI / 180, 0.1);
        proxy.dbcRad = THREE.MathUtils.lerp(proxy.dbcRad, dbc * Math.PI / 180, 0.1);
        
        const totalDeg = abd + dbc;
        proxy.op90 = THREE.MathUtils.lerp(proxy.op90, totalDeg === 90 ? 1 : 0, 0.1);
        proxy.op180 = THREE.MathUtils.lerp(proxy.op180, totalDeg === 180 ? 1 : 0, 0.1);

        const r1 = 0; // BC
        const r2 = proxy.dbcRad; // BD
        const r3 = proxy.dbcRad + proxy.abdRad; // BA

        if (rayBDRef.current) rayBDRef.current.rotation.z = r2;
        if (rayBARef.current) rayBARef.current.rotation.z = r3;

        const arcRadius = 2.5;
        const arc180Radius = 3.2;

        // Draw Arcs
        if (arcDBCRef.current) updateArc(arcDBCRef.current.geometry, r1, r2, arcRadius);
        if (arcABDRef.current) updateArc(arcABDRef.current.geometry, r2, r3, arcRadius);
        if (arc180Ref.current) updateArc(arc180Ref.current.geometry, 0, Math.PI, arc180Radius);

        // Texts positions
        const textRadius = 1.5;
        if (textDBCGroupRef.current && textDBCRef.current) {
            const mid = r1 + (r2 - r1) / 2;
            textDBCGroupRef.current.position.set(Math.cos(mid) * textRadius, Math.sin(mid) * textRadius, 0);
            textDBCRef.current.text = `${Math.round(proxy.dbcRad * 180 / Math.PI)}°`;
        }
        
        if (textABDGroupRef.current && textABDRef.current) {
            const mid = r2 + (r3 - r2) / 2;
            textABDGroupRef.current.position.set(Math.cos(mid) * textRadius, Math.sin(mid) * textRadius, 0);
            textABDRef.current.text = `${Math.round(proxy.abdRad * 180 / Math.PI)}°`;
        }

        // Opacity Updates for Badges
        if (mat90Ref.current) mat90Ref.current.opacity = proxy.op90;
        if (mat180Ref.current) mat180Ref.current.opacity = proxy.op180;
        if (text90Ref.current) text90Ref.current.fillOpacity = proxy.op90;
        if (text180Ref.current) text180Ref.current.fillOpacity = proxy.op180;
    });

    return (
        <group position={[-1, -1.5, 0]}>
            <ambientLight intensity={0.6} />
            <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow />
            <Environment preset="city" opacity={0.3} />
            
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[50, 50]} />
                <meshStandardMaterial color="#020617" />
            </mesh>
            <gridHelper args={[50, 50, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* Vertex B */}
            <group position={[0,0,0.1]}>
                <Sphere args={[0.2, 32, 32]}>
                    <meshStandardMaterial color="#f8fafc" roughness={0.2} metalness={0.5} />
                </Sphere>
                <Text position={[-0.5, -0.5, 0]} fontSize={0.5} color="#ffffff" fontWeight="bold">B</Text>
            </group>

            {/* Rays */}
            <Ray color="#94a3b8" label="C" ref={rayBCRef} />
            <Ray color="#22d3ee" label="D" ref={rayBDRef} length={5.5} />
            <Ray color="#94a3b8" label="A" ref={rayBARef} />

            {/* Arcs */}
            <line ref={arcDBCRef as any} geometry={arcDBCGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#3b82f6" linewidth={5} transparent opacity={0.9} /> {/* Blue */}
            </line>
            
            <line ref={arcABDRef as any} geometry={arcABDGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#10b981" linewidth={5} transparent opacity={0.9} /> {/* Green */}
            </line>

            {/* Texts */}
            <group ref={textDBCGroupRef}>
                <Text ref={textDBCRef} fontSize={0.4} color="#3b82f6" fontWeight="bold" anchorX="center" anchorY="middle">130°</Text>
            </group>
            
            <group ref={textABDGroupRef}>
                <Text ref={textABDRef} fontSize={0.4} color="#10b981" fontWeight="bold" anchorX="center" anchorY="middle">50°</Text>
            </group>

            {/* Right Angle Badge (90°) */}
            <group position={[0,0,0.06]}>
                <line geometry={squareCornerGeom}>
                    <lineBasicMaterial ref={mat90Ref} color="#22c55e" linewidth={4} transparent opacity={0} />
                </line>
                <Text ref={text90Ref} position={[1, 1, 0]} fontSize={0.4} color="#22c55e" fontWeight="bold" transparent fillOpacity={0}>
                    Perpendicular (90°)
                </Text>
            </group>

            {/* Straight Line Badge (180°) */}
            <group position={[0,0,0.06]}>
                <line ref={arc180Ref as any} geometry={arc180Geom}>
                    <lineBasicMaterial ref={mat180Ref} color="#facc15" linewidth={5} transparent opacity={0} />
                </line>
                <Text ref={text180Ref} position={[0, 3.8, 0]} fontSize={0.4} color="#facc15" fontWeight="bold" transparent fillOpacity={0} anchorX="center">
                    Straight Line (180°)
                </Text>
            </group>

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function AdjacentAngleOutcomesSimulation() {
    const [abd, setAbd] = useState(50);
    const [dbc, setDbc] = useState(130);

    const sum = abd + dbc;

    const handleAbdChange = (val: number) => {
        if (val + dbc > 180) setDbc(180 - val);
        setAbd(val);
    };

    const handleDbcChange = (val: number) => {
        if (abd + val > 180) setAbd(180 - val);
        setDbc(val);
    };

    const applyPreset = (newAbd: number, newDbc: number) => {
        setAbd(newAbd);
        setDbc(newDbc);
    };

    const renderMath = () => {
        let sumColor = "white";
        if (sum === 180) sumColor = "#facc15";
        if (sum === 90) sumColor = "#22c55e";

        return (
            <BlockMath math={`m\\angle ABD + m\\angle DBC = \\color{#10b981}${abd}^\\circ \\color{white}+ \\color{#3b82f6}${dbc}^\\circ \\color{white}= \\color{${sumColor}}${sum}^\\circ`} />
        );
    };

    const getAlertContent = () => {
        if (sum === 180) {
            return { 
                title: "Straight Line Confirmed", 
                icon: <AlignEndHorizontal size={18}/>, 
                color: "border-l-yellow-500 text-yellow-300 bg-yellow-900/40", 
                body: <>Because the sum is exactly <b>180°</b>, the non-common outer rays (BA and BC) flatten perfectly into a straight continuous line AC.</> 
            };
        }
        if (sum === 90) {
            return { 
                title: "Perpendicular Confirmed", 
                icon: <Square size={18}/>, 
                color: "border-l-green-500 text-green-300 bg-green-900/40", 
                body: <>Because the sum is exactly <b>90°</b>, the non-common outer rays (BA and BC) snap to form a perfectly perpendicular right angle.</> 
            };
        }
        return { 
            title: "General Adjacent Angles", 
            icon: <ShieldAlert size={18}/>, 
            color: "border-l-rose-500 text-rose-300 bg-rose-900/40", 
            body: <><b>CRITICAL MISCONCEPTION:</b> Do not assume any two adjacent angles form a straight line! Unless they sum exactly to 180°, the outer rays are just an ordinary bent angle.</> 
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
                            Adjacent Angle Sum Outcomes
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Testing straight lines vs perpendicular sums.
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
                        <OrbitControls enablePan={false} enableZoom={false} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <Scene abd={abd} dbc={dbc} />
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
                    
                    <div className="grid grid-cols-2 gap-3 mb-6">
                        <button 
                            onClick={() => applyPreset(50, 130)} 
                            className={`font-bold py-2.5 px-3 rounded-lg flex items-center justify-center gap-2 transition-all border text-xs
                                ${sum === 180 ? 'bg-yellow-900/40 border-yellow-500 text-yellow-300' : 'bg-slate-800 border-slate-700 text-slate-400 hover:bg-slate-700'}`}
                        >
                            <AlignEndHorizontal size={14} /> Case 1: 50° + 130°
                        </button>
                        <button 
                            onClick={() => applyPreset(42, 48)} 
                            className={`font-bold py-2.5 px-3 rounded-lg flex items-center justify-center gap-2 transition-all border text-xs
                                ${sum === 90 ? 'bg-green-900/40 border-green-500 text-green-300' : 'bg-slate-800 border-slate-700 text-slate-400 hover:bg-slate-700'}`}
                        >
                            <Square size={14} /> Case 2: 42° + 48°
                        </button>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {/* ABD Slider */}
                        <div>
                            <div className="flex justify-between text-xs font-bold mb-1">
                                <span className="text-slate-400">m∠ABD</span>
                                <span className="text-green-400">{abd}°</span>
                            </div>
                            <input 
                                type="range" 
                                min="10" 
                                max="170" 
                                step="1" 
                                value={abd} 
                                onChange={(e) => handleAbdChange(parseInt(e.target.value))} 
                                className="w-full accent-green-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer"
                            />
                        </div>

                        {/* DBC Slider */}
                        <div>
                            <div className="flex justify-between text-xs font-bold mb-1">
                                <span className="text-slate-400">m∠DBC</span>
                                <span className="text-blue-400">{dbc}°</span>
                            </div>
                            <input 
                                type="range" 
                                min="10" 
                                max="170" 
                                step="1" 
                                value={dbc} 
                                onChange={(e) => handleDbcChange(parseInt(e.target.value))} 
                                className="w-full accent-blue-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
