import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere, Line as DreiLine } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, ShieldAlert, Split, RotateCcw, ArrowRightLeft } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function Ray({ color, label, position, rotation = [0,0,0], length = 4, labelOffset = [0,0,0] }: any) {
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

function AngleScene({ alphaDeg }: { alphaDeg: number }) {
    const { camera } = useThree();
    
    // Animation Proxy
    const proxy = useRef({ alpha: 60 * Math.PI / 180 }).current;
    
    // Refs
    const rayOCRef = useRef<THREE.Group>(null);
    const labelCRef = useRef<THREE.Group>(null);
    const arc1Ref = useRef<THREE.Line>(null);
    const arc2Ref = useRef<THREE.Line>(null);
    const textAlphaGroupRef = useRef<THREE.Group>(null);
    const textBetaGroupRef = useRef<THREE.Group>(null);
    const textAlphaRef = useRef<any>(null);
    const textBetaRef = useRef<any>(null);

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
            x: 0, y: 3, z: 12, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    useFrame(() => {
        // Smooth target tracking
        const targetRad = alphaDeg * Math.PI / 180;
        proxy.alpha = THREE.MathUtils.lerp(proxy.alpha, targetRad, 0.1);
        
        const a = proxy.alpha;
        const betaRad = Math.PI - a;
        
        // Rotate Divider Ray OC
        if (rayOCRef.current) {
            rayOCRef.current.rotation.z = a;
        }
        
        // Counter-rotate Label C so it stays upright
        if (labelCRef.current) {
            labelCRef.current.rotation.z = -a;
        }

        const arcRadius = 1.8;
        const segments = 64;

        // Update Arc 1 (COB: 0 to a)
        if (arc1Ref.current) {
            const positions1 = arc1Ref.current.geometry.attributes.position.array as Float32Array;
            for (let i = 0; i <= segments; i++) {
                const theta = (i / segments) * a;
                positions1[i * 3] = Math.cos(theta) * arcRadius;
                positions1[i * 3 + 1] = Math.sin(theta) * arcRadius;
                positions1[i * 3 + 2] = 0;
            }
            arc1Ref.current.geometry.attributes.position.needsUpdate = true;
        }

        // Update Arc 2 (AOC: a to PI)
        if (arc2Ref.current) {
            const positions2 = arc2Ref.current.geometry.attributes.position.array as Float32Array;
            for (let i = 0; i <= segments; i++) {
                const theta = a + (i / segments) * betaRad;
                positions2[i * 3] = Math.cos(theta) * arcRadius;
                positions2[i * 3 + 1] = Math.sin(theta) * arcRadius;
                positions2[i * 3 + 2] = 0;
            }
            arc2Ref.current.geometry.attributes.position.needsUpdate = true;
        }

        // Update Text Positions
        const textRadius = 2.4;
        if (textAlphaGroupRef.current && textAlphaRef.current) {
            const midA = a / 2;
            textAlphaGroupRef.current.position.set(Math.cos(midA) * textRadius, Math.sin(midA) * textRadius, 0);
            textAlphaRef.current.text = `${Math.round(a * 180 / Math.PI)}°`;
        }
        
        if (textBetaGroupRef.current && textBetaRef.current) {
            const midB = a + betaRad / 2;
            textBetaGroupRef.current.position.set(Math.cos(midB) * textRadius, Math.sin(midB) * textRadius, 0);
            textBetaRef.current.text = `${Math.round(betaRad * 180 / Math.PI)}°`;
        }
    });

    return (
        <group position={[0, -1, 0]}>
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

            {/* Straight Line Indicator Arc (Faint Background) */}
            <mesh position={[0, 0, -0.1]}>
                <ringGeometry args={[1.75, 1.85, 64, 1, 0, Math.PI]} />
                <meshBasicMaterial color="#334155" transparent opacity={0.3} />
            </mesh>

            {/* Vertex O */}
            <group position={[0,0,0.1]}>
                <Sphere args={[0.2, 32, 32]}>
                    <meshStandardMaterial color="#f8fafc" roughness={0.2} metalness={0.5} />
                </Sphere>
                <Text position={[0, -0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">O</Text>
            </group>

            {/* Base Rays forming Straight Line AB (180 degrees) */}
            <Ray color="#3b82f6" label="B" position={[0,0,0]} rotation={[0,0,0]} />
            <Ray color="#3b82f6" label="A" position={[0,0,0]} rotation={[0,0,Math.PI]} labelOffset={[0, 0, 0]} />
            
            {/* Divider Ray OC */}
            <group ref={rayOCRef}>
                <mesh position={[2.5, 0, 0]} rotation={[0, 0, -Math.PI/2]} castShadow>
                    <cylinderGeometry args={[0.06, 0.06, 5]} />
                    <meshStandardMaterial color="#facc15" roughness={0.3} metalness={0.2} />
                </mesh>
                <mesh position={[5, 0, 0]} rotation={[0, 0, -Math.PI/2]} castShadow>
                    <coneGeometry args={[0.25, 0.5]} />
                    <meshStandardMaterial color="#facc15" roughness={0.3} metalness={0.2} />
                </mesh>
                <group position={[5.5, 0, 0]} ref={labelCRef}>
                    <Text fontSize={0.5} color="#facc15" fontWeight="bold">C</Text>
                </group>
            </group>

            {/* Arcs */}
            <line ref={arc1Ref as any} geometry={arc1Geometry} position={[0,0,0.05]}>
                <lineBasicMaterial color="#facc15" linewidth={4} transparent opacity={0.9} />
            </line>
            
            <line ref={arc2Ref as any} geometry={arc2Geometry} position={[0,0,0.05]}>
                <lineBasicMaterial color="#06b6d4" linewidth={4} transparent opacity={0.9} />
            </line>

            {/* Angle Texts */}
            <group ref={textAlphaGroupRef}>
                <Text ref={textAlphaRef} fontSize={0.5} color="#facc15" fontWeight="bold">60°</Text>
            </group>
            
            <group ref={textBetaGroupRef}>
                <Text ref={textBetaRef} fontSize={0.5} color="#06b6d4" fontWeight="bold">120°</Text>
            </group>

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function SupplementaryAnglesSimulation() {
    // alphaDeg is the right-side angle (COB), initialized to 60 as per spec.
    // The left-side angle (AOC) will be 180 - alphaDeg = 120.
    const [alphaDeg, setAlphaDeg] = useState(60);
    const betaDeg = 180 - alphaDeg;

    const handleReset = () => {
        setAlphaDeg(60);
    };

    const renderMath = () => {
        return (
            <BlockMath math={`\\color{#06b6d4}${betaDeg}^\\circ \\color{white}+ \\color{#facc15}${alphaDeg}^\\circ \\color{white}= \\color{#3b82f6}180^\\circ`} />
        );
    };

    const getAlertContent = () => {
        return { 
            title: "Supplementary Angles Rule", 
            icon: <ShieldAlert size={18}/>, 
            color: "border-l-rose-500 text-rose-300 bg-rose-900/40", 
            body: <><b>CRITICAL MISCONCEPTION:</b> Students often mistakenly think Supplementary angles must involve perpendicular lines (90°). <b>Supplementary angles ALWAYS sum to exactly 180°.</b> When adjacent, they form a perfect, continuous Straight Line (Line AB).</> 
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
                            Supplementary Angles
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Two adjacent angles that form a straight line.
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
                        <AngleScene alphaDeg={alphaDeg} />
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
                        <Split size={14}/> Divider Ray OC Slider
                    </label>

                    {/* Continuous Slider */}
                    <div className="mb-4">
                        <div className="flex justify-between text-xs font-bold mb-2">
                            <span className="text-cyan-400">m∠AOC = {betaDeg}°</span>
                            <span className="text-yellow-400">m∠COB = {alphaDeg}°</span>
                        </div>
                        <input 
                            type="range" 
                            min="10" 
                            max="170" 
                            step="1" 
                            value={alphaDeg} 
                            onChange={(e) => setAlphaDeg(parseInt(e.target.value))} 
                            className="w-full accent-yellow-400 h-2 bg-slate-800 rounded-lg appearance-none cursor-pointer"
                        />
                        <div className="flex justify-between text-[10px] text-slate-500 mt-2 font-mono">
                            <span>Tilt Left</span>
                            <span>Tilt Right</span>
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="mt-auto">
                        <button 
                            onClick={handleReset} 
                            className="w-full bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
                        >
                            <RotateCcw size={16} /> Reset to 120° / 60°
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
