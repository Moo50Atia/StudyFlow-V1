import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Maximize2, RotateCcw, Link } from 'lucide-react';

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
    const arcRadius = 1.3;
    const textRadius = 2.0;

    // Refs for Intersections & Transversal
    const transRef = useRef<THREE.Group>(null);
    const pointA = useRef<THREE.Group>(null);
    const pointB = useRef<THREE.Group>(null);
    
    // U-Shape Refs
    const uSpineRef = useRef<THREE.Mesh>(null);
    const uArmTopRef = useRef<THREE.Mesh>(null);
    const uArmBotRef = useRef<THREE.Mesh>(null);

    // Refs for Arcs
    const arcTopRef = useRef<THREE.Line>(null);
    const arcBotRef = useRef<THREE.Line>(null);

    // Refs for Texts
    const textTopGroupRef = useRef<THREE.Group>(null);
    const textBotGroupRef = useRef<THREE.Group>(null);
    const textTopRef = useRef<any>(null);
    const textBotRef = useRef<any>(null);

    // Geometries
    const makeGeom = () => {
        const geom = new THREE.BufferGeometry();
        geom.setAttribute('position', new THREE.BufferAttribute(new Float32Array(65 * 3), 3));
        return geom;
    };

    const arcTopGeom = useMemo(makeGeom, []);
    const arcBotGeom = useMemo(makeGeom, []);

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
        geom.setDrawRange(0, segments + 1);
        geom.attributes.position.needsUpdate = true;
    };

    useFrame(() => {
        // Smooth target tracking
        proxy.tiltRad = THREE.MathUtils.lerp(proxy.tiltRad, tiltDeg * Math.PI / 180, 0.1);
        
        const theta = proxy.tiltRad;
        const tanTheta = Math.tan(theta);
        const sinTheta = Math.sin(theta);
        
        // Calculate intersection x coordinates
        const xa = d / tanTheta;
        const xb = -d / tanTheta;

        // Base Line positions
        if (pointA.current) pointA.current.position.set(xa, d, 0);
        if (pointB.current) pointB.current.position.set(xb, -d, 0);
        
        // Transversal rotation
        if (transRef.current) {
            transRef.current.position.set(0, 0, 0);
            transRef.current.rotation.z = theta - Math.PI/2;
        }

        // U-Shape Update (Orange Wireframe)
        const ext = 0; 
        const spineLen = Math.abs((2 * d) / sinTheta);
        
        if (uSpineRef.current) {
            uSpineRef.current.position.set((xa+xb)/2, 0, 0.02);
            uSpineRef.current.rotation.z = theta - Math.PI/2;
            uSpineRef.current.scale.y = spineLen;
        }

        const armLen = 5.5;
        if (uArmTopRef.current) {
            uArmTopRef.current.position.set(xa + armLen/2, d, 0.02);
        }
        if (uArmBotRef.current) {
            uArmBotRef.current.position.set(xb + armLen/2, -d, 0.02);
        }

        // Update Arcs
        // Bottom interior arc (right side): angle from 0 to theta
        if (arcBotRef.current) {
            updateArc(arcBotRef.current.geometry, 0, theta, arcRadius);
        }
        
        // Top interior arc (right side): angle from (PI + theta) to 2*PI
        const topStart = Math.PI + theta;
        const topEnd = Math.PI * 2;
        if (arcTopRef.current) {
            updateArc(arcTopRef.current.geometry, topStart, topEnd, arcRadius);
        }

        // Angle math for display
        const botDeg = Math.round(theta * 180 / Math.PI);
        const topDeg = 180 - botDeg;

        // Update Texts
        if (textTopGroupRef.current && textTopRef.current) {
            const mid = topStart + (topEnd - topStart) / 2;
            textTopGroupRef.current.position.set(xa + Math.cos(mid) * textRadius, d + Math.sin(mid) * textRadius, 0);
            textTopRef.current.text = `${topDeg}°`;
        }

        if (textBotGroupRef.current && textBotRef.current) {
            const mid = theta / 2;
            textBotGroupRef.current.position.set(xb + Math.cos(mid) * textRadius, -d + Math.sin(mid) * textRadius, 0);
            textBotRef.current.text = `${botDeg}°`;
        }
    });

    return (
        <group position={[0, 0, 0]}>
            <ambientLight intensity={0.6} />
            <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow />
            <Environment preset="city" opacity={0.3} />
            
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[50, 50]} />
                <meshStandardMaterial color="#020617" />
            </mesh>
            <gridHelper args={[50, 50, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* Parallel Track L1 */}
            <group position={[0, d, 0]}>
                <mesh rotation={[0, 0, -Math.PI/2]} castShadow>
                    <cylinderGeometry args={[0.06, 0.06, 20]} />
                    <meshStandardMaterial color="#94a3b8" roughness={0.3} metalness={0.2} />
                </mesh>
                <Text position={[-9, 0.4, 0]} fontSize={0.5} color="#94a3b8" fontWeight="bold">L1</Text>
            </group>

            {/* Parallel Track L2 */}
            <group position={[0, -d, 0]}>
                <mesh rotation={[0, 0, -Math.PI/2]} castShadow>
                    <cylinderGeometry args={[0.06, 0.06, 20]} />
                    <meshStandardMaterial color="#94a3b8" roughness={0.3} metalness={0.2} />
                </mesh>
                <Text position={[-9, 0.4, 0]} fontSize={0.5} color="#94a3b8" fontWeight="bold">L2</Text>
            </group>

            {/* Transversal Track T (Base) */}
            <group ref={transRef}>
                <mesh castShadow>
                    <cylinderGeometry args={[0.04, 0.04, 20]} />
                    <meshStandardMaterial color="#94a3b8" roughness={0.3} metalness={0.2} />
                </mesh>
                <Text position={[0, 9, 0]} rotation={[0,0,0]} fontSize={0.5} color="#94a3b8" fontWeight="bold">T</Text>
            </group>

            {/* U-SHAPE WIREFRAME (Glowing Orange) */}
            <mesh ref={uSpineRef}>
                <cylinderGeometry args={[0.08, 0.08, 1]} />
                <meshStandardMaterial color="#f97316" emissive="#f97316" emissiveIntensity={1.5} toneMapped={false} />
            </mesh>
            
            <mesh ref={uArmTopRef} rotation={[0, 0, -Math.PI/2]}>
                <cylinderGeometry args={[0.08, 0.08, 5.5]} />
                <meshStandardMaterial color="#f97316" emissive="#f97316" emissiveIntensity={1.5} toneMapped={false} />
            </mesh>
            
            <mesh ref={uArmBotRef} rotation={[0, 0, -Math.PI/2]}>
                <cylinderGeometry args={[0.08, 0.08, 5.5]} />
                <meshStandardMaterial color="#f97316" emissive="#f97316" emissiveIntensity={1.5} toneMapped={false} />
            </mesh>

            {/* Vertices A & B */}
            <group ref={pointA}>
                <Sphere args={[0.2, 32, 32]} position={[0,0,0.05]}>
                    <meshStandardMaterial color="#ffffff" roughness={0.2} metalness={0.5} />
                </Sphere>
            </group>

            <group ref={pointB}>
                <Sphere args={[0.2, 32, 32]} position={[0,0,0.05]}>
                    <meshStandardMaterial color="#ffffff" roughness={0.2} metalness={0.5} />
                </Sphere>
            </group>

            {/* Arcs (Colored specifically for sum mapping) */}
            <line ref={arcTopRef as any} geometry={arcTopGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#22c55e" linewidth={6} transparent opacity={0.9} /> {/* Green */}
            </line>
            <line ref={arcBotRef as any} geometry={arcBotGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#06b6d4" linewidth={6} transparent opacity={0.9} /> {/* Cyan */}
            </line>

            {/* Texts */}
            <group ref={textTopGroupRef}>
                <Text ref={textTopRef} fontSize={0.6} color="#22c55e" fontWeight="bold" anchorX="center" anchorY="middle">115°</Text>
            </group>
            
            <group ref={textBotGroupRef}>
                <Text ref={textBotRef} fontSize={0.6} color="#06b6d4" fontWeight="bold" anchorX="center" anchorY="middle">65°</Text>
            </group>

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function ConsecutiveInteriorAngles() {
    // Initial specified state: lower is 65, upper is 115. Tilt is lower angle.
    const [tiltDeg, setTiltDeg] = useState(65);

    const upperDeg = 180 - tiltDeg;
    const lowerDeg = tiltDeg;

    const handlePreset = (newTilt: number) => {
        setTiltDeg(newTilt);
    };

    const renderMath = () => {
        return (
            <BlockMath math={`m\\angle \\text{Int}_1 + m\\angle \\text{Int}_2 = \\color{#22c55e}${upperDeg}^\\circ \\color{white}+ \\color{#06b6d4}${lowerDeg}^\\circ \\color{white}= \\color{#facc15}180^\\circ`} />
        );
    };

    const alertContent = { 
        title: "Consecutive Interior Angles", 
        icon: <ShieldAlert size={18}/>, 
        color: "border-l-rose-500 text-rose-300 bg-rose-900/40", 
        body: <><b>CRITICAL MISCONCEPTION:</b> Do not assume all interior angles are equal! While alternate interior angles are equal, Consecutive Interior Angles (on the <b>SAME</b> side of the transversal) are always <b>Supplementary</b>—meaning they add up to exactly 180°.</> 
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            Consecutive Interior Angles (U-Shape)
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Same-side interior angles sum to 180°.
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
                        <OrbitControls enablePan={true} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={Math.PI / 4} minAzimuthAngle={-Math.PI / 4} />
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
                            <Maximize2 size={14} /> Transversal Tilt
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className="text-green-400">Upper = {upperDeg}°</span>
                            <span className="text-cyan-400">Lower = {lowerDeg}°</span>
                        </div>
                        {/* 
                            Note: Slider directly maps to lowerDeg (tiltDeg). 
                            When slider is at 50, lower is 50, upper is 130.
                        */}
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

                    <div className="flex flex-col gap-2 mb-4 bg-orange-950/30 p-3 rounded-lg border border-orange-900/50">
                        <div className="text-xs font-semibold text-orange-400 mb-1 flex items-center gap-2">
                            <Link size={14} /> Supplementary Pod (U-Shape)
                        </div>
                        <div className="text-[11px] text-slate-300 leading-tight">
                            The orange wireframe binds the two consecutive interior angles. Their combined sum is physically locked to 180° by the parallel tracks.
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={() => handlePreset(50)} 
                            className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
                        >
                            <RotateCcw size={16} /> Test 130° / 50°
                        </button>
                        <button 
                            onClick={() => handlePreset(65)} 
                            className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
                        >
                            <RotateCcw size={16} /> Reset 115° / 65°
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
