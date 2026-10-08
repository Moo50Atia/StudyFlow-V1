import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Text, Line, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Layers, CheckCircle, Move, Ruler } from 'lucide-react';

// ---------------------------------------------------------
// GEOMETRY & MATH HELPERS
// ---------------------------------------------------------

function getCentroid(pts: THREE.Vector3[]) {
    const c = new THREE.Vector3();
    pts.forEach(p => c.add(p));
    return c.divideScalar(pts.length);
}

// ---------------------------------------------------------
// SUB-COMPONENTS
// ---------------------------------------------------------

function Caliper({ p1, p2, text, color, allPts, hide = false }: { p1: THREE.Vector3, p2: THREE.Vector3, text: string, color: string, allPts: THREE.Vector3[], hide?: boolean }) {
    if (hide) return null;
    const mid = new THREE.Vector3().addVectors(p1, p2).multiplyScalar(0.5);
    const dx = p2.x - p1.x;
    const dy = p2.y - p1.y;
    
    const centroid = getCentroid(allPts);
    
    let nx = -dy; 
    let ny = dx;
    const dot = nx * (mid.x - centroid.x) + ny * (mid.y - centroid.y);
    if (dot < 0) { nx = -nx; ny = -ny; }
    
    const len = Math.hypot(nx, ny);
    const offset = 0.6;
    const px = mid.x + (nx/len)*offset;
    const py = mid.y + (ny/len)*offset;

    // Ticks
    const tickLen = 0.3;
    const t1a = new THREE.Vector3(p1.x + (nx/len)*(offset - tickLen/2), p1.y + (ny/len)*(offset - tickLen/2), 0);
    const t1b = new THREE.Vector3(p1.x + (nx/len)*(offset + tickLen/2), p1.y + (ny/len)*(offset + tickLen/2), 0);
    const t2a = new THREE.Vector3(p2.x + (nx/len)*(offset - tickLen/2), p2.y + (ny/len)*(offset - tickLen/2), 0);
    const t2b = new THREE.Vector3(p2.x + (nx/len)*(offset + tickLen/2), p2.y + (ny/len)*(offset + tickLen/2), 0);

    const c1 = new THREE.Vector3(p1.x + (nx/len)*offset, p1.y + (ny/len)*offset, 0);
    const c2 = new THREE.Vector3(p2.x + (nx/len)*offset, p2.y + (ny/len)*offset, 0);
    
    return (
        <group>
            <Line points={[t1a, t1b]} color={color} lineWidth={2} />
            <Line points={[t2a, t2b]} color={color} lineWidth={2} />
            <Line points={[c1, c2]} color={color} lineWidth={2} />
            <Text position={[px + (nx/len)*0.4, py + (ny/len)*0.4, 0]} fontSize={0.4} color={color} fontWeight="bold">
                {text}
            </Text>
        </group>
    );
}

function AngleArc({ center, v1, v2, radius, text, color, showLines = true }: { center: THREE.Vector3, v1: THREE.Vector3, v2: THREE.Vector3, radius: number, text: string, color: string, showLines?: boolean }) {
    const startAngle = Math.atan2(v1.y - center.y, v1.x - center.x);
    let endAngle = Math.atan2(v2.y - center.y, v2.x - center.x);
    
    // Ensure we draw the smaller interior angle
    let diff = endAngle - startAngle;
    if (diff < 0) diff += 2 * Math.PI;
    if (diff > Math.PI) {
        const temp = endAngle;
        endAngle = startAngle;
        // startAngle = temp; (not needed because we regenerate arc points carefully below)
    }

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
            {showLines && <Line points={[center, v1]} color={color} lineWidth={3} />}
            {showLines && <Line points={[center, v2]} color={color} lineWidth={3} />}
            <Line points={arcPts} color={color} lineWidth={3} />
            <Text position={textPos} fontSize={0.35} color={color} fontWeight="bold">{text}</Text>
        </group>
    );
}

