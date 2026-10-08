import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Maximize2, RotateCcw, Equal } from 'lucide-react';

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
    const arcRadius = 1.2;
    const textRadius = 1.8;

    // Refs for Intersections & Transversal
    const transRef = useRef<THREE.Group>(null);
    const pointA = useRef<THREE.Group>(null);
    const pointB = useRef<THREE.Group>(null);
    
    // Z-Shape Refs
    const zTopRef = useRef<THREE.Mesh>(null);
    const zMidRef = useRef<THREE.Mesh>(null);
    const zBotRef = useRef<THREE.Mesh>(null);

    // Refs for Arcs
    const arcARef = useRef<THREE.Line>(null);
    const arcBRef = useRef<THREE.Line>(null);

    // Refs for Texts
    const textAGroupRef = useRef<THREE.Group>(null);
    const textBGroupRef = useRef<THREE.Group>(null);
    const textARef = useRef<any>(null);
    const textBRef = useRef<any>(null);

    // Geometries
    const makeGeom = () => {
        const geom = new THREE.BufferGeometry();
        geom.setAttribute('position', new THREE.BufferAttribute(new Float32Array(65 * 3), 3));
        return geom;
    };

    const arcAGeom = useMemo(makeGeom, []);
    const arcBGeom = useMemo(makeGeom, []);

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

        // Z-Shape Update
        if (zTopRef.current) zTopRef.current.position.set(xa - 3.5, d, 0.02);
        if (zBotRef.current) zBotRef.current.position.set(xb + 3.5, -d, 0.02);
        if (zMidRef.current) {
            zMidRef.current.rotation.z = theta - Math.PI/2;
            zMidRef.current.scale.y = (2 * d) / sinTheta;
        }

        // Update Arcs
        if (arcARef.current) updateArc(arcARef.current.geometry, Math.PI, Math.PI + theta, arcRadius);
        if (arcBRef.current) updateArc(arcBRef.current.geometry, 0, theta, arcRadius);

        // Update Texts
        const angleDisplay = Math.round(theta * 180 / Math.PI);
        if (textAGroupRef.current && textARef.current) {
            const mid = Math.PI + theta / 2;
            textAGroupRef.current.position.set(xa + Math.cos(mid) * textRadius, d + Math.sin(mid) * textRadius, 0);
            textARef.current.text = `${angleDisplay}°`;
        }

        if (textBGroupRef.current && textBRef.current) {
            const mid = theta / 2;
            textBGroupRef.current.position.set(xb + Math.cos(mid) * textRadius, -d + Math.sin(mid) * textRadius, 0);
            textBRef.current.text = `${angleDisplay}°`;
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

            {/* Z-SHAPE WIREFRAME (Glowing Yellow) */}
            <mesh ref={zTopRef} rotation={[0, 0, -Math.PI/2]}>
                <cylinderGeometry args={[0.08, 0.08, 7]} />
                <meshStandardMaterial color="#facc15" emissive="#facc15" emissiveIntensity={1.5} toneMapped={false} />
            </mesh>
            
            <mesh ref={zMidRef} position={[0,0,0.02]}>
                <cylinderGeometry args={[0.08, 0.08, 1]} />
                <meshStandardMaterial color="#facc15" emissive="#facc15" emissiveIntensity={1.5} toneMapped={false} />
            </mesh>
            
            <mesh ref={zBotRef} rotation={[0, 0, -Math.PI/2]}>
                <cylinderGeometry args={[0.08, 0.08, 7]} />
                <meshStandardMaterial color="#facc15" emissive="#facc15" emissiveIntensity={1.5} toneMapped={false} />
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

            {/* Arcs */}
            <line ref={arcARef as any} geometry={arcAGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#facc15" linewidth={6} transparent opacity={0.9} />
            </line>
            <line ref={arcBRef as any} geometry={arcBGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#facc15" linewidth={6} transparent opacity={0.9} />
            </line>

            {/* Texts */}
            <group ref={textAGroupRef}>
                <Text ref={textARef} fontSize={0.6} color="#facc15" fontWeight="bold" anchorX="center" anchorY="middle">A</Text>
            </group>
            
            <group ref={textBGroupRef}>
                <Text ref={textBRef} fontSize={0.6} color="#facc15" fontWeight="bold" anchorX="center" anchorY="middle">B</Text>
            </group>

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function AlternateInteriorAngles() {
    // Specification asks to start at 65, then move to 40
    const [tiltDeg, setTiltDeg] = useState(65);

    const handlePreset = (val: number) => {
        setTiltDeg(val);
    };

    const renderMath = () => {
        return (
            <BlockMath math={`m\\angle \\text{Alt}_1 = m\\angle \\text{Alt}_2 = \\color{#facc15}${tiltDeg}^\\circ`} />
        );
    };

    const alertContent = { 
        title: "Alternate Interior Angles", 
        icon: <ShieldAlert size={18}/>, 
        color: "border-l-rose-500 text-rose-300 bg-rose-900/40", 
        body: <><b>CRITICAL MISCONCEPTION:</b> Students often assume Alternate Interior Angles sum to 180° because they form a zigzag. <b>This is false.</b> Alternate interior angles (locked inside the glowing Z-shape) are always strictly <b>EQUAL</b> to each other when lines are parallel.</> 
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            Alternate Interior Angles (Z-Shape)
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Exploring the inner crooks of the transversal intersection.
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
                            <span className="text-yellow-400">Angle = {tiltDeg}°</span>
                        </div>
                        <input 
                            type="range" 
                            min="20" 
                            max="160" 
                            step="1" 
                            value={tiltDeg} 
                            onChange={(e) => setTiltDeg(parseInt(e.target.value))} 
                            className="w-full accent-yellow-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer mt-2"
                        />
                    </div>

                    <div className="flex flex-col gap-2 mb-4 bg-yellow-950/30 p-3 rounded-lg border border-yellow-900/50">
                        <div className="text-xs font-semibold text-yellow-400 mb-1 flex items-center gap-2">
                            <Equal size={14} /> Equality Lock
                        </div>
                        <div className="text-[11px] text-slate-300 leading-tight">
                            The yellow wireframe bounds the interior space. Notice how both crooks compress and expand <b>identically</b>.
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={() => handlePreset(40)} 
                            className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
                        >
                            <RotateCcw size={16} /> Test 40°
                        </button>
                        <button 
                            onClick={() => handlePreset(65)} 
                            className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
                        >
                            <RotateCcw size={16} /> Reset 65°
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
