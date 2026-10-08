import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere, Line as DreiLine } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, ShieldAlert, Split, Square, RotateCcw, Crosshair, CircleDashed } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function Ray({ color, label, length = 5, labelOffset = [0,0,0] }: any) {
    return (
        <group>
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

function Scene({ alphaDeg, step }: { alphaDeg: number, step: number }) {
    const { camera } = useThree();
    
    // Groups for mode switching
    const voaGroupRef = useRef<THREE.Group>(null);
    const pointGroupRef = useRef<THREE.Group>(null);

    // VOA Proxies & Refs
    const voaProxy = useRef({ alpha: 50 * Math.PI / 180 }).current;
    const rayCRef = useRef<THREE.Group>(null);
    const rayDRef = useRef<THREE.Group>(null);
    
    const arc1Ref = useRef<THREE.Line>(null);
    const arc2Ref = useRef<THREE.Line>(null);
    const arc3Ref = useRef<THREE.Line>(null);
    const arc4Ref = useRef<THREE.Line>(null);
    
    const arc1MatRef = useRef<THREE.LineBasicMaterial>(null);
    const arc2MatRef = useRef<THREE.LineBasicMaterial>(null);
    const arc3MatRef = useRef<THREE.LineBasicMaterial>(null);
    const arc4MatRef = useRef<THREE.LineBasicMaterial>(null);

    const txt1Ref = useRef<THREE.Group>(null);
    const txt2Ref = useRef<THREE.Group>(null);
    const txt3Ref = useRef<THREE.Group>(null);
    const txt4Ref = useRef<THREE.Group>(null);

    // Point Proxies & Refs
    const pointProxy = useRef({ sweep: 0 }).current;
    const gaugeRef = useRef<THREE.Line>(null);

    // Geometries
    const makeGeom = () => {
        const g = new THREE.BufferGeometry();
        g.setAttribute('position', new THREE.BufferAttribute(new Float32Array(100 * 3), 3));
        return g;
    };

    const geomArc1 = useMemo(makeGeom, []);
    const geomArc2 = useMemo(makeGeom, []);
    const geomArc3 = useMemo(makeGeom, []);
    const geomArc4 = useMemo(makeGeom, []);
    const geomGauge = useMemo(makeGeom, []);

    // Point Mode Static Arcs
    const geomPArc1 = useMemo(makeGeom, []);
    const geomPArc2 = useMemo(makeGeom, []);
    const geomPArc4 = useMemo(makeGeom, []);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 0, z: 14, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    useEffect(() => {
        if (step < 2) {
            gsap.to(voaGroupRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.8, ease: "back.out(1.2)" });
            gsap.to(pointGroupRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.5, ease: "power2.in" });
        } else {
            gsap.to(voaGroupRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.5, ease: "power2.in" });
            gsap.to(pointGroupRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.8, ease: "back.out(1.2)" });
            
            // Sweep gauge animation
            gsap.fromTo(pointProxy, { sweep: 0 }, { sweep: Math.PI * 2, duration: 1.5, ease: "power2.out", delay: 0.3 });
        }
    }, [step, pointProxy]);

    const updateArc = (geom: THREE.BufferGeometry, start: number, end: number, r: number = 1.8, segments: number = 64) => {
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
        // --- VOA LOGIC ---
        if (step < 2) {
            const target = alphaDeg * Math.PI / 180;
            voaProxy.alpha = THREE.MathUtils.lerp(voaProxy.alpha, target, 0.1);
            const a = voaProxy.alpha;

            if (rayCRef.current) rayCRef.current.rotation.z = a;
            if (rayDRef.current) rayDRef.current.rotation.z = a + Math.PI;

            updateArc(geomArc1, 0, a);
            updateArc(geomArc2, a, Math.PI);
            updateArc(geomArc3, Math.PI, Math.PI + a);
            updateArc(geomArc4, Math.PI + a, Math.PI * 2);

            const rT = 2.4;
            if (txt1Ref.current) txt1Ref.current.position.set(Math.cos(a/2)*rT, Math.sin(a/2)*rT, 0);
            if (txt2Ref.current) txt2Ref.current.position.set(Math.cos((a+Math.PI)/2)*rT, Math.sin((a+Math.PI)/2)*rT, 0);
            if (txt3Ref.current) txt3Ref.current.position.set(Math.cos(Math.PI+a/2)*rT, Math.sin(Math.PI+a/2)*rT, 0);
            if (txt4Ref.current) txt4Ref.current.position.set(Math.cos((3*Math.PI+a)/2)*rT, Math.sin((3*Math.PI+a)/2)*rT, 0);

            const isVoaActive = step === 0;
            const isAdjActive = step === 1;

            if (arc1MatRef.current) arc1MatRef.current.opacity = THREE.MathUtils.lerp(arc1MatRef.current.opacity, isVoaActive ? 1 : 0.2, 0.1);
            if (arc3MatRef.current) arc3MatRef.current.opacity = THREE.MathUtils.lerp(arc3MatRef.current.opacity, isVoaActive ? 1 : 0.2, 0.1);
            if (arc2MatRef.current) arc2MatRef.current.opacity = THREE.MathUtils.lerp(arc2MatRef.current.opacity, isAdjActive ? 1 : 0.2, 0.1);
            if (arc4MatRef.current) arc4MatRef.current.opacity = THREE.MathUtils.lerp(arc4MatRef.current.opacity, isAdjActive ? 1 : 0.2, 0.1);
        }

        // --- POINT LOGIC ---
        if (step === 2) {
            // Draw static arcs for Point mode
            updateArc(geomPArc1, 0, 110 * Math.PI/180, 1.8);
            updateArc(geomPArc2, 110 * Math.PI/180, 190 * Math.PI/180, 1.8);
            // 190 to 280 is handled by Square Corner
            updateArc(geomPArc4, 280 * Math.PI/180, 360 * Math.PI/180, 1.8);

            // Draw sweeping gauge
            updateArc(geomGauge, 0, pointProxy.sweep, 2.8, 90);
        }
    });

    const getSquareCornerPoints = () => {
        const r = 1.0;
        const a1 = 190 * Math.PI / 180;
        const a2 = 280 * Math.PI / 180;
        const p1 = [r * Math.cos(a1), r * Math.sin(a1), 0] as [number, number, number];
        const p2 = [r * (Math.cos(a1) + Math.cos(a2)), r * (Math.sin(a1) + Math.sin(a2)), 0] as [number, number, number];
        const p3 = [r * Math.cos(a2), r * Math.sin(a2), 0] as [number, number, number];
        return [p1, p2, p3];
    };

    return (
        <group>
            <ambientLight intensity={0.6} />
            <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow />
            <Environment preset="city" opacity={0.3} />
            
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[50, 50]} />
                <meshStandardMaterial color="#020617" />
            </mesh>
            <gridHelper args={[50, 50, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            <group position={[0,0,0.1]}>
                <Sphere args={[0.25, 32, 32]}>
                    <meshStandardMaterial color="#f8fafc" roughness={0.2} metalness={0.5} />
                </Sphere>
                <Text position={[0, -0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">M</Text>
            </group>

            {/* --- V.O.A GROUP --- */}
            <group ref={voaGroupRef}>
                <group rotation={[0,0,0]}>
                    <Ray color="#94a3b8" label="A" />
                </group>
                <group rotation={[0,0,Math.PI]}>
                    <Ray color="#94a3b8" label="B" />
                </group>
                <group ref={rayCRef}>
                    <Ray color="#94a3b8" label="C" />
                </group>
                <group ref={rayDRef}>
                    <Ray color="#94a3b8" label="D" />
                </group>

                {/* VOA Arcs */}
                <line ref={arc1Ref as any} geometry={geomArc1} position={[0,0,0.05]}>
                    <lineBasicMaterial ref={arc1MatRef} color="#06b6d4" linewidth={4} transparent opacity={1} />
                </line>
                <line ref={arc2Ref as any} geometry={geomArc2} position={[0,0,0.05]}>
                    <lineBasicMaterial ref={arc2MatRef} color="#facc15" linewidth={4} transparent opacity={0.2} />
                </line>
                <line ref={arc3Ref as any} geometry={geomArc3} position={[0,0,0.05]}>
                    <lineBasicMaterial ref={arc3MatRef} color="#06b6d4" linewidth={4} transparent opacity={1} />
                </line>
                <line ref={arc4Ref as any} geometry={geomArc4} position={[0,0,0.05]}>
                    <lineBasicMaterial ref={arc4MatRef} color="#facc15" linewidth={4} transparent opacity={0.2} />
                </line>

                {/* VOA Texts */}
                <group ref={txt1Ref}>
                    <Text fontSize={0.5} color="#06b6d4" fontWeight="bold">
                        {`${alphaDeg}° ${step === 0 ? '(=)' : ''}`}
                    </Text>
                </group>
                <group ref={txt2Ref}>
                    <Text fontSize={0.5} color="#facc15" fontWeight="bold">
                        {`${180 - alphaDeg}°`}
                    </Text>
                </group>
                <group ref={txt3Ref}>
                    <Text fontSize={0.5} color="#06b6d4" fontWeight="bold">
                        {`${alphaDeg}° ${step === 0 ? '(=)' : ''}`}
                    </Text>
                </group>
                <group ref={txt4Ref}>
                    <Text fontSize={0.5} color="#facc15" fontWeight="bold">
                        {`${180 - alphaDeg}°`}
                    </Text>
                </group>
            </group>

            {/* --- POINT GROUP --- */}
            <group ref={pointGroupRef} scale={[0,0,0]}>
                <group rotation={[0,0,0]}><Ray color="#94a3b8" label="" /></group>
                <group rotation={[0,0,110 * Math.PI/180]}><Ray color="#94a3b8" label="" /></group>
                <group rotation={[0,0,190 * Math.PI/180]}><Ray color="#94a3b8" label="" /></group>
                <group rotation={[0,0,280 * Math.PI/180]}><Ray color="#94a3b8" label="" /></group>

                {/* Point Arcs */}
                <line geometry={geomPArc1} position={[0,0,0.05]}>
                    <lineBasicMaterial color="#ef4444" linewidth={4} transparent opacity={0.9} />
                </line>
                <line geometry={geomPArc2} position={[0,0,0.05]}>
                    <lineBasicMaterial color="#10b981" linewidth={4} transparent opacity={0.9} />
                </line>
                <line geometry={geomPArc4} position={[0,0,0.05]}>
                    <lineBasicMaterial color="#a855f7" linewidth={4} transparent opacity={0.9} />
                </line>
                
                {/* 90 Deg Square Corner */}
                <DreiLine points={getSquareCornerPoints()} color="#3b82f6" lineWidth={4} position={[0,0,0.05]} />

                {/* Circular Gauge */}
                <line ref={gaugeRef as any} geometry={geomGauge} position={[0,0,0.02]}>
                    <lineBasicMaterial color="#ffffff" linewidth={6} transparent opacity={0.4} />
                </line>

                {/* Point Texts */}
                <group position={[Math.cos(55 * Math.PI/180)*2.4, Math.sin(55 * Math.PI/180)*2.4, 0]}>
                    <Text fontSize={0.5} color="#ef4444" fontWeight="bold">110°</Text>
                </group>
                <group position={[Math.cos(150 * Math.PI/180)*2.4, Math.sin(150 * Math.PI/180)*2.4, 0]}>
                    <Text fontSize={0.5} color="#10b981" fontWeight="bold">80°</Text>
                </group>
                <group position={[Math.cos(235 * Math.PI/180)*2.4, Math.sin(235 * Math.PI/180)*2.4, 0]}>
                    <Text fontSize={0.5} color="#3b82f6" fontWeight="bold">90°</Text>
                </group>
                <group position={[Math.cos(320 * Math.PI/180)*2.4, Math.sin(320 * Math.PI/180)*2.4, 0]}>
                    <Text fontSize={0.5} color="#a855f7" fontWeight="bold">80°</Text>
                </group>
                
                <group position={[0, 3.2, 0]}>
                    <Text fontSize={0.6} color="#ffffff" fontWeight="bold">360° Total</Text>
                </group>
            </group>

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function IntersectionAndAccumulationSimulation() {
    const [step, setStep] = useState(0);
    const [alphaDeg, setAlphaDeg] = useState(50);

    const betaDeg = 180 - alphaDeg;

    const renderMath = () => {
        if (step === 0) return <BlockMath math={`\\text{V.O.A: } m\\angle AMC = m\\angle BMD = \\color{#06b6d4}${alphaDeg}^\\circ`} />;
        if (step === 1) return <BlockMath math={`\\text{Adj: } m\\angle AMD = 180^\\circ - ${alphaDeg}^\\circ = \\color{#facc15}${betaDeg}^\\circ`} />;
        if (step === 2) return <BlockMath math={`\\color{#ef4444}110^\\circ \\color{white}+ \\color{#10b981}80^\\circ \\color{white}+ \\color{#3b82f6}90^\\circ \\color{white}+ \\color{#a855f7}80^\\circ \\color{white}= 360^\\circ`} />;
    };

    const getAlertContent = () => {
        if (step === 0) return { 
            title: "Vertically Opposite Angles (V.O.A)", 
            icon: <Crosshair size={18}/>, 
            color: "border-l-cyan-500 text-cyan-300 bg-cyan-900/40", 
            body: <><b>CRITICAL MISCONCEPTION:</b> Students often confuse V.O.A with supplementary angles. <b>Vertically opposite angles are exactly EQUAL.</b> Notice how the intersecting lines mirror the angles across the vertex symmetrically.</> 
        };
        if (step === 1) return { 
            title: "Adjacent Angles on a Line", 
            icon: <Split size={18}/>, 
            color: "border-l-yellow-500 text-yellow-300 bg-yellow-900/40", 
            body: <>While opposite angles are equal, the <b>adjacent</b> angles along either straight line are Supplementary (sum to 180°). Thus, opposite angle BMC is also exactly {betaDeg}°.</> 
        };
        if (step === 2) return { 
            title: "Angles around a Point", 
            icon: <CircleDashed size={18}/>, 
            color: "border-l-purple-500 text-purple-300 bg-purple-900/40", 
            body: <>No matter how many rays emanate from a single vertex, the sum of all accumulated non-overlapping angles around that point will <b>always sum to exactly 360°</b>, forming a complete circle.</> 
        };
        return { title: "", icon: null, color: "", body: <></> };
    };

    const alertContent = getAlertContent();

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            Intersections & Accumulation
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Exploring V.O.A and 360-degree sums.
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
                        <Scene alphaDeg={alphaDeg} step={step} />
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
                    
                    {/* Steps Map */}
                    <div className="flex flex-col gap-2 mb-4">
                        <div className="flex justify-between text-[10px] font-bold uppercase tracking-wider text-slate-400 px-1">
                            <span className="text-cyan-400">Opposite (=)</span>
                            <span className="text-yellow-400">Adjacent (+)</span>
                            <span className="text-purple-400">Accumulate (360)</span>
                        </div>
                        <div className="flex gap-1">
                            {[0, 1, 2].map(s => (
                                <div 
                                    key={s} 
                                    className={`h-2 flex-1 rounded-full transition-all duration-500 ${
                                        s === step ? 'bg-white shadow-[0_0_10px_rgba(255,255,255,0.8)] scale-y-125' : 
                                        s < step ? 'bg-slate-500' : 'bg-slate-800'
                                    }`} 
                                />
                            ))}
                        </div>
                    </div>

                    {/* Continuous Slider for VOA */}
                    <div className={`mb-4 transition-all duration-300 ${step === 2 ? 'opacity-30 pointer-events-none' : 'opacity-100'}`}>
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            Intersecting Angle (V.O.A)
                        </label>
                        <input 
                            type="range" 
                            min="20" 
                            max="90" 
                            step="1" 
                            value={alphaDeg} 
                            onChange={(e) => setAlphaDeg(parseInt(e.target.value))} 
                            className="w-full accent-cyan-400 h-2 bg-slate-800 rounded-lg appearance-none cursor-pointer"
                            disabled={step === 2}
                        />
                    </div>

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={() => setStep(s => Math.min(2, s + 1))} 
                            disabled={step === 2} 
                            className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                ${step === 2 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                        >
                            {step === 0 ? "View Adjacent" : 
                             step === 1 ? "Point Accumulation" : "Completed"} 
                            {step < 2 && <MoveRight size={16} />}
                        </button>
                        <button 
                            onClick={() => { setStep(0); setAlphaDeg(50); }} 
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
