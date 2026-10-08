import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, RoundedBox, Line } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, Scale as ScaleIcon, Search, AlertTriangle, CheckCircle2, XCircle, Beaker, PackageOpen, RotateCcw } from 'lucide-react';

// ---------------------------------------------------------
// TYPES & CONSTANTS
// ---------------------------------------------------------

type CandidateStatus = 'rack' | 'testing' | 'wrong' | 'solution';

interface Candidate {
    val: number;
    status: CandidateStatus;
}

const INITIAL_CANDIDATES: Candidate[] = [
    { val: 2, status: 'rack' },
    { val: 3, status: 'rack' },
    { val: 4, status: 'rack' },
    { val: 5, status: 'rack' }
];

const FULCRUM_Y = 1.5;
const BEAM_LENGTH = 7; // -3.5 to 3.5
const PAN_DROP = 2.5;

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function CandidateOrb({ val, status, index, tiltAngle }: { val: number, status: CandidateStatus, index: number, tiltAngle: number }) {
    const groupRef = useRef<THREE.Group>(null);
    const meshRef = useRef<THREE.Mesh>(null);
    
    // Target calculation
    const targetPos = useRef(new THREE.Vector3());

    useFrame((state, delta) => {
        if (!groupRef.current) return;

        if (status === 'rack') {
            targetPos.current.set(-3 + index * 2, 4, 0);
        } else if (status === 'wrong') {
            targetPos.current.set(-3 + index * 2, 4, 0);
        } else if (status === 'testing') {
            const px = -(BEAM_LENGTH / 2) * Math.cos(tiltAngle);
            const py = FULCRUM_Y - (BEAM_LENGTH / 2) * Math.sin(tiltAngle) - PAN_DROP + 0.4;
            targetPos.current.set(px, py, 0);
        } else if (status === 'solution') {
            targetPos.current.set(4, -3, 0); // Inside solution pouch
        }

        // Smooth lerp to target
        groupRef.current.position.lerp(targetPos.current, 0.1);
    });

    useEffect(() => {
        if (!meshRef.current) return;
        const mat = meshRef.current.material as THREE.MeshStandardMaterial;
        
        if (status === 'wrong') {
            gsap.to(mat.color, { r: 0.9, g: 0.2, b: 0.2, duration: 0.5 });
            gsap.to(mat, { opacity: 0.4, transparent: true, duration: 0.5 });
        } else if (status === 'solution') {
            gsap.to(mat.color, { r: 0.1, g: 0.8, b: 0.3, duration: 0.5 });
            gsap.to(mat, { opacity: 1, transparent: false, duration: 0.5 });
        } else if (status === 'testing') {
            gsap.to(mat.color, { r: 0.2, g: 0.6, b: 1.0, duration: 0.3 });
            gsap.to(mat, { opacity: 1, transparent: false, duration: 0.3 });
        } else {
            // rack
            gsap.to(mat.color, { r: 0.3, g: 0.4, b: 0.8, duration: 0.5 });
            gsap.to(mat, { opacity: 0.9, transparent: true, duration: 0.5 });
        }
    }, [status]);

    return (
        <group ref={groupRef} position={[-3 + index * 2, 4, 0]}>
            <mesh ref={meshRef} castShadow>
                <sphereGeometry args={[0.5, 32, 32]} />
                <meshStandardMaterial color="#4f46e5" roughness={0.2} metalness={0.5} />
            </mesh>
            <Text position={[0, 0, 0.51]} fontSize={0.5} color="#ffffff" fontWeight="bold">
                {val}
            </Text>
        </group>
    );
}

