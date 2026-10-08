import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { OrbitControls, Environment, Text, Line, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, CheckCircle, SlidersHorizontal, Settings2 } from 'lucide-react';

// ---------------------------------------------------------
// CALIPER COMPONENT
// ---------------------------------------------------------

function Caliper({ p1, p2, text, color }: { p1: THREE.Vector3, p2: THREE.Vector3, text: string, color: string }) {
    const dir = p2.clone().sub(p1).normalize();
    const perp = new THREE.Vector3(dir.y, -dir.x, 0); // Outward normal for CCW polygon
    const offset = 0.6; // Distance from edge
    
    const c1 = p1.clone().add(perp.clone().multiplyScalar(offset));
    const c2 = p2.clone().add(perp.clone().multiplyScalar(offset));
    
    // Tiny ticks
    const t1_1 = p1.clone().add(perp.clone().multiplyScalar(offset - 0.2));
    const t1_2 = p1.clone().add(perp.clone().multiplyScalar(offset + 0.2));
    
    const t2_1 = p2.clone().add(perp.clone().multiplyScalar(offset - 0.2));
    const t2_2 = p2.clone().add(perp.clone().multiplyScalar(offset + 0.2));
    
    const mid = c1.clone().add(c2).multiplyScalar(0.5);
    const txtPos = mid.clone().add(perp.clone().multiplyScalar(0.4));
    
    return (
        <group>
            <Line points={[t1_1, t1_2]} color={color} lineWidth={2} />
            <Line points={[t2_1, t2_2]} color={color} lineWidth={2} />
            <Line points={[c1, c2]} color={color} lineWidth={2} />
            <Text position={txtPos} fontSize={0.35} color={color} fontWeight="bold">
                {text}
            </Text>
        </group>
    );
}

// ---------------------------------------------------------
// 3D SCENE COMPONENT
// ---------------------------------------------------------

