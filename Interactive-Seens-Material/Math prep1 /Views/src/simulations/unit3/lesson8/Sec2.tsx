import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Text, Line, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Layers, CheckCircle, Move } from 'lucide-react';

// ---------------------------------------------------------
// GEOMETRY & CONFIGURATION
// ---------------------------------------------------------

const CONFIG = {
    sas: {
        baseLabels: ['D', 'E', 'F'],
        moveLabels: ['A', 'B', 'C'],
        basePts: [[-0.127, 1.41, 0], [-3, -1, 0], [3, -1, 0]],
        movePts: [[-0.127, 1.41, 0], [-3, -1, 0], [3, -1, 0]],
        arcData: { vertexIdx: 1, startAngle: 0, endAngle: 40 * Math.PI / 180, radius: 1.0, text: "40°" },
        sidesData: [
            { idx1: 0, idx2: 1, text: "5 cm" },
            { idx1: 1, idx2: 2, text: "8 cm" }
        ]
    },
    ssa: {
        baseLabels: ['D', 'E', 'F'],
        moveLabels: ['A', 'B', 'C'],
        basePts: [[-2.5, -1.5, 0], [2.696, 1.5, 0], [4.946, -1.5, 0]],
        movePts: [[-2.5, -1.5, 0], [2.696, 1.5, 0], [0.446, -1.5, 0]],
        arcData: { vertexIdx: 0, startAngle: 0, endAngle: 30 * Math.PI / 180, radius: 1.2, text: "30°" },
        sidesData: [
            { idx1: 0, idx2: 1, text: "8 cm" },
            { idx1: 1, idx2: 2, text: "5 cm" }
        ]
    }
};

// ---------------------------------------------------------
// SUB-COMPONENTS
// ---------------------------------------------------------

function SideMarker({ p1, p2, text, color, allPts, hide }) {
    if (hide) return null;
    const mid = [(p1[0]+p2[0])/2, (p1[1]+p2[1])/2, 0];
    const dx = p2[0]-p1[0];
    const dy = p2[1]-p1[1];
    
    const cx = (allPts[0][0] + allPts[1][0] + allPts[2][0]) / 3;
    const cy = (allPts[0][1] + allPts[1][1] + allPts[2][1]) / 3;
    
    let nx = -dy; let ny = dx;
    const dot = nx * (mid[0] - cx) + ny * (mid[1] - cy);
    if (dot < 0) { nx = -nx; ny = -ny; }
    
    const len = Math.hypot(nx, ny);
    const offset = 0.6;
    const px = mid[0] + (nx/len)*offset;
    const py = mid[1] + (ny/len)*offset;
    
    return <Text position={[px, py, 0]} fontSize={0.4} color={color} fontWeight="bold">{text}</Text>;
}

function Label({ p, centroid, text, color, hide }) {
    if (hide) return null;
    const cx = (centroid[0][0] + centroid[1][0] + centroid[2][0]) / 3;
    const cy = (centroid[0][1] + centroid[1][1] + centroid[2][1]) / 3;
    const dx = p[0] - cx;
    const dy = p[1] - cy;
    const len = Math.hypot(dx, dy);
    const offset = 0.5;
    const px = p[0] + (dx/len)*offset;
    const py = p[1] + (dy/len)*offset;
    return <Text position={[px, py, 0]} fontSize={0.5} color={color} fontWeight="extrabold">{text}</Text>;
}

function TriangleGroup({ pts, labels, color, opacity, arcData, sidesData, hideLabels }) {
    const geom = useMemo(() => {
        const shape = new THREE.Shape();
        shape.moveTo(pts[0][0], pts[0][1]);
        shape.lineTo(pts[1][0], pts[1][1]);
        shape.lineTo(pts[2][0], pts[2][1]);
        shape.lineTo(pts[0][0], pts[0][1]);
        return new THREE.ShapeGeometry(shape);
    }, [pts]);
    
    useEffect(() => {
        return () => geom.dispose();
    }, [geom]);

    const arcPts = useMemo(() => {
        const arr = [];
        for (let i = 0; i <= 16; i++) {
            const t = arcData.startAngle + (arcData.endAngle - arcData.startAngle) * (i / 16);
            arr.push(new THREE.Vector3(arcData.radius * Math.cos(t), arcData.radius * Math.sin(t), 0));
        }
        return arr;
    }, [arcData]);

    const angleVertex = pts[arcData.vertexIdx];
    const angleMid = arcData.startAngle + (arcData.endAngle - arcData.startAngle) / 2;
    const angleTextPos = [
        arcData.radius * 1.5 * Math.cos(angleMid),
        arcData.radius * 1.5 * Math.sin(angleMid),
        0
    ];

    const borderPts = [...pts, pts[0]].map(p => new THREE.Vector3(p[0], p[1], 0));

    return (
        <group>
            <mesh geometry={geom} position={[0,0,-0.01]}>
                <meshBasicMaterial color={color} transparent opacity={opacity} />
            </mesh>
            <Line points={borderPts} color={color} lineWidth={4} />
            
            {pts.map((p, i) => (
                <group key={i}>
                    <Sphere position={p as [number,number,number]} args={[0.15]}>
                        <meshBasicMaterial color={color} />
                    </Sphere>
                    <Label p={p} centroid={pts} text={labels[i]} color={color} hide={hideLabels} />
                </group>
            ))}

            {/* Arc */}
            {!hideLabels && (
                <group position={angleVertex as [number,number,number]}>
                    <Line points={arcPts} color={color} lineWidth={3} />
                    <Text position={angleTextPos} fontSize={0.35} color={color} fontWeight="bold">{arcData.text}</Text>
                </group>
            )}

            {/* Sides */}
            {sidesData.map((sd, i) => (
                <SideMarker key={i} p1={pts[sd.idx1]} p2={pts[sd.idx2]} text={sd.text} color={color} allPts={pts} hide={hideLabels} />
            ))}
        </group>
    );
}