function ScaleSystem({ tiltAngle }: { tiltAngle: number }) {
    const beamRef = useRef<THREE.Group>(null);
    const leftPanRef = useRef<THREE.Group>(null);
    const rightPanRef = useRef<THREE.Group>(null);

    useFrame(() => {
        if (beamRef.current && leftPanRef.current && rightPanRef.current) {
            // Smoothly rotate the beam to the target tilt angle
            beamRef.current.rotation.z = THREE.MathUtils.lerp(beamRef.current.rotation.z, tiltAngle, 0.1);
            
            // Counter-rotate the pans so they stay upright
            leftPanRef.current.rotation.z = -beamRef.current.rotation.z;
            rightPanRef.current.rotation.z = -beamRef.current.rotation.z;
        }
    });

    const panHalfWidth = 1.5;

    return (
        <group position={[0, 0, 0]}>
            {/* Base & Pillar */}
            <mesh position={[0, -4, 0]} castShadow>
                <cylinderGeometry args={[1.5, 2, 0.2, 32]} />
                <meshStandardMaterial color="#1e293b" />
            </mesh>
            <mesh position={[0, -1.25, 0]} castShadow>
                <cylinderGeometry args={[0.2, 0.3, 5.5, 16]} />
                <meshStandardMaterial color="#334155" metalness={0.5} />
            </mesh>

            {/* Fulcrum */}
            <mesh position={[0, FULCRUM_Y, 0]} rotation={[Math.PI / 2, 0, 0]}>
                <cylinderGeometry args={[0.3, 0.3, 0.6, 32]} />
                <meshStandardMaterial color="#0f172a" />
            </mesh>

            {/* Beam Group (Rotates) */}
            <group position={[0, FULCRUM_Y, 0]} ref={beamRef}>
                <mesh castShadow>
                    <boxGeometry args={[BEAM_LENGTH, 0.2, 0.4]} />
                    <meshStandardMaterial color="#475569" metalness={0.6} />
                </mesh>

                {/* Left Pan Attachment */}
                <group position={[-BEAM_LENGTH / 2, 0, 0]}>
                    <group ref={leftPanRef}>
                        <Line points={[[0, 0, 0], [-panHalfWidth, -PAN_DROP, 0]]} color="#94a3b8" lineWidth={2} />
                        <Line points={[[0, 0, 0], [panHalfWidth, -PAN_DROP, 0]]} color="#94a3b8" lineWidth={2} />
                        <Line points={[[0, 0, 0], [0, -PAN_DROP, -panHalfWidth]]} color="#94a3b8" lineWidth={2} />
                        <Line points={[[0, 0, 0], [0, -PAN_DROP, panHalfWidth]]} color="#94a3b8" lineWidth={2} />
                        
                        <mesh position={[0, -PAN_DROP, 0]} castShadow receiveShadow>
                            <cylinderGeometry args={[panHalfWidth + 0.2, panHalfWidth, 0.1, 32]} />
                            <meshStandardMaterial color="#1e293b" />
                        </mesh>
                        
                        {/* Fixed -3 Block on Left Pan */}
                        <group position={[1, -PAN_DROP + 0.2, 0]}>
                            <RoundedBox args={[0.8, 0.4, 0.8]} radius={0.05}>
                                <meshStandardMaterial color="#ef4444" />
                            </RoundedBox>
                            <Text position={[0, 0.21, 0]} rotation={[-Math.PI/2, 0, 0]} fontSize={0.3} color="#ffffff">-3</Text>
                        </group>

                        {/* Floating Equation Label */}
                        <Text position={[0, -PAN_DROP + 1.5, 0]} fontSize={0.6} color="#94a3b8" fontStyle="italic">
                            2(   ) - 3
                        </Text>
                    </group>
                </group>

                {/* Right Pan Attachment */}
                <group position={[BEAM_LENGTH / 2, 0, 0]}>
                    <group ref={rightPanRef}>
                        <Line points={[[0, 0, 0], [-panHalfWidth, -PAN_DROP, 0]]} color="#94a3b8" lineWidth={2} />
                        <Line points={[[0, 0, 0], [panHalfWidth, -PAN_DROP, 0]]} color="#94a3b8" lineWidth={2} />
                        <Line points={[[0, 0, 0], [0, -PAN_DROP, -panHalfWidth]]} color="#94a3b8" lineWidth={2} />
                        <Line points={[[0, 0, 0], [0, -PAN_DROP, panHalfWidth]]} color="#94a3b8" lineWidth={2} />
                        
                        <mesh position={[0, -PAN_DROP, 0]} castShadow receiveShadow>
                            <cylinderGeometry args={[panHalfWidth + 0.2, panHalfWidth, 0.1, 32]} />
                            <meshStandardMaterial color="#1e293b" />
                        </mesh>

                        {/* Fixed 5 Block on Right Pan */}
                        <group position={[0, -PAN_DROP + 0.4, 0]}>
                            <RoundedBox args={[1.5, 0.8, 1.5]} radius={0.1}>
                                <meshStandardMaterial color="#10b981" />
                            </RoundedBox>
                            <Text position={[0, 0.41, 0]} rotation={[-Math.PI/2, 0, 0]} fontSize={0.6} color="#ffffff">5</Text>
                        </group>

                        {/* Floating Label */}
                        <Text position={[0, -PAN_DROP + 1.5, 0]} fontSize={0.6} color="#94a3b8" fontStyle="italic">
                            5
                        </Text>
                    </group>
                </group>
            </group>
        </group>
    );
}

