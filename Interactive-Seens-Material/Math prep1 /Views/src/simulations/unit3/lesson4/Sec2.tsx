import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Maximize2, MoveRight, ArrowRight, Shapes } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function Scene({ angleA, angleB, step }: { angleA: number, angleB: number, step: number }) {
    const { camera } = useThree();
    
    // Animation Proxy
    const proxy = useRef({ 
        angleA: angleA,
        angleB: angleB,
        packOp: 0,
        packA_x: 0, // A starts at vertex A, moves to C
        packA_y: 0,
        packB_x: 0, // B starts at vertex B, moves to C
        packB_y: 0,
        packA_rot: 0, // A rotates to fit into exterior
        packB_rot: 0, // B rotates to fit into exterior
    }).current;
    
    // Fixed base line points (B is origin roughly, C is along x axis)
    const bx = -2;
    const by = -2;
    const cx = 3;
    const cy = -2;

    const arcRadius = 1.0;
    const textRadius = 1.6;

    // Geometry refs
    const lineABRef = useRef<THREE.Mesh>(null);
    const lineBCRef = useRef<THREE.Mesh>(null);
    const lineCARef = useRef<THREE.Mesh>(null);
    const ptA = useRef<THREE.Group>(null);
    
    // Arcs
    const arcBRef = useRef<THREE.Line>(null);
    const arcCRef = useRef<THREE.Line>(null);
    const arcAIntRef = useRef<THREE.Line>(null);
    const arcExtRef = useRef<THREE.Line>(null);
    
    // Packing wedges (moving arcs)
    const packARef = useRef<THREE.Group>(null);
    const packBRef = useRef<THREE.Group>(null);
    const packAArcRef = useRef<THREE.Line>(null);
    const packBArcRef = useRef<THREE.Line>(null);

    // Text Refs
    const textBRef = useRef<any>(null);
    const textBGroupRef = useRef<THREE.Group>(null);
    const textCRef = useRef<any>(null);
    const textCGroupRef = useRef<THREE.Group>(null);
    const textAIntRef = useRef<any>(null);
    const textAIntGroupRef = useRef<THREE.Group>(null);
    const textExtRef = useRef<any>(null);
    const textExtGroupRef = useRef<THREE.Group>(null);

    const makeGeom = () => {
        const geom = new THREE.BufferGeometry();
        geom.setAttribute('position', new THREE.BufferAttribute(new Float32Array(65 * 3), 3));
        return geom;
    };

    const arcBGeom = useMemo(makeGeom, []);
    const arcCGeom = useMemo(makeGeom, []);
    const arcAIntGeom = useMemo(makeGeom, []);
    const arcExtGeom = useMemo(makeGeom, []);
    const packAArcGeom = useMemo(makeGeom, []);
    const packBArcGeom = useMemo(makeGeom, []);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 0, z: 12, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    useEffect(() => {
        if (step === 0 || step === 1) {
            gsap.to(proxy, {
                packOp: 0,
                packA_x: 0,
                packA_y: 0,
                packB_x: 0,
                packB_y: 0,
                packA_rot: 0,
                packB_rot: 0,
                duration: 0.5
            });
        } else if (step === 2) {
            // Pack them into C exterior
            gsap.to(proxy, {
                packOp: 1,
                packA_x: 1,
                packA_y: 1,
                packB_x: 1,
                packB_y: 1,
                packA_rot: 1,
                packB_rot: 1,
                duration: 1.5,
                ease: "power2.inOut"
            });
        }
    }, [step, proxy]);

    const updateArc = (geom: THREE.BufferGeometry, start: number, end: number, r: number, segments: number = 64) => {
        const positions = geom.attributes.position.array as Float32Array;
        let s = start;
        let e = end;
        if (e < s) e += Math.PI * 2;
        
        const delta = e - s;
        for (let i = 0; i <= segments; i++) {
            const theta = s + (i / segments) * delta;
            positions[i * 3] = Math.cos(theta) * r;
            positions[i * 3 + 1] = Math.sin(theta) * r;
            positions[i * 3 + 2] = 0;
        }
        geom.setDrawRange(0, segments + 1);
        geom.attributes.position.needsUpdate = true;
    };

    const updateLine = (mesh: THREE.Mesh, p1: THREE.Vector3, p2: THREE.Vector3) => {
        const dist = p1.distanceTo(p2);
        mesh.scale.y = dist;
        mesh.position.copy(p1).lerp(p2, 0.5);
        mesh.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), p2.clone().sub(p1).normalize());
    };

    useFrame(() => {
        proxy.angleA = THREE.MathUtils.lerp(proxy.angleA, angleA, 0.1);
        proxy.angleB = THREE.MathUtils.lerp(proxy.angleB, angleB, 0.1);
        
        const aRad = proxy.angleA * Math.PI / 180;
        const bRad = proxy.angleB * Math.PI / 180;
        const cRad = Math.PI - aRad - bRad; // Interior C
        const extCRad = Math.PI - cRad; // Exterior C
        
        // B is at (-2, -2)
        // C is at (3, -2)
        // Distance BC = 5
        const cDist = 5;
        
        // Law of sines to find AC length:
        // AC / sin(B) = BC / sin(A)  => AC = BC * sin(B) / sin(A)
        const acLen = cDist * Math.sin(bRad) / Math.sin(aRad);
        
        // A coordinate relative to C
        // Angle of CA from positive x-axis is (180 - C)
        const angleCA = Math.PI - cRad;
        const ax = cx + acLen * Math.cos(angleCA);
        const ay = cy + acLen * Math.sin(angleCA);
        
        const pA = new THREE.Vector3(ax, ay, 0);
        const pB = new THREE.Vector3(bx, by, 0);
        const pC = new THREE.Vector3(cx, cy, 0);

        if (ptA.current) ptA.current.position.copy(pA);
        
        if (lineABRef.current) updateLine(lineABRef.current, pA, pB);
        if (lineCARef.current) updateLine(lineCARef.current, pC, pA);
        if (lineBCRef.current) updateLine(lineBCRef.current, pB, pC);

        // Angles for drawing
        // For B: Base BC is at angle 0. Line BA is at angle B.
        if (arcBRef.current) {
            arcBRef.current.position.copy(pB);
            updateArc(arcBRef.current.geometry, 0, bRad, arcRadius);
        }
        if (textBGroupRef.current && textBRef.current) {
            textBGroupRef.current.position.set(pB.x + Math.cos(bRad/2)*textRadius, pB.y + Math.sin(bRad/2)*textRadius, 0);
            textBRef.current.text = `${Math.round(proxy.angleB)}°`;
        }

        // For C interior: Base CB is at angle PI. Line CA is at angle PI - C.
        if (arcCRef.current) {
            arcCRef.current.position.copy(pC);
            updateArc(arcCRef.current.geometry, Math.PI - cRad, Math.PI, arcRadius);
        }
        if (textCGroupRef.current && textCRef.current) {
            const mid = Math.PI - cRad/2;
            textCGroupRef.current.position.set(pC.x + Math.cos(mid)*textRadius, pC.y + Math.sin(mid)*textRadius, 0);
            textCRef.current.text = `${Math.round(cRad * 180 / Math.PI)}°`;
        }
        
        // For C exterior: Base CD (extended BC) is at angle 0. Line CA is at angle PI - C.
        // Or wait, line CA is at angle PI - C? No, angle of CA relative to positive X is PI - C.
        // Wait, if C is (3,-2) and B is (-2,-2), CB goes left (PI). Extended ray CD goes right (0).
        // Line CA goes up and left. Angle of CA from positive X is PI - C (e.g. 180 - 70 = 110 deg).
        // So exterior angle is from 0 to PI - C.
        if (arcExtRef.current) {
            arcExtRef.current.position.copy(pC);
            updateArc(arcExtRef.current.geometry, 0, extCRad, arcRadius + 0.5);
        }
        if (textExtGroupRef.current && textExtRef.current) {
            const extMid = extCRad / 2;
            textExtGroupRef.current.position.set(pC.x + Math.cos(extMid)*(textRadius + 0.5), pC.y + Math.sin(extMid)*(textRadius + 0.5), 0);
            textExtRef.current.text = `${Math.round(extCRad * 180 / Math.PI)}°`;
        }

        // For A interior: Line AB goes down/left, Line AC goes down/right.
        // Vector AB = pB - pA. Vector AC = pC - pA.
        const angleAB = Math.atan2(pB.y - pA.y, pB.x - pA.x);
        const angleAC = Math.atan2(pC.y - pA.y, pC.x - pA.x);
        
        let startA = angleAC;
        let endA = angleAB;
        if (startA < 0) startA += Math.PI * 2;
        if (endA < 0) endA += Math.PI * 2;
        if (endA < startA) endA += Math.PI * 2;

        if (arcAIntRef.current) {
            arcAIntRef.current.position.copy(pA);
            updateArc(arcAIntRef.current.geometry, startA, endA, arcRadius);
        }
        if (textAIntGroupRef.current && textAIntRef.current) {
            const mid = (startA + endA) / 2;
            textAIntGroupRef.current.position.set(pA.x + Math.cos(mid)*textRadius, pA.y + Math.sin(mid)*textRadius, 0);
            textAIntRef.current.text = `${Math.round(proxy.angleA)}°`;
        }

        // PACKING ANIMATIONS
        if (packARef.current && packBRef.current) {
            // A packing arc
            const curAx = THREE.MathUtils.lerp(pA.x, pC.x, proxy.packA_x);
            const curAy = THREE.MathUtils.lerp(pA.y, pC.y, proxy.packA_y);
            packARef.current.position.set(curAx, curAy, 0.1);
            
            // A needs to rotate so its sector (startA to endA) maps to (0 to aRad) in C exterior?
            // Actually, A and B pack together to form extCRad.
            // B packs into (0 to bRad). A packs into (bRad to bRad + aRad).
            
            // B Packing
            const curBx = THREE.MathUtils.lerp(pB.x, pC.x, proxy.packB_x);
            const curBy = THREE.MathUtils.lerp(pB.y, pC.y, proxy.packB_y);
            packBRef.current.position.set(curBx, curBy, 0.1);
            
            // Arc geometries for packing
            if (packBArcRef.current) {
                // B starts as (0 to bRad). It just slides to C, no rotation needed!
                updateArc(packBArcRef.current.geometry, 0, bRad, arcRadius);
            }
            if (packAArcRef.current) {
                // A starts at (startA to endA). We want to map it to (bRad to bRad + aRad).
                // So initial rotation offset is 0. Target rotation offset maps startA to bRad.
                // Wait, the angle size is aRad. So startA rotates to bRad.
                const targetRotA = bRad - startA;
                const curRotA = THREE.MathUtils.lerp(0, targetRotA, proxy.packA_rot);
                
                packARef.current.rotation.z = curRotA;
                
                updateArc(packAArcRef.current.geometry, startA, endA, arcRadius);
            }
        }
    });

    return (
        <group position={[0, -0.5, 0]}>
            <ambientLight intensity={0.6} />
            <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow />
            <Environment preset="city" opacity={0.3} />
            
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[50, 50]} />
                <meshStandardMaterial color="#020617" />
            </mesh>
            <gridHelper args={[50, 50, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* Triangle Edges */}
            <mesh ref={lineABRef} castShadow>
                <cylinderGeometry args={[0.04, 0.04, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} />
            </mesh>
            <mesh ref={lineBCRef} castShadow>
                <cylinderGeometry args={[0.04, 0.04, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} />
            </mesh>
            <mesh ref={lineCARef} castShadow>
                <cylinderGeometry args={[0.04, 0.04, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} />
            </mesh>

            {/* Extended Ray BD */}
            <mesh position={[cx + 3, cy, 0]} rotation={[0, 0, -Math.PI/2]}>
                <cylinderGeometry args={[0.04, 0.04, 6]} />
                <meshStandardMaterial color="#facc15" roughness={0.3} emissive="#facc15" emissiveIntensity={0.5} />
            </mesh>
            <mesh position={[cx + 6, cy, 0]} rotation={[0, 0, -Math.PI/2]}>
                <coneGeometry args={[0.2, 0.5]} />
                <meshStandardMaterial color="#facc15" />
            </mesh>
            <Text position={[cx + 6, cy + 0.4, 0]} fontSize={0.5} color="#facc15" fontWeight="bold">D</Text>


            {/* Vertices */}
            <group ref={ptA}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0, 0.5, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">A</Text>
            </group>
            <group position={[bx, by, 0]}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.4, -0.4, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">B</Text>
            </group>
            <group position={[cx, cy, 0]}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0.4, -0.4, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">C</Text>
            </group>

            {/* Interior Arcs (Faded when packing) */}
            <line ref={arcBRef as any} geometry={arcBGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#22c55e" linewidth={step === 2 ? 2 : 5} transparent opacity={step === 2 ? 0.3 : 0.9} /> 
            </line>
            <line ref={arcCRef as any} geometry={arcCGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#f97316" linewidth={5} transparent opacity={0.9} /> 
            </line>
            <line ref={arcAIntRef as any} geometry={arcAIntGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#3b82f6" linewidth={step === 2 ? 2 : 5} transparent opacity={step === 2 ? 0.3 : 0.9} /> 
            </line>

            {/* Exterior Arc C */}
            <line ref={arcExtRef as any} geometry={arcExtGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#facc15" linewidth={5} transparent opacity={0.9} />
            </line>

            {/* Packing Wedges (Solid faces mapped to A and B) */}
            {step === 2 && (
                <>
                    <group ref={packARef}>
                        <line ref={packAArcRef as any} geometry={packAArcGeom}>
                            <lineBasicMaterial color="#3b82f6" linewidth={7} transparent opacity={1} />
                        </line>
                        <Text position={[0.6, 0.6, 0]} fontSize={0.4} color="#3b82f6" fontWeight="bold">A</Text>
                    </group>
                    <group ref={packBRef}>
                        <line ref={packBArcRef as any} geometry={packBArcGeom}>
                            <lineBasicMaterial color="#22c55e" linewidth={7} transparent opacity={1} />
                        </line>
                        <Text position={[0.8, 0.2, 0]} fontSize={0.4} color="#22c55e" fontWeight="bold">B</Text>
                    </group>
                </>
            )}

            {/* Texts */}
            <group ref={textBGroupRef}>
                <Text ref={textBRef} fontSize={0.5} color="#22c55e" fontWeight="bold" anchorX="center" anchorY="middle" transparent opacity={step === 2 ? 0.3 : 1} />
            </group>
            <group ref={textCGroupRef}>
                <Text ref={textCRef} fontSize={0.5} color="#f97316" fontWeight="bold" anchorX="center" anchorY="middle" />
            </group>
            <group ref={textAIntGroupRef}>
                <Text ref={textAIntRef} fontSize={0.5} color="#3b82f6" fontWeight="bold" anchorX="center" anchorY="middle" transparent opacity={step === 2 ? 0.3 : 1} />
            </group>
            <group ref={textExtGroupRef}>
                <Text ref={textExtRef} fontSize={0.5} color="#facc15" fontWeight="bold" anchorX="center" anchorY="middle" />
            </group>

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function ExteriorAngleTheorem() {
    const [angleA, setAngleA] = useState(60);
    const [angleB, setAngleB] = useState(50);
    const [step, setStep] = useState(0); 

    const angleC = 180 - angleA - angleB;
    const extC = 180 - angleC; // which is just A + B

    const handleNext = () => {
        if (step < 2) setStep(prev => prev + 1);
    };

    const handleReset = () => {
        setStep(0);
        setAngleA(60);
        setAngleB(50);
    };

    const renderMath = () => {
        if (step === 0) {
            return <BlockMath math={`m\\angle \\text{Ext} = 180^\\circ - m\\angle C = 180^\\circ - ${angleC}^\\circ = \\color{#facc15}${extC}^\\circ`} />;
        }
        if (step === 1) {
            return <BlockMath math={`m\\angle A + m\\angle B = \\color{#3b82f6}${angleA}^\\circ \\color{white}+ \\color{#22c55e}${angleB}^\\circ \\color{white}= \\color{#facc15}${angleA + angleB}^\\circ`} />;
        }
        return <BlockMath math={`m\\angle \\text{Ext} = m\\angle A + m\\angle B`} />;
    };

    const getAlertContent = () => {
        if (step < 2) {
            return { 
                title: "Exterior Angle Evaluation", 
                icon: <Shapes size={18}/>, 
                color: "border-l-blue-500 text-blue-300 bg-blue-900/40", 
                body: <>We can calculate the exterior angle using supplementary logic (180 - Interior C). Notice how the sum of the two remote interior angles (A + B) produces the exact same number.</> 
            };
        }
        return { 
            title: "Remote Interior Packing Theorem", 
            icon: <ShieldAlert size={18}/>, 
            color: "border-l-rose-500 text-rose-300 bg-rose-900/40", 
            body: <><b>CRITICAL MISCONCEPTION:</b> Students often try to calculate the exterior angle by subtracting one remote interior angle from 180. The physical packing animation proves the exterior angle is always exactly equal to the <b>SUM</b> of the two non-adjacent (remote) interior angles.</> 
        };
    };

    const alertContent = getAlertContent();

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            The Exterior Angle Theorem
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Relating the exterior angle to the remote interior angles.
                        </p>
                    </div>
                    <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[340px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-all duration-500">
                        <div className="text-white text-lg px-4 flex items-center justify-center h-full min-h-[4rem]">
                            {renderMath()}
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas shadows>
                        <OrbitControls enablePan={false} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <Scene angleA={angleA} angleB={angleB} step={step} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                <div className={`flex-1 pointer-events-auto max-w-md w-full transition-all duration-500`}>
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
                    
                    <div className="mb-4">
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className="text-slate-400">Angle A (Remote)</span>
                            <span className="text-blue-400">{angleA}°</span>
                        </div>
                        <input 
                            type="range" 
                            min="20" 
                            max="100" 
                            step="1" 
                            value={angleA} 
                            onChange={(e) => {
                                if (step === 2) setStep(1); // un-pack to allow dragging
                                const maxAllowed = 150 - angleB;
                                setAngleA(Math.min(parseInt(e.target.value), maxAllowed));
                            }} 
                            className="w-full accent-blue-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer mt-1"
                        />
                    </div>
                    <div className="mb-6">
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className="text-slate-400">Angle B (Remote)</span>
                            <span className="text-green-400">{angleB}°</span>
                        </div>
                        <input 
                            type="range" 
                            min="20" 
                            max="100" 
                            step="1" 
                            value={angleB} 
                            onChange={(e) => {
                                if (step === 2) setStep(1);
                                const maxAllowed = 150 - angleA;
                                setAngleB(Math.min(parseInt(e.target.value), maxAllowed));
                            }} 
                            className="w-full accent-green-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer mt-1"
                        />
                    </div>

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={handleNext} 
                            disabled={step >= 2} 
                            className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                ${step >= 2 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                        >
                            {step === 0 ? "Evaluate Sum" : step === 1 ? "Pack Wedges" : "Proof Complete"} <ArrowRight size={16} />
                        </button>
                        <button 
                            onClick={handleReset} 
                            className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
                        >
                            <RotateCcw size={16} /> Reset State
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
