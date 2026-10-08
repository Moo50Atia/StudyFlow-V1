import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, Repeat, Scissors, Settings2, RotateCcw, AlertOctagon, CheckCircle2, Languages } from 'lucide-react';

// ---------------------------------------------------------
// DATA & LOGIC
// ---------------------------------------------------------

const PHRASES = [
    { id: 0, text: "Subtract 3a from 8a", t1: 3, v1: 'a', t2: 8, v2: 'a', reverse: true },
    { id: 1, text: "Increase of 7y over 2y", t1: 7, v1: 'y', t2: 2, v2: 'y', reverse: false },
    { id: 2, text: "Take away 4z from 10z", t1: 4, v1: 'z', t2: 10, v2: 'z', reverse: true },
    { id: 3, text: "Difference between 9b and 5b", t1: 9, v1: 'b', t2: 5, v2: 'b', reverse: false },
];

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function TermStack({ count, varName, color, isMinuend, step, subtrahendCount }: any) {
    const cubesRef = useRef<THREE.Mesh[]>([]);

    const displayCount = (step >= 3 && isMinuend) ? (count - subtrahendCount) : count;

    useEffect(() => {
        if (step === 0 || step === 1) {
            cubesRef.current.forEach((c) => {
                if (c) {
                    gsap.killTweensOf(c.scale);
                    gsap.killTweensOf((c.material as any).color);
                    gsap.to(c.scale, { x: 1, y: 1, z: 1, duration: 0.3 });
                    gsap.to((c.material as any).color, { r: new THREE.Color(color).r, g: new THREE.Color(color).g, b: new THREE.Color(color).b, duration: 0.3 });
                }
            });
        }
        if (step === 2 && isMinuend) {
            // Destroy the rightmost `subtrahendCount` blocks
            for (let i = 0; i < subtrahendCount; i++) {
                const idx = count - 1 - i;
                if (cubesRef.current[idx]) {
                    gsap.to(cubesRef.current[idx].scale, { x: 0, y: 0, z: 0, duration: 0.5, delay: 0.2 + (i * 0.15), ease: "back.in(1.5)" });
                    gsap.to((cubesRef.current[idx].material as any).color, { r: 1, g: 0.2, b: 0.2, duration: 0.2, delay: 0.1 + (i * 0.15) });
                }
            }
        }
    }, [step, isMinuend, count, subtrahendCount, color]);

    return (
        <group>
            {Array.from({length: count}).map((_, i) => (
                <mesh key={i} ref={el => cubesRef.current[i] = el!} position={[(i - count/2) * 1.1 + 0.55, 0, 0]} castShadow>
                    <boxGeometry args={[1, 1, 1]} />
                    <meshStandardMaterial color={color} roughness={0.3} metalness={0.2} transparent opacity={0.9} />
                </mesh>
            ))}
            <Text position={[0, -1.2, 0]} fontSize={0.7} color="#fff" fontStyle="italic">
                {displayCount}{varName}
            </Text>
        </group>
    )
}