function PolygonEquilateralScene({ n, isRegular }: { n: number, isRegular: boolean }) {
    const groupRef = useRef<THREE.Group>(null);
    const L = 3.2; // Constant visual side length
    
    // GSAP animated perturbation parameter 'c'
    const cRef = useRef({ val: isRegular ? 0 : 0.4 });
    const [, setRenderTrigger] = useState(0);

    useEffect(() => {
        const targetC = isRegular ? 0 : 0.35;
        gsap.to(cRef.current, {
            val: targetC,
            duration: 0.8,
            ease: "power2.out",
            onUpdate: () => setRenderTrigger(v => v + 1)
        });
    }, [isRegular]);

    // Animate scale on N change
    useEffect(() => {
        if (groupRef.current) {
            gsap.fromTo(groupRef.current.scale, 
                { x: 0.8, y: 0.8, z: 0.8 }, 
                { x: 1, y: 1, z: 1, duration: 0.6, ease: "back.out(1.5)" }
            );
        }
    }, [n]);

    const cVal = cRef.current.val;
    const isEquiangular = Math.abs(cVal) < 0.01;

    // 1. Generate Vertices (Ensuring strict equilateral properties)
    const pts = useMemo(() => {
        let currentPoint = new THREE.Vector3(0, 0, 0);
        const newPts = [currentPoint.clone()];
        for (let i = 0; i < n; i++) {
            const baseAngle = i * (2 * Math.PI / n);
            // Alternating perturbation guarantees closure for EVEN n
            const angle = baseAngle + cVal * (i % 2 === 0 ? 1 : -1);
            currentPoint.x += L * Math.cos(angle);
            currentPoint.y += L * Math.sin(angle);
            if (i < n - 1) newPts.push(currentPoint.clone());
        }
        // Center the polygon
        const centroid = new THREE.Vector3();
        newPts.forEach(p => centroid.add(p));
        centroid.divideScalar(n);
        newPts.forEach(p => p.sub(centroid));
        return newPts;
    }, [n, cVal, L]);

    // 2. Generate Interior Angle Arcs and Labels
    const anglesData = useMemo(() => {
        if (pts.length < 3) return [];
        const data = [];
        for(let i = 0; i < n; i++) {
            const pCurr = pts[i];
            const pNext = pts[(i+1)%n];
            const pPrev = pts[(i+n-1)%n];
            
            const v1 = pNext.clone().sub(pCurr);
            const v2 = pPrev.clone().sub(pCurr);
            
            let a1 = Math.atan2(v1.y, v1.x);
            let a2 = Math.atan2(v2.y, v2.x);
            
            let angle = a2 - a1;
            if (angle < 0) angle += 2 * Math.PI;
            
            const radius = 0.8;
            const arcPts = [];
            for(let j = 0; j <= 32; j++) {
                const theta = a1 + (j/32)*angle;
                arcPts.push(new THREE.Vector3(pCurr.x + Math.cos(theta)*radius, pCurr.y + Math.sin(theta)*radius, 0.05));
            }
            
            const deg = Math.round(angle * 180 / Math.PI);
            const midTheta = a1 + angle / 2;
            const txtPos = new THREE.Vector3(pCurr.x + Math.cos(midTheta)*1.5, pCurr.y + Math.sin(midTheta)*1.5, 0.05);
            
            data.push({ arcPts, deg, txtPos, pCurr });
        }
        return data;
    }, [pts, n]);

    // Shape fill
    const shapeGeom = useMemo(() => {
        const shape = new THREE.Shape();
        if (pts.length > 0) {
            shape.moveTo(pts[0].x, pts[0].y);
            for(let i = 1; i < pts.length; i++) shape.lineTo(pts[i].x, pts[i].y);
            shape.lineTo(pts[0].x, pts[0].y);
        }
        return new THREE.ShapeGeometry(shape);
    }, [pts]);

    useEffect(() => {
        return () => shapeGeom.dispose();
    }, [shapeGeom]);

    const borderPts = [...pts, pts[0]].map(v => [v.x, v.y, 0] as [number, number, number]);
    const themeColor = isEquiangular ? "#22c55e" : "#ef4444";

    return (
        <group ref={groupRef} position={[0, -0.5, 0]}>
            <ambientLight intensity={0.8} />
            <directionalLight position={[0, 10, 10]} intensity={1.5} />
            
            {/* Dark Grid Background */}
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[80, 80]} />
                <meshBasicMaterial color="#020617" />
            </mesh>
            <gridHelper args={[80, 80, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* Polygon Fill */}
            <mesh geometry={shapeGeom} position={[0,0,0]}>
                <meshBasicMaterial color={themeColor} transparent opacity={0.15} side={THREE.DoubleSide} />
            </mesh>

            {/* Boundary Outline */}
            <Line points={borderPts} color={themeColor} lineWidth={4} />

            {/* Edges Calipers (Always Equal) */}
            {pts.map((p, i) => (
                <Caliper key={`caliper-${i}`} p1={p} p2={pts[(i+1)%n]} text="5 cm" color="#facc15" />
            ))}

            {/* Angles */}
            {anglesData.map((d, i) => (
                <group key={`angle-${i}`}>
                    <Line points={d.arcPts} color={themeColor} lineWidth={3} />
                    <Text position={d.txtPos} fontSize={0.45} color={themeColor} fontWeight="bold">
                        {`${d.deg}°`}
                    </Text>
                </group>
            ))}

            {/* Vertices */}
            {pts.map((p, i) => (
                <Sphere key={`v-${i}`} args={[0.15]} position={[p.x, p.y, 0.1]}>
                    <meshBasicMaterial color="#ffffff" />
                </Sphere>
            ))}
        </group>
    );
}

// ---------------------------------------------------------
// MAIN UI COMPONENT
// ---------------------------------------------------------

