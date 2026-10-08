import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Text, Line, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Layers, CheckCircle, Move, Search } from 'lucide-react';

// ---------------------------------------------------------
// SUB-COMPONENTS
// ---------------------------------------------------------

function Caliper({ p1, p2, text, color, flip = false }: { p1: THREE.Vector3, p2: THREE.Vector3, text: string, color: string, flip?: boolean }) {
    const mid = new THREE.Vector3().addVectors(p1, p2).multiplyScalar(0.5);
    const dx = p2.x - p1.x;
    const dy = p2.y - p1.y;
    const len = Math.hypot(dx, dy);
    
    let nx = -dy / len;
    let ny = dx / len;
    if (flip) {
        nx = -nx;
        ny = -ny;
    }
    
    const offset = 0.6;
    const tickLen = 0.3;

    const t1a = new THREE.Vector3(p1.x + nx * (offset - tickLen/2), p1.y + ny * (offset - tickLen/2), 0);
    const t1b = new THREE.Vector3(p1.x + nx * (offset + tickLen/2), p1.y + ny * (offset + tickLen/2), 0);
    const t2a = new THREE.Vector3(p2.x + nx * (offset - tickLen/2), p2.y + ny * (offset - tickLen/2), 0);
    const t2b = new THREE.Vector3(p2.x + nx * (offset + tickLen/2), p2.y + ny * (offset + tickLen/2), 0);

    const c1 = new THREE.Vector3(p1.x + nx * offset, p1.y + ny * offset, 0);
    const c2 = new THREE.Vector3(p2.x + nx * offset, p2.y + ny * offset, 0);

    return (
        <group>
            <Line points={[t1a, t1b]} color={color} lineWidth={2} />
            <Line points={[t2a, t2b]} color={color} lineWidth={2} />
            <Line points={[c1, c2]} color={color} lineWidth={2} />
            <Text position={[mid.x + nx * 1.0, mid.y + ny * 1.0, 0]} fontSize={0.4} color={color} fontWeight="bold">
                {text}
            </Text>
        </group>
    );
}

function Beam({ p1, p2, text, color }: { p1: THREE.Vector3, p2: THREE.Vector3, text: string, color: string }) {
    const mid = new THREE.Vector3().addVectors(p1, p2).multiplyScalar(0.5);
    const dx = p2.x - p1.x;
    const dy = p2.y - p1.y;
    const len = Math.hypot(dx, dy);
    let nx = -dy / len;
    let ny = dx / len;
    if (nx < 0) { nx = -nx; ny = -ny; }
    
    return (
        <group>
            <Line points={[p1, p2]} color={color} lineWidth={8} transparent opacity={0.4} />
            <Line points={[p1, p2]} color="#ffffff" lineWidth={2} />
            <Text position={[mid.x + nx * 0.7, mid.y + ny * 0.7, 0]} fontSize={0.45} color={color} fontWeight="extrabold">
                {text}
            </Text>
        </group>
    );
}

function RightAngleIcon({ p, p1, p2, color }: { p: THREE.Vector3, p1: THREE.Vector3, p2: THREE.Vector3, color: string }) {
    const size = 0.5;
    const d1 = new THREE.Vector3().subVectors(p1, p).normalize().multiplyScalar(size);
    const d2 = new THREE.Vector3().subVectors(p2, p).normalize().multiplyScalar(size);
    
    const pt1 = new THREE.Vector3().addVectors(p, d1);
    const pt2 = new THREE.Vector3().addVectors(p, d2);
    const pt3 = new THREE.Vector3().addVectors(pt1, d2);
    
    return <Line points={[pt1, pt3, pt2]} color={color} lineWidth={3} />;
}

function AngleArc({ center, v1, v2, radius, text, color }: { center: THREE.Vector3, v1: THREE.Vector3, v2: THREE.Vector3, radius: number, text: string, color: string }) {
    const arcPts = useMemo(() => {
        const pts = [];
        const s = Math.atan2(v1.y - center.y, v1.x - center.x);
        let e = Math.atan2(v2.y - center.y, v2.x - center.x);
        
        let d = e - s;
        while (d <= -Math.PI) d += 2 * Math.PI;
        while (d > Math.PI) d -= 2 * Math.PI;
        
        for (let i = 0; i <= 16; i++) {
            const t = s + d * (i / 16);
            pts.push(new THREE.Vector3(center.x + radius * Math.cos(t), center.y + radius * Math.sin(t), 0));
        }
        return pts;
    }, [center, v1, v2, radius]);

    const midIdx = Math.floor(arcPts.length / 2);
    const midP = arcPts[midIdx];
    const textPos = new THREE.Vector3(
        center.x + (midP.x - center.x) * 1.6,
        center.y + (midP.y - center.y) * 1.6,
        0
    );

    return (
        <group>
            <Line points={arcPts} color={color} lineWidth={3} />
            <Text position={textPos} fontSize={0.35} color={color} fontWeight="bold">{text}</Text>
        </group>
    );
}

