import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, RoundedBox } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, Wand2, ShieldAlert, CheckCircle2, RotateCcw, SlidersHorizontal, ArrowDown } from 'lucide-react';

// ---------------------------------------------------------
// CONSTANTS & LOGIC
// ---------------------------------------------------------

const SLOT_X = [-3.5, 0, 3.5];

const MINUEND = [
    { id: 'm-sq', c: 6, v: 'x^2', slot: 0 },
    { id: 'm-lin', c: 1, v: 'x', slot: 1 },
    { id: 'm-const', c: -4, v: '', slot: 2 },
];

const INITIAL_SUBTRAHEND = [
    { id: 's-sq', c: 2, v: 'x^2', slot: 0 },
    { id: 's-lin', c: -5, v: 'x', slot: 1 },
    { id: 's-const', c: 3, v: '', slot: 2 },
];

const formatTerm = (c: number, v: string, isFirst: boolean) => {
    if (c === 0 && v !== '') return '';
    const sign = c < 0 ? '-' : (isFirst ? (c > 0 ? '' : '') : '+');
    const absC = Math.abs(c);
    let coefStr = absC.toString();
    if (absC === 1 && v !== '') coefStr = '';
    return `${sign}${isFirst && c > 0 ? '' : ' '}${coefStr}${v}`.trim();
};

const getInvertedColor = (originalC: number, currentC: number) => {
    if (originalC === currentC) return "#334155"; // base color
    return currentC < 0 ? "#ef4444" : "#10b981"; // red if negative, green if positive
};

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function TermBlock({ text, slot, isTop, color = "#1e293b", isResult = false }: any) {
    const groupRef = useRef<THREE.Group>(null);
    const boxRef = useRef<THREE.Mesh>(null);
    
    const targetY = isResult ? -2.7 : (isTop ? 1.5 : -0.5);

    useEffect(() => {
        if (groupRef.current) {
            gsap.to(groupRef.current.position, {
                x: SLOT_X[slot],
                y: targetY,
                duration: 0.5,
                ease: "power2.out"
            });

            if (boxRef.current && (boxRef.current.material as any).color) {
                gsap.to((boxRef.current.material as any).color, {
                    r: new THREE.Color(color).r,
                    g: new THREE.Color(color).g,
                    b: new THREE.Color(color).b,
                    duration: 0.3
                });
            }
        }
    }, [slot, targetY, color]);

    return (
        <group ref={groupRef} position={[SLOT_X[slot], targetY, 0]}>
            <RoundedBox 
                ref={boxRef as any}
                args={[2.8, 1.2, 0.4]} 
                radius={0.2}
                castShadow
            >
                <meshStandardMaterial 
                    color={color} 
                    roughness={0.2} 
                    metalness={0.5} 
                    transparent 
                    opacity={0.9} 
                    emissive={isResult ? color : "#000000"}
                    emissiveIntensity={isResult ? 0.3 : 0}
                />
            </RoundedBox>
            
            <Text position={[0, 0, 0.21]} fontSize={0.6} color="#f8fafc" fontStyle="italic" fontWeight={isResult ? "bold" : "normal"}>
                {text}
            </Text>
        </group>
    );
}

function OperationSign({ step }: { step: number }) {
    const groupRef = useRef<THREE.Group>(null);
    const textRef = useRef<any>(null);

    useEffect(() => {
        if (step >= 1) {
            // Flip from minus to plus
            gsap.to(groupRef.current!.rotation, { x: Math.PI, duration: 0.5, ease: "back.out(1.5)" });
            setTimeout(() => {
                if (textRef.current) textRef.current.text = "+";
                gsap.to(textRef.current, { color: "#10b981", duration: 0.3 });
            }, 250);
        } else {
            gsap.to(groupRef.current!.rotation, { x: 0, duration: 0.5 });
            setTimeout(() => {
                if (textRef.current) textRef.current.text = "-";
                gsap.to(textRef.current, { color: "#f87171", duration: 0.3 });
            }, 250);
        }
    }, [step]);

    return (
        <group position={[-5.8, -0.5, 0]}>
            <group ref={groupRef}>
                <Text ref={textRef} fontSize={1.2} color="#f87171" fontWeight="bold">
                    -
                </Text>
            </group>
        </group>
    );
}