// ---------------------------------------------------------
// 3D SCENE COMPONENT
// ---------------------------------------------------------

function CongruenceScene({ alignment, mode }: { alignment: number, mode: 'sas' | 'ssa' }) {
    const moveGroup = useRef<THREE.Group>(null);
    const proxy = useRef({ t: alignment / 100 }).current;
    
    useFrame(() => {
        proxy.t = THREE.MathUtils.lerp(proxy.t, alignment / 100, 0.1);
        const t = proxy.t;
        if (moveGroup.current) {
            moveGroup.current.position.x = -3.5 + t * 7.0;
            moveGroup.current.position.y = Math.sin(t * Math.PI) * 1.5;
            moveGroup.current.position.z = 0.1; // Ensure it stays on top
        }
    });

    const data = CONFIG[mode];
    const isSasMatched = mode === 'sas' && alignment === 100;
    
    const baseColor = isSasMatched ? "#22c55e" : "#3b82f6";
    const moveColor = isSasMatched ? "#22c55e" : "#a855f7";

    // In SAS, we fade out the moving details when perfectly matched to avoid clutter.
    // In SSA, we keep them so the divergence is perfectly visible.
    const hideMovingLabels = mode === 'sas' && alignment > 90;

    return (
        <group position={[0, -0.5, 0]}>
            <ambientLight intensity={0.8} />
            
            {/* Dark Grid Background */}
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[80, 80]} />
                <meshBasicMaterial color="#020617" />
            </mesh>
            <gridHelper args={[80, 80, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* Base Triangle (Right) */}
            <group position={[3.5, 0, 0]}>
                <TriangleGroup 
                    pts={data.basePts} 
                    labels={data.baseLabels} 
                    color={baseColor} 
                    opacity={isSasMatched ? 0.4 : 0.15} 
                    arcData={data.arcData} 
                    sidesData={data.sidesData} 
                    hideLabels={false}
                />
            </group>

            {/* Moving Triangle (Left) */}
            <group ref={moveGroup} position={[-3.5, 0, 0]}>
                <TriangleGroup 
                    pts={data.movePts} 
                    labels={data.moveLabels} 
                    color={moveColor} 
                    opacity={isSasMatched ? 0.4 : 0.15} 
                    arcData={data.arcData} 
                    sidesData={data.sidesData}
                    hideLabels={hideMovingLabels}
                />
            </group>

            {/* Success Badge */}
            {isSasMatched && (
                <group position={[3.5, 3.5, 0]}>
                    <mesh position={[0,0,-0.1]}>
                        <planeGeometry args={[6.5, 1.2]} />
                        <meshBasicMaterial color="#0f172a" />
                    </mesh>
                    <Line points={[[-3.25, 0.6, 0], [3.25, 0.6, 0], [3.25, -0.6, 0], [-3.25, -0.6, 0], [-3.25, 0.6, 0]]} color="#22c55e" lineWidth={2} />
                    <Text position={[0, 0, 0]} fontSize={0.6} color="#22c55e" fontWeight="extrabold">
                        SAS Congruence (ABC ≅ DEF)
                    </Text>
                </group>
            )}
        </group>
    );
}

// ---------------------------------------------------------
// MAIN UI COMPONENT
// ---------------------------------------------------------

