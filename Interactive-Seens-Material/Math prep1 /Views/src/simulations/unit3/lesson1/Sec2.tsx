import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere, Line as DreiLine } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, Circle, Square, RotateCcw, ShieldAlert, Navigation } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function Ray({ color, label, position }: any) {
    return (
        <group position={position}>
            <mesh position={[2, 0, 0]} rotation={[0, 0, -Math.PI/2]} castShadow>
                <cylinderGeometry args={[0.06, 0.06, 4]} />
                <meshStandardMaterial color={color} roughness={0.3} metalness={0.2} />
            </mesh>
            <mesh position={[4, 0, 0]} rotation={[0, 0, -Math.PI/2]} castShadow>
                <coneGeometry args={[0.25, 0.5]} />
                <meshStandardMaterial color={color} roughness={0.3} metalness={0.2} />
            </mesh>
            {/* Label always stays upright - we will counter-rotate it in useFrame if it's the moving ray, but for simplicity we can just let it rotate or keep it simple. Let's just attach it. */}
            <Text position={[4.5, 0, 0]} fontSize={0.5} color={color} fontWeight="bold">{label}</Text>
        </group>
    );
}

function ProtractorMarks() {
    return (
        <group position={[0,0,-0.1]}>
             <mesh>
                 <ringGeometry args={[3.9, 4.0, 64]} />
                 <meshBasicMaterial color="#334155" transparent opacity={0.5} />
             </mesh>
             {Array.from({length: 36}).map((_, i) => { 
                 const a = (i * 10) * Math.PI / 180;
                 const isCard = i % 9 === 0; // 0, 90, 180, 270
                 const r1 = isCard ? 3.5 : 3.7;
                 const r2 = 4.0;
                 return (
                     <DreiLine key={i} points={[[Math.cos(a)*r1, Math.sin(a)*r1, 0], [Math.cos(a)*r2, Math.sin(a)*r2, 0]]} color={isCard ? "#cbd5e1" : "#64748b"} lineWidth={isCard ? 3 : 1} />
                 );
             })}
        </group>
    );
}

