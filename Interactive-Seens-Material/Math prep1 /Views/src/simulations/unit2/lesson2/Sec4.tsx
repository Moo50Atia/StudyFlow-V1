import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, RoundedBox } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, Wand2, RotateCcw, ArrowLeftRight, Languages, Layers, Scale as ScaleIcon } from 'lucide-react';

// ---------------------------------------------------------
// DATA & LOGIC
// ---------------------------------------------------------

const PHRASES = [
    {
        id: 'increase',
        label: "Increase of over",
        text: "Increase of (5x + 3y) over (2x - y)",
        expA: [{ c: 5, v: 'x' }, { c: 3, v: 'y' }],
        expB: [{ c: 2, v: 'x' }, { c: -1, v: 'y' }],
        isReverse: false // A minus B
    },
    {
        id: 'added_to',
        label: "What must be added",
        text: "What must be added to (3x² - 4) to get (7x² + 2)",
        expA: [{ c: 3, v: 'x^2' }, { c: -4, v: '' }],
        expB: [{ c: 7, v: 'x^2' }, { c: 2, v: '' }],
        isReverse: true // B minus A
    },
    {
        id: 'subtract_from',
        label: "Subtract from",
        text: "Subtract (4a - 5b) from (8a + 2b)",
        expA: [{ c: 4, v: 'a' }, { c: -5, v: 'b' }],
        expB: [{ c: 8, v: 'a' }, { c: 2, v: 'b' }],
        isReverse: true // B minus A
    }
];

const formatTerm = (c: number, v: string, isFirst: boolean) => {
    if (c === 0 && v !== '') return '';
    const sign = c < 0 ? '-' : (isFirst ? (c > 0 ? '' : '') : '+');
    const absC = Math.abs(c);
    let coefStr = absC.toString();
    if (absC === 1 && v !== '') coefStr = '';
    return `${sign}${isFirst && c > 0 ? '' : ' '}${coefStr}${v}`.trim();
};

const formatExpression = (exp: any[]) => {
    return `${formatTerm(exp[0].c, exp[0].v, true)} ${formatTerm(exp[1].c, exp[1].v, false)}`;
};

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function TermBlock({ text, targetPos, color, isVisible = true }: any) {
    const groupRef = useRef<THREE.Group>(null);
    const boxRef = useRef<THREE.Mesh>(null);

    useEffect(() => {
        if (!groupRef.current) return;
        if (isVisible) {
            gsap.to(groupRef.current.position, { x: targetPos[0], y: targetPos[1], z: targetPos[2], duration: 0.8, ease: "power2.out" });
            gsap.to(groupRef.current.scale, { x: 1, y: 1, z: 1, duration: 0.4 });
        } else {
            gsap.to(groupRef.current.scale, { x: 0, y: 0, z: 0, duration: 0.4 });
        }

        if (boxRef.current) {
            gsap.to((boxRef.current.material as any).color, {
                r: new THREE.Color(color).r,
                g: new THREE.Color(color).g,
                b: new THREE.Color(color).b,
                duration: 0.4
            });
        }
    }, [targetPos, color, isVisible]);

    return (
        <group ref={groupRef} scale={[0, 0, 0]}>
            <RoundedBox ref={boxRef as any} args={[1.8, 1.2, 0.3]} radius={0.15} castShadow>
                <meshStandardMaterial color={color} roughness={0.2} metalness={0.5} transparent opacity={0.9} />
            </RoundedBox>
            <Text position={[0, 0, 0.16]} fontSize={0.6} color="#ffffff" fontStyle="italic" fontWeight="bold">
                {text}
            </Text>
        </group>
    );
}

