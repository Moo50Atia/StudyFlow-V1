import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Line, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Layers, CheckCircle, Move } from 'lucide-react';

// ---------------------------------------------------------
// 3D SCENE COMPONENT
// ---------------------------------------------------------

function CongruenceScene({ alignment, isMatched, showMovingLabels }: { alignment: number, isMatched: boolean, showMovingLabels: boolean }) {
    const proxy = useRef({ t: alignment / 100 }).current;
    
    const baseLineRef = useRef<THREE.Group>(null);
    const moveLineRef = useRef<THREE.Group>(null);
    const baseAngleRef = useRef<THREE.Group>(null);
    const moveAngleRef = useRef<THREE.Group>(null);
    const basePolyRef = useRef<THREE.Group>(null);
    const movePolyRef = useRef<THREE.Group>(null);

    const baseColor = isMatched ? "#22c55e" : "#3b82f6";
    const moveColor = isMatched ? "#22c55e" : "#a855f7";

    useFrame(() => {
        // Smooth interpolation for dragging
        proxy.t = THREE.MathUtils.lerp(proxy.t, alignment / 100, 0.15);
        const t = proxy.t;
        const currentX = 5 * (1 - t);

        if (baseLineRef.current) baseLineRef.current.position.set(-currentX, 6, 0);
        if (moveLineRef.current) moveLineRef.current.position.set(currentX, 6, 0);

        if (baseAngleRef.current) baseAngleRef.current.position.set(-currentX, 1, 0);
        if (moveAngleRef.current) {
            moveAngleRef.current.position.set(currentX, 1, 0);
            moveAngleRef.current.rotation.z = (Math.PI / 4) * (1 - t);
        }

        if (basePolyRef.current) basePolyRef.current.position.set(-currentX, -4.5, 0);
        if (movePolyRef.current) {
            movePolyRef.current.position.set(currentX, -4.5, 0);
            movePolyRef.current.rotation.z = (-Math.PI / 3) * (1 - t);
        }
    });

    // Angle Arc Geometry
    const angleRad = 50 * Math.PI / 180;
    const arcPts = useMemo(() => {
        const pts = [];
        for (let i = 0; i <= 16; i++) {
            const theta = (i / 16) * angleRad;
            pts.push(new THREE.Vector3(0.8 * Math.cos(theta), 0.8 * Math.sin(theta), 0));
        }
        return pts;
    }, [angleRad]);

    // Polygon Geometry
    const polyPts = useMemo(() => {
        return [
            new THREE.Vector3(0, 2, 0),
            new THREE.Vector3(2.5, 0.5, 0),
            new THREE.Vector3(1.5, -2, 0),
            new THREE.Vector3(-1.5, -1.5, 0),
            new THREE.Vector3(-2, 1, 0)
        ];
    }, []);

    const polyGeom = useMemo(() => {
        const shape = new THREE.Shape();
        shape.moveTo(polyPts[0].x, polyPts[0].y);
        for (let i = 1; i < polyPts.length; i++) {
            shape.lineTo(polyPts[i].x, polyPts[i].y);
        }
        shape.lineTo(polyPts[0].x, polyPts[0].y);
        return new THREE.ShapeGeometry(shape);
    }, [polyPts]);

    useEffect(() => {
        return () => polyGeom.dispose();
    }, [polyGeom]);

    return (
        <group position={[0, 0, 0]}>
            <ambientLight intensity={0.8} />
            <directionalLight position={[0, 10, 10]} intensity={1.5} />
            
            {/* Dark Grid Background */}
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[80, 80]} />
                <meshBasicMaterial color="#020617" />
            </mesh>
            <gridHelper args={[80, 80, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* 1. LINE SEGMENTS */}
            <group ref={baseLineRef}>
                <Line points={[[-2, 0, 0], [2, 0, 0]]} color={baseColor} lineWidth={4} />
                <Sphere position={[-2, 0, 0]} args={[0.15]}><meshBasicMaterial color={baseColor}/></Sphere>
                <Sphere position={[2, 0, 0]} args={[0.15]}><meshBasicMaterial color={baseColor}/></Sphere>
                <Text position={[-2, 0.5, 0]} fontSize={0.5} color={baseColor} fontWeight="bold">A</Text>
                <Text position={[2, 0.5, 0]} fontSize={0.5} color={baseColor} fontWeight="bold">B</Text>
                <Text position={[0, -0.5, 0]} fontSize={0.4} color={baseColor}>6 cm</Text>
            </group>

            <group ref={moveLineRef}>
                <Line points={[[-2, 0, 0], [2, 0, 0]]} color={moveColor} lineWidth={4} />
                <Sphere position={[-2, 0, 0]} args={[0.15]}><meshBasicMaterial color={moveColor}/></Sphere>
                <Sphere position={[2, 0, 0]} args={[0.15]}><meshBasicMaterial color={moveColor}/></Sphere>
                {showMovingLabels && (
                    <group>
                        <Text position={[-2, 0.5, 0]} fontSize={0.5} color={moveColor} fontWeight="bold">C</Text>
                        <Text position={[2, 0.5, 0]} fontSize={0.5} color={moveColor} fontWeight="bold">D</Text>
                        <Text position={[0, -0.5, 0]} fontSize={0.4} color={moveColor}>6 cm</Text>
                    </group>
                )}
            </group>

            {/* 2. ANGLES */}
            <group ref={baseAngleRef}>
                <Line points={[[3, 0, 0], [0, 0, 0], [3 * Math.cos(angleRad), 3 * Math.sin(angleRad), 0]]} color={baseColor} lineWidth={4} />
                <Line points={arcPts} color={baseColor} lineWidth={3} />
                <Sphere position={[0, 0, 0]} args={[0.15]}><meshBasicMaterial color={baseColor}/></Sphere>
                <Text position={[-0.5, -0.5, 0]} fontSize={0.5} color={baseColor} fontWeight="bold">B</Text>
                <Text position={[3, -0.5, 0]} fontSize={0.5} color={baseColor} fontWeight="bold">C</Text>
                <Text position={[3 * Math.cos(angleRad) - 0.2, 3 * Math.sin(angleRad) + 0.5, 0]} fontSize={0.5} color={baseColor} fontWeight="bold">A</Text>
                <Text position={[1.4 * Math.cos(angleRad/2), 1.4 * Math.sin(angleRad/2), 0]} fontSize={0.4} color={baseColor}>50°</Text>
            </group>

            <group ref={moveAngleRef}>
                <Line points={[[3, 0, 0], [0, 0, 0], [3 * Math.cos(angleRad), 3 * Math.sin(angleRad), 0]]} color={moveColor} lineWidth={4} />
                <Line points={arcPts} color={moveColor} lineWidth={3} />
                <Sphere position={[0, 0, 0]} args={[0.15]}><meshBasicMaterial color={moveColor}/></Sphere>
                {showMovingLabels && (
                    <group>
                        <Text position={[-0.5, -0.5, 0]} fontSize={0.5} color={moveColor} fontWeight="bold">E</Text>
                        <Text position={[3, -0.5, 0]} fontSize={0.5} color={moveColor} fontWeight="bold">F</Text>
                        <Text position={[3 * Math.cos(angleRad) - 0.2, 3 * Math.sin(angleRad) + 0.5, 0]} fontSize={0.5} color={moveColor} fontWeight="bold">D</Text>
                        <Text position={[1.4 * Math.cos(angleRad/2), 1.4 * Math.sin(angleRad/2), 0]} fontSize={0.4} color={moveColor}>50°</Text>
                    </group>
                )}
            </group>

            {/* 3. POLYGONS */}
            <group ref={basePolyRef}>
                <mesh geometry={polyGeom}>
                    <meshBasicMaterial color={baseColor} transparent opacity={isMatched ? 0.4 : 0.15} />
                </mesh>
                <Line points={[...polyPts, polyPts[0]]} color={baseColor} lineWidth={4} />
                {polyPts.map((p, i) => (
                    <Sphere key={i} position={[p.x, p.y, 0]} args={[0.15]}>
                        <meshBasicMaterial color={baseColor} />
                    </Sphere>
                ))}
                <Text position={[polyPts[0].x, polyPts[0].y + 0.5, 0]} fontSize={0.5} color={baseColor} fontWeight="bold">A</Text>
                <Text position={[polyPts[1].x + 0.5, polyPts[1].y, 0]} fontSize={0.5} color={baseColor} fontWeight="bold">B</Text>
                <Text position={[polyPts[2].x + 0.5, polyPts[2].y - 0.5, 0]} fontSize={0.5} color={baseColor} fontWeight="bold">C</Text>
                <Text position={[polyPts[3].x - 0.5, polyPts[3].y - 0.5, 0]} fontSize={0.5} color={baseColor} fontWeight="bold">D</Text>
                <Text position={[polyPts[4].x - 0.5, polyPts[4].y + 0.5, 0]} fontSize={0.5} color={baseColor} fontWeight="bold">E</Text>
            </group>

            <group ref={movePolyRef}>
                <mesh geometry={polyGeom}>
                    <meshBasicMaterial color={moveColor} transparent opacity={isMatched ? 0.4 : 0.15} />
                </mesh>
                <Line points={[...polyPts, polyPts[0]]} color={moveColor} lineWidth={4} />
                {polyPts.map((p, i) => (
                    <Sphere key={i} position={[p.x, p.y, 0]} args={[0.15]}>
                        <meshBasicMaterial color={moveColor} />
                    </Sphere>
                ))}
                {showMovingLabels && (
                    <group>
                        <Text position={[polyPts[0].x, polyPts[0].y + 0.5, 0]} fontSize={0.5} color={moveColor} fontWeight="bold">X</Text>
                        <Text position={[polyPts[1].x + 0.5, polyPts[1].y, 0]} fontSize={0.5} color={moveColor} fontWeight="bold">Y</Text>
                        <Text position={[polyPts[2].x + 0.5, polyPts[2].y - 0.5, 0]} fontSize={0.5} color={moveColor} fontWeight="bold">Z</Text>
                        <Text position={[polyPts[3].x - 0.5, polyPts[3].y - 0.5, 0]} fontSize={0.5} color={moveColor} fontWeight="bold">W</Text>
                        <Text position={[polyPts[4].x - 0.5, polyPts[4].y + 0.5, 0]} fontSize={0.5} color={moveColor} fontWeight="bold">L</Text>
                    </group>
                )}
            </group>

            {/* OUTCOME BADGES */}
            {isMatched && (
                <group>
                    <Text position={[0, 7.5, 0]} fontSize={0.8} color="#22c55e" fontWeight="bold">AB ≅ CD</Text>
                    <Text position={[1.5, 3.8, 0]} fontSize={0.8} color="#22c55e" fontWeight="bold">∠ABC ≅ ∠DEF</Text>
                    <Text position={[0, 0, 0]} fontSize={0.9} color="#22c55e" fontWeight="bold">ABCDE ≅ XYZWL</Text>
                </group>
            )}
        </group>
    );
}

