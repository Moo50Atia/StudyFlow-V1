import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Maximize2, RotateCcw, Equal, FlipHorizontal } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function Scene({ tiltDeg, orientation }: { tiltDeg: number, orientation: 'right' | 'left' }) {
    const { camera } = useThree();
    
    // Animation Proxy
    const proxy = useRef({ 
        tiltRad: tiltDeg * Math.PI / 180,
        flip: orientation === 'right' ? 1 : -1
    }).current;
    
    // Geometric constants
    const d = 2.0; // Half distance between parallel lines (L1 at y=2, L2 at y=-2)
    const arcRadius = 1.2;
    const textRadius = 1.8;

    // Refs for Intersections & Transversal
    const transRef = useRef<THREE.Group>(null);
    const pointA = useRef<THREE.Group>(null);
    const pointB = useRef<THREE.Group>(null);
    
    // F-Shape Refs
    const fSpineRef = useRef<THREE.Mesh>(null);
    const fArmTopRef = useRef<THREE.Mesh>(null);
    const fArmBotRef = useRef<THREE.Mesh>(null);

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
        // Handle angles correctly depending on direction
        let s = start;
        let e = end;
        if (e < s) e += Math.PI * 2;
        
        const delta = e - s;
        for (let i = 0; i <= segments; i++) {
            const theta = s + (i / segments) * delta;
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
        proxy.flip = THREE.MathUtils.lerp(proxy.flip, orientation === 'right' ? 1 : -1, 0.1);
        
        const theta = proxy.tiltRad;
        const tanTheta = Math.tan(theta);
        const sinTheta = Math.sin(theta);
        const cosTheta = Math.cos(theta);
        
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

        // F-Shape Update
        // Spine goes along transversal, from a bit above A to a bit below B
        const ext = 1.5; // extension past intersections
        const spineLen = (2 * d) / Math.abs(sinTheta) + ext;
        
        if (fSpineRef.current) {
            // center of spine is slightly offset to match arms
            fSpineRef.current.position.set((xa+xb)/2, 0, 0.02);
            fSpineRef.current.rotation.z = theta - Math.PI/2;
            fSpineRef.current.scale.y = spineLen;
        }

        // Arms: position at A and B, extend horizontally. 
        // We use proxy.flip to animate the arm direction (scale X from 1 to -1)
        const armLen = 5;
        if (fArmTopRef.current) {
            // center of arm is half length away from A
            fArmTopRef.current.position.set(xa + (armLen/2) * proxy.flip, d, 0.02);
            fArmTopRef.current.scale.x = proxy.flip; // flip geometrically
        }
        if (fArmBotRef.current) {
            fArmBotRef.current.position.set(xb + (armLen/2) * proxy.flip, -d, 0.02);
            fArmBotRef.current.scale.x = proxy.flip;
        }

        // Update Arcs
        // If right (flip = 1), angle is from 0 to theta (or rather, from theta to 2PI if obtuse, but it's "under arm")
        // "Under arm" means the angle between the arm (0 deg) and the spine going DOWN (theta + PI)
        // Wait, T goes from angle theta. Downward is theta + PI.
        // Actually, T line angle is theta. The vector pointing down-right is theta + PI? No, if theta is 110 (top-left to bottom-right),
        // vector going down is theta + PI.
        const spineDownAngle = (theta + Math.PI) % (Math.PI * 2);
        
        // If orientation is right, arm is at 0 rad.
        // If orientation is left, arm is at PI rad.
        const armAngle = proxy.flip > 0 ? 0 : Math.PI;

        if (arcTopRef.current) updateArc(arcTopRef.current.geometry, armAngle, spineDownAngle, arcRadius);
        if (arcBotRef.current) updateArc(arcBotRef.current.geometry, armAngle, spineDownAngle, arcRadius);

        // Calculate actual angle value for text
        // If right: angle is between 0 and spineDownAngle
        let currentAngleDeg = 0;
        if (orientation === 'right') {
            // Angle between (1,0) and down-spine
            let diff = spineDownAngle - 0;
            if (diff < 0) diff += Math.PI * 2;
            currentAngleDeg = Math.round(diff * 180 / Math.PI);
            if (currentAngleDeg > 180) currentAngleDeg = 360 - currentAngleDeg;
        } else {
            // Angle between (-1,0) and down-spine
            let diff = spineDownAngle - Math.PI;
            if (diff < 0) diff += Math.PI * 2;
            currentAngleDeg = Math.round(diff * 180 / Math.PI);
            if (currentAngleDeg > 180) currentAngleDeg = 360 - currentAngleDeg;
        }

        // Texts
        if (textTopGroupRef.current && textTopRef.current) {
            // Midpoint of arc
            let mid = (armAngle + spineDownAngle) / 2;
            // Handle wrap around
            if (Math.abs(armAngle - spineDownAngle) > Math.PI) {
                mid += Math.PI;
            }
            
            textTopGroupRef.current.position.set(xa + Math.cos(mid) * textRadius, d + Math.sin(mid) * textRadius, 0);
            textTopRef.current.text = `${currentAngleDeg}°`;
        }

        if (textBotGroupRef.current && textBotRef.current) {
            let mid = (armAngle + spineDownAngle) / 2;
            if (Math.abs(armAngle - spineDownAngle) > Math.PI) {
                mid += Math.PI;
            }
            
            textBotGroupRef.current.position.set(xb + Math.cos(mid) * textRadius, -d + Math.sin(mid) * textRadius, 0);
            textBotRef.current.text = `${currentAngleDeg}°`;
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

            {/* F-SHAPE WIREFRAME (Glowing Cyan) */}
            <mesh ref={fSpineRef}>
                <cylinderGeometry args={[0.08, 0.08, 1]} />
                <meshStandardMaterial color="#22d3ee" emissive="#22d3ee" emissiveIntensity={1.5} toneMapped={false} />
            </mesh>
            
            <mesh ref={fArmTopRef} rotation={[0, 0, -Math.PI/2]}>
                <cylinderGeometry args={[0.08, 0.08, 5]} />
                <meshStandardMaterial color="#22d3ee" emissive="#22d3ee" emissiveIntensity={1.5} toneMapped={false} />
            </mesh>
            
            <mesh ref={fArmBotRef} rotation={[0, 0, -Math.PI/2]}>
                <cylinderGeometry args={[0.08, 0.08, 5]} />
                <meshStandardMaterial color="#22d3ee" emissive="#22d3ee" emissiveIntensity={1.5} toneMapped={false} />
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

            {/* Arcs (Green) */}
            <line ref={arcTopRef as any} geometry={arcTopGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#22c55e" linewidth={6} transparent opacity={0.9} />
            </line>
            <line ref={arcBotRef as any} geometry={arcBotGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#22c55e" linewidth={6} transparent opacity={0.9} />
            </line>

            {/* Texts */}
            <group ref={textTopGroupRef}>
                <Text ref={textTopRef} fontSize={0.6} color="#22c55e" fontWeight="bold" anchorX="center" anchorY="middle">110°</Text>
            </group>
            
            <group ref={textBotGroupRef}>
                <Text ref={textBotRef} fontSize={0.6} color="#22c55e" fontWeight="bold" anchorX="center" anchorY="middle">110°</Text>
            </group>

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function CorrespondingAngles() {
    const [tiltDeg, setTiltDeg] = useState(110);
    const [orientation, setOrientation] = useState<'right' | 'left'>('right');

    const handleFlip = () => {
        setOrientation(prev => prev === 'right' ? 'left' : 'right');
    };

    const currentAngle = orientation === 'right' ? tiltDeg : 180 - tiltDeg;

    const renderMath = () => {
        return (
            <BlockMath math={`m\\angle \\text{Corr}_1 = m\\angle \\text{Corr}_2 = \\color{#22c55e}${currentAngle}^\\circ`} />
        );
    };

    const alertContent = { 
        title: "Corresponding Angles Location", 
        icon: <ShieldAlert size={18}/>, 
        color: "border-l-cyan-500 text-cyan-300 bg-cyan-900/40", 
        body: <><b>CRITICAL MISCONCEPTION:</b> Students often mistakenly look on opposite sides of the transversal. Corresponding angles <b>ALWAYS sit on the SAME side</b> of the transversal, occupying the exact same relative corner at each intersection (like the "under-arms" of the F-Shape).</> 
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            Corresponding Angles (F-Shape)
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Same relative position on the transversal.
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
                        <Scene tiltDeg={tiltDeg} orientation={orientation} />
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
                            <span className="text-cyan-400">Angle = {tiltDeg}°</span>
                        </div>
                        <input 
                            type="range" 
                            min="30" 
                            max="150" 
                            step="1" 
                            value={tiltDeg} 
                            onChange={(e) => setTiltDeg(parseInt(e.target.value))} 
                            className="w-full accent-cyan-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer mt-2"
                        />
                    </div>

                    <div className="flex flex-col gap-2 mb-4 bg-cyan-950/30 p-3 rounded-lg border border-cyan-900/50">
                        <div className="text-xs font-semibold text-cyan-400 mb-1 flex items-center gap-2">
                            <Equal size={14} /> F-Shape Equality Lock
                        </div>
                        <div className="text-[11px] text-slate-300 leading-tight">
                            The cyan wireframe traps the corresponding angles. They are mathematically identical copies shifted along the transversal.
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={handleFlip} 
                            className="bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg shadow-blue-900/20 text-sm"
                        >
                            <FlipHorizontal size={16} /> Flip F-Shape 180°
                        </button>
                        <button 
                            onClick={() => { setTiltDeg(110); setOrientation('right'); }} 
                            className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
                        >
                            <RotateCcw size={16} /> Reset
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