function Wand({ step, onWandPass }: { step: number, onWandPass: (slot: number) => void }) {
    const wandRef = useRef<THREE.Group>(null);
    const laserRef = useRef<THREE.Mesh>(null);

    useEffect(() => {
        if (step === 1) {
            gsap.to(wandRef.current!.scale, { y: 1, duration: 0.3 });
            
            const tl = gsap.timeline();
            tl.fromTo(wandRef.current!.position, { x: -6 }, { x: 5, duration: 2, ease: "power1.inOut" });

            // Trigger flips based on timing/position
            tl.call(() => onWandPass(0), [], 0.4);
            tl.call(() => onWandPass(1), [], 1.0);
            tl.call(() => onWandPass(2), [], 1.6);
            
            tl.to(wandRef.current!.scale, { y: 0, duration: 0.3 });

        } else if (step === 0) {
            gsap.to(wandRef.current!.position, { x: -6, duration: 0 });
            gsap.to(wandRef.current!.scale, { y: 0, duration: 0.3 });
        }
    }, [step, onWandPass]);

    return (
        <group ref={wandRef} position={[-6, -0.5, 0.5]} scale={[1, 0, 1]}>
            <mesh position={[0, 0, 0]}>
                <cylinderGeometry args={[0.05, 0.05, 2, 16]} />
                <meshStandardMaterial color="#fde047" emissive="#fde047" emissiveIntensity={2} />
            </mesh>
            <mesh ref={laserRef} position={[0, 0, 0]} scale={[1, 1, 0.1]}>
                <planeGeometry args={[0.2, 2.5]} />
                <meshBasicMaterial color="#fef08a" transparent opacity={0.4} side={THREE.DoubleSide} />
            </mesh>
        </group>
    );
}

function GridLines({ step }: { step: number }) {
    const groupRef = useRef<THREE.Group>(null);

    useEffect(() => {
        if (step >= 2) {
            gsap.to(groupRef.current!.scale, { y: 1, x: 1, duration: 0.5, ease: "power2.out" });
        } else {
            gsap.to(groupRef.current!.scale, { y: 0, x: 0, duration: 0.3 });
        }
    }, [step]);

    return (
        <group ref={groupRef} scale={[0,0,0]}>
            {SLOT_X.map((x, i) => (
                <mesh key={`v-${i}`} position={[x, 0.5, -0.5]}>
                    <cylinderGeometry args={[0.02, 0.02, 5, 8]} />
                    <meshBasicMaterial color="#06b6d4" transparent opacity={0.3} />
                </mesh>
            ))}
            <mesh position={[0, -1.6, 0]}>
                <boxGeometry args={[10, 0.05, 0.05]} />
                <meshBasicMaterial color="#ffffff" />
            </mesh>
        </group>
    );
}

