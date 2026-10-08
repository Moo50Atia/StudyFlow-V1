import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, RoundedBox, QuadraticBezierLine, Float } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, Merge, ScanLine, Calculator, SlidersHorizontal, RotateCcw, AlertTriangle, CheckCircle2 } from 'lucide-react';

// ---------------------------------------------------------
// DATA & FORMATTING
// ---------------------------------------------------------

const EXPR2 = [
    { c: 2, v: 'x^2', type: 'sq', id: 'e2-1' },
    { c: 7, v: 'x', type: 'lin', id: 'e2-2' },
    { c: -1, v: '', type: 'const', id: 'e2-3' }
];

const formatTerm = (c: number, v: string, isFirst: boolean) => {
    if (c === 0 && v !== '') return '';
    const sign = c < 0 ? '-' : (isFirst ? '' : '+');
    const absC = Math.abs(c);
    let coefStr = absC.toString();
    if (absC === 1 && v !== '') coefStr = '';
    return `${sign}${isFirst && c > 0 ? '' : ' '}${coefStr}${v}`.trim();
};

const getColor = (type: string) => {
    if (type === 'sq') return '#3b82f6'; // blue
    if (type === 'lin') return '#10b981'; // emerald
    if (type === 'const') return '#f59e0b'; // amber
    return '#64748b';
};

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function TermBlock({ text, type, positionRef, isMerged = false }: any) {
    const boxRef = useRef<THREE.Mesh>(null);
    const textRef = useRef<THREE.Group>(null);
    const color = getColor(type);

    return (
        <group ref={positionRef}>
            <Float floatIntensity={1} rotationIntensity={0.2} speed={2}>
                <RoundedBox ref={boxRef as any} args={[1.8, 1.2, 0.4]} radius={0.15} castShadow>
                    <meshStandardMaterial color={color} roughness={0.2} metalness={0.5} transparent opacity={0.95} />
                </RoundedBox>
                <group ref={textRef} position={[0, 0, 0.21]}>
                    <Text fontSize={0.6} color="#ffffff" fontStyle="italic" fontWeight="bold">
                        {text}
                    </Text>
                </group>
            </Float>
        </group>
    );
}

