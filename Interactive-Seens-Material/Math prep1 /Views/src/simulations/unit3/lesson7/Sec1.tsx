import React, { useState, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere, Line } from '@react-three/drei';
import * as THREE from 'three';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, CheckCircle, ScanLine, Move } from 'lucide-react';

// ---------------------------------------------------------
// 3D SCENE COMPONENT
// ---------------------------------------------------------

function PolygonScene({ targetRE, showTest }: { targetRE: number, showTest: boolean }) {
    const [rE, setRE] = useState(targetRE);
    
    // Smooth interpolation for the slider movement
    useFrame(() => {
        if (Math.abs(rE - targetRE) > 0.01) {
            setRE(prev => THREE.MathUtils.lerp(prev, targetRE, 0.15));
        } else if (rE !== targetRE) {
            setRE(targetRE);
        }
    });

    // 1. Calculate the 6 vertices based on rE
    const pts = useMemo(() => {
        return [
            new THREE.Vector3(4, 0, 0),
            new THREE.Vector3(2, 3.464, 0),
            new THREE.Vector3(-2, 3.464, 0),
            new THREE.Vector3(-4, 0, 0),
            // Vertex E translates radially inward based on rE
            new THREE.Vector3(rE * Math.cos(4*Math.PI/3), rE * Math.sin(4*Math.PI/3), 0),
            new THREE.Vector3(2, -3.464, 0)
        ];
    }, [rE]);
    
    // 2. Generate the transparent solid fill geometry
    const shapeGeom = useMemo(() => {
        const shape = new THREE.Shape();
        shape.moveTo(pts[0].x, pts[0].y);
        for(let i=1; i<pts.length; i++) shape.lineTo(pts[i].x, pts[i].y);
        shape.lineTo(pts[0].x, pts[0].y);
        return new THREE.ShapeGeometry(shape);
    }, [pts]);

    // Cleanup geometry to prevent memory leaks during rapid state changes
    useEffect(() => {
        return () => shapeGeom.dispose();
    }, [shapeGeom]);

    // 3. Calculate interior angles, drawing arcs and labels dynamically
    const anglesData = useMemo(() => {
        const data = [];
        for(let i=0; i<6; i++) {
            const pCurr = pts[i];
            const pNext = pts[(i+1)%6];
            const pPrev = pts[(i+5)%6]; // (i - 1 + 6) % 6
            
            const v1 = pNext.clone().sub(pCurr);
            const v2 = pPrev.clone().sub(pCurr);
            let a1 = Math.atan2(v1.y, v1.x);
            let a2 = Math.atan2(v2.y, v2.x);
            
            // Interior angle measured counter-clockwise
            let angle = a2 - a1;
            if (angle < 0) angle += 2 * Math.PI;
            
            // Generate Arc points
            const radius = 0.8;
            const arcPts = [];
            for(let j=0; j<=32; j++) {
                const theta = a1 + (j/32)*angle;
                arcPts.push(new THREE.Vector3(pCurr.x + Math.cos(theta)*radius, pCurr.y + Math.sin(theta)*radius, 0.05));
            }
            
            const deg = angle * 180 / Math.PI;
            const isReflex = deg > 180.1; // Mathematical threshold for reflex (concave)
            
            // Position for the text label
            const midTheta = a1 + angle / 2;
            const txtPos = new THREE.Vector3(pCurr.x + Math.cos(midTheta)*1.4, pCurr.y + Math.sin(midTheta)*1.4, 0.05);
            
            data.push({ arcPts, deg, isReflex, txtPos, pCurr });
        }
        return data;
    }, [pts]);

    // Determine current global state for coloring
    const isCurrentlyConcave = anglesData.some(d => d.isReflex);
    
    const labels = ["A", "B", "C", "D", "E", "F"];

    return (
        <group>
            <ambientLight intensity={0.8} />
            
            {/* Dark Grid Background */}
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[60, 60]} />
                <meshBasicMaterial color="#020617" />
            </mesh>
            <gridHelper args={[60, 60, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* Polygon Fill */}
            <mesh geometry={shapeGeom} position={[0,0,0]}>
                <meshBasicMaterial color={isCurrentlyConcave ? "#881337" : "#064e3b"} transparent opacity={0.6} side={THREE.DoubleSide} />
            </mesh>

            {/* Polygon Boundary Outline */}
            <Line points={[...pts, pts[0]]} color={isCurrentlyConcave ? "#f43f5e" : "#10b981"} lineWidth={4} />

            {/* Angle Arcs & Measurements */}
            {anglesData.map((d, i) => (
                <group key={`angle-${i}`}>
                    <Line points={d.arcPts} color={d.isReflex ? "#ef4444" : "#22c55e"} lineWidth={3} />
                    <Text position={d.txtPos} fontSize={0.4} color={d.isReflex ? "#ef4444" : "#22c55e"} fontWeight="bold">
                        {`${Math.round(d.deg)}°`}
                    </Text>
                </group>
            ))}

            {/* Vertex Spheres & Labels */}
            {pts.map((p, i) => (
                <group key={`vertex-${i}`} position={p}>
                    <Sphere args={[0.15]}><meshBasicMaterial color="#ffffff" /></Sphere>
                    <Text position={[0, (i===1||i===2) ? 0.6 : -0.6, 0]} fontSize={0.45} color="#ffffff" fontWeight="bold">
                        {labels[i]}
                    </Text>
                </group>
            ))}

            {/* Active Handle Indicator for Vertex E */}
            <Sphere position={pts[4]} args={[0.3]}><meshBasicMaterial color="#3b82f6" transparent opacity={0.6} /></Sphere>

            {/* Interior Segment Test Line */}
            {showTest && (
                <group>
                    <Sphere position={[-2.5, 0.2, 0.1]} args={[0.12]}><meshBasicMaterial color="#eab308" /></Sphere>
                    <Text position={[-2.5, 0.6, 0.1]} fontSize={0.4} color="#eab308" fontWeight="bold">P1</Text>
                    
                    <Sphere position={[1.0, -1.0, 0.1]} args={[0.12]}><meshBasicMaterial color="#eab308" /></Sphere>
                    <Text position={[1.0, -1.4, 0.1]} fontSize={0.4} color="#eab308" fontWeight="bold">P2</Text>
                    
                    <Line points={[[-2.5, 0.2, 0.05], [1.0, -1.0, 0.05]]} color="#eab308" lineWidth={3} />
                </group>
            )}
        </group>
    );
}