function OperatorSign({ step }: { step: number }) {
    const groupRef = useRef<THREE.Group>(null);
    const textRef = useRef<any>(null);
    const glowRef = useRef<THREE.Mesh>(null);

    useEffect(() => {
        if (step === 0 || step >= 4) {
            gsap.to(groupRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
            gsap.to(glowRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
        } else {
            gsap.to(groupRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.3 });
            gsap.to(glowRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.3 });

            if (step >= 2) {
                gsap.to(groupRef.current!.rotation, { x: Math.PI, duration: 0.5 });
                setTimeout(() => {
                    if (textRef.current) textRef.current.text = "+";
                    gsap.to(textRef.current, { color: "#10b981", duration: 0.3 });
                    gsap.to((glowRef.current!.material as any).color, { r: 0.06, g: 0.72, b: 0.5, duration: 0.3 }); // #10b981
                }, 250);
            } else {
                gsap.to(groupRef.current!.rotation, { x: 0, duration: 0.5 });
                setTimeout(() => {
                    if (textRef.current) textRef.current.text = "-";
                    gsap.to(textRef.current, { color: "#f87171", duration: 0.3 });
                    gsap.to((glowRef.current!.material as any).color, { r: 0.97, g: 0.44, b: 0.44, duration: 0.3 }); // #f87171
                }, 250);
            }
        }
    }, [step]);

    return (
        <group position={[-3.5, 1.25, 0]}>
            <mesh ref={glowRef} position={[0, 0, -0.2]}>
                <sphereGeometry args={[0.7, 16, 16]} />
                <meshBasicMaterial transparent opacity={0.2} depthWrite={false} />
            </mesh>
            <group ref={groupRef} scale={[0, 0, 0]}>
                <Text ref={textRef} fontSize={1.4} color="#f87171" fontWeight="bold">-</Text>
            </group>
        </group>
    );
}

function BalanceScale({ step }: { step: number }) {
    const scaleRef = useRef<THREE.Group>(null);
    const beamRef = useRef<THREE.Group>(null);

    useEffect(() => {
        if (step === 4) {
            gsap.to(scaleRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: "back.out(1.2)" });
            gsap.fromTo(beamRef.current!.rotation, { z: 0.15 }, { z: 0, duration: 1.5, ease: "elastic.out(1, 0.4)", delay: 0.6 });
        } else {
            gsap.to(scaleRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
        }
    }, [step]);

    return (
        <group ref={scaleRef} scale={[0, 0, 0]} position={[0, 0, 0]}>
            <mesh position={[0, -4.5, 0]} castShadow>
                <cylinderGeometry args={[0, 0.5, 1, 4]} />
                <meshStandardMaterial color="#475569" roughness={0.2} metalness={0.8} />
            </mesh>
            <group ref={beamRef} position={[0, -4.0, 0]}>
                <mesh position={[0, 0, 0]} castShadow>
                    <boxGeometry args={[7, 0.15, 0.4]} />
                    <meshStandardMaterial color="#94a3b8" metalness={0.5} />
                </mesh>
                <group position={[-3.5, 0, 0]}>
                    <mesh position={[0, -0.25, 0]}><cylinderGeometry args={[0.02, 0.02, 0.5]} /><meshBasicMaterial color="#cbd5e1" /></mesh>
                    <mesh position={[0, -0.5, 0]} castShadow receiveShadow><boxGeometry args={[4.4, 0.1, 1.5]} /><meshStandardMaterial color="#334155" /></mesh>
                </group>
                <group position={[3.5, 0, 0]}>
                    <mesh position={[0, -0.25, 0]}><cylinderGeometry args={[0.02, 0.02, 0.5]} /><meshBasicMaterial color="#cbd5e1" /></mesh>
                    <mesh position={[0, -0.5, 0]} castShadow receiveShadow><boxGeometry args={[4.4, 0.1, 1.5]} /><meshStandardMaterial color="#334155" /></mesh>
                </group>
            </group>
            <group position={[0, -4.3, 0.6]}>
                <Text fontSize={0.6} color="#10b981" fontWeight="bold">=</Text>
            </group>
        </group>
    );
}