export default function RegularizePolygon() {
    const [sides, setSides] = useState(6);
    const [isRegular, setIsRegular] = useState(false);

    const renderMath = () => {
        if (isRegular) {
            const sum = (sides - 2) * 180;
            const theta = sum / sides;
            return <BlockMath math={`\\theta = \\frac{(${sides} - 2) \\times 180^\\circ}{${sides}} = ${theta}^\\circ`} />;
        }
        return <BlockMath math={`\\text{Equilateral } (\\text{Sides Equal}) \\neq \\text{Equiangular} (\\text{Angles Equal})`} />;
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col lg:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            Regular Polygon Properties
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Does having equal side lengths guarantee equal angles?
                        </p>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl min-w-[380px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-all duration-500 ${isRegular ? 'border-emerald-500/50' : 'border-rose-500/50'}`}>
                        <div className={`text-lg px-4 flex items-center justify-center h-full min-h-[3.5rem] ${isRegular ? 'text-emerald-400' : 'text-rose-400'}`}>
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
                        <PolygonEquilateralScene n={sides} isRegular={isRegular} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                {/* Dynamic Alert Panel */}
                <div className="flex-1 pointer-events-auto max-w-md w-full">
                    <div className={`border-l-4 border-t border-r border-b p-5 rounded-2xl shadow-2xl backdrop-blur-md transition-all duration-500 ${
                        isRegular ? 'bg-emerald-900/40 border-l-emerald-500 border-emerald-800 text-emerald-200' : 'bg-rose-900/40 border-l-rose-500 border-rose-800 text-rose-200'
                    }`}>
                        <h3 className="font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide">
                            {isRegular ? <CheckCircle size={18}/> : <ShieldAlert size={18}/>} 
                            {isRegular ? "True Regular Polygon" : "The Equilateral Myth"}
                        </h3>
                        <p className="text-sm leading-relaxed drop-shadow-md">
                            {isRegular ? (
                                <><b>SUCCESS:</b> You applied the Regularize Filter. Now, not only are all sides exactly 5 cm (<b>Equilateral</b>), but all interior angles have locked to identical values (<b>Equiangular</b>). Only when both conditions are met is a polygon truly "Regular".</>
                            ) : (
                                <><b>CRITICAL MISCONCEPTION:</b> Students often assume any polygon with equal sides is "Regular". Look closely! Every side is marked with a 5 cm caliper, proving it is <b>Equilateral</b>. However, the interior angles are alternating and unequal. It is NOT equiangular, and therefore NOT regular.</>
                            )}
                        </p>
                    </div>
                </div>

                {/* Control Panel */}
                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[400px]">
                    
                    <div className="mb-6 border-b border-slate-800 pb-5">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <SlidersHorizontal size={14} /> Side Count (Even Polygons)
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className="text-blue-400">n = {sides} Sides</span>
                        </div>
                        <input 
                            type="range" 
                            min="4" max="12" step="2" 
                            value={sides} onChange={(e) => setSides(parseInt(e.target.value))} 
                            className="w-full h-1.5 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 accent-blue-500"
                        />
                        <div className="flex justify-between text-[10px] text-slate-500 font-bold mt-2 px-1">
                            <span>Rhombus (4)</span>
                            <span>Dodecagon (12)</span>
                        </div>
                    </div>

                    <button 
                        onClick={() => setIsRegular(!isRegular)} 
                        className={`p-4 rounded-xl flex items-center justify-between transition-all border text-sm font-bold shadow-sm ${
                            isRegular ? 'bg-emerald-600/20 border-emerald-500/50 text-emerald-400' : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700'
                        }`}
                    >
                        <span className="flex items-center gap-2"><Settings2 size={18} /> Regularize Filter</span>
                        <span className="text-[10px] uppercase bg-slate-950/50 px-2 py-1 rounded">
                            {isRegular ? 'Active' : 'Off'}
                        </span>
                    </button>
                    
                </div>
            </div>
        </div>
    );
}