function AngleScene({ angleDeg }: { angleDeg: number }) {
    const { camera } = useThree();
    
    const proxy = useRef({ angle: 0, radius: 1.5 }).current;
    const rayGroupRef = useRef<THREE.Group>(null);
    const arcLineRef = useRef<THREE.Line>(null);
    const textGroupRef = useRef<THREE.Group>(null);
    const textRef = useRef<any>(null);

    const arcGeometry = useMemo(() => {
        const geom = new THREE.BufferGeometry();
        const positions = new Float32Array(65 * 3);
        geom.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        return geom;
    }, []);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 0, z: 12, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    useFrame(() => {
        const targetRad = angleDeg * Math.PI / 180;
        proxy.angle = THREE.MathUtils.lerp(proxy.angle, targetRad, 0.1);
        
        const a = proxy.angle;
        
        if (rayGroupRef.current) {
            rayGroupRef.current.rotation.z = a;
            // Counter-rotate the label B so it stays upright
            const labelGroup = rayGroupRef.current.children[0].children[2] as any;
            if (labelGroup && labelGroup.rotation) {
                labelGroup.rotation.z = -a;
            }
        }
        
        if (arcLineRef.current) {
            const positions = arcLineRef.current.geometry.attributes.position.array as Float32Array;
            const isSquare = Math.abs(a - Math.PI/2) < 0.05; // Lock to square if very close to 90
            const isReflex = a > Math.PI; // > 180
            
            const targetRadius = isReflex ? 2.2 : 1.2;
            proxy.radius = THREE.MathUtils.lerp(proxy.radius, targetRadius, 0.1);
            const r = proxy.radius;

            if (a < 0.01) {
                arcLineRef.current.visible = false;
                if (textGroupRef.current) textGroupRef.current.visible = false;
            } else {
                arcLineRef.current.visible = true;
                if (textGroupRef.current) textGroupRef.current.visible = true;
                
                if (isSquare) {
                    // Square corner for 90 degrees
                    positions[0] = 0.8; positions[1] = 0; positions[2] = 0;
                    positions[3] = 0.8; positions[4] = 0.8; positions[5] = 0;
                    positions[6] = 0; positions[7] = 0.8; positions[8] = 0;
                    arcLineRef.current.geometry.setDrawRange(0, 3);
                    
                    if (textGroupRef.current && textRef.current) {
                        textGroupRef.current.position.set(1.4, 1.4, 0);
                        textRef.current.text = `90°`;
                    }
                } else {
                    const segments = 64;
                    for (let i = 0; i <= segments; i++) {
                        const theta = (i / segments) * a;
                        positions[i * 3] = Math.cos(theta) * r;
                        positions[i * 3 + 1] = Math.sin(theta) * r;
                        positions[i * 3 + 2] = 0;
                    }
                    arcLineRef.current.geometry.setDrawRange(0, segments + 1);
                    
                    if (textGroupRef.current && textRef.current) {
                        const mid = a / 2;
                        textGroupRef.current.position.set(Math.cos(mid) * (r + 0.6), Math.sin(mid) * (r + 0.6), 0);
                        textRef.current.text = `${Math.round(a * 180 / Math.PI)}°`;
                    }
                }
                
                arcLineRef.current.geometry.attributes.position.needsUpdate = true;
            }
        }
    });

    return (
        <group>
            {/* Ambient & Lights */}
            <ambientLight intensity={0.6} />
            <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow />
            <Environment preset="city" opacity={0.3} />
            
            {/* Background Grid */}
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[50, 50]} />
                <meshStandardMaterial color="#020617" />
            </mesh>
            <gridHelper args={[50, 50, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            <ProtractorMarks />

            {/* Base Ray OA */}
            <Ray color="#3b82f6" label="A" position={[0,0,0]} />
            
            {/* Rotating Ray OB */}
            <group ref={rayGroupRef}>
                <Ray color="#10b981" label="B" position={[0,0,0]} />
            </group>

            {/* Vertex O */}
            <group position={[0,0,0.1]}>
                <Sphere args={[0.2, 32, 32]}>
                    <meshStandardMaterial color="#f8fafc" roughness={0.2} metalness={0.5} />
                </Sphere>
                <Text position={[-0.5, -0.5, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">O</Text>
            </group>

            {/* Dynamic Arc Line */}
            <line ref={arcLineRef as any} geometry={arcGeometry} position={[0,0,0.05]}>
                <lineBasicMaterial color="#facc15" linewidth={4} transparent opacity={0.9} />
            </line>

            {/* Dynamic Angle Text */}
            <group ref={textGroupRef}>
                <Text ref={textRef} fontSize={0.6} color="#facc15" fontWeight="bold">0°</Text>
            </group>

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function AngleCategoriesSimulation() {
    const [angleDeg, setAngleDeg] = useState(0);

    const PRESETS = [0, 45, 90, 135, 180, 240, 360];

    const renderMath = () => {
        return <BlockMath math={`m\\angle AOB = ${angleDeg}^\\circ`} />;
    };

    const getAlertContent = (deg: number) => {
        if (deg === 0) return { title: "Zero Angle", icon: <Circle size={18}/>, color: "border-l-slate-500 text-slate-300 bg-slate-900/40", body: <>Rays OA and OB are coincident (lying exactly on top of each other). There is zero rotation.</> };
        if (deg > 0 && deg < 90) return { title: "Acute Angle", icon: <Navigation size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>An angle measuring between 0° and 90°. It represents a sharp, narrow rotation.</> };
        if (deg === 90) return { title: "Right Angle", icon: <Square size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>A perfect 90° rotation (a quarter turn). Notice the arc is replaced by a square symbol, the universal geometric marker for perpendicularity.</> };
        if (deg > 90 && deg < 180) return { title: "Obtuse Angle", icon: <Navigation size={18} className="rotate-45"/>, color: "border-l-amber-500 text-amber-300 bg-amber-900/40", body: <>An angle measuring between 90° and 180°. It is wide and blunt.</> };
        if (deg === 180) return { title: "Straight Angle", icon: <MoveRight size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>A 180° rotation (a half turn). The two rays form a perfectly straight line.</> };
        if (deg > 180 && deg < 360) return { title: "Reflex Angle", icon: <ShieldAlert size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> Reflex angles are strictly larger than 180°! They are measured on the <b>outer arc</b> of the rotation, not the interior. Notice how the arc dynamically expands outward to capture the full sweep past a straight line.</> };
        if (deg === 360) return { title: "Complete Rotation", icon: <Circle size={18}/>, color: "border-l-cyan-500 text-cyan-300 bg-cyan-900/40", body: <>A full 360° turn. The ray has swept a complete circle and returned exactly to its starting position.</> };
        return { title: "", icon: null, color: "", body: <></> };
    };

    const alertContent = getAlertContent(angleDeg);

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            Angle Classifications
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Sweep the arm to explore angle types.
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
                        <AngleScene angleDeg={angleDeg} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                <div className={`flex-1 pointer-events-auto max-w-md w-full transition-all duration-500 origin-bottom-left`}>
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
                    
                    <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex justify-between">
                        <span>Interactive Protractor</span>
                        <span>{angleDeg}°</span>
                    </label>

                    {/* Presets */}
                    <div className="grid grid-cols-7 gap-1 mb-4">
                        {PRESETS.map(p => (
                            <button 
                                key={p} 
                                onClick={() => setAngleDeg(p)}
                                className={`py-2 text-xs font-bold rounded-lg border transition-all ${
                                    angleDeg === p ? 'bg-emerald-600 text-white border-emerald-500 shadow-[0_0_10px_rgba(16,185,129,0.5)]' : 
                                    'bg-slate-800 text-slate-400 border-slate-700 hover:bg-slate-700'
                                }`}
                            >
                                {p}°
                            </button>
                        ))}
                    </div>

                    {/* Continuous Slider */}
                    <div className="mb-2">
                        <input 
                            type="range" 
                            min="0" 
                            max="360" 
                            step="1" 
                            value={angleDeg} 
                            onChange={(e) => setAngleDeg(parseInt(e.target.value))} 
                            className="w-full accent-emerald-500 h-2 bg-slate-800 rounded-lg appearance-none cursor-pointer"
                        />
                        <div className="flex justify-between text-[10px] text-slate-500 mt-2 font-mono">
                            <span>0° (Zero)</span>
                            <span>90° (Right)</span>
                            <span>180° (Straight)</span>
                            <span>360° (Full)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