function ExpressionBacking({ step, targetPos, label }: any) {
    const ref = useRef<THREE.Group>(null);
    useEffect(() => {
        if (step === 0) {
            gsap.to(ref.current!.scale, { x: 1, y: 1, z: 1, duration: 0.5 });
            gsap.to(ref.current!.position, { x: targetPos[0], y: targetPos[1], duration: 0.5 });
        } else {
            gsap.to(ref.current!.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
        }
    }, [step, targetPos]);

    return (
        <group ref={ref} scale={[0, 0, 0]} position={targetPos}>
            <mesh position={[0, 0, -0.2]}>
                <planeGeometry args={[4.6, 2]} />
                <meshBasicMaterial color="#1e293b" transparent opacity={0.4} />
            </mesh>
            <Text position={[0, 1.3, 0]} fontSize={0.35} color="#94a3b8" fontWeight="bold">{label}</Text>
        </group>
    );
}

function Scene({ step, phraseIndex }: any) {
    const { camera } = useThree();
    const phrase = PHRASES[phraseIndex];
    
    const minuend = phrase.isReverse ? phrase.expB : phrase.expA;
    const subtrahend = phrase.isReverse ? phrase.expA : phrase.expB;

    const resCoefs = [
        minuend[0].c - subtrahend[0].c,
        minuend[1].c - subtrahend[1].c
    ];

    const getPos = (identity: string, slot: number, currentStep: number) => {
        if (identity === 'minuend') {
            if (currentStep === 0) return phrase.isReverse ? [2.5 + slot * 2, 0.5, 0] : [-4.5 + slot * 2, 0.5, 0];
            if (currentStep === 4) return [3.5 + (slot === 0 ? -1 : 1), -3.85, 0];
            return [slot === 0 ? -1 : 1, 2, 0]; // Top Row
        }
        if (identity === 'subtrahend') {
            if (currentStep === 0) return phrase.isReverse ? [-4.5 + slot * 2, 0.5, 0] : [2.5 + slot * 2, 0.5, 0];
            if (currentStep === 4) return [-3.5 + (slot === 0 ? -1 : 1), -3.85, 0];
            return [slot === 0 ? -1 : 1, 0.5, 0]; // Bottom Row
        }
        if (identity === 'result') {
            if (currentStep < 3) return [slot === 0 ? -1 : 1, -1, 0]; // Hidden
            if (currentStep === 4) return [-3.5 + (slot === 0 ? -1 : 1), -2.55, 0]; // Stacked Left
            return [slot === 0 ? -1 : 1, -1, 0]; // Result Row
        }
        return [0,0,0];
    };

    const subColors = subtrahend.map(t => {
        if (step < 2) return "#334155";
        return -t.c < 0 ? "#ef4444" : "#10b981";
    });

    const subTexts = subtrahend.map((t, i) => {
        const currentC = step >= 2 ? -t.c : t.c;
        return formatTerm(currentC, t.v, i === 0);
    });

    useEffect(() => {
        if (step === 0) {
            gsap.to(camera.position, { x: 0, y: 0.5, z: 12, duration: 1.5, ease: "power2.inOut" });
        } else if (step >= 1 && step <= 3) {
            gsap.to(camera.position, { x: 0, y: 0.5, z: 9, duration: 1.5, ease: "power2.inOut" });
        } else if (step === 4) {
            gsap.to(camera.position, { x: 0, y: -1, z: 13.5, duration: 1.5, ease: "power2.inOut" });
        }
    }, [step, camera]);

    return (
        <group>
            {/* Background alignment grid */}
            <mesh rotation={[-Math.PI / 2, 0, 0]} position={[0, -4.5, 0]}>
                <planeGeometry args={[50, 50]} />
                <meshStandardMaterial color="#1e293b" wireframe transparent opacity={0.15} />
            </mesh>

            <OrbitControls enablePan={false} enableZoom={false} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
            
            <ExpressionBacking step={step} targetPos={[-3.5, 0.5, 0]} label="Expression 1" />
            <ExpressionBacking step={step} targetPos={[3.5, 0.5, 0]} label="Expression 2" />

            <OperatorSign step={step} />

            {/* Minuend Blocks */}
            <TermBlock text={formatTerm(minuend[0].c, minuend[0].v, true)} targetPos={getPos('minuend', 0, step)} color="#1e293b" />
            <TermBlock text={formatTerm(minuend[1].c, minuend[1].v, false)} targetPos={getPos('minuend', 1, step)} color="#1e293b" />

            {/* Subtrahend Blocks */}
            <TermBlock text={subTexts[0]} targetPos={getPos('subtrahend', 0, step)} color={subColors[0]} />
            <TermBlock text={subTexts[1]} targetPos={getPos('subtrahend', 1, step)} color={subColors[1]} />

            {/* Result Blocks */}
            <TermBlock text={formatTerm(resCoefs[0], minuend[0].v, true)} targetPos={getPos('result', 0, step)} color="#ca8a04" isVisible={step >= 3} />
            <TermBlock text={formatTerm(resCoefs[1], minuend[1].v, false)} targetPos={getPos('result', 1, step)} color="#ca8a04" isVisible={step >= 3} />

            <BalanceScale step={step} />
        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function LinguisticTranslationSimulation() {
    const [step, setStep] = useState(0);
    const [phraseIndex, setPhraseIndex] = useState(0);

    const handleNextStep = () => {
        if (step < 4) setStep(s => s + 1);
    };

    const p = PHRASES[phraseIndex];
    const minuend = p.isReverse ? p.expB : p.expA;
    const subtrahend = p.isReverse ? p.expA : p.expB;
    
    const strM = formatExpression(minuend);
    const strS = formatExpression(subtrahend);
    const invSub = subtrahend.map(t => ({ c: -t.c, v: t.v }));
    const strInvS = formatExpression(invSub);
    const result = [
        { c: minuend[0].c - subtrahend[0].c, v: minuend[0].v },
        { c: minuend[1].c - subtrahend[1].c, v: minuend[1].v }
    ];
    const strRes = formatExpression(result);

    const renderMath = () => {
        if (step === 0) return <div className="text-xl md:text-2xl font-serif text-white italic drop-shadow-md">"{p.text}"</div>;
        if (step === 1) return <BlockMath math={`(${strM}) - (${strS})`} />;
        if (step === 2) return <BlockMath math={`(${strM}) + (${strInvS})`} />;
        if (step === 3) return <BlockMath math={`${strRes}`} />;
        if (step === 4) return <BlockMath math={`\\text{Check: } (${strS}) + (${strRes}) = (${strM})`} />;
    };

    const getAlertContent = () => {
        if (step === 0) return { title: "Linguistic Translation", icon: <Languages size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>Carefully read the verbal phrase. Identify which expression is the starting point (<b>Minuend</b>) and which is being applied to it (<b>Subtrahend</b>).</> };
        if (step === 1) {
            if (p.isReverse) {
                 return { title: "Order Reversed", icon: <ArrowLeftRight size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>Notice how the second expression in the sentence actually became the top Minuend! Phrases like <b>"added to"</b> or <b>"subtract from"</b> require reversing the linguistic order into algebraic order.</> };
            } else {
                 return { title: "Direct Order", icon: <MoveRight size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>For this phrase, the linguistic order matches the algebraic order. The first expression is the Minuend, and the second is the Subtrahend.</> };
            }
        }
        if (step === 2) return { title: "Sign Inversion", icon: <Wand2 size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <>The subtractor node distributes the negative sign across the entire bottom expression, flipping every term to its opposite to convert the operation to addition.</> };
        if (step === 3) return { title: "Column Evaluation", icon: <Layers size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>With the subtrahend inverted, we simply add the columns vertically to find the resulting polynomial.</> };
        if (step === 4) return { title: "Balance Verification", icon: <ScaleIcon size={18}/>, color: "border-l-amber-500 text-amber-300 bg-amber-900/40", body: <>To verify subtraction (<span className="font-mono text-white">M - S = R</span>), we check if the Subtrahend plus the Result balances perfectly with the original Minuend (<span className="font-mono text-white">S + R = M</span>). The scale confirms our translation!</> };
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
                            Verbal Subtraction Translator
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Converting English phrases into structural algebra.
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
                    <Canvas shadows camera={{ position: [0, 0.5, 12], fov: 45 }}>
                        <color attach="background" args={['#020617']} />
                        <ambientLight intensity={0.6} />
                        <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
                        <Environment preset="city" opacity={0.3} />
                        
                        <Scene step={step} phraseIndex={phraseIndex} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                <div className={`flex-1 pointer-events-auto max-w-md w-full transition-all duration-500 origin-bottom-left scale-100 opacity-100`}>
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
                    
                    {/* Phrase Selector */}
                    <div className="mb-4">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 block px-1">Select Phrase</label>
                        <select 
                            value={phraseIndex} 
                            onChange={(e) => {
                                setPhraseIndex(Number(e.target.value));
                                setStep(0);
                            }}
                            className="bg-slate-950 border border-slate-700 text-slate-200 rounded-lg p-2.5 text-sm w-full outline-none focus:border-blue-500 shadow-inner"
                        >
                            {PHRASES.map((ph, idx) => (
                                <option key={ph.id} value={idx}>{ph.label}: "{ph.text.substring(0, 25)}..."</option>
                            ))}
                        </select>
                    </div>

                    {/* Steps Map */}
                    <div className="flex flex-col gap-2 mb-4">
                        <div className="flex justify-between text-[10px] font-bold uppercase tracking-wider text-slate-400 px-1">
                            <span className="text-blue-400">Read</span>
                            <span className="text-purple-400">Order</span>
                            <span className="text-rose-400">Invert</span>
                            <span className="text-emerald-400">Evaluate</span>
                            <span className="text-amber-400">Verify</span>
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

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={handleNextStep} 
                            disabled={step === 4} 
                            className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                ${step === 4 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                        >
                            {step === 0 ? "Translate Order" : 
                             step === 1 ? "Invert Signs" : 
                             step === 2 ? "Combine" : 
                             step === 3 ? "Verify Math" : "Completed"} 
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