// ---------------------------------------------------------
// MAIN UI COMPONENT
// ---------------------------------------------------------

export default function ConvexConcaveSimulation() {
    // State
    const [targetRE, setTargetRE] = useState(4.0);
    const [showTest, setShowTest] = useState(false);

    // Threshold logic: mathematically derived threshold where angle E hits exactly 180 deg
    const isConcave = targetRE < 2.0;

    // Helper to calculate just E's angle for the UI text readout
    const getAngleE = (r: number) => {
        const E = {x: -0.5*r, y: -0.866*r};
        const D = {x: -4, y: 0};
        const F = {x: 2, y: -3.464};
        const v1 = {x: F.x - E.x, y: F.y - E.y};
        const v2 = {x: D.x - E.x, y: D.y - E.y};
        let a1 = Math.atan2(v1.y, v1.x);
        let a2 = Math.atan2(v2.y, v2.x);
        let angle = a2 - a1;
        if (angle < 0) angle += 2 * Math.PI;
        return angle * 180 / Math.PI;
    }
    const angleE = Math.round(getAngleE(targetRE));

    // Dynamic KaTeX
    const renderMath = () => {
        if (isConcave) {
            return <BlockMath math={`\\exists \\angle > 180^\\circ \\implies \\text{Concave}`} />;
        }
        return <BlockMath math={`\\forall \\angle < 180^\\circ \\implies \\text{Convex}`} />;
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col lg:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            Convex vs Concave Polygons
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Does having "straight sides" guarantee a shape is convex?
                        </p>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl min-w-[280px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-all duration-500 ${isConcave ? 'border-rose-500/50' : 'border-emerald-500/50'}`}>
                        <div className={`text-lg px-4 flex items-center justify-center h-full min-h-[3rem] transition-colors ${isConcave ? 'text-rose-400' : 'text-emerald-400'}`}>
                            {renderMath()}
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas camera={{ position: [0, 0, 14] }}>
                        <OrbitControls enablePan={false} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <PolygonScene targetRE={targetRE} showTest={showTest} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                {/* Dynamic Alert Panel */}
                <div className={`flex-1 pointer-events-auto max-w-md w-full transition-all duration-500`}>
                    <div className={`border-l-4 border-t border-r border-b p-5 rounded-2xl shadow-2xl backdrop-blur-md transition-all duration-500 ${
                        isConcave ? 'bg-rose-900/50 border-l-rose-500 border-rose-800 text-rose-200' : 'bg-emerald-900/50 border-l-emerald-500 border-emerald-800 text-emerald-200'
                    }`}>
                        <h3 className="font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide">
                            {isConcave ? <ShieldAlert size={18}/> : <CheckCircle size={18}/>} 
                            {isConcave ? "Concave Polygon Detected" : "Convex Polygon Confirmed"}
                        </h3>
                        <p className="text-sm leading-relaxed drop-shadow-md">
                            {isConcave ? (
                                <><b>CRITICAL MISCONCEPTION:</b> A polygon with straight sides is NOT automatically convex. By dragging vertex E inward, you've created a <b>reflex angle</b> ({angleE}° &gt; 180°). If you run the Segment Test, you'll see a line connecting two interior points passing <b>outside</b> the boundary!</>
                            ) : (
                                <><b>DEFINITION:</b> All interior angles are strictly less than 180°. The boundary bulges outward uniformly. Any line segment drawn between two points inside this shape is guaranteed to remain completely inside the boundary.</>
                            )}
                        </p>
                    </div>
                </div>

                {/* Control Panel */}
                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
                    
                    <div className="mb-6">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <Move size={14} /> Vertex E Radial Position
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className={isConcave ? "text-rose-400" : "text-emerald-400"}>Radius = {targetRE.toFixed(1)}</span>
                            <span className="text-slate-400">{isConcave ? 'Reflex State' : 'Normal State'}</span>
                        </div>
                        <input 
                            type="range" 
                            min="0.5" max="4.0" step="0.1" 
                            value={targetRE} onChange={(e) => setTargetRE(parseFloat(e.target.value))} 
                            className={`w-full h-1.5 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 ${isConcave ? 'accent-rose-500' : 'accent-emerald-500'}`}
                        />
                    </div>

                    <button 
                        onClick={() => setShowTest(!showTest)} 
                        className={`p-3 rounded-xl flex items-center justify-center gap-2 transition-all border text-sm font-bold shadow-sm ${
                            showTest ? 'bg-yellow-600/20 border-yellow-500/50 text-yellow-400' : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700'
                        }`}
                    >
                        <ScanLine size={18} /> {showTest ? 'Hide Segment Test' : 'Run Interior Segment Test'}
                    </button>
                    
                </div>
            </div>
        </div>
    );
}