// ---------------------------------------------------------
// ASA SCENE
// ---------------------------------------------------------
function ASAScene({ progress }: { progress: number }) {
    const proxy = useRef({ t: progress / 100 }).current;
    const [renderTick, setRenderTick] = useState(0);

    useFrame(() => {
        proxy.t = THREE.MathUtils.lerp(proxy.t, progress / 100, 0.1);
        if (Math.abs(proxy.t - progress / 100) > 0.001) {
            setRenderTick(v => v + 1);
        }
    });

    const t = proxy.t;
    const isMatched = t > 0.98;
    const themeColor = isMatched ? "#22c55e" : "#0ea5e9";

    // Geometry Definitions
    // Base 7 cm scaled to 4.2 units
    // Tri 1: Center at x=-4. Tri 2: Center at x=4
    const t1B = new THREE.Vector3(-6.1, -1.5, 0);
    const t1C = new THREE.Vector3(-1.9, -1.5, 0);
    const t1A = new THREE.Vector3(-3.612, 1.465, 0);

    const t2E = new THREE.Vector3(1.9, -1.5, 0);
    const t2F = new THREE.Vector3(6.1, -1.5, 0);
    const t2D = new THREE.Vector3(4.388, 1.465, 0);

    // Current Ray endpoints based on t
    const t1A_current1 = new THREE.Vector3().lerpVectors(t1B, t1A, t);
    const t1A_current2 = new THREE.Vector3().lerpVectors(t1C, t1A, t);

    const t2D_current1 = new THREE.Vector3().lerpVectors(t2E, t2D, t);
    const t2D_current2 = new THREE.Vector3().lerpVectors(t2F, t2D, t);

    // Triangle fill geometries
    const t1Geom = useMemo(() => {
        const shape = new THREE.Shape();
        shape.moveTo(t1B.x, t1B.y); shape.lineTo(t1C.x, t1C.y); shape.lineTo(t1A.x, t1A.y); shape.lineTo(t1B.x, t1B.y);
        return new THREE.ShapeGeometry(shape);
    }, [t1B, t1C, t1A]);

    const t2Geom = useMemo(() => {
        const shape = new THREE.Shape();
        shape.moveTo(t2E.x, t2E.y); shape.lineTo(t2F.x, t2F.y); shape.lineTo(t2D.x, t2D.y); shape.lineTo(t2E.x, t2E.y);
        return new THREE.ShapeGeometry(shape);
    }, [t2E, t2F, t2D]);

    useEffect(() => {
        return () => { t1Geom.dispose(); t2Geom.dispose(); };
    }, [t1Geom, t2Geom]);

    return (
        <group position={[0, -0.5, 0]}>
            {/* Base Lines */}
            <Line points={[t1B, t1C]} color={themeColor} lineWidth={4} />
            <Line points={[t2E, t2F]} color={themeColor} lineWidth={4} />
            
            {/* Calipers for the included side */}
            <Caliper p1={t1B} p2={t1C} text="7 cm" color="#facc15" allPts={[t1B, t1C, t1A]} />
            <Caliper p1={t2E} p2={t2F} text="7 cm" color="#facc15" allPts={[t2E, t2F, t2D]} />

            {/* Vertices */}
            <Sphere position={t1B} args={[0.15]}><meshBasicMaterial color={themeColor}/></Sphere>
            <Sphere position={t1C} args={[0.15]}><meshBasicMaterial color={themeColor}/></Sphere>
            <Text position={[t1B.x - 0.5, t1B.y - 0.5, 0]} fontSize={0.5} color={themeColor} fontWeight="bold">B</Text>
            <Text position={[t1C.x + 0.5, t1C.y - 0.5, 0]} fontSize={0.5} color={themeColor} fontWeight="bold">C</Text>

            <Sphere position={t2E} args={[0.15]}><meshBasicMaterial color={themeColor}/></Sphere>
            <Sphere position={t2F} args={[0.15]}><meshBasicMaterial color={themeColor}/></Sphere>
            <Text position={[t2E.x - 0.5, t2E.y - 0.5, 0]} fontSize={0.5} color={themeColor} fontWeight="bold">E</Text>
            <Text position={[t2F.x + 0.5, t2F.y - 0.5, 0]} fontSize={0.5} color={themeColor} fontWeight="bold">F</Text>

            {/* Rays */}
            <Line points={[t1B, t1A_current1]} color={themeColor} lineWidth={3} dashed dashSize={0.2} gapSize={0.2} />
            <Line points={[t1C, t1A_current2]} color={themeColor} lineWidth={3} dashed dashSize={0.2} gapSize={0.2} />
            <Line points={[t2E, t2D_current1]} color={themeColor} lineWidth={3} dashed dashSize={0.2} gapSize={0.2} />
            <Line points={[t2F, t2D_current2]} color={themeColor} lineWidth={3} dashed dashSize={0.2} gapSize={0.2} />

            {/* Angles */}
            {/* Using a helper point on the line to orient the arc correctly */}
            <AngleArc center={t1B} v1={t1C} v2={new THREE.Vector3().lerpVectors(t1B, t1A, 0.5)} radius={0.8} text="50°" color="#facc15" showLines={false} />
            <AngleArc center={t1C} v1={new THREE.Vector3().lerpVectors(t1C, t1A, 0.5)} v2={t1B} radius={0.8} text="60°" color="#facc15" showLines={false} />
            
            <AngleArc center={t2E} v1={t2F} v2={new THREE.Vector3().lerpVectors(t2E, t2D, 0.5)} radius={0.8} text="50°" color="#facc15" showLines={false} />
            <AngleArc center={t2F} v1={new THREE.Vector3().lerpVectors(t2F, t2D, 0.5)} v2={t2E} radius={0.8} text="60°" color="#facc15" showLines={false} />

            {/* Filled Triangles (when matched) */}
            {isMatched && (
                <group>
                    <mesh geometry={t1Geom}><meshBasicMaterial color={themeColor} transparent opacity={0.3} /></mesh>
                    <mesh geometry={t2Geom}><meshBasicMaterial color={themeColor} transparent opacity={0.3} /></mesh>
                    <Sphere position={t1A} args={[0.15]}><meshBasicMaterial color={themeColor}/></Sphere>
                    <Text position={[t1A.x, t1A.y + 0.6, 0]} fontSize={0.5} color={themeColor} fontWeight="bold">A</Text>
                    <Sphere position={t2D} args={[0.15]}><meshBasicMaterial color={themeColor}/></Sphere>
                    <Text position={[t2D.x, t2D.y + 0.6, 0]} fontSize={0.5} color={themeColor} fontWeight="bold">D</Text>

                    <group position={[0, 4.5, 0]}>
                        <mesh position={[0,0,-0.1]}><planeGeometry args={[7, 1.2]} /><meshBasicMaterial color="#0f172a" /></mesh>
                        <Line points={[[-3.5, 0.6, 0], [3.5, 0.6, 0], [3.5, -0.6, 0], [-3.5, -0.6, 0], [-3.5, 0.6, 0]]} color="#22c55e" lineWidth={2} />
                        <Text position={[0, 0, 0]} fontSize={0.6} color="#22c55e" fontWeight="extrabold">
                            ASA Congruence (ABC ≅ DEF)
                        </Text>
                    </group>
                </group>
            )}
        </group>
    );
}