function Scene({ step, c1, c2, c3 }: any) {
    const { camera } = useThree();

    const t1Ref = useRef<THREE.Group>(null);
    const t2Ref = useRef<THREE.Group>(null);
    const t3Ref = useRef<THREE.Group>(null);
    
    const t4Ref = useRef<THREE.Group>(null);
    const t5Ref = useRef<THREE.Group>(null);
    const t6Ref = useRef<THREE.Group>(null);

    const m1Ref = useRef<THREE.Group>(null); // Merged sq
    const m2Ref = useRef<THREE.Group>(null); // Merged lin
    const m3Ref = useRef<THREE.Group>(null); // Merged const

    const plusSignRef = useRef<THREE.Group>(null);
    const lensRef = useRef<THREE.Mesh>(null);
    
    const arc1Ref = useRef<any>(null);
    const arc2Ref = useRef<any>(null);
    const arc3Ref = useRef<any>(null);

    // Initial Layout positions (Step 0)
    const POS_INIT = {
        t1: [-6.5, 0, 0], t2: [-4.5, 0, 0], t3: [-2.5, 0, 0],
        t4: [2.5, 0, 0], t5: [4.5, 0, 0], t6: [6.5, 0, 0],
        plus: [0, 0, 0]
    };

    // Grouped Layout positions (Step 1)
    const POS_GRP = {
        t1: [-6, 1, 0], t4: [-4, 1, 0],
        t2: [-1, 1, 0], t5: [1, 1, 0],
        t3: [4, 1, 0], t6: [6, 1, 0]
    };

    // Merged Layout positions (Step 2)
    const POS_MRG = {
        m1: [-5, 1, 0], m2: [0, 1, 0], m3: [5, 1, 0]
    };

    // Final Layout positions (Step 3 & 4)
    const POS_FIN = {
        m1: [-2.5, 0, 0], m2: [0, 0, 0], m3: [2.5, 0, 0]
    };

    useEffect(() => {
        const t = gsap.timeline();

        if (step === 0) {
            gsap.to([t1Ref.current?.position], { x: POS_INIT.t1[0], y: POS_INIT.t1[1], duration: 0.8, ease: "power2.out" });
            gsap.to([t2Ref.current?.position], { x: POS_INIT.t2[0], y: POS_INIT.t2[1], duration: 0.8, ease: "power2.out" });
            gsap.to([t3Ref.current?.position], { x: POS_INIT.t3[0], y: POS_INIT.t3[1], duration: 0.8, ease: "power2.out" });
            gsap.to([t4Ref.current?.position], { x: POS_INIT.t4[0], y: POS_INIT.t4[1], duration: 0.8, ease: "power2.out" });
            gsap.to([t5Ref.current?.position], { x: POS_INIT.t5[0], y: POS_INIT.t5[1], duration: 0.8, ease: "power2.out" });
            gsap.to([t6Ref.current?.position], { x: POS_INIT.t6[0], y: POS_INIT.t6[1], duration: 0.8, ease: "power2.out" });
            
            gsap.to([t1Ref.current?.scale, t2Ref.current?.scale, t3Ref.current?.scale, t4Ref.current?.scale, t5Ref.current?.scale, t6Ref.current?.scale], { x: 1, y: 1, z: 1, duration: 0.3 });
            gsap.to([m1Ref.current?.scale, m2Ref.current?.scale, m3Ref.current?.scale], { x: 0, y: 0, z: 0, duration: 0.3 });
            
            gsap.to(plusSignRef.current?.scale, { x: 1, y: 1, z: 1, duration: 0.3 });
            gsap.to(lensRef.current?.position, { x: -10, duration: 0 });
            gsap.to(lensRef.current?.scale, { x: 0, y: 0, z: 0, duration: 0.3 });

            if(arc1Ref.current?.material) gsap.to(arc1Ref.current.material, { opacity: 0, duration: 0.3 });
            if(arc2Ref.current?.material) gsap.to(arc2Ref.current.material, { opacity: 0, duration: 0.3 });
            if(arc3Ref.current?.material) gsap.to(arc3Ref.current.material, { opacity: 0, duration: 0.3 });
        }
        else if (step === 1) {
            // Lens sweep
            gsap.to(lensRef.current?.scale, { x: 1, y: 1, z: 1, duration: 0.2 });
            gsap.to(plusSignRef.current?.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
            
            t.fromTo(lensRef.current?.position, { x: -10 }, { x: 10, duration: 1.5, ease: "power1.inOut" })
             .to(lensRef.current?.scale, { x: 0, y: 0, z: 0, duration: 0.2 });

            // Grouping arcs fade in during sweep
            if(arc1Ref.current?.material) gsap.to(arc1Ref.current.material, { opacity: 0.8, duration: 0.5, delay: 0.5 });
            if(arc2Ref.current?.material) gsap.to(arc2Ref.current.material, { opacity: 0.8, duration: 0.5, delay: 0.8 });
            if(arc3Ref.current?.material) gsap.to(arc3Ref.current.material, { opacity: 0.8, duration: 0.5, delay: 1.1 });

            // Blocks move to groups
            gsap.to(t1Ref.current?.position, { x: POS_GRP.t1[0], y: POS_GRP.t1[1], duration: 1, ease: "back.out(1.2)", delay: 0.5 });
            gsap.to(t4Ref.current?.position, { x: POS_GRP.t4[0], y: POS_GRP.t4[1], duration: 1, ease: "back.out(1.2)", delay: 0.5 });
            
            gsap.to(t2Ref.current?.position, { x: POS_GRP.t2[0], y: POS_GRP.t2[1], duration: 1, ease: "back.out(1.2)", delay: 0.8 });
            gsap.to(t5Ref.current?.position, { x: POS_GRP.t5[0], y: POS_GRP.t5[1], duration: 1, ease: "back.out(1.2)", delay: 0.8 });

            gsap.to(t3Ref.current?.position, { x: POS_GRP.t3[0], y: POS_GRP.t3[1], duration: 1, ease: "back.out(1.2)", delay: 1.1 });
            gsap.to(t6Ref.current?.position, { x: POS_GRP.t6[0], y: POS_GRP.t6[1], duration: 1, ease: "back.out(1.2)", delay: 1.1 });
        }
        else if (step === 2) {
            // Fade arcs out
            if(arc1Ref.current?.material) gsap.to(arc1Ref.current.material, { opacity: 0, duration: 0.3 });
            if(arc2Ref.current?.material) gsap.to(arc2Ref.current.material, { opacity: 0, duration: 0.3 });
            if(arc3Ref.current?.material) gsap.to(arc3Ref.current.material, { opacity: 0, duration: 0.3 });

            // Collapse original terms
            gsap.to([t1Ref.current?.scale, t2Ref.current?.scale, t3Ref.current?.scale, t4Ref.current?.scale, t5Ref.current?.scale, t6Ref.current?.scale], { x: 0, y: 0, z: 0, duration: 0.5, ease: "back.in(1.5)" });
            
            // Pop merged terms at group centers
            gsap.to(m1Ref.current?.position, { x: POS_MRG.m1[0], y: POS_MRG.m1[1], duration: 0 });
            gsap.to(m2Ref.current?.position, { x: POS_MRG.m2[0], y: POS_MRG.m2[1], duration: 0 });
            gsap.to(m3Ref.current?.position, { x: POS_MRG.m3[0], y: POS_MRG.m3[1], duration: 0 });
            
            gsap.to(m1Ref.current?.scale, { x: 1, y: 1, z: 1, duration: 0.6, ease: "elastic.out(1, 0.5)", delay: 0.4 });
            gsap.to(m2Ref.current?.scale, { x: 1, y: 1, z: 1, duration: 0.6, ease: "elastic.out(1, 0.5)", delay: 0.5 });
            gsap.to(m3Ref.current?.scale, { x: 1, y: 1, z: 1, duration: 0.6, ease: "elastic.out(1, 0.5)", delay: 0.6 });
        }
        else if (step >= 3) {
            // Move merged blocks to final positions
            gsap.to(m1Ref.current?.position, { x: POS_FIN.m1[0], y: POS_FIN.m1[1], duration: 1, ease: "power2.inOut" });
            gsap.to(m2Ref.current?.position, { x: POS_FIN.m2[0], y: POS_FIN.m2[1], duration: 1, ease: "power2.inOut" });
            gsap.to(m3Ref.current?.position, { x: POS_FIN.m3[0], y: POS_FIN.m3[1], duration: 1, ease: "power2.inOut" });

            if (step === 3) {
                 // Pulse animation for misconception correction (exponents unchanged)
                 const m1Text = m1Ref.current?.children[0].children[1]; // Accessing the text group inside Float
                 if (m1Text) {
                     gsap.to(m1Text.scale, { x: 1.3, y: 1.3, z: 1.3, duration: 0.3, yoyo: true, repeat: 3, delay: 1 });
                     gsap.to((m1Text.children[0] as any).material, { color: '#fde047', duration: 0.3, yoyo: true, repeat: 3, delay: 1 });
                 }
            }
        }

        // Camera dynamics
        gsap.to(camera.position, { 
            y: step >= 1 ? 1 : 0,
            z: step === 1 ? 16 : 14,
            duration: 1.5, 
            ease: "power2.inOut" 
        });

    }, [step, camera]);

    return (
        <group>
            {/* Background alignment grid */}
            <mesh rotation={[-Math.PI/2, 0, 0]} position={[0, -2, 0]}>
                <planeGeometry args={[50, 50]} />
                <meshStandardMaterial color="#1e293b" wireframe transparent opacity={0.15} />
            </mesh>

            <OrbitControls enablePan={false} enableZoom={false} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
            
            {/* Operator in Step 0 */}
            <group ref={plusSignRef} position={[0, 0, 0]}>
                <Text fontSize={1} color="#94a3b8" fontWeight="bold">+</Text>
            </group>

            {/* Commutative Lens */}
            <mesh ref={lensRef} scale={[0,0,0]} position={[-10, 0, 0]} rotation={[0, Math.PI/2, 0]}>
                <planeGeometry args={[10, 10]} />
                <meshBasicMaterial color="#38bdf8" transparent opacity={0.2} side={THREE.DoubleSide} depthWrite={false} />
            </mesh>
            
            {/* Grouping Arcs (Step 1) */}
            <group position={[0, 1, -1]}>
                <QuadraticBezierLine ref={arc1Ref} start={[-6, 0.5, 0]} mid={[-5, 3, 0]} end={[-4, 0.5, 0]} color="#3b82f6" lineWidth={3} transparent opacity={0} />
                <QuadraticBezierLine ref={arc2Ref} start={[-1, 0.5, 0]} mid={[0, 3, 0]} end={[1, 0.5, 0]} color="#10b981" lineWidth={3} transparent opacity={0} />
                <QuadraticBezierLine ref={arc3Ref} start={[4, 0.5, 0]} mid={[5, 3, 0]} end={[6, 0.5, 0]} color="#f59e0b" lineWidth={3} transparent opacity={0} />
            </group>

            {/* Initial Expression 1 */}
            <TermBlock positionRef={t1Ref} text={formatTerm(c1, 'x^2', true)} type="sq" />
            <TermBlock positionRef={t2Ref} text={formatTerm(c2, 'x', false)} type="lin" />
            <TermBlock positionRef={t3Ref} text={formatTerm(c3, '', false)} type="const" />

            {/* Initial Expression 2 */}
            <TermBlock positionRef={t4Ref} text={formatTerm(EXPR2[0].c, 'x^2', true)} type="sq" />
            <TermBlock positionRef={t5Ref} text={formatTerm(EXPR2[1].c, 'x', false)} type="lin" />
            <TermBlock positionRef={t6Ref} text={formatTerm(EXPR2[2].c, '', false)} type="const" />

            {/* Merged Expression */}
            <TermBlock positionRef={m1Ref} text={formatTerm(c1 + EXPR2[0].c, 'x^2', true)} type="sq" isMerged />
            <TermBlock positionRef={m2Ref} text={formatTerm(c2 + EXPR2[1].c, 'x', false)} type="lin" isMerged />
            <TermBlock positionRef={m3Ref} text={formatTerm(c3 + EXPR2[2].c, '', false)} type="const" isMerged />

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function HorizontalAdditionSimulation() {
    const [step, setStep] = useState(0);
    
    // Sandbox State
    const [c1, setC1] = useState(5);
    const [c2, setC2] = useState(-3);
    const [c3, setC3] = useState(4);

    const handleNextStep = () => {
        if (step < 4) setStep(s => s + 1);
    };

    const strExp1 = `${formatTerm(c1, 'x^2', true)} ${formatTerm(c2, 'x', false)} ${formatTerm(c3, '', false)}`;
    const strExp2 = `${formatTerm(EXPR2[0].c, 'x^2', true)} ${formatTerm(EXPR2[1].c, 'x', false)} ${formatTerm(EXPR2[2].c, '', false)}`;
    const strFinal = `${formatTerm(c1 + EXPR2[0].c, 'x^2', true)} ${formatTerm(c2 + EXPR2[1].c, 'x', false)} ${formatTerm(c3 + EXPR2[2].c, '', false)}`;

    const renderMath = () => {
        if (step === 0) return <BlockMath math={`(${strExp1}) + (${strExp2})`} />;
        if (step === 1) return <BlockMath math={`(${c1}x^2 + ${EXPR2[0].c}x^2) + (${c2}x + ${EXPR2[1].c}x) + (${c3} - ${Math.abs(EXPR2[2].c)})`} />;
        if (step === 2) return <BlockMath math={`(${c1 + EXPR2[0].c}x^2) + (${c2 + EXPR2[1].c}x) + (${c3 + EXPR2[2].c})`} />;
        if (step >= 3) return <BlockMath math={`${strFinal}`} />;
    };

    const getAlertContent = () => {
        if (step === 0) return { title: "Horizontal String", icon: <MoveRight size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>Two polynomials are displayed horizontally. To add them, we do not need to rewrite them vertically; we can identify and combine like terms directly within the string.</> };
        if (step === 1) return { title: "Commutative Grouping", icon: <ScanLine size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>The Commutative Property allows us to rearrange the terms. The lens scans the string, drawing arcs to group identical variable categories together: <span className="font-mono text-white">x²</span> with <span className="font-mono text-white">x²</span>, <span className="font-mono text-white">x</span> with <span className="font-mono text-white">x</span>, and constants with constants.</> };
        if (step === 2) return { title: "Simplify Pods", icon: <Calculator size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>Now we mathematically merge each isolated grouping pod by adding their numerical coefficients.</> };
        if (step === 3) return { title: "Exponents Unchanged", icon: <AlertTriangle size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> Look at the final <span className="font-mono text-white">x²</span> term. Did it become <span className="font-mono text-white">x⁴</span>? NO! Horizontal addition only sums coefficients. The variable identity (the exponent) remains completely unchanged.</> };
        if (step === 4) return { title: "Dynamic Sandbox", icon: <SlidersHorizontal size={18}/>, color: "border-l-slate-500 text-slate-300 bg-slate-800/40", body: <>Modify the coefficients of the first expression. Watch how the final simplified polynomial recalculates horizontally in real-time.</> };
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
                            Horizontal Addition
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Grouping and simplifying strings of expressions.
                        </p>
                    </div>
                    <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[340px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto">
                        <div className="text-white text-lg px-4 flex items-center h-full min-h-[4rem]">
                            {renderMath()}
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas shadows camera={{ position: [0, 0, 14], fov: 45 }}>
                        <color attach="background" args={['#020617']} />
                        <ambientLight intensity={0.6} />
                        <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
                        <Environment preset="city" opacity={0.3} />
                        
                        <Scene step={step} c1={c1} c2={c2} c3={c3} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                <div className={`flex-1 pointer-events-auto max-w-md w-full transition-all duration-500 origin-bottom-left ${step === 0 ? 'scale-100 opacity-100' : 'scale-105 opacity-100'}`}>
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
                            <span className="text-blue-400">String</span>
                            <span className="text-emerald-400">Group</span>
                            <span className="text-purple-400">Simplify</span>
                            <span className="text-rose-400">Result</span>
                            <span className="text-slate-300">Sandbox</span>
                        </div>
                        <div className="flex gap-1">
                            {[0, 1, 2, 3, 4].map(s => (
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

                    {/* Sandbox Controls */}
                    {step === 4 && (
                        <div className="flex flex-col gap-3 mb-4">
                            <div className="text-xs font-bold text-slate-400 uppercase">Adjust Expression 1</div>
                            <div className="grid grid-cols-3 gap-4">
                                <div>
                                    <label className="text-[10px] uppercase text-blue-400 mb-1 block font-bold">x² Coef</label>
                                    <input type="range" min="-10" max="10" value={c1} onChange={e=>setC1(parseInt(e.target.value))} className="w-full accent-blue-500" />
                                    <div className="text-xs text-center text-slate-300">{c1}</div>
                                </div>
                                <div>
                                    <label className="text-[10px] uppercase text-emerald-400 mb-1 block font-bold">x Coef</label>
                                    <input type="range" min="-10" max="10" value={c2} onChange={e=>setC2(parseInt(e.target.value))} className="w-full accent-emerald-500" />
                                    <div className="text-xs text-center text-slate-300">{c2}</div>
                                </div>
                                <div>
                                    <label className="text-[10px] uppercase text-amber-400 mb-1 block font-bold">Constant</label>
                                    <input type="range" min="-10" max="10" value={c3} onChange={e=>setC3(parseInt(e.target.value))} className="w-full accent-amber-500" />
                                    <div className="text-xs text-center text-slate-300">{c3}</div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={handleNextStep} 
                            disabled={step === 4} 
                            className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                ${step === 4 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                        >
                            {step === 0 ? "Trigger Lens" : 
                             step === 1 ? "Simplify Pods" : 
                             step === 2 ? "Finalize" : 
                             step === 3 ? "Enter Sandbox" : "Completed"} 
                            {step < 4 && <MoveRight size={16} />}
                        </button>
                        <button 
                            onClick={() => { setStep(0); }} 
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
