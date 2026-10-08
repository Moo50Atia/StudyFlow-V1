import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere, Line as DreiLine } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Split, RotateCcw, Star } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function Ray({ color, label, position, rotation = [0,0,0], length = 5, labelOffset = [0,0,0], isLaser = false }: any) {
    return (
        <group position={position} rotation={rotation}>
            <mesh position={[length / 2, 0, 0]} rotation={[0, 0, -Math.PI/2]} castShadow={!isLaser}>
                <cylinderGeometry args={[isLaser ? 0.08 : 0.06, isLaser ? 0.08 : 0.06, length]} />
                {isLaser ? (
                    <meshStandardMaterial color={color} emissive={color} emissiveIntensity={1.5} toneMapped={false} />
                ) : (
                    <meshStandardMaterial color={color} roughness={0.3} metalness={0.2} />
                )}
            </mesh>
            <mesh position={[length, 0, 0]} rotation={[0, 0, -Math.PI/2]} castShadow={!isLaser}>
                <coneGeometry args={[isLaser ? 0.3 : 0.25, 0.5]} />
                {isLaser ? (
                    <meshStandardMaterial color={color} emissive={color} emissiveIntensity={1.5} toneMapped={false} />
                ) : (
                    <meshStandardMaterial color={color} roughness={0.3} metalness={0.2} />
                )}
            </mesh>
            <group position={[length + 0.5 + labelOffset[0], labelOffset[1], labelOffset[2]]}>
                <Text fontSize={0.5} color={color} fontWeight="bold">{label}</Text>
            </group>
        </group>
    );
}