function Containers() {
    return (
        <group>
            {/* Substitution Set Container (Top) */}
            <group position={[0, 4.5, -1]}>
                <mesh>
                    <boxGeometry args={[10, 2, 0.2]} />
                    <meshBasicMaterial color="#334155" transparent opacity={0.3} />
                </mesh>
                <Text position={[0, 1.2, 0]} fontSize={0.4} color="#94a3b8" fontWeight="bold">
                    Substitution Set
                </Text>
            </group>

            {/* Solution Set Container (Bottom Right) */}
            <group position={[4, -3, -1]}>
                <mesh>
                    <boxGeometry args={[3, 3, 0.2]} />
                    <meshBasicMaterial color="#ca8a04" transparent opacity={0.2} />
                </mesh>
                <mesh position={[0, -1.5, 0.1]}>
                    <boxGeometry args={[3.2, 0.2, 1.5]} />
                    <meshStandardMaterial color="#854d0e" />
                </mesh>
                <Text position={[0, 1.8, 0]} fontSize={0.4} color="#facc15" fontWeight="bold">
                    Solution Set (S.S.)
                </Text>
            </group>
        </group>
    );
}

function Scene({ candidates, tiltAngle }: { candidates: Candidate[], tiltAngle: number }) {
    const { camera } = useThree();

    useEffect(() => {
        gsap.to(camera.position, { x: 0, y: 0.5, z: 12, duration: 1.5, ease: "power2.inOut" });
    }, [camera]);

    return (
        <group>
            <OrbitControls enablePan={false} enableZoom={false} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
            
            <ambientLight intensity={0.6} />
            <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow shadow-mapSize={[1024, 1024]} />
            <Environment preset="city" opacity={0.3} />

            <ScaleSystem tiltAngle={tiltAngle} />
            <Containers />
            
            {candidates.map((c, i) => (
                <CandidateOrb key={`cand-${c.val}`} val={c.val} status={c.status} index={i} tiltAngle={tiltAngle} />
            ))}
        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function SubstitutionSolutionSimulation() {
    const [candidates, setCandidates] = useState<Candidate[]>(INITIAL_CANDIDATES);
    const [tiltAngle, setTiltAngle] = useState(0);
    const [activeVal, setActiveVal] = useState<number | null>(null);
    const [evalState, setEvalState] = useState<'idle' | 'testing' | 'wrong' | 'correct'>('idle');
    const [hasSolved, setHasSolved] = useState(false);

    const testValue = (val: number) => {
        if (evalState === 'testing') return;

        // Reset all to rack (except if we want to keep the solution in the pouch)
        // Specification: "Final visual state locks accepted solution element in Solution Set pouch"
        setCandidates(prev => prev.map(c => {
            if (c.val === val) return { ...c, status: 'testing' };
            if (c.status === 'solution') return c; // keep solved one in pouch
            return { ...c, status: 'rack' };
        }));
        
        setActiveVal(val);
        setEvalState('testing');

        // Math
        const leftW = 2 * val - 3;
        const rightW = 5;
        const diff = rightW - leftW; 
        
        // If left is lighter (diff > 0), left goes up, right goes down -> tilt > 0
        const targetTilt = Math.max(-0.3, Math.min(0.3, diff * 0.12));

        setTimeout(() => {
            setTiltAngle(targetTilt); // Trigger scale tilt

            setTimeout(() => {
                if (leftW === rightW) {
                    setEvalState('correct');
                    setHasSolved(true);
                    setCandidates(prev => prev.map(c => c.val === val ? { ...c, status: 'solution' } : c));
                    setTiltAngle(0); // Level out
                } else {
                    setEvalState('wrong');
                    setCandidates(prev => prev.map(c => c.val === val ? { ...c, status: 'wrong' } : c));
                    // Optional: keep it tilted until they select another, or level out?
                    // Let's keep it tilted while they observe the failure, then it resets when they click another.
                }
            }, 1500); // Time spent tilted
        }, 800); // Time for ball to drop into pan
    };

    const handleReset = () => {
        setCandidates(INITIAL_CANDIDATES);
        setTiltAngle(0);
        setActiveVal(null);
        setEvalState('idle');
        setHasSolved(false);
    };

    const renderMath = () => {
        if (evalState === 'idle') return <BlockMath math={`2x - 3 = 5`} />;
        if (evalState === 'testing' && activeVal !== null) return <BlockMath math={`2(${activeVal}) - 3 \\stackrel{?}{=} 5`} />;
        
        if (evalState === 'wrong' && activeVal !== null) {
            const res = 2 * activeVal - 3;
            return <BlockMath math={`2(${activeVal}) - 3 = ${res} \\Rightarrow ${res} \\neq 5`} />;
        }
        if (evalState === 'correct' && activeVal !== null) {
            return <BlockMath math={`2(${activeVal}) - 3 = 5 \\Rightarrow 5 = 5`} />;
        }
    };

    const getAlertContent = () => {
        if (evalState === 'idle') return { title: "The Substitution Set", icon: <PackageOpen size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>We have a pool of candidate values <span className="font-mono text-white">{'{2, 3, 4, 5}'}</span>. This is the <b>Substitution Set</b>. Select a value to test it in the equation testing chamber.</> };
        
        if (evalState === 'testing') return { title: "Evaluating Expression", icon: <ScaleIcon size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>The candidate is dropped into the expression <span className="font-mono text-white">2x - 3</span>. The scale physically calculates the resulting weight and compares it against the target weight of 5.</> };
        
        if (evalState === 'wrong') return { title: "Unbalanced (False Statement)", icon: <XCircle size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> Just because a number is in the Substitution Set does NOT mean it satisfies the equation! The scale is tilted, meaning this candidate is rejected and returned to the rack.</> };

        if (evalState === 'correct') return { title: "Balanced (True Statement)", icon: <CheckCircle2 size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>Perfect Balance! This value makes the statement true. It is officially transferred from the Substitution Set into the elite <b>Solution Set (S.S.)</b>.</> };

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
                            Substitution vs Solution Set
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Testing candidate values to find true equality.
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
                    <Canvas shadows camera={{ position: [0, 0, 12], fov: 45 }}>
                        <color attach="background" args={['#020617']} />
                        
                        <Scene candidates={candidates} tiltAngle={tiltAngle} />
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
                    
                    <div className="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Test Substitution Candidates</div>
                    
                    {/* Candidate Selectors */}
                    <div className="grid grid-cols-4 gap-2 mb-4">
                        {INITIAL_CANDIDATES.map(c => {
                            const candState = candidates.find(can => can.val === c.val)?.status;
                            const isSolution = candState === 'solution';
                            const isWrong = candState === 'wrong';
                            const isTesting = candState === 'testing';
                            
                            let btnStyle = "bg-slate-800 text-slate-300 hover:bg-indigo-600 border-slate-700";
                            if (isSolution) btnStyle = "bg-emerald-600 text-white border-emerald-500 shadow-[0_0_15px_rgba(16,185,129,0.4)]";
                            if (isWrong) btnStyle = "bg-rose-900/50 text-rose-300 border-rose-800 opacity-50";
                            if (isTesting) btnStyle = "bg-blue-600 text-white border-blue-500 animate-pulse";

                            return (
                                <button
                                    key={c.val}
                                    onClick={() => testValue(c.val)}
                                    disabled={evalState === 'testing' || isSolution}
                                    className={`py-3 rounded-xl font-bold border transition-all ${btnStyle} ${evalState === 'testing' ? 'cursor-not-allowed opacity-50' : ''}`}
                                >
                                    x = {c.val}
                                </button>
                            );
                        })}
                    </div>

                    {/* Reset Action */}
                    <div className="mt-auto h-[50px] flex items-center justify-center">
                        {hasSolved ? (
                            <button 
                                onClick={handleReset} 
                                className="w-full bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
                            >
                                <RotateCcw size={16} /> Reset Simulation
                            </button>
                        ) : (
                            <div className="text-sm text-slate-400 italic text-center px-4 w-full">
                                {evalState === 'testing' ? "Evaluating..." : "Select a value to test."}
                            </div>
                        )}
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