// ---------------------------------------------------------
// SSS SCENE
// ---------------------------------------------------------
function SSSScene({ progress }: { progress: number }) {
    const moveGroup = useRef<THREE.Group>(null);
    const proxy = useRef({ t: progress / 100 }).current;
    const [renderTick, setRenderTick] = useState(0);
    
    useFrame(() => {
        proxy.t = THREE.MathUtils.lerp(proxy.t, progress / 100, 0.1);
        if (moveGroup.current) {
            // Slide from x = -7 to x = 0
            moveGroup.current.position.x = -7 * (1 - proxy.t);
            moveGroup.current.position.y = Math.sin(proxy.t * Math.PI) * 1.5; // Slight arc
        }
        if (Math.abs(proxy.t - progress / 100) > 0.001) {
            setRenderTick(v => v + 1);
        }
    });

    const isMatched = proxy.t > 0.98;
    const baseColor = isMatched ? "#22c55e" : "#3b82f6";
    const moveColor = isMatched ? "#22c55e" : "#a855f7";

    // Geometry: 6-8-10 scaled to 3.6 - 4.8 - 6.0
    const D = new THREE.Vector3(1.4, -1.5, 0);
    const E = new THREE.Vector3(6.2, -1.5, 0);
    const F = new THREE.Vector3(1.4, 2.1, 0);
    const pts = [D, E, F];

    const geom = useMemo(() => {
        const shape = new THREE.Shape();
        shape.moveTo(D.x, D.y); shape.lineTo(E.x, E.y); shape.lineTo(F.x, F.y); shape.lineTo(D.x, D.y);
        return new THREE.ShapeGeometry(shape);
    }, [D, E, F]);

    useEffect(() => { return () => geom.dispose(); }, [geom]);

    return (
        <group position={[0, -0.5, 0]}>
            {/* Static Base Triangle (DEF) */}
            <group>
                <mesh geometry={geom}><meshBasicMaterial color={baseColor} transparent opacity={isMatched ? 0.4 : 0.15} /></mesh>
                <Line points={[...pts, pts[0]]} color={baseColor} lineWidth={4} />
                <Sphere position={D} args={[0.15]}><meshBasicMaterial color={baseColor}/></Sphere>
                <Sphere position={E} args={[0.15]}><meshBasicMaterial color={baseColor}/></Sphere>
                <Sphere position={F} args={[0.15]}><meshBasicMaterial color={baseColor}/></Sphere>
                <Text position={[D.x - 0.5, D.y - 0.5, 0]} fontSize={0.5} color={baseColor} fontWeight="bold">D</Text>
                <Text position={[E.x + 0.5, E.y - 0.5, 0]} fontSize={0.5} color={baseColor} fontWeight="bold">E</Text>
                <Text position={[F.x - 0.5, F.y + 0.5, 0]} fontSize={0.5} color={baseColor} fontWeight="bold">F</Text>
            </group>

            {/* Moving Triangle (ABC) */}
            <group ref={moveGroup}>
                <mesh geometry={geom}><meshBasicMaterial color={moveColor} transparent opacity={isMatched ? 0.4 : 0.15} /></mesh>
                <Line points={[...pts, pts[0]]} color={moveColor} lineWidth={4} />
                <Sphere position={D} args={[0.15]}><meshBasicMaterial color={moveColor}/></Sphere>
                <Sphere position={E} args={[0.15]}><meshBasicMaterial color={moveColor}/></Sphere>
                <Sphere position={F} args={[0.15]}><meshBasicMaterial color={moveColor}/></Sphere>
                {!isMatched && (
                    <group>
                        <Text position={[D.x - 0.5, D.y - 0.5, 0]} fontSize={0.5} color={moveColor} fontWeight="bold">A</Text>
                        <Text position={[E.x + 0.5, E.y - 0.5, 0]} fontSize={0.5} color={moveColor} fontWeight="bold">B</Text>
                        <Text position={[F.x - 0.5, F.y + 0.5, 0]} fontSize={0.5} color={moveColor} fontWeight="bold">C</Text>
                    </group>
                )}
                {/* Calipers fixed on the moving triangle */}
                <Caliper p1={D} p2={E} text="8 cm" color="#facc15" allPts={pts} />
                <Caliper p1={E} p2={F} text="10 cm" color="#facc15" allPts={pts} />
                <Caliper p1={F} p2={D} text="6 cm" color="#facc15" allPts={pts} />
            </group>

            {/* Success Badge */}
            {isMatched && (
                <group position={[3.8, 4.5, 0]}>
                    <mesh position={[0,0,-0.1]}><planeGeometry args={[7, 1.2]} /><meshBasicMaterial color="#0f172a" /></mesh>
                    <Line points={[[-3.5, 0.6, 0], [3.5, 0.6, 0], [3.5, -0.6, 0], [-3.5, -0.6, 0], [-3.5, 0.6, 0]]} color="#22c55e" lineWidth={2} />
                    <Text position={[0, 0, 0]} fontSize={0.6} color="#22c55e" fontWeight="extrabold">
                        SSS Congruence (ABC ≅ DEF)
                    </Text>
                </group>
            )}
        </group>
    );
}