function Scene({ step, phraseId }: any) {
    const { camera } = useThree();
    const activePhrase = PHRASES[phraseId];
    const isReverse = activePhrase.reverse;

    // T1 is the first term written in text. T2 is the second.
    const t1IsMinuend = !isReverse;
    const t2IsMinuend = isReverse;

    const t1Ref = useRef<THREE.Group>(null);
    const t2Ref = useRef<THREE.Group>(null);
    const minusRef = useRef<THREE.Group>(null);
    const laserRef = useRef<THREE.Mesh>(null);
    const matrixGridRef = useRef<THREE.Group>(null);

    const subtrahendCount = isReverse ? activePhrase.t1 : activePhrase.t2;

    useEffect(() => {
        const spacing = Math.max(activePhrase.t1, activePhrase.t2) * 0.55 + 2;
        const t1TargetX = step === 0 ? -spacing : (isReverse ? spacing : -spacing);
        const t2TargetX = step === 0 ? spacing : (isReverse ? -spacing : spacing);

        if (t1Ref.current && t2Ref.current && minusRef.current) {
            gsap.to(t1Ref.current.position, { x: t1TargetX, duration: 1, ease: "power2.inOut" });
            gsap.to(t2Ref.current.position, { x: t2TargetX, duration: 1, ease: "power2.inOut" });

            if (step === 0) {
                gsap.to(minusRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
                gsap.to([t1Ref.current.scale, t2Ref.current.scale], { x: 1, y: 1, z: 1, duration: 0.3 });
                gsap.to(laserRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.2 });
                gsap.to(matrixGridRef.current!.material, { opacity: 0.1, duration: 0.5 });
            }
            if (step === 1) {
                gsap.to(minusRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.5, delay: 0.5, ease: "back.out(1.5)" });
                gsap.to(matrixGridRef.current!.material, { opacity: 0.4, duration: 0.5 });
            }
            if (step === 2) {
                // Laser fires from right to left
                gsap.to(laserRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.3 });
                gsap.fromTo(laserRef.current!.position, { x: spacing }, { x: 0, duration: 0.8, ease: "power1.inOut" });
                gsap.to(laserRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3, delay: 1 });
            }
            if (step === 3) {
                const subtrahendRef = isReverse ? t1Ref : t2Ref;
                const minuendRef = isReverse ? t2Ref : t1Ref;

                gsap.to(subtrahendRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.5, ease: "power2.in" });
                gsap.to(minusRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.5 });
                gsap.to(minuendRef.current!.position, { x: 0, duration: 1, ease: "power2.inOut", delay: 0.2 });
                gsap.to(matrixGridRef.current!.material, { opacity: 0.1, duration: 0.5 });
            }
        }

        // Dynamic Camera
        gsap.to(camera.position, { 
            z: step === 3 ? 12 : 16 + Math.max(activePhrase.t1, activePhrase.t2) * 0.2, 
            y: step === 3 ? 2 : 4,
            duration: 1.5, 
            ease: "power2.inOut" 
        });

    }, [step, phraseId, isReverse, activePhrase.t1, activePhrase.t2, camera]);

    return (
        <group>
            {/* Environment Grid */}
            <mesh rotation={[-Math.PI/2, 0, 0]} position={[0, -2, 0]}>
                <planeGeometry args={[50, 50]} />
                <meshStandardMaterial ref={matrixGridRef as any} color="#1e293b" wireframe transparent opacity={0.1} />
            </mesh>

            <OrbitControls enablePan={false} enableZoom={false} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.4} minAzimuthAngle={-0.4} />
            
            {/* Term 1 (Text Order 1st) */}
            <group ref={t1Ref}>
                <TermStack 
                    count={activePhrase.t1} 
                    varName={activePhrase.v1} 
                    color={t1IsMinuend ? "#3b82f6" : "#8b5cf6"} 
                    isMinuend={t1IsMinuend} 
                    step={step}
                    subtrahendCount={subtrahendCount}
                />
            </group>

            {/* Term 2 (Text Order 2nd) */}
            <group ref={t2Ref}>
                <TermStack 
                    count={activePhrase.t2} 
                    varName={activePhrase.v2} 
                    color={t2IsMinuend ? "#3b82f6" : "#8b5cf6"} 
                    isMinuend={t2IsMinuend} 
                    step={step}
                    subtrahendCount={subtrahendCount}
                />
            </group>

            {/* Subtraction Operator */}
            <group ref={minusRef} scale={[0,0,0]} position={[0, 0, 0]}>
                <mesh>
                    <boxGeometry args={[1.5, 0.3, 0.3]} />
                    <meshStandardMaterial color="#f87171" emissive="#f87171" emissiveIntensity={0.5} />
                </mesh>
            </group>

            {/* Laser Effect */}
            <mesh ref={laserRef} position={[0, 0, 0]} scale={[0,0,0]}>
                <boxGeometry args={[4, 0.1, 0.1]} />
                <meshBasicMaterial color="#ef4444" transparent opacity={0.8} />
            </mesh>
        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function SubtractionTranslationSimulation() {
    const [step, setStep] = useState(0);
    const [phraseId, setPhraseId] = useState(0);

    const activePhrase = PHRASES[phraseId];
    const isReverse = activePhrase.reverse;

    const handleNextStep = () => {
        if (step < 3) setStep(s => s + 1);
    };

    const handlePhraseChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
        setPhraseId(parseInt(e.target.value));
        setStep(0);
    };

    const minuendVal = isReverse ? activePhrase.t2 : activePhrase.t1;
    const minuendVar = isReverse ? activePhrase.v2 : activePhrase.v1;
    const subVal = isReverse ? activePhrase.t1 : activePhrase.t2;
    const subVar = isReverse ? activePhrase.v1 : activePhrase.v2;

    const renderMath = () => {
        if (step === 0) return <BlockMath math={`\\text{"${activePhrase.text}"}`} />;
        if (step === 1) return <BlockMath math={`${minuendVal}${minuendVar} - ${subVal}${subVar}`} />;
        if (step === 2) return <BlockMath math={`\\cancel{${subVal}${subVar}} \\text{ subtracted from } ${minuendVal}${minuendVar}`} />;
        if (step === 3) return <BlockMath math={`${minuendVal}${minuendVar} - ${subVal}${subVar} = ${minuendVal - subVal}${minuendVar}`} />;
    };

    const getAlertContent = () => {
        if (step === 0) return { title: "Verbal Processing", icon: <Languages size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>The terms enter the Translation Matrix exactly as they appear in the English phrase. Notice the first term is <span className="font-mono text-white">{activePhrase.t1}{activePhrase.v1}</span> and the second is <span className="font-mono text-white">{activePhrase.t2}{activePhrase.v2}</span>.</> };
        if (step === 1) {
            if (isReverse) {
                return { title: "Matrix Flip Triggered", icon: <Repeat size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> English order does not equal math order! The word "from" means <span className="font-mono text-white">{activePhrase.t2}{activePhrase.v2}</span> is our starting amount (Minuend). The matrix physically flips their positions to form the correct algebraic expression.</> };
            } else {
                return { title: "Direct Translation", icon: <CheckCircle2 size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>Phrases like "difference" or "increase over" translate directly. <span className="font-mono text-white">{activePhrase.t1}{activePhrase.v1}</span> remains the starting amount. No matrix flip is required.</> };
            }
        }
        if (step === 2) return { title: "Evaluate Subtraction", icon: <Scissors size={18}/>, color: "border-l-yellow-500 text-yellow-300 bg-yellow-900/40", body: <>The subtraction operator activates. We must remove exactly <span className="font-mono text-white">{subVal}</span> units from the starting block of <span className="font-mono text-white">{minuendVal}</span> units.</> };
        if (step === 3) return { title: "Result Locked", icon: <CheckCircle2 size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>The remaining structure is evaluated. The final algebraic result is perfectly calculated. Use the sandbox dropdown below to test other linguistic phrases.</> };
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
                            Verbal to Algebraic Translation
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Decoding word order in algebraic subtraction.
                        </p>
                    </div>
                    <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[340px] flex justify-center shadow-inner overflow-x-auto">
                        <div className="text-white text-lg px-2 whitespace-nowrap">
                            {renderMath()}
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas shadows>
                        <color attach="background" args={['#020617']} />
                        <ambientLight intensity={0.6} />
                        <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
                        <Environment preset="city" opacity={0.3} />
                        
                        <Scene step={step} phraseId={phraseId} />
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
                            <span className="text-blue-400">Input</span>
                            <span className="text-rose-400">Matrix Flip</span>
                            <span className="text-yellow-400">Subtract</span>
                            <span className="text-emerald-400">Result</span>
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

                    {/* Sandbox Controls */}
                    <div className="flex flex-col gap-3 mb-4">
                        <div>
                            <label className="text-[10px] uppercase text-slate-500 mb-1 block flex items-center gap-1">
                                <Settings2 size={12}/> Select Verbal Phrase
                            </label>
                            <select 
                                value={phraseId} 
                                onChange={handlePhraseChange}
                                className="w-full bg-slate-950 text-white text-sm p-3 rounded-xl border border-slate-700 outline-none hover:border-slate-500 focus:border-blue-500 transition-colors"
                            >
                                {PHRASES.map(p => (
                                    <option key={p.id} value={p.id}>{p.text}</option>
                                ))}
                            </select>
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={handleNextStep} 
                            disabled={step === 3} 
                            className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm ${step === 3 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                        >
                            {step === 0 ? "Engage Matrix" : 
                             step === 1 ? "Run Subtraction" : 
                             step === 2 ? "Finalize" : "Completed"} 
                            {step < 3 && <MoveRight size={16} />}
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