function Scene({ step, subCoefs, onWandPass }: any) {
    const { camera } = useThree();
    const resultRef = useRef<THREE.Group>(null);

    useEffect(() => {
        if (step >= 2) {
            resultRef.current?.children.forEach((child, i) => {
                gsap.to(child.scale, { x: 1, y: 1, z: 1, duration: 0.5, delay: i * 0.2 + 0.3, ease: "back.out(1.5)" });
            });
        } else {
            resultRef.current?.children.forEach((child) => {
                gsap.to(child.scale, { x: 0, y: 0, z: 0, duration: 0.3 });
            });
        }

        gsap.to(camera.position, { 
            y: step >= 2 ? -0.5 : 0.5,
            z: step >= 2 ? 12 : 11,
            duration: 1.5, 
            ease: "power2.inOut" 
        });
    }, [step, camera]);

    return (
        <group>
            {/* Background alignment grid */}
            <mesh rotation={[-Math.PI/2, 0, 0]} position={[0, -3, 0]}>
                <planeGeometry args={[50, 50]} />
                <meshStandardMaterial color="#1e293b" wireframe transparent opacity={0.15} />
            </mesh>

            <OrbitControls enablePan={false} enableZoom={false} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
            
            {/* Top Row (Minuend) */}
            {MINUEND.map(term => (
                <TermBlock 
                    key={term.id} 
                    text={formatTerm(term.c, term.v, term.slot === 0)} 
                    slot={term.slot} 
                    isTop={true} 
                />
            ))}

            {/* Bottom Row (Subtrahend) */}
            {INITIAL_SUBTRAHEND.map((term, i) => (
                <TermBlock 
                    key={term.id} 
                    text={formatTerm(subCoefs[i], term.v, term.slot === 0)} 
                    slot={term.slot} 
                    isTop={false} 
                    color={getInvertedColor(INITIAL_SUBTRAHEND[i].c, subCoefs[i])}
                />
            ))}

            <OperationSign step={step} />
            <Wand step={step} onWandPass={onWandPass} />
            <GridLines step={step} />

            {/* Results Row */}
            <group ref={resultRef}>
                {MINUEND.map((term, i) => (
                    <group key={`res-${i}`} scale={[0,0,0]}>
                        <TermBlock 
                            text={formatTerm(term.c + subCoefs[i], term.v, term.slot === 0)} 
                            slot={term.slot} 
                            isResult={true} 
                            color="#ca8a04"
                        />
                    </group>
                ))}
            </group>

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function SubtractionWandSimulation() {
    const [step, setStep] = useState(0);
    const [subCoefs, setSubCoefs] = useState([INITIAL_SUBTRAHEND[0].c, INITIAL_SUBTRAHEND[1].c, INITIAL_SUBTRAHEND[2].c]);

    const handleWandPass = (slot: number) => {
        setSubCoefs(prev => {
            const next = [...prev];
            next[slot] = -next[slot]; // Flip sign
            return next;
        });
    };

    const handleNextStep = () => {
        if (step < 3) {
            setStep(s => s + 1);
        }
    };

    const handleReset = () => {
        setStep(0);
        setSubCoefs([INITIAL_SUBTRAHEND[0].c, INITIAL_SUBTRAHEND[1].c, INITIAL_SUBTRAHEND[2].c]);
    };

    // Derived states for math display
    const strM = `${formatTerm(MINUEND[0].c, 'x^2', true)} ${formatTerm(MINUEND[1].c, 'x', false)} ${formatTerm(MINUEND[2].c, '', false)}`;
    const strS = `${formatTerm(INITIAL_SUBTRAHEND[0].c, 'x^2', true)} ${formatTerm(INITIAL_SUBTRAHEND[1].c, 'x', false)} ${formatTerm(INITIAL_SUBTRAHEND[2].c, '', false)}`;
    const strInv = `${formatTerm(subCoefs[0], 'x^2', true)} ${formatTerm(subCoefs[1], 'x', false)} ${formatTerm(subCoefs[2], '', false)}`;
    const strRes = `${formatTerm(MINUEND[0].c + subCoefs[0], 'x^2', true)} ${formatTerm(MINUEND[1].c + subCoefs[1], 'x', false)} ${formatTerm(MINUEND[2].c + subCoefs[2], '', false)}`;

    const renderMath = () => {
        if (step === 0) return <BlockMath math={`(${strM}) - (${strS})`} />;
        if (step === 1) return <BlockMath math={`(${strM}) + (${strInv})`} />;
        if (step >= 2) return <BlockMath math={`${strRes}`} />;
    };

    const getAlertContent = () => {
        if (step === 0) return { title: "Vertical Subtraction Setup", icon: <MoveRight size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>The expression to be subtracted is placed in the bottom row. Notice the large negative operator <span className="font-mono font-bold">-</span> affecting the entire bottom expression.</> };
        if (step === 1) return { title: "The Wand of Inversion", icon: <Wand2 size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION AVOIDED:</b> The negative sign distributes to EVERY term inside the subtracted expression, not just the first one. Watch the wand sweep across and flip every single sign, turning the operation into addition.</> };
        if (step === 2) return { title: "Column-Wise Addition", icon: <ArrowDown size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>Now that we have properly inverted the subtrahend, we simply perform vertical addition straight down the columns to find our final expression.</> };
        if (step === 3) return { title: "Sandbox Testing", icon: <SlidersHorizontal size={18}/>, color: "border-l-slate-500 text-slate-300 bg-slate-800/40", body: <>Reset and try adjusting the original subtrahend. The rule holds true: subtracting an expression is identical to adding its opposite.</> };
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
                            Subtracting Expressions
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Distributing the negative sign across all terms.
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
                    <Canvas shadows camera={{ position: [0, 0.5, 11], fov: 45 }}>
                        <color attach="background" args={['#020617']} />
                        <ambientLight intensity={0.6} />
                        <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
                        <Environment preset="city" opacity={0.3} />
                        
                        <Scene step={step} subCoefs={subCoefs} onWandPass={handleWandPass} />
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
                            <span className="text-blue-400">Setup</span>
                            <span className="text-rose-400">Invert Signs</span>
                            <span className="text-emerald-400">Add Columns</span>
                            <span className="text-slate-300">Sandbox</span>
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

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={handleNextStep} 
                            disabled={step === 3} 
                            className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                ${step === 3 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                        >
                            {step === 0 ? "Sweep Wand" : 
                             step === 1 ? "Evaluate Columns" : 
                             step === 2 ? "Finish" : "Completed"} 
                            {step < 3 && <MoveRight size={16} />}
                        </button>
                        <button 
                            onClick={handleReset} 
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