// ---------------------------------------------------------
// MAIN UI COMPONENT
// ---------------------------------------------------------
export default function TriangleCongruenceSim2() {
    const [mode, setMode] = useState<'asa'|'sss'>('asa');
    const [progress, setProgress] = useState(0);

    useEffect(() => { setProgress(0); }, [mode]);

    const isMatched = progress === 100;

    const getAlertConfig = () => {
        if (mode === 'asa') {
            if (isMatched) return {
                title: "ASA Proof Complete",
                desc: "SUCCESS: The fixed 7 cm base locked the starting points. The fixed 50° and 60° angles constrained the direction of the rays. The rays MUST intersect at exactly one point, guaranteeing congruent triangles.",
                colorClass: "bg-green-900/40 border-l-green-500 border-green-800 text-green-100",
                icon: <CheckCircle size={18} className="text-green-400" />
            };
            return {
                title: "Angle-Side-Angle (ASA)",
                desc: "Drag the slider to extend the rays. Notice how fixing the 'included side' (7 cm) between two specific angles (50°, 60°) leaves no ambiguity. There is only one possible triangle that can be formed.",
                colorClass: "bg-sky-900/40 border-l-sky-500 border-sky-800 text-sky-100",
                icon: <Ruler size={18} className="text-sky-400" />
            };
        } else {
            if (isMatched) return {
                title: "SSS Proof Complete",
                desc: "SUCCESS: The three side lengths (6cm, 8cm, 10cm) lock rigidly. Unlike a polygon with 4+ sides, a triangle cannot flex or skew once its side lengths are set. The shapes overlap perfectly.",
                colorClass: "bg-green-900/40 border-l-green-500 border-green-800 text-green-100",
                icon: <CheckCircle size={18} className="text-green-400" />
            };
            return {
                title: "Side-Side-Side (SSS) vs The AAA Fallacy",
                desc: "CRITICAL MISCONCEPTION: Students often think 3 equal angles (AAA) proves congruence. But AAA only proves similarity (scale). SSS fixes all side lengths, creating a completely rigid, un-scalable shape. Slide to superimpose.",
                colorClass: "bg-purple-900/40 border-l-purple-500 border-purple-800 text-purple-100",
                icon: <ShieldAlert size={18} className="text-purple-400" />
            };
        }
    };

    const alertConfig = getAlertConfig();

    const renderMath = () => {
        if (mode === 'asa') return <BlockMath math={`\\text{Angle (50}^\\circ\\text{)} \\to \\text{Side (7)} \\to \\text{Angle (60}^\\circ\\text{)} \\implies \\text{Congruent } (\\cong)`} />;
        return <BlockMath math={`\\text{Side (6)} \\to \\text{Side (8)} \\to \\text{Side (10)} \\implies \\text{Congruent } (\\cong)`} />;
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex flex-col gap-4 max-w-7xl mx-auto">
                    <div className="flex justify-between items-start md:items-center w-full">
                        <div>
                            <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                                ASA and SSS Congruence
                            </h1>
                            <p className="text-slate-400 text-sm mt-1">
                                Rigid geometric constraints that guarantee identical shapes.
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
                    <Canvas camera={{ position: [0, 0, 15] }}>
                        <OrbitControls enablePan={false} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <ambientLight intensity={0.8} />
                        <mesh position={[0, 0, -2]}><planeGeometry args={[80, 80]} /><meshBasicMaterial color="#020617" /></mesh>
                        <gridHelper args={[80, 80, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />
                        
                        {mode === 'asa' ? <ASAScene progress={progress} /> : <SSSScene progress={progress} />}
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
                            onClick={() => setMode('asa')} 
                            className={`flex-1 py-2 text-xs font-bold rounded-md transition-colors ${mode === 'asa' ? 'bg-sky-600/80 text-white shadow-sm' : 'text-slate-400 hover:bg-slate-800'}`}
                        >
                            ASA Theorem
                        </button>
                        <button 
                            onClick={() => setMode('sss')} 
                            className={`flex-1 py-2 text-xs font-bold rounded-md transition-colors ${mode === 'sss' ? 'bg-purple-600/80 text-white shadow-sm' : 'text-slate-400 hover:bg-slate-800'}`}
                        >
                            SSS Theorem
                        </button>
                    </div>

                    <div className="mb-6 border-b border-slate-800 pb-5">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <Move size={14} /> {mode === 'asa' ? 'Extend Rays' : 'Alignment Progress'}
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className={isMatched ? "text-green-400" : (mode === 'asa' ? 'text-sky-400' : 'text-purple-400')}>
                                {progress}% Complete
                            </span>
                        </div>
                        <input 
                            type="range" 
                            min="0" max="100" step="1" 
                            value={progress} onChange={(e) => setProgress(parseFloat(e.target.value))} 
                            className={`w-full h-2 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 ${isMatched ? 'accent-green-500' : (mode === 'asa' ? 'accent-sky-500' : 'accent-purple-500')}`}
                        />
                    </div>

                    <button 
                        onClick={() => setProgress(isMatched ? 0 : 100)} 
                        className={`p-4 rounded-xl flex items-center justify-center gap-2 transition-all border text-sm font-bold shadow-sm ${
                            isMatched ? 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700' : 'bg-slate-800 border-slate-600 text-white hover:bg-slate-700'
                        }`}
                    >
                        <Layers size={18} /> {isMatched ? 'Reset Animation' : (mode === 'asa' ? 'Fast-Forward Rays' : 'Snap to Coincidence')}
                    </button>
                    
                </div>
            </div>
        </div>
    );
}