function BisectorScene({ totalDeg }: { totalDeg: number }) {
    const { camera } = useThree();
    
    // Animation Proxy
    const proxy = useRef({ total: 80 * Math.PI / 180 }).current;
    
    // Refs
    const rayBARef = useRef<THREE.Group>(null);
    const rayBDRef = useRef<THREE.Group>(null);
    const labelARef = useRef<THREE.Group>(null);
    const labelDRef = useRef<THREE.Group>(null);
    
    const arc1Ref = useRef<THREE.Line>(null);
    const arc2Ref = useRef<THREE.Line>(null);
    const text1GroupRef = useRef<THREE.Group>(null);
    const text2GroupRef = useRef<THREE.Group>(null);
    const text1Ref = useRef<any>(null);
    const text2Ref = useRef<any>(null);

    // Geometries for the arcs
    const arc1Geometry = useMemo(() => {
        const geom = new THREE.BufferGeometry();
        geom.setAttribute('position', new THREE.BufferAttribute(new Float32Array(65 * 3), 3));
        return geom;
    }, []);

    const arc2Geometry = useMemo(() => {
        const geom = new THREE.BufferGeometry();
        geom.setAttribute('position', new THREE.BufferAttribute(new Float32Array(65 * 3), 3));
        return geom;
    }, []);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 0, z: 12, duration: 1.5, ease: "power2.inOut" 
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
        geom.attributes.position.needsUpdate = true;
    };

    useFrame(() => {
        // Smooth target tracking
        const targetRad = totalDeg * Math.PI / 180;
        proxy.total = THREE.MathUtils.lerp(proxy.total, targetRad, 0.1);
        
        const totalRad = proxy.total;
        const halfRad = totalRad / 2;
        
        // Rotate Rays
        if (rayBARef.current) rayBARef.current.rotation.z = totalRad;
        if (rayBDRef.current) rayBDRef.current.rotation.z = halfRad;
        
        // Counter-rotate Labels
        if (labelARef.current) labelARef.current.rotation.z = -totalRad;
        if (labelDRef.current) labelDRef.current.rotation.z = -halfRad;

        const arcRadius = 2.5;

        // Update Arc 1 (CBD: 0 to half)
        if (arc1Ref.current) updateArc(arc1Ref.current.geometry, 0, halfRad, arcRadius);
        
        // Update Arc 2 (ABD: half to total)
        if (arc2Ref.current) updateArc(arc2Ref.current.geometry, halfRad, totalRad, arcRadius);

        // Update Text Positions (incorporating the star icon via unicode)
        const textRadius = 3.2;
        if (text1GroupRef.current && text1Ref.current) {
            const mid1 = halfRad / 2;
            text1GroupRef.current.position.set(Math.cos(mid1) * textRadius, Math.sin(mid1) * textRadius, 0);
            text1Ref.current.text = `${Math.round(halfRad * 180 / Math.PI)}° ★`;
        }
        
        if (text2GroupRef.current && text2Ref.current) {
            const mid2 = halfRad + halfRad / 2;
            text2GroupRef.current.position.set(Math.cos(mid2) * textRadius, Math.sin(mid2) * textRadius, 0);
            text2Ref.current.text = `${Math.round(halfRad * 180 / Math.PI)}° ★`;
        }
    });

    return (
        <group position={[-1, -1, 0]}>
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
                <Text position={[-0.5, -0.5, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">B</Text>
            </group>

            {/* Base Ray BC (Fixed at 0 rad) */}
            <Ray color="#3b82f6" label="C" position={[0,0,0]} rotation={[0,0,0]} />
            
            {/* Outer Ray BA */}
            <group ref={rayBARef}>
                <mesh position={[2.5, 0, 0]} rotation={[0, 0, -Math.PI/2]} castShadow>
                    <cylinderGeometry args={[0.06, 0.06, 5]} />
                    <meshStandardMaterial color="#3b82f6" roughness={0.3} metalness={0.2} />
                </mesh>
                <mesh position={[5, 0, 0]} rotation={[0, 0, -Math.PI/2]} castShadow>
                    <coneGeometry args={[0.25, 0.5]} />
                    <meshStandardMaterial color="#3b82f6" roughness={0.3} metalness={0.2} />
                </mesh>
                <group position={[5.5, 0, 0]} ref={labelARef}>
                    <Text fontSize={0.5} color="#3b82f6" fontWeight="bold">A</Text>
                </group>
            </group>

            {/* Bisector Laser Ray BD */}
            <group ref={rayBDRef}>
                <mesh position={[3, 0, 0]} rotation={[0, 0, -Math.PI/2]}>
                    <cylinderGeometry args={[0.08, 0.08, 6]} />
                    <meshStandardMaterial color="#22d3ee" emissive="#22d3ee" emissiveIntensity={1.5} toneMapped={false} />
                </mesh>
                <mesh position={[6, 0, 0]} rotation={[0, 0, -Math.PI/2]}>
                    <coneGeometry args={[0.3, 0.5]} />
                    <meshStandardMaterial color="#22d3ee" emissive="#22d3ee" emissiveIntensity={1.5} toneMapped={false} />
                </mesh>
                <group position={[6.6, 0, 0]} ref={labelDRef}>
                    <Text fontSize={0.5} color="#22d3ee" fontWeight="bold">D</Text>
                </group>
            </group>

            {/* Arcs */}
            <line ref={arc1Ref as any} geometry={arc1Geometry} position={[0,0,0.05]}>
                <lineBasicMaterial color="#f59e0b" linewidth={5} transparent opacity={0.9} />
            </line>
            
            <line ref={arc2Ref as any} geometry={arc2Geometry} position={[0,0,0.05]}>
                <lineBasicMaterial color="#10b981" linewidth={5} transparent opacity={0.9} />
            </line>

            {/* Angle Texts */}
            <group ref={text1GroupRef}>
                <Text ref={text1Ref} fontSize={0.5} color="#f59e0b" fontWeight="bold">40° ★</Text>
            </group>
            
            <group ref={text2GroupRef}>
                <Text ref={text2Ref} fontSize={0.5} color="#10b981" fontWeight="bold">40° ★</Text>
            </group>

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function AngleBisectorSimulation() {
    const [totalDeg, setTotalDeg] = useState(80);
    const halfDeg = totalDeg / 2;

    const handleReset = () => {
        setTotalDeg(80);
    };

    const renderMath = () => {
        return (
            <BlockMath math={`m\\angle ABD = m\\angle CBD = \\frac{${totalDeg}^\\circ}{2} = \\color{#22d3ee}${halfDeg}^\\circ`} />
        );
    };

    const getAlertContent = () => {
        return { 
            title: "Angle Bisector Equality", 
            icon: <ShieldAlert size={18}/>, 
            color: "border-l-cyan-500 text-cyan-300 bg-cyan-900/40", 
            body: <><b>CRITICAL MISCONCEPTION:</b> Students often think an angle bisector merely cuts an angle arbitrarily. A true angle bisector <b>ALWAYS divides the angle into exactly two EQUAL measures</b> (indicated by the matching <Star size={12} className="inline text-yellow-400 fill-yellow-400 mb-1" /> badges).</> 
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
                            Angle Bisector
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            A ray that divides an angle into two equal parts.
                        </p>
                    </div>
                    <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[340px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto">
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
                        <BisectorScene totalDeg={totalDeg} />
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
                    
                    <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-4 flex items-center gap-2">
                        <Split size={14}/> Total Angle ABC Slider
                    </label>

                    {/* Continuous Slider */}
                    <div className="mb-4">
                        <div className="flex justify-between text-xs font-bold mb-2">
                            <span className="text-blue-400">m∠ABC = {totalDeg}°</span>
                            <span className="text-cyan-400">Bisected Sectors = {halfDeg}°</span>
                        </div>
                        <input 
                            type="range" 
                            min="20" 
                            max="160" 
                            step="2" 
                            value={totalDeg} 
                            onChange={(e) => setTotalDeg(parseInt(e.target.value))} 
                            className="w-full accent-blue-500 h-2 bg-slate-800 rounded-lg appearance-none cursor-pointer"
                        />
                    </div>

                    {/* Actions */}
                    <div className="mt-auto">
                        <button 
                            onClick={handleReset} 
                            className="w-full bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
                        >
                            <RotateCcw size={16} /> Reset to 80°
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