export default function TriangleCongruenceSim() {
    const [mode, setMode] = useState<'sas'|'ssa'>('sas');
    const [alignment, setAlignment] = useState(0);

    // Reset alignment when changing modes
    useEffect(() => {
        setAlignment(0);
    }, [mode]);

    const isMatched = alignment === 100;

    let alertConfig = { title: "", desc: "", colorClass: "", icon: <ShieldAlert size={18} /> };

    if (mode === 'sas') {
        if (isMatched) {
            alertConfig = {
                title: "SAS Congruence Verified",
                desc: "SUCCESS: Because the 40° angle is strictly INCLUDED (locked between) the 5 cm and 8 cm sides, the third side has exactly one possible path to close the shape. Triangle ABC perfectly covers DEF.",
                colorClass: "bg-green-900/40 border-l-green-500 border-green-800 text-green-100",
                icon: <CheckCircle size={18} className="text-green-400" />
            };
        } else {
            alertConfig = {
                title: "The Included Angle Guarantee",
                desc: "These triangles share two equal sides (5 cm, 8 cm) and an included angle (40°) strictly between them. This is the SAS postulate. Drag the slider to prove that this guarantees complete congruence.",
                colorClass: "bg-blue-900/40 border-l-blue-500 border-blue-800 text-blue-100",
                icon: <ShieldAlert size={18} className="text-blue-400" />
            };
        }
    } else {
        if (isMatched) {
            alertConfig = {
                title: "The SSA Ambiguity Fallacy",
                desc: "CRITICAL MISCONCEPTION: Both triangles have sides of 8 cm and 5 cm, and a 30° angle. But the angle is NOT included! Notice how side BC swings inwards (obtuse), while EF swings outwards (acute). They are NOT congruent!",
                colorClass: "bg-rose-900/40 border-l-rose-500 border-rose-800 text-rose-100",
                icon: <ShieldAlert size={18} className="text-rose-400" />
            };
        } else {
            alertConfig = {
                title: "The Non-Included Angle Trap",
                desc: "We switched to SSA mode. The triangles share side AB=DE (8 cm), side BC=EF (5 cm), and angle A=D (30°). But the angle is outside the two known sides. Does this guarantee congruence? Superimpose them to find out.",
                colorClass: "bg-purple-900/40 border-l-purple-500 border-purple-800 text-purple-100",
                icon: <ShieldAlert size={18} className="text-purple-400" />
            };
        }
    }

    const renderMath = () => {
        if (mode === 'sas') {
            return <BlockMath math={`\\text{Side (5)} \\to \\text{Angle (40}^\\circ\\text{)} \\to \\text{Side (8)} \\implies \\text{Congruent}`} />;
        }
        return <BlockMath math={`\\text{Side (8)} \\to \\text{Side (5)} \\to \\text{Angle (30}^\\circ\\text{)} \\implies \\text{Ambiguous (Fail)}`} />;
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex flex-col gap-4 max-w-7xl mx-auto">
                    <div className="flex justify-between items-start md:items-center w-full">
                        <div>
                            <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                                Triangle Congruence Tests
                            </h1>
                            <p className="text-slate-400 text-sm mt-1">
                                Why does SAS work, but SSA fail?
                            </p>
                        </div>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl w-full flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-colors duration-500 ${mode === 'sas' && isMatched ? 'border-green-500/50' : 'border-slate-700'}`}>
                        <div className={`text-sm md:text-lg px-4 flex items-center justify-center h-full min-h-[3.5rem] w-full ${mode === 'sas' && isMatched ? 'text-green-400' : 'text-slate-300'}`}>
                            {renderMath()}
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas camera={{ position: [0, 1, 16] }}>
                        <OrbitControls enablePan={false} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <CongruenceScene alignment={alignment} mode={mode} />
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
                            onClick={() => setMode('sas')} 
                            className={`flex-1 py-2 text-xs font-bold rounded-md transition-colors ${mode === 'sas' ? 'bg-blue-600/80 text-white shadow-sm' : 'text-slate-400 hover:bg-slate-800'}`}
                        >
                            SAS (Included Angle)
                        </button>
                        <button 
                            onClick={() => setMode('ssa')} 
                            className={`flex-1 py-2 text-xs font-bold rounded-md transition-colors ${mode === 'ssa' ? 'bg-purple-600/80 text-white shadow-sm' : 'text-slate-400 hover:bg-slate-800'}`}
                        >
                            SSA (Non-Included)
                        </button>
                    </div>

                    <div className="mb-6 border-b border-slate-800 pb-5">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <Move size={14} /> Alignment Progress
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className={isMatched ? "text-green-400" : "text-sky-400"}>{alignment}% Overlap</span>
                        </div>
                        <input 
                            type="range" 
                            min="0" max="100" step="1" 
                            value={alignment} onChange={(e) => setAlignment(parseFloat(e.target.value))} 
                            className={`w-full h-2 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 ${isMatched ? 'accent-green-500' : (mode === 'sas' ? 'accent-blue-500' : 'accent-purple-500')}`}
                        />
                    </div>

                    <button 
                        onClick={() => setAlignment(isMatched ? 0 : 100)} 
                        className={`p-4 rounded-xl flex items-center justify-center gap-2 transition-all border text-sm font-bold shadow-sm ${
                            isMatched ? 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700' : 'bg-slate-800 border-slate-600 text-white hover:bg-slate-700'
                        }`}
                    >
                        <Layers size={18} /> {isMatched ? 'Separate Triangles' : 'Superimpose Triangles'}
                    </button>
                    
                </div>
            </div>
        </div>
    );
}