// ---------------------------------------------------------
// 3D SCENE COMPONENT
// ---------------------------------------------------------

function RHSScene({ alignment, isRight }: { alignment: number, isRight: boolean }) {
    const moveGroup = useRef<THREE.Group>(null);
    const proxy = useRef({ t: alignment / 100 }).current;
    
    useFrame(() => {
        proxy.t = THREE.MathUtils.lerp(proxy.t, alignment / 100, 0.1);
        if (moveGroup.current) {
            moveGroup.current.position.x = proxy.t * 8;
            moveGroup.current.position.y = Math.sin(proxy.t * Math.PI) * 1.5; // Arc trajectory
        }
    });

    const isMatched = proxy.t > 0.98 && isRight;
    const baseColor = isMatched ? "#22c55e" : "#3b82f6";
    const moveColor = isMatched ? "#22c55e" : "#a855f7";

    // Geometry Definitions
    // ABC (Moving) - Always Right Angled
    const A = new THREE.Vector3(-6, 1.8, 0);
    const B = new THREE.Vector3(-6, -1.8, 0);
    const C = new THREE.Vector3(-1.2, -1.8, 0);
    const ptsABC = [A, B, C];

    // DEF (Static Base) - Toggles based on isRight
    const D = new THREE.Vector3(2, 1.8, 0);
    const E = new THREE.Vector3(2, -1.8, 0);
    const F = isRight 
        ? new THREE.Vector3(6.8, -1.8, 0)
        : new THREE.Vector3(5.5, -3.07, 0); // 110 degree rotation
    const ptsDEF = [D, E, F];

    const geomABC = useMemo(() => {
        const shape = new THREE.Shape();
        shape.moveTo(A.x, A.y); shape.lineTo(B.x, B.y); shape.lineTo(C.x, C.y); shape.lineTo(A.x, A.y);
        return new THREE.ShapeGeometry(shape);
    }, [A, B, C]);

    const geomDEF = useMemo(() => {
        const shape = new THREE.Shape();
        shape.moveTo(D.x, D.y); shape.lineTo(E.x, E.y); shape.lineTo(F.x, F.y); shape.lineTo(D.x, D.y);
        return new THREE.ShapeGeometry(shape);
    }, [D, E, F]);

    useEffect(() => {
        return () => { geomABC.dispose(); geomDEF.dispose(); };
    }, [geomABC, geomDEF]);

    return (
        <group position={[0, -0.5, 0]}>
            <ambientLight intensity={0.8} />
            <mesh position={[0, 0, -2]}><planeGeometry args={[80, 80]} /><meshBasicMaterial color="#020617" /></mesh>
            <gridHelper args={[80, 80, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* BASE TRIANGLE (DEF) */}
            <group>
                <mesh geometry={geomDEF}><meshBasicMaterial color={baseColor} transparent opacity={isMatched ? 0.4 : 0.15} /></mesh>
                <Line points={[...ptsDEF, ptsDEF[0]]} color={baseColor} lineWidth={4} />
                
                {/* Labels */}
                <Sphere position={D} args={[0.15]}><meshBasicMaterial color={baseColor}/></Sphere>
                <Sphere position={E} args={[0.15]}><meshBasicMaterial color={baseColor}/></Sphere>
                <Sphere position={F} args={[0.15]}><meshBasicMaterial color={baseColor}/></Sphere>
                <Text position={[D.x - 0.5, D.y + 0.5, 0]} fontSize={0.5} color={baseColor} fontWeight="bold">D</Text>
                <Text position={[E.x - 0.5, E.y - 0.5, 0]} fontSize={0.5} color={baseColor} fontWeight="bold">E</Text>
                <Text position={[F.x + 0.5, F.y - 0.5, 0]} fontSize={0.5} color={baseColor} fontWeight="bold">F</Text>

                {/* Measurements */}
                <Beam p1={D} p2={F} text="10 cm" color="#f59e0b" />
                <Caliper p1={D} p2={E} text="6 cm" color="#0ea5e9" flip={true} />
                
                {/* Angle */}
                {isRight ? (
                    <RightAngleIcon p={E} p1={D} p2={F} color="#22c55e" />
                ) : (
                    <AngleArc center={E} v1={F} v2={D} radius={0.8} text="110°" color="#ef4444" />
                )}
            </group>

            {/* MOVING TRIANGLE (ABC) */}
            <group ref={moveGroup}>
                <mesh geometry={geomABC}><meshBasicMaterial color={moveColor} transparent opacity={isMatched ? 0.4 : 0.15} /></mesh>
                <Line points={[...ptsABC, ptsABC[0]]} color={moveColor} lineWidth={4} />
                
                {/* Labels - fade out on match */}
                <Sphere position={A} args={[0.15]}><meshBasicMaterial color={moveColor}/></Sphere>
                <Sphere position={B} args={[0.15]}><meshBasicMaterial color={moveColor}/></Sphere>
                <Sphere position={C} args={[0.15]}><meshBasicMaterial color={moveColor}/></Sphere>
                {(!isMatched) && (
                    <group>
                        <Text position={[A.x - 0.5, A.y + 0.5, 0]} fontSize={0.5} color={moveColor} fontWeight="bold">A</Text>
                        <Text position={[B.x - 0.5, B.y - 0.5, 0]} fontSize={0.5} color={moveColor} fontWeight="bold">B</Text>
                        <Text position={[C.x + 0.5, C.y - 0.5, 0]} fontSize={0.5} color={moveColor} fontWeight="bold">C</Text>
                    </group>
                )}

                {/* Measurements */}
                <Beam p1={A} p2={C} text="10 cm" color="#f59e0b" />
                <Caliper p1={A} p2={B} text="6 cm" color="#0ea5e9" flip={true} />
                <RightAngleIcon p={B} p1={A} p2={C} color="#22c55e" />
            </group>

            {/* Success Badge */}
            {isMatched && (
                <group position={[4.4, 4.5, 0]}>
                    <mesh position={[0,0,-0.1]}><planeGeometry args={[9, 1.2]} /><meshBasicMaterial color="#0f172a" /></mesh>
                    <Line points={[[-4.5, 0.6, 0], [4.5, 0.6, 0], [4.5, -0.6, 0], [-4.5, -0.6, 0], [-4.5, 0.6, 0]]} color="#22c55e" lineWidth={2} />
                    <Text position={[0, 0, 0]} fontSize={0.6} color="#22c55e" fontWeight="extrabold">
                        RHS Right-Triangle Congruence (ABC ≅ DEF)
                    </Text>
                </group>
            )}
        </group>
    );
}

// ---------------------------------------------------------
// MAIN UI COMPONENT
// ---------------------------------------------------------

export default function RHSCongruenceSim() {
    const [isRight, setIsRight] = useState(true);
    const [alignment, setAlignment] = useState(0);

    useEffect(() => {
        setAlignment(0);
    }, [isRight]);

    const isMatched = isRight && alignment === 100;

    const getAlertConfig = () => {
        if (isRight) {
            if (isMatched) return {
                title: "RHS Congruence Verified",
                desc: "SUCCESS: Both triangles share a 90° angle, equal hypotenuses (10 cm), and equal legs (6 cm). Because the 90° angle locks the geometry rigidly, the Pythagorean theorem forces the third side to match (8 cm). Triangle ABC perfectly covers DEF.",
                colorClass: "bg-green-900/40 border-l-green-500 border-green-800 text-green-100",
                icon: <CheckCircle size={18} className="text-green-400" />
            };
            return {
                title: "Right-Angle, Hypotenuse, Side (RHS)",
                desc: "Notice the green 90° locks at vertices B and E. The hypotenuse measuring beams (10 cm) and vertical leg calipers (6 cm) show exactly two sides are known. For right triangles, this is enough to prove congruence. Drag to superimpose.",
                colorClass: "bg-sky-900/40 border-l-sky-500 border-sky-800 text-sky-100",
                icon: <Search size={18} className="text-sky-400" />
            };
        } else {
            return {
                title: "The Right-Angle Requirement",
                desc: "CRITICAL MISCONCEPTION: Triangle DEF still has a 10 cm side and a 6 cm side, but its angle is 110°, not 90°. RHS ONLY applies to right-angled triangles! Attempting to superimpose shows they do not match. A 90° angle is strictly required.",
                colorClass: "bg-rose-900/40 border-l-rose-500 border-rose-800 text-rose-100",
                icon: <ShieldAlert size={18} className="text-rose-400" />
            };
        }
    };

    const alertConfig = getAlertConfig();

    const renderMath = () => {
        if (isRight) return <BlockMath math={`\\text{Right Angle} + \\text{Hyp (10)} + \\text{Leg (6)} \\implies \\text{Congruent (RHS)}`} />;
        return <BlockMath math={`\\text{Angle (110}^\\circ\\text{)} + \\text{Side (10)} + \\text{Side (6)} \\implies \\text{Not Congruent to ABC}`} />;
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex flex-col gap-4 max-w-7xl mx-auto">
                    <div className="flex justify-between items-start md:items-center w-full">
                        <div>
                            <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                                RHS Triangle Congruence
                            </h1>
                            <p className="text-slate-400 text-sm mt-1">
                                Why the Pythagorean theorem guarantees congruence for right triangles.
                            </p>
                        </div>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl w-full flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-colors duration-500 ${isMatched ? 'border-green-500/50' : 'border-slate-700'}`}>
                        <div className={`text-sm md:text-lg px-4 flex items-center justify-center h-full min-h-[3.5rem] w-full ${isMatched ? 'text-green-400' : 'text-slate-300'}`}>
                            {renderMath()}
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas camera={{ position: [0, 0, 16] }}>
                        <OrbitControls enablePan={false} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <RHSScene alignment={alignment} isRight={isRight} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                {/* Dynamic Alert Panel */}
                <div className="flex-1 pointer-events-auto max-w-md w-full">
                    <div className={`border-l-4 border-t border-r border-b p-5 rounded-2xl shadow-2xl backdrop-blur-md transition-all duration-500 ${alertConfig.colorClass}`}>
                        <h3 className="font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide">
                            {alertConfig.icon} 
                            {alertConfig.title}
                        </h3>
                        <p className="text-sm leading-relaxed drop-shadow-md">
                            {alertConfig.desc}
                        </p>
                    </div>
                </div>

                {/* Control Panel */}
                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
                    
                    <div className="flex bg-slate-950 p-1 rounded-lg mb-6 border border-slate-800">
                        <button 
                            onClick={() => setIsRight(true)} 
                            className={`flex-1 py-2 text-xs font-bold rounded-md transition-colors ${isRight ? 'bg-sky-600/80 text-white shadow-sm' : 'text-slate-400 hover:bg-slate-800'}`}
                        >
                            Right-Angled (90°)
                        </button>
                        <button 
                            onClick={() => setIsRight(false)} 
                            className={`flex-1 py-2 text-xs font-bold rounded-md transition-colors ${!isRight ? 'bg-rose-600/80 text-white shadow-sm' : 'text-slate-400 hover:bg-slate-800'}`}
                        >
                            Obtuse Triangle (110°)
                        </button>
                    </div>

                    <div className="mb-6 border-b border-slate-800 pb-5">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <Move size={14} /> Alignment Progress
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className={isMatched ? "text-green-400" : "text-sky-400"}>
                                {alignment}% Complete
                            </span>
                        </div>
                        <input 
                            type="range" 
                            min="0" max="100" step="1" 
                            value={alignment} onChange={(e) => setAlignment(parseFloat(e.target.value))} 
                            className={`w-full h-2 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 ${isMatched ? 'accent-green-500' : 'accent-sky-500'}`}
                        />
                    </div>

                    <button 
                        onClick={() => setAlignment(isMatched ? 0 : 100)} 
                        className={`p-4 rounded-xl flex items-center justify-center gap-2 transition-all border text-sm font-bold shadow-sm ${
                            isMatched ? 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700' : 'bg-slate-800 border-slate-600 text-white hover:bg-slate-700'
                        }`}
                    >
                        <Layers size={18} /> {isMatched ? 'Separate Triangles' : 'Snap to Coincidence'}
                    </button>
                    
                </div>
            </div>
        </div>
    );
}