// ---------------------------------------------------------
// MAIN UI COMPONENT
// ---------------------------------------------------------
export default function CongruenceSimulation() {
    const [alignment, setAlignment] = useState(0);
    const isMatched = alignment === 100;
    const showMovingLabels = alignment < 95;

    const renderMath = () => {
        if (isMatched) {
            return (
                <BlockMath math={`\\text{Complete Coincidence} \\implies \\text{Strict Congruence (} \\cong \\text{)}`} />
            );
        }
        return (
            <BlockMath math={`\\text{Translate and Rotate to Test for Coincidence}`} />
        );
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex flex-col gap-4 max-w-7xl mx-auto">
                    <div className="flex justify-between items-start md:items-center w-full">
                        <div>
                            <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                                Congruence & Coincidence
                            </h1>
                            <p className="text-slate-400 text-sm mt-1">
                                Proving equality through perfect spatial alignment.
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
                    <Canvas camera={{ position: [0, 1, 19] }}>
                        <OrbitControls enablePan={true} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <CongruenceScene alignment={alignment} isMatched={isMatched} showMovingLabels={showMovingLabels} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                {/* Dynamic Alert Panel */}
                <div className="flex-1 pointer-events-auto max-w-md w-full">
                    <div className={`border-l-4 border-t border-r border-b p-5 rounded-2xl shadow-2xl backdrop-blur-md transition-all duration-500 ${
                        isMatched ? 'bg-green-900/40 border-l-green-500 border-green-800 text-green-100' : 'bg-slate-900/90 border-l-amber-500 border-amber-800 text-amber-100'
                    }`}>
                        <h3 className={`font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide ${isMatched ? 'text-green-400' : 'text-amber-400'}`}>
                            {isMatched ? <CheckCircle size={18}/> : <ShieldAlert size={18}/>} 
                            {isMatched ? "Exact Congruence Proven" : "The 'Looks Similar' Fallacy"}
                        </h3>
                        <p className="text-sm leading-relaxed drop-shadow-md">
                            {isMatched ? (
                                <><b>SUCCESS:</b> The shapes have achieved <b>Complete Coincidence</b>. Because they overlay perfectly without any gaps or overhangs, we have mathematically proven that they are <b>Congruent (≅)</b>. Every corresponding side and angle is identical.</>
                            ) : (
                                <><b>CRITICAL MISCONCEPTION:</b> Students often think shapes are congruent just because they "look similar" or share the same angles. True congruence requires <b>Complete Coincidence</b>. Drag the slider to overlay the segments, angles, and polygons to prove they match exactly!</>
                            )}
                        </p>
                    </div>
                </div>

                {/* Control Panel */}
                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
                    
                    <div className="mb-6 border-b border-slate-800 pb-5">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <Move size={14} /> Alignment Progress
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className={isMatched ? "text-green-400" : "text-purple-400"}>{alignment}% Overlap</span>
                        </div>
                        <input 
                            type="range" 
                            min="0" max="100" step="1" 
                            value={alignment} onChange={(e) => setAlignment(parseFloat(e.target.value))} 
                            className={`w-full h-2 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 ${isMatched ? 'accent-green-500' : 'accent-purple-500'}`}
                        />
                    </div>

                    <button 
                        onClick={() => setAlignment(isMatched ? 0 : 100)} 
                        className={`p-4 rounded-xl flex items-center justify-center gap-2 transition-all border text-sm font-bold shadow-sm ${
                            isMatched ? 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700' : 'bg-purple-600/20 border-purple-500/50 text-purple-400 hover:bg-purple-600/30'
                        }`}
                    >
                        <Layers size={18} /> {isMatched ? 'Separate Shapes' : 'Snap to Coincidence'}
                    </button>
                    
                </div>
            </div>
        </div>
    );
}
