import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Maximize2, MoveRight, ArrowRight, Shapes } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function Scene({ ax, ay, step }: { ax: number, ay: number, step: number }) {
    const { camera } = useThree();
    
    // Animation Proxy
    const proxy = useRef({ 
        ax: ax,
        ay: ay,
        deOp: 0,
        altOp: 0,
        sumOp: 0
    }).current;
    
    // Fixed base vertices
    const bx = -3;
    const by = -2;
    const cx = 3;
    const cy = -2;

    const arcRadius = 1.0;
    const textRadius = 1.6;

    // Geometry refs
    const lineABRef = useRef<THREE.Mesh>(null);
    const lineBCRef = useRef<THREE.Mesh>(null);
    const lineCARef = useRef<THREE.Mesh>(null);
    const ptA = useRef<THREE.Group>(null);
    const lineDERef = useRef<THREE.Group>(null);

    // Arcs
    const arcBRef = useRef<THREE.Line>(null);
    const arcCRef = useRef<THREE.Line>(null);
    const arcAIntRef = useRef<THREE.Line>(null);
    
    const arcDABRef = useRef<THREE.Line>(null);
    const arcEACRef = useRef<THREE.Line>(null);
    const arc180Ref = useRef<THREE.Line>(null);

    // Text Refs
    const textBRef = useRef<any>(null);
    const textBGroupRef = useRef<THREE.Group>(null);
    const textCRef = useRef<any>(null);
    const textCGroupRef = useRef<THREE.Group>(null);
    const textAIntRef = useRef<any>(null);
    const textAIntGroupRef = useRef<THREE.Group>(null);

    const textDABRef = useRef<any>(null);
    const textDABGroupRef = useRef<THREE.Group>(null);
    const textEACRef = useRef<any>(null);
    const textEACGroupRef = useRef<THREE.Group>(null);

    // Materials
    const matDERef = useRef<THREE.MeshStandardMaterial>(null);
    const matDABRef = useRef<THREE.LineBasicMaterial>(null);
    const matEACRef = useRef<THREE.LineBasicMaterial>(null);
    const mat180Ref = useRef<THREE.LineBasicMaterial>(null);
    
    const textDABMatRef = useRef<any>(null);
    const textEACMatRef = useRef<any>(null);

    const makeGeom = () => {
        const geom = new THREE.BufferGeometry();
        geom.setAttribute('position', new THREE.BufferAttribute(new Float32Array(65 * 3), 3));
        return geom;
    };

    const arcBGeom = useMemo(makeGeom, []);
    const arcCGeom = useMemo(makeGeom, []);
    const arcAIntGeom = useMemo(makeGeom, []);
    const arcDABGeom = useMemo(makeGeom, []);
    const arcEACGeom = useMemo(makeGeom, []);
    const arc180Geom = useMemo(makeGeom, []);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 0, z: 12, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    useEffect(() => {
        gsap.to(proxy, {
            deOp: step >= 1 ? 1 : 0,
            altOp: step >= 2 ? 1 : 0,
            sumOp: step >= 3 ? 1 : 0,
            duration: 0.8,
            ease: "power2.out"
        });
    }, [step, proxy]);

    const updateArc = (geom: THREE.BufferGeometry, start: number, end: number, r: number, segments: number = 64) => {
        const positions = geom.attributes.position.array as Float32Array;
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

    const updateLine = (mesh: THREE.Mesh, p1: THREE.Vector3, p2: THREE.Vector3) => {
        const dist = p1.distanceTo(p2);
        mesh.scale.y = dist;
        mesh.position.copy(p1).lerp(p2, 0.5);
        mesh.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), p2.clone().sub(p1).normalize());
    };

    useFrame(() => {
        proxy.ax = THREE.MathUtils.lerp(proxy.ax, ax, 0.1);
        proxy.ay = THREE.MathUtils.lerp(proxy.ay, ay, 0.1);
        
        const pA = new THREE.Vector3(proxy.ax, proxy.ay, 0);
        const pB = new THREE.Vector3(bx, by, 0);
        const pC = new THREE.Vector3(cx, cy, 0);

        if (ptA.current) ptA.current.position.copy(pA);
        
        if (lineABRef.current) updateLine(lineABRef.current, pA, pB);
        if (lineCARef.current) updateLine(lineCARef.current, pC, pA);
        if (lineBCRef.current) updateLine(lineBCRef.current, pB, pC);

        if (lineDERef.current) {
            lineDERef.current.position.set(0, proxy.ay, 0);
        }

        // Calculate Angles
        const angleB = Math.atan2(proxy.ay - by, proxy.ax - bx);
        const angleC = Math.atan2(proxy.ay - cy, cx - proxy.ax); // Angle from negative x axis
        
        const degB = Math.round(angleB * 180 / Math.PI);
        const degC = Math.round(angleC * 180 / Math.PI);
        const degA = 180 - degB - degC;

        // Interior B arc (0 to angleB)
        if (arcBRef.current) {
            arcBRef.current.position.copy(pB);
            updateArc(arcBRef.current.geometry, 0, angleB, arcRadius);
        }
        if (textBGroupRef.current && textBRef.current) {
            textBGroupRef.current.position.set(pB.x + Math.cos(angleB/2)*textRadius, pB.y + Math.sin(angleB/2)*textRadius, 0);
            textBRef.current.text = `${degB}°`;
        }

        // Interior C arc (PI - angleC to PI)
        if (arcCRef.current) {
            arcCRef.current.position.copy(pC);
            updateArc(arcCRef.current.geometry, Math.PI - angleC, Math.PI, arcRadius);
        }
        if (textCGroupRef.current && textCRef.current) {
            const mid = Math.PI - angleC/2;
            textCGroupRef.current.position.set(pC.x + Math.cos(mid)*textRadius, pC.y + Math.sin(mid)*textRadius, 0);
            textCRef.current.text = `${degC}°`;
        }

        // Interior A arc (PI + angleB to 2PI - angleC)
        const startA = Math.PI + angleB;
        const endA = 2 * Math.PI - angleC;
        if (arcAIntRef.current) {
            arcAIntRef.current.position.copy(pA);
            updateArc(arcAIntRef.current.geometry, startA, endA, arcRadius);
        }
        if (textAIntGroupRef.current && textAIntRef.current) {
            const mid = (startA + endA) / 2;
            textAIntGroupRef.current.position.set(pA.x + Math.cos(mid)*textRadius, pA.y + Math.sin(mid)*textRadius, 0);
            textAIntRef.current.text = `${degA}°`;
        }

        // Alt Interior DAB (PI to PI + angleB)
        if (arcDABRef.current) {
            arcDABRef.current.position.copy(pA);
            updateArc(arcDABRef.current.geometry, Math.PI, startA, arcRadius);
        }
        if (textDABGroupRef.current && textDABRef.current) {
            const mid = Math.PI + angleB/2;
            textDABGroupRef.current.position.set(pA.x + Math.cos(mid)*textRadius, pA.y + Math.sin(mid)*textRadius, 0);
            textDABRef.current.text = `${degB}°`;
        }

        // Alt Interior EAC (2PI - angleC to 2PI)
        if (arcEACRef.current) {
            arcEACRef.current.position.copy(pA);
            updateArc(arcEACRef.current.geometry, endA, 2 * Math.PI, arcRadius);
        }
        if (textEACGroupRef.current && textEACRef.current) {
            const mid = 2 * Math.PI - angleC/2;
            textEACGroupRef.current.position.set(pA.x + Math.cos(mid)*textRadius, pA.y + Math.sin(mid)*textRadius, 0);
            textEACRef.current.text = `${degC}°`;
        }

        // 180 Sum Arc (PI to 2PI)
        if (arc180Ref.current) {
            arc180Ref.current.position.copy(pA);
            updateArc(arc180Ref.current.geometry, Math.PI, 2 * Math.PI, arcRadius + 1.0);
        }

        // Opacities
        if (matDERef.current) {
            matDERef.current.opacity = proxy.deOp;
            matDERef.current.transparent = true;
        }
        if (matDABRef.current) matDABRef.current.opacity = proxy.altOp;
        if (matEACRef.current) matEACRef.current.opacity = proxy.altOp;
        if (textDABMatRef.current) textDABMatRef.current.fillOpacity = proxy.altOp;
        if (textEACMatRef.current) textEACMatRef.current.fillOpacity = proxy.altOp;
        
        if (mat180Ref.current) mat180Ref.current.opacity = proxy.sumOp;
    });

    return (
        <group position={[0, -0.5, 0]}>
            <ambientLight intensity={0.6} />
            <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow />
            <Environment preset="city" opacity={0.3} />
            
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[50, 50]} />
                <meshStandardMaterial color="#020617" />
            </mesh>
            <gridHelper args={[50, 50, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* Triangle Edges */}
            <mesh ref={lineABRef} castShadow>
                <cylinderGeometry args={[0.04, 0.04, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} />
            </mesh>
            <mesh ref={lineBCRef} castShadow>
                <cylinderGeometry args={[0.04, 0.04, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} />
            </mesh>
            <mesh ref={lineCARef} castShadow>
                <cylinderGeometry args={[0.04, 0.04, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} />
            </mesh>

            {/* Vertices */}
            <group ref={ptA}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0, 0.5, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">A</Text>
            </group>
            <group position={[bx, by, 0]}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.4, -0.4, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">B</Text>
            </group>
            <group position={[cx, cy, 0]}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0.4, -0.4, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">C</Text>
            </group>

            {/* Top Parallel Line DE */}
            <group ref={lineDERef}>
                <mesh rotation={[0, 0, -Math.PI/2]}>
                    <cylinderGeometry args={[0.03, 0.03, 20]} />
                    <meshStandardMaterial ref={matDERef} color="#facc15" roughness={0.3} emissive="#facc15" emissiveIntensity={0.5} />
                </mesh>
                <Text position={[-8, 0.4, 0]} fontSize={0.4} color="#facc15" fontWeight="bold">D</Text>
                <Text position={[8, 0.4, 0]} fontSize={0.4} color="#facc15" fontWeight="bold">E</Text>
            </group>

            {/* Base Line extended */}
            <mesh position={[0, by, 0]} rotation={[0, 0, -Math.PI/2]}>
                <cylinderGeometry args={[0.02, 0.02, 20]} />
                <meshStandardMaterial color="#64748b" roughness={0.3} transparent opacity={0.5} />
            </mesh>

            {/* Arcs Base */}
            <line ref={arcBRef as any} geometry={arcBGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#22c55e" linewidth={5} transparent opacity={0.9} /> {/* Green */}
            </line>
            <line ref={arcCRef as any} geometry={arcCGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#f97316" linewidth={5} transparent opacity={0.9} /> {/* Orange */}
            </line>
            <line ref={arcAIntRef as any} geometry={arcAIntGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#3b82f6" linewidth={5} transparent opacity={0.9} /> {/* Blue */}
            </line>

            {/* Arcs Top (Alt Interior) */}
            <line ref={arcDABRef as any} geometry={arcDABGeom} position={[0,0,0.05]}>
                <lineBasicMaterial ref={matDABRef} color="#22c55e" linewidth={5} transparent opacity={0} />
            </line>
            <line ref={arcEACRef as any} geometry={arcEACGeom} position={[0,0,0.05]}>
                <lineBasicMaterial ref={matEACRef} color="#f97316" linewidth={5} transparent opacity={0} />
            </line>

            {/* 180 Sum Arc */}
            <line ref={arc180Ref as any} geometry={arc180Geom} position={[0,0,0.05]}>
                <lineBasicMaterial ref={mat180Ref} color="#facc15" linewidth={4} transparent opacity={0} />
            </line>
            
            <group position={[0,0,0]}>
                {/* A position is updated, but for text we need it relative to A, handled in useFrame */}
            </group>

            {/* Texts */}
            <group ref={textBGroupRef}>
                <Text ref={textBRef} fontSize={0.5} color="#22c55e" fontWeight="bold" anchorX="center" anchorY="middle" />
            </group>
            <group ref={textCGroupRef}>
                <Text ref={textCRef} fontSize={0.5} color="#f97316" fontWeight="bold" anchorX="center" anchorY="middle" />
            </group>
            <group ref={textAIntGroupRef}>
                <Text ref={textAIntRef} fontSize={0.5} color="#3b82f6" fontWeight="bold" anchorX="center" anchorY="middle" />
            </group>
            <group ref={textDABGroupRef}>
                <Text ref={textDABRef} ref-material={textDABMatRef} fontSize={0.5} color="#22c55e" fontWeight="bold" anchorX="center" anchorY="middle" transparent fillOpacity={0} />
            </group>
            <group ref={textEACGroupRef}>
                <Text ref={textEACRef} ref-material={textEACMatRef} fontSize={0.5} color="#f97316" fontWeight="bold" anchorX="center" anchorY="middle" transparent fillOpacity={0} />
            </group>

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function TriangleAngleSum() {
    const [ax, setAx] = useState(-1.5);
    const [ay, setAy] = useState(2.5);
    const [step, setStep] = useState(0); 

    const handleNext = () => {
        if (step < 3) setStep(prev => prev + 1);
    };

    const handleReset = () => {
        setStep(0);
        setAx(-1.5);
        setAy(2.5);
    };

    // Derived angle calculations for HUD
    const bx = -3;
    const by = -2;
    const cx = 3;
    const cy = -2;

    const angleB = Math.atan2(ay - by, ax - bx);
    const angleC = Math.atan2(ay - cy, cx - ax);
    
    const degB = Math.round(angleB * 180 / Math.PI);
    const degC = Math.round(angleC * 180 / Math.PI);
    const degA = 180 - degB - degC;

    const renderMath = () => {
        if (step === 0) {
            return <BlockMath math={`m\\angle A + m\\angle B + m\\angle C = \\text{?}`} />;
        }
        if (step === 1) {
            return <BlockMath math={`DE \\parallel BC`} />;
        }
        if (step === 2) {
            return <BlockMath math={`m\\angle DAB = m\\angle B \\quad m\\angle EAC = m\\angle C`} />;
        }
        return <BlockMath math={`\\color{#22c55e}${degB}^\\circ \\color{white}+ \\color{#3b82f6}${degA}^\\circ \\color{white}+ \\color{#f97316}${degC}^\\circ \\color{white}= \\color{#facc15}180^\\circ`} />;
    };

    const getAlertContent = () => {
        if (step < 3) {
            return { 
                title: "Triangle Interior Angle Sum", 
                icon: <Shapes size={18}/>, 
                color: "border-l-blue-500 text-blue-300 bg-blue-900/40", 
                body: <>How can we prove the sum of a triangle's interior angles without measuring? We construct a parallel line DE through vertex A and use alternate interior angles to map the base angles to the top.</> 
            };
        }
        return { 
            title: "Invariant 180° Sum", 
            icon: <ShieldAlert size={18}/>, 
            color: "border-l-rose-500 text-rose-300 bg-rose-900/40", 
            body: <><b>CRITICAL MISCONCEPTION:</b> Students often think larger triangles have larger angle sums. By mapping the three angles onto the straight line DE, we physically prove they form exactly <b>180°</b>, regardless of the triangle's shape or size.</> 
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
                            The Triangle Angle Sum Theorem
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Proving the 180° interior sum using parallel transversals.
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
                        <OrbitControls enablePan={false} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <Scene ax={ax} ay={ay} step={step} />
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
                    
                    <div className="mb-4">
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className="text-slate-400">Vertex A (Horizontal)</span>
                        </div>
                        <input 
                            type="range" 
                            min="-5" 
                            max="5" 
                            step="0.1" 
                            value={ax} 
                            onChange={(e) => setAx(parseFloat(e.target.value))} 
                            className="w-full accent-blue-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer mt-1"
                        />
                    </div>
                    <div className="mb-6">
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className="text-slate-400">Vertex A (Height)</span>
                        </div>
                        <input 
                            type="range" 
                            min="1" 
                            max="5" 
                            step="0.1" 
                            value={ay} 
                            onChange={(e) => setAy(parseFloat(e.target.value))} 
                            className="w-full accent-blue-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer mt-1"
                        />
                    </div>

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={handleNext} 
                            disabled={step >= 3} 
                            className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                ${step >= 3 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                        >
                            {step >= 3 ? "Proof Complete" : "Next Step"} <ArrowRight size={16} />
                        </button>
                        <button 
                            onClick={handleReset} 
                            className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
                        >
                            <RotateCcw size={16} /> Reset Proof
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
