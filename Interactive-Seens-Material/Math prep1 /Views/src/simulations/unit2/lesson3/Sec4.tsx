import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, RoundedBox } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, Filter, ShieldAlert, CheckCircle2, RotateCcw, XCircle, ArrowRightLeft } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

const TermBlock = React.forwardRef(({ textRef, boxRef, text, color, pos }: any, ref) => {
    return (
        <group ref={ref as any} position={pos}>
            <RoundedBox ref={boxRef as any} args={[1.8, 1.2, 0.4]} radius={0.15} castShadow>
                <meshStandardMaterial color={color} roughness={0.2} metalness={0.5} transparent opacity={0.9} />
            </RoundedBox>
            <Text ref={textRef as any} position={[0, 0, 0.21]} fontSize={0.6} color="#ffffff" fontStyle="italic" fontWeight="bold">
                {text}
            </Text>
        </group>
    )
});

function Scene({ step, testTrigger, setTestDone, domainTarget, setTestResult }: any) {
    const { camera } = useThree();
    
    const xRef = useRef<THREE.Group>(null);
    const c1Ref = useRef<THREE.Group>(null);
    const c2Ref = useRef<THREE.Group>(null);
    const equalRef = useRef<THREE.Group>(null);
    const divGroupRef = useRef<THREE.Group>(null);
    
    const xText = useRef<any>(null);
    const c1Text = useRef<any>(null);
    const c2Text = useRef<any>(null);
    const c1Box = useRef<any>(null);
    const c2Box = useRef<any>(null);
    const ssText = useRef<any>(null);
    const gateBox = useRef<any>(null);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, 
            y: step === 3 ? -0.5 : 1.5, 
            z: step === 3 ? 12 : 10, 
            duration: 1.5, 
            ease: "power2.inOut" 
        });
    }, [step, camera]);

    useEffect(() => {
        if (step === 0) {
            // Reset
            gsap.killTweensOf('*');
            gsap.set(xRef.current!.position, { x: -3, y: 2, z: 0 });
            gsap.set(xRef.current!.scale, { x: 1, y: 1, z: 1 });
            gsap.set(c1Ref.current!.position, { x: -1, y: 2, z: 0 });
            gsap.set(c1Ref.current!.scale, { x: 1, y: 1, z: 1 });
            gsap.set(c2Ref.current!.position, { x: 3, y: 2, z: 0 });
            gsap.set(c2Ref.current!.scale, { x: 1, y: 1, z: 1 });
            gsap.set(equalRef.current!.scale, { x: 1, y: 1, z: 1 });
            gsap.set(divGroupRef.current!.scale, { x: 0, y: 0, z: 0 });
            
            if (xText.current) xText.current.text = "3x";
            if (c1Text.current) c1Text.current.text = "+11";
            if (c2Text.current) c2Text.current.text = "9";
            if (ssText.current) ssText.current.text = "S.S. = { }";
            
            if (c1Box.current) c1Box.current.material.color.set("#10b981");
            if (c2Box.current) c2Box.current.material.color.set("#1e293b");
            if (gateBox.current) gateBox.current.material.color.set("#334155");
        }
        else if (step === 1) {
            const tl = gsap.timeline();
            
            // Bridge arc
            tl.to(c1Ref.current!.position, { x: 5, y: 2, duration: 1, ease: "power1.inOut" }, 0);
            tl.to(c1Ref.current!.position, { z: 1.5, y: 3.5, duration: 0.5, yoyo: true, repeat: 1, ease: "power1.out" }, 0);
            
            // Sign flip
            tl.call(() => {
                if (c1Text.current) c1Text.current.text = "-11";
                if (c1Box.current) gsap.to(c1Box.current.material.color, { r: 0.9, g: 0.2, b: 0.2, duration: 0.3 });
            }, [], 0.5);
            
            // Combine
            tl.to(c1Ref.current!.position, { x: 3, duration: 0.5, ease: "power2.in" }, 1.5);
            tl.to(c1Ref.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 }, 1.8);
            tl.call(() => {
                if (c2Text.current) c2Text.current.text = "-2";
                if (c2Box.current) {
                    gsap.fromTo(c2Box.current.scale, { x: 1.2, y: 1.2, z: 1.2 }, { x: 1, y: 1, z: 1, duration: 0.4, ease: "back.out" });
                }
            }, [], 2.0);
        }
        else if (step === 2) {
            const tl = gsap.timeline();
            tl.to(divGroupRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: "back.out(1.5)" });
            
            tl.to(divGroupRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.5, ease: "power2.in" }, 1.5);
            tl.call(() => {
                if (xText.current) xText.current.text = "x";
                if (c2Text.current) c2Text.current.text = "-2/3";
            }, [], 1.8);
        }
        else if (step === 3) {
            const tl = gsap.timeline();
            tl.to(xRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.4 });
            tl.to(equalRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.4 }, 0);
            tl.to(c2Ref.current!.position, { x: 0, y: 1, duration: 0.8, ease: "power2.inOut" }, 0);
        }
    }, [step]);
    
    // Domain Filter Test Animation
    useEffect(() => {
        if (step === 3 && testTrigger > 0) {
            setTestResult('testing');
            const tl = gsap.timeline({ onComplete: () => setTestDone() });
            
            // Reset gate and block
            gsap.set(gateBox.current.material.color, { r: 0.2, g: 0.25, b: 0.33 }); 
            gsap.set(c2Ref.current!.position, { x: 0, y: 1, z: 0 });
            if (ssText.current) ssText.current.text = "S.S. = { }";
            
            // Drop to gate
            tl.to(c2Ref.current!.position, { y: -0.5, duration: 0.8, ease: "power2.in" });
            
            if (domainTarget === 'Q') {
                // Pass (Rational)
                tl.call(() => {
                    gsap.to(gateBox.current.material.color, { r: 0.1, g: 0.8, b: 0.3, duration: 0.3 });
                    setTestResult('passed');
                });
                tl.to(c2Ref.current!.position, { y: -3, duration: 0.6, ease: "power2.out" });
                tl.call(() => {
                    if (ssText.current) ssText.current.text = "S.S. = {-2/3}";
                });
            } else {
                // Block (Integer or Natural)
                tl.call(() => {
                    gsap.to(gateBox.current.material.color, { r: 0.9, g: 0.2, b: 0.2, duration: 0.3 });
                    setTestResult('rejected');
                });
                // Bounce off
                tl.to(c2Ref.current!.position, { y: 0.5, duration: 0.3, ease: "power2.out" });
                tl.to(c2Ref.current!.position, { y: -0.2, duration: 0.3, ease: "bounce.out" });
                tl.to(c2Ref.current!.position, { y: 1, duration: 0.5, ease: "power2.out", delay: 0.2 });
                tl.call(() => {
                    if (ssText.current) ssText.current.text = "S.S. = Ø";
                });
            }
        }
    }, [testTrigger]);

    return (
        <group>
            {/* Equation Elements */}
            <TermBlock ref={xRef} textRef={xText} text="3x" color="#3b82f6" pos={[-3, 2, 0]} />
            <TermBlock ref={c1Ref} boxRef={c1Box} textRef={c1Text} text="+11" color="#10b981" pos={[-1, 2, 0]} />
            
            <group ref={equalRef} position={[1, 2, 0]}>
                <Text fontSize={0.8} color="#ffffff" fontWeight="bold">=</Text>
            </group>
            
            <TermBlock ref={c2Ref} boxRef={c2Box} textRef={c2Text} text="9" color="#1e293b" pos={[3, 2, 0]} />
            
            {/* Division Nodes */}
            <group ref={divGroupRef} scale={[0,0,0]}>
                <mesh position={[-3, 1.2, 0]}><boxGeometry args={[1.5, 0.05, 0.2]}/><meshBasicMaterial color="#ffffff"/></mesh>
                <Text position={[-3, 0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">3</Text>
                
                <mesh position={[3, 1.2, 0]}><boxGeometry args={[1.5, 0.05, 0.2]}/><meshBasicMaterial color="#ffffff"/></mesh>
                <Text position={[3, 0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">3</Text>
            </group>
            
            {/* Filter Gate & Pouch (Only visible at step 3) */}
            <group position={[0, 0, 0]} scale={step === 3 ? 1 : 0}>
                {/* Gate */}
                <group position={[0, -1.5, 0]}>
                     <mesh ref={gateBox} castShadow>
                         <boxGeometry args={[4, 0.4, 1.5]} />
                         <meshStandardMaterial color="#334155" transparent opacity={0.8} />
                     </mesh>
                     <mesh position={[0,0,0.76]}><planeGeometry args={[3.8, 0.2]} /><meshBasicMaterial color="#0f172a" /></mesh>
                     <Text position={[0, 0, 0.77]} fontSize={0.3} color="#f8fafc" fontWeight="bold">
                         Domain: {domainTarget === 'N' ? 'Natural (N)' : domainTarget === 'Z' ? 'Integer (Z)' : 'Rational (Q)'}
                     </Text>
                </group>
                
                {/* Pouch */}
                <group position={[0, -3.5, 0]}>
                    <mesh position={[0, 0, -0.4]}>
                        <boxGeometry args={[4.5, 2.5, 0.2]} />
                        <meshStandardMaterial color="#854d0e" transparent opacity={0.6} />
                    </mesh>
                    <mesh position={[0, -1.15, 0]}>
                        <boxGeometry args={[4.5, 0.2, 1]} />
                        <meshStandardMaterial color="#713f12" />
                    </mesh>
                    <Text ref={ssText} position={[0, 1.5, 0]} fontSize={0.5} color="#facc15" fontWeight="bold">
                        S.S. = { }
                    </Text>
                </group>
            </group>
        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function DomainFilterSimulation() {
    const [step, setStep] = useState(0);
    const [domainTarget, setDomainTarget] = useState<'N'|'Z'|'Q'>('N');
    const [testTrigger, setTestTrigger] = useState(0);
    const [isAnimating, setIsAnimating] = useState(false);
    const [testResult, setTestResult] = useState<'idle'|'testing'|'passed'|'rejected'>('idle');

    const handleNextStep = () => {
        if (step < 3) setStep(s => s + 1);
    };

    const handleReset = () => {
        setStep(0);
        setTestTrigger(0);
        setTestResult('idle');
    };

    const runTest = (domain: 'N'|'Z'|'Q') => {
        if (isAnimating) return;
        setDomainTarget(domain);
        setIsAnimating(true);
        setTestTrigger(prev => prev + 1);
    };

    const renderMath = () => {
        if (step === 0) return <BlockMath math={`3x + 11 = 9`} />;
        if (step === 1) return <BlockMath math={`3x = 9 - 11 \\implies 3x = -2`} />;
        if (step === 2) return <BlockMath math={`x = -\\frac{2}{3}`} />;
        if (step === 3) return <BlockMath math={`\\text{Test } x = -\\frac{2}{3} \\text{ in Domain } \\mathbb{${domainTarget}}`} />;
    };

    const getAlertContent = () => {
        if (step === 0) return { title: "The Solver Pipeline", icon: <ArrowRightLeft size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>Let's isolate the variable. We begin by pushing the constant <span className="font-mono text-white">+11</span> across the equal sign bridge.</> };
        if (step === 1) return { title: "Sign Transformation", icon: <MoveRight size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>When crossing the equality bridge, <span className="font-mono text-white">+11</span> transforms into <span className="font-mono text-white">-11</span>. The right side evaluates to <span className="font-mono text-white">9 - 11 = -2</span>.</> };
        if (step === 2) return { title: "Division Node", icon: <CheckCircle2 size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>Divide both sides by 3 to completely isolate the variable. We yield <span className="font-mono text-white">x = -2/3</span>. But wait, is this our final, guaranteed answer?</> };
        if (step === 3) {
            if (testResult === 'idle') return { title: "Domain Constraints", icon: <Filter size={18}/>, color: "border-l-amber-500 text-amber-300 bg-amber-900/40", body: <>An equation's solution MUST belong to a specified number domain. Test our result against different domain filters below to see if it survives.</> };
            if (testResult === 'testing') return { title: "Evaluating...", icon: <Filter size={18} className="animate-spin"/>, color: "border-l-slate-500 text-slate-300 bg-slate-800/40", body: <>The candidate is falling into the domain filter...</> };
            if (testResult === 'rejected') return { title: "Empty Set (Ø)", icon: <ShieldAlert size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> Equations do not always have solutions! <span className="font-mono text-white">-2/3</span> is a fraction. It is neither Natural (ℕ) nor Integer (ℤ). The filter blocks it, resulting in an Empty Solution Set.</> };
            if (testResult === 'passed') return { title: "Accepted Solution", icon: <CheckCircle2 size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <><span className="font-mono text-white">-2/3</span> is a Rational number (ℚ). It passes the filter and successfully enters the Solution Set pouch.</> };
        }
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
                            Time Evolution & Domains
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Solving equations and filtering by number sets.
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
                    <Canvas shadows camera={{ position: [0, 1.5, 10], fov: 45 }}>
                        <color attach="background" args={['#020617']} />
                        <ambientLight intensity={0.6} />
                        <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
                        <Environment preset="city" opacity={0.3} />
                        
                        <Scene 
                            step={step} 
                            testTrigger={testTrigger} 
                            setTestDone={() => setIsAnimating(false)} 
                            domainTarget={domainTarget}
                            setTestResult={setTestResult}
                        />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                <div className="flex-1 pointer-events-auto max-w-md w-full transition-all duration-500 origin-bottom-left">
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
                            <span className="text-blue-400">Equation</span>
                            <span className="text-purple-400">Transpose</span>
                            <span className="text-emerald-400">Divide</span>
                            <span className="text-amber-400">Filter</span>
                        </div>
                        <div className="flex gap-1">
                            {[0, 1, 2, 3].map(s => (
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

                    {step === 3 && (
                        <div className="grid grid-cols-3 gap-2 mb-4">
                            <button onClick={() => runTest('N')} disabled={isAnimating} className="bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 py-2 rounded-lg text-xs font-bold transition-all disabled:opacity-50">Test ℕ (Natural)</button>
                            <button onClick={() => runTest('Z')} disabled={isAnimating} className="bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 py-2 rounded-lg text-xs font-bold transition-all disabled:opacity-50">Test ℤ (Integer)</button>
                            <button onClick={() => runTest('Q')} disabled={isAnimating} className="bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 py-2 rounded-lg text-xs font-bold transition-all disabled:opacity-50">Test ℚ (Rational)</button>
                        </div>
                    )}

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={handleNextStep} 
                            disabled={step === 3} 
                            className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                ${step === 3 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                        >
                            {step === 0 ? "Transpose Constant" : 
                             step === 1 ? "Divide by 3" : 
                             step === 2 ? "Enter Domain Filter" : "Completed"} 
                            {step < 3 && <MoveRight size={16} />}
                        </button>
                        <button 
                            onClick={handleReset} 
                            disabled={isAnimating}
                            className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm disabled:opacity-50"
                        >
                            <RotateCcw size={16} /> Reset
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
