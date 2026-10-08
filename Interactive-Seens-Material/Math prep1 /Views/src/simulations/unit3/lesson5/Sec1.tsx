import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, SplitSquareHorizontal, ArrowRight, Shapes } from 'lucide-react';

// ---------------------------------------------------------
// MATH & GEOMETRY HELPERS
// ---------------------------------------------------------

const getArcParams = (pCenter: THREE.Vector3, p1: THREE.Vector3, p2: THREE.Vector3) => {
    const v1 = new THREE.Vector3().subVectors(p1, pCenter);
    const v2 = new THREE.Vector3().subVectors(p2, pCenter);
    let a1 = Math.atan2(v1.y, v1.x);
    let a2 = Math.atan2(v2.y, v2.x);
    
    let diff = a2 - a1;
    while(diff < -Math.PI) diff += 2 * Math.PI;
    while(diff > Math.PI) diff -= 2 * Math.PI;
    
    if (diff < 0) {
        return { start: a2, end: a1, delta: -diff };
    } else {
        return { start: a1, end: a2, delta: diff };
    }
};

const updateArcGeom = (geom: THREE.BufferGeometry, params: { start: number, delta: number }, r: number) => {
    const positions = geom.attributes.position.array as Float32Array;
    const segments = 32;
    for (let i = 0; i <= segments; i++) {
        const theta = params.start + (i / segments) * params.delta;
        positions[i * 3] = Math.cos(theta) * r;
        positions[i * 3 + 1] = Math.sin(theta) * r;
        positions[i * 3 + 2] = 0;
    }
    geom.setDrawRange(0, segments + 1);
    geom.attributes.position.needsUpdate = true;
};

const makeWedgeGeom = () => {
    const geom = new THREE.BufferGeometry();
    const segs = 32;
    const pos = new Float32Array((segs + 2) * 3);
    const indices = [];
    for(let i = 1; i <= segs; i++) {
        indices.push(0, i, i + 1);
    }
    geom.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    geom.setIndex(indices);
    return geom;
};

const updateWedgeGeom = (geom: THREE.BufferGeometry, start: number, delta: number, r: number) => {
    const pos = geom.attributes.position.array as Float32Array;
    pos[0] = 0; pos[1] = 0; pos[2] = 0; // center
    const segs = 32;
    for(let i = 0; i <= segs; i++) {
        const theta = start + (i / segs) * delta;
        pos[(i + 1) * 3] = Math.cos(theta) * r;
        pos[(i + 1) * 3 + 1] = Math.sin(theta) * r;
        pos[(i + 1) * 3 + 2] = 0;
    }
    geom.attributes.position.needsUpdate = true;
};

const updateLine = (mesh: THREE.Mesh, p1: THREE.Vector3, p2: THREE.Vector3) => {
    const dist = p1.distanceTo(p2);
    mesh.scale.y = dist;
    mesh.position.copy(p1).lerp(p2, 0.5);
    mesh.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), p2.clone().sub(p1).normalize());
};

const updatePolygon = (geom: THREE.BufferGeometry, points: THREE.Vector3[]) => {
    const pos = geom.attributes.position.array as Float32Array;
    // For a triangle
    if (points.length === 3) {
        pos[0] = points[0].x; pos[1] = points[0].y; pos[2] = points[0].z;
        pos[3] = points[1].x; pos[4] = points[1].y; pos[5] = points[1].z;
        pos[6] = points[2].x; pos[7] = points[2].y; pos[8] = points[2].z;
        geom.setDrawRange(0, 3);
        geom.attributes.position.needsUpdate = true;
    }
};

// ---------------------------------------------------------
// 3D SCENE
// ---------------------------------------------------------

function Scene({ ax, ay, dx, dy, step }: { ax: number, ay: number, dx: number, dy: number, step: number }) {
    const { camera } = useThree();
    
    // Animation Proxy
    const proxy = useRef({ 
        ax: ax, ay: ay, dx: dx, dy: dy,
        splitOp: 0,
        pieOp: 0,
        pieScale: 0
    }).current;
    
    // Fixed base vertices
    const b = useMemo(() => new THREE.Vector3(-3, -2, 0), []);
    const c = useMemo(() => new THREE.Vector3(3, -2, 0), []);

    // Refs
    const lineABRef = useRef<THREE.Mesh>(null);
    const lineBCRef = useRef<THREE.Mesh>(null);
    const lineCDRef = useRef<THREE.Mesh>(null);
    const lineDARef = useRef<THREE.Mesh>(null);
    const diagACRef = useRef<THREE.Mesh>(null);

    const ptA = useRef<THREE.Group>(null);
    const ptD = useRef<THREE.Group>(null);

    // Arcs
    const arcARef = useRef<THREE.Line>(null);
    const arcBRef = useRef<THREE.Line>(null);
    const arcCRef = useRef<THREE.Line>(null);
    const arcDRef = useRef<THREE.Line>(null);
    
    const arcARef2 = useRef<THREE.Line>(null); // Split A (DAC)
    const arcCRef2 = useRef<THREE.Line>(null); // Split C (BCA)

    // Triangle Fills
    const t1Geom = useMemo(() => {
        const g = new THREE.BufferGeometry();
        g.setAttribute('position', new THREE.BufferAttribute(new Float32Array(9), 3));
        return g;
    }, []);
    const t2Geom = useMemo(() => {
        const g = new THREE.BufferGeometry();
        g.setAttribute('position', new THREE.BufferAttribute(new Float32Array(9), 3));
        return g;
    }, []);
    const matT1Ref = useRef<THREE.MeshBasicMaterial>(null);
    const matT2Ref = useRef<THREE.MeshBasicMaterial>(null);
    const matDiagRef = useRef<THREE.MeshStandardMaterial>(null);

    // Pie Chart Geoms
    const pieAGeom = useMemo(makeWedgeGeom, []);
    const pieBGeom = useMemo(makeWedgeGeom, []);
    const pieCGeom = useMemo(makeWedgeGeom, []);
    const pieDGeom = useMemo(makeWedgeGeom, []);
    const pieGroupRef = useRef<THREE.Group>(null);

    // Make arc geoms
    const makeArc = () => {
        const geom = new THREE.BufferGeometry();
        geom.setAttribute('position', new THREE.BufferAttribute(new Float32Array(65 * 3), 3));
        return geom;
    };
    const agA = useMemo(makeArc, []);
    const agB = useMemo(makeArc, []);
    const agC = useMemo(makeArc, []);
    const agD = useMemo(makeArc, []);
    const agA2 = useMemo(makeArc, []);
    const agC2 = useMemo(makeArc, []);

    // Text Refs
    const textARef = useRef<any>(null);
    const textBRef = useRef<any>(null);
    const textCRef = useRef<any>(null);
    const textDRef = useRef<any>(null);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 0, z: 12, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    useEffect(() => {
        gsap.to(proxy, {
            splitOp: step >= 1 ? 1 : 0,
            pieOp: step >= 2 ? 1 : 0,
            pieScale: step >= 2 ? 1 : 0.01,
            duration: 1.0,
            ease: "power2.out"
        });
    }, [step, proxy]);

    useFrame(() => {
        proxy.ax = THREE.MathUtils.lerp(proxy.ax, ax, 0.1);
        proxy.ay = THREE.MathUtils.lerp(proxy.ay, ay, 0.1);
        proxy.dx = THREE.MathUtils.lerp(proxy.dx, dx, 0.1);
        proxy.dy = THREE.MathUtils.lerp(proxy.dy, dy, 0.1);
        
        const a = new THREE.Vector3(proxy.ax, proxy.ay, 0);
        const d = new THREE.Vector3(proxy.dx, proxy.dy, 0);

        if (ptA.current) ptA.current.position.copy(a);
        if (ptD.current) ptD.current.position.copy(d);
        
        if (lineABRef.current) updateLine(lineABRef.current, a, b);
        if (lineBCRef.current) updateLine(lineBCRef.current, b, c);
        if (lineCDRef.current) updateLine(lineCDRef.current, c, d);
        if (lineDARef.current) updateLine(lineDARef.current, d, a);
        if (diagACRef.current) updateLine(diagACRef.current, a, c);

        updatePolygon(t1Geom, [a, b, c]);
        updatePolygon(t2Geom, [a, c, d]);

        // Angle logic
        const pA = getArcParams(a, d, b);
        const pB = getArcParams(b, a, c);
        const pC = getArcParams(c, b, d);
        const pD = getArcParams(d, c, a);

        // Split angles
        const pBAC = getArcParams(a, c, b);
        const pDAC = getArcParams(a, d, c);
        const pBCA = getArcParams(c, b, a);
        const pDCA = getArcParams(c, a, d);

        const rad = 0.8;
        
        // Base arcs (always visible, but we might override A and C visually)
        // If splitOp > 0, A and C display splits instead. We handle this via opacity.
        if (arcARef.current) updateArcGeom(agA, pA, rad);
        if (arcBRef.current) updateArcGeom(agB, pB, rad);
        if (arcCRef.current) updateArcGeom(agC, pC, rad);
        if (arcDRef.current) updateArcGeom(agD, pD, rad);
        
        if (arcARef2.current) updateArcGeom(agA2, pDAC, rad);
        if (arcCRef2.current) updateArcGeom(agC2, pDCA, rad);

        // Calculate degrees
        let degA = Math.round(pA.delta * 180 / Math.PI);
        let degB = Math.round(pB.delta * 180 / Math.PI);
        let degC = Math.round(pC.delta * 180 / Math.PI);
        let degD = 360 - degA - degB - degC; // Force sum to exactly 360

        // Positioning Texts
        const tr = 1.4;
        if (textARef.current) {
            const mid = pA.start + pA.delta / 2;
            textARef.current.position.set(a.x + Math.cos(mid)*tr, a.y + Math.sin(mid)*tr, 0);
            textARef.current.text = `${degA}°`;
        }
        if (textBRef.current) {
            const mid = pB.start + pB.delta / 2;
            textBRef.current.position.set(b.x + Math.cos(mid)*tr, b.y + Math.sin(mid)*tr, 0);
            textBRef.current.text = `${degB}°`;
        }
        if (textCRef.current) {
            const mid = pC.start + pC.delta / 2;
            textCRef.current.position.set(c.x + Math.cos(mid)*tr, c.y + Math.sin(mid)*tr, 0);
            textCRef.current.text = `${degC}°`;
        }
        if (textDRef.current) {
            const mid = pD.start + pD.delta / 2;
            textDRef.current.position.set(d.x + Math.cos(mid)*tr, d.y + Math.sin(mid)*tr, 0);
            textDRef.current.text = `${degD}°`;
        }

        // Opacity Updates
        if (matDiagRef.current) {
            matDiagRef.current.opacity = proxy.splitOp;
        }
        if (matT1Ref.current) matT1Ref.current.opacity = proxy.splitOp * 0.2;
        if (matT2Ref.current) matT2Ref.current.opacity = proxy.splitOp * 0.2;
        
        if (arcARef2.current) arcARef2.current.material.opacity = proxy.splitOp * 0.9;
        if (arcCRef2.current) arcCRef2.current.material.opacity = proxy.splitOp * 0.9;
        
        // In step 1, A and C base arcs should fade? No, keep them but draw split line.
        // Actually, the prompt says split triangle logic. So let's just make the split line clear.

        // Pie Chart Updates
        if (pieGroupRef.current) {
            const centroid = new THREE.Vector3(
                (a.x + b.x + c.x + d.x) / 4,
                (a.y + b.y + c.y + d.y) / 4,
                0.2
            );
            pieGroupRef.current.position.copy(centroid);
            pieGroupRef.current.scale.setScalar(proxy.pieScale);
            
            // Set opacity of children
            pieGroupRef.current.children.forEach((c: any) => {
                if (c.material) c.material.opacity = proxy.pieOp * 0.9;
            });
            
            // Angles for pie chart
            updateWedgeGeom(pieAGeom, 0, pA.delta, 1.2);
            updateWedgeGeom(pieBGeom, pA.delta, pB.delta, 1.2);
            updateWedgeGeom(pieCGeom, pA.delta + pB.delta, pC.delta, 1.2);
            updateWedgeGeom(pieDGeom, pA.delta + pB.delta + pC.delta, pD.delta, 1.2);
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

            {/* Triangle Fills */}
            <mesh geometry={t1Geom} position={[0,0,-0.05]}>
                <meshBasicMaterial ref={matT1Ref} color="#0ea5e9" transparent opacity={0} side={THREE.DoubleSide} />
            </mesh>
            <mesh geometry={t2Geom} position={[0,0,-0.05]}>
                <meshBasicMaterial ref={matT2Ref} color="#d946ef" transparent opacity={0} side={THREE.DoubleSide} />
            </mesh>

            {/* Edges */}
            <mesh ref={lineABRef} castShadow>
                <cylinderGeometry args={[0.04, 0.04, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} />
            </mesh>
            <mesh ref={lineBCRef} castShadow>
                <cylinderGeometry args={[0.04, 0.04, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} />
            </mesh>
            <mesh ref={lineCDRef} castShadow>
                <cylinderGeometry args={[0.04, 0.04, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} />
            </mesh>
            <mesh ref={lineDARef} castShadow>
                <cylinderGeometry args={[0.04, 0.04, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} />
            </mesh>
            <mesh ref={diagACRef}>
                <cylinderGeometry args={[0.03, 0.03, 1]} />
                <meshStandardMaterial ref={matDiagRef} color="#facc15" roughness={0.3} emissive="#facc15" emissiveIntensity={0.8} transparent opacity={0} />
            </mesh>

            {/* Vertices */}
            <group ref={ptA}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.4, 0.4, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">A</Text>
            </group>
            <group position={[-3, -2, 0]}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.4, -0.4, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">B</Text>
            </group>
            <group position={[3, -2, 0]}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0.4, -0.4, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">C</Text>
            </group>
            <group ref={ptD}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0.4, 0.4, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">D</Text>
            </group>

            {/* Main Arcs */}
            <line ref={arcARef as any} geometry={agA} position={[0,0,0.05]}>
                <lineBasicMaterial color="#3b82f6" linewidth={5} transparent opacity={0.9} /> 
            </line>
            <line ref={arcBRef as any} geometry={agB} position={[0,0,0.05]}>
                <lineBasicMaterial color="#22c55e" linewidth={5} transparent opacity={0.9} /> 
            </line>
            <line ref={arcCRef as any} geometry={agC} position={[0,0,0.05]}>
                <lineBasicMaterial color="#f97316" linewidth={5} transparent opacity={0.9} /> 
            </line>
            <line ref={arcDRef as any} geometry={agD} position={[0,0,0.05]}>
                <lineBasicMaterial color="#a855f7" linewidth={5} transparent opacity={0.9} /> 
            </line>

            {/* Split Arcs (Visible in Step 1/2) */}
            <line ref={arcARef2 as any} geometry={agA2} position={[0,0,0.06]}>
                <lineBasicMaterial color="#0ea5e9" linewidth={5} transparent opacity={0} /> 
            </line>
            <line ref={arcCRef2 as any} geometry={agC2} position={[0,0,0.06]}>
                <lineBasicMaterial color="#d946ef" linewidth={5} transparent opacity={0} /> 
            </line>

            {/* Central 360 Gauge (Step 2) */}
            <group ref={pieGroupRef}>
                <mesh geometry={pieAGeom}>
                    <meshBasicMaterial color="#3b82f6" transparent opacity={0} side={THREE.DoubleSide} />
                </mesh>
                <mesh geometry={pieBGeom}>
                    <meshBasicMaterial color="#22c55e" transparent opacity={0} side={THREE.DoubleSide} />
                </mesh>
                <mesh geometry={pieCGeom}>
                    <meshBasicMaterial color="#f97316" transparent opacity={0} side={THREE.DoubleSide} />
                </mesh>
                <mesh geometry={pieDGeom}>
                    <meshBasicMaterial color="#a855f7" transparent opacity={0} side={THREE.DoubleSide} />
                </mesh>
                
                {/* Gauge outline */}
                <mesh position={[0,0,0.01]}>
                    <ringGeometry args={[1.2, 1.25, 64]} />
                    <meshBasicMaterial color="#ffffff" transparent opacity={0.5} />
                </mesh>
                
                {/* Center text */}
                <mesh position={[0,0,0.02]}>
                    <circleGeometry args={[0.5, 32]} />
                    <meshBasicMaterial color="#0f172a" />
                </mesh>
                <Text position={[0,0,0.03]} fontSize={0.35} color="#ffffff" fontWeight="bold">
                    360°
                </Text>
            </group>

            {/* Texts */}
            <Text ref={textARef} fontSize={0.4} color="#3b82f6" fontWeight="bold" anchorX="center" anchorY="middle" />
            <Text ref={textBRef} fontSize={0.4} color="#22c55e" fontWeight="bold" anchorX="center" anchorY="middle" />
            <Text ref={textCRef} fontSize={0.4} color="#f97316" fontWeight="bold" anchorX="center" anchorY="middle" />
            <Text ref={textDRef} fontSize={0.4} color="#a855f7" fontWeight="bold" anchorX="center" anchorY="middle" />
        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function QuadrilateralSum() {
    const [ax, setAx] = useState(-2);
    const [ay, setAy] = useState(3);
    const [dx, setDx] = useState(2);
    const [dy, setDy] = useState(3);
    
    const [step, setStep] = useState(0); 

    const handleNext = () => {
        if (step < 2) setStep(prev => prev + 1);
    };

    const renderMath = () => {
        if (step === 0) {
            return <BlockMath math={`m\\angle A + m\\angle B + m\\angle C + m\\angle D = \\text{?}`} />;
        }
        if (step === 1) {
            return <BlockMath math={`\\text{Quad } ABCD = \\triangle ABC + \\triangle ADC`} />;
        }
        return <BlockMath math={`180^\\circ (\\triangle 1) + 180^\\circ (\\triangle 2) = \\color{#facc15}360^\\circ`} />;
    };

    const getAlertContent = () => {
        if (step === 0) {
            return { 
                title: "Quadrilateral Interior Sum", 
                icon: <Shapes size={18}/>, 
                color: "border-l-blue-500 text-blue-300 bg-blue-900/40", 
                body: <>What is the sum of the interior angles of any quadrilateral? Let's use what we already know about triangles to prove the result.</> 
            };
        }
        if (step === 1) {
            return { 
                title: "Diagonal Division", 
                icon: <SplitSquareHorizontal size={18}/>, 
                color: "border-l-green-500 text-green-300 bg-green-900/40", 
                body: <>By drawing diagonal AC, we physically divide the 4-sided polygon into exactly two adjacent triangles. Every angle in the quadrilateral is fully accounted for within these two triangles.</> 
            };
        }
        return { 
            title: "Invariant 360° Sum", 
            icon: <ShieldAlert size={18}/>, 
            color: "border-l-rose-500 text-rose-300 bg-rose-900/40", 
            body: <><b>CRITICAL MISCONCEPTION:</b> Students often think stretching or enlarging a quadrilateral increases its angle sum. The central pie chart dynamically proves that regardless of the boundary shape, the 4 angles always map perfectly to a full 360° circle (2 × 180°).</> 
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
                            The Quadrilateral Sum Theorem
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Any polygon's angles depend on its triangular components.
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
                        <Scene ax={ax} ay={ay} dx={dx} dy={dy} step={step} />
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
                    
                    <div className="flex gap-4 mb-4">
                        <div className="flex-1">
                            <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                                Vertex A Shift
                            </label>
                            <input 
                                type="range" 
                                min="-4" max="-0.5" step="0.1" 
                                value={ax} onChange={(e) => setAx(parseFloat(e.target.value))} 
                                className="w-full accent-blue-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer mt-1 mb-3"
                            />
                            <input 
                                type="range" 
                                min="1" max="4" step="0.1" 
                                value={ay} onChange={(e) => setAy(parseFloat(e.target.value))} 
                                className="w-full accent-blue-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer"
                            />
                        </div>
                        
                        <div className="flex-1">
                            <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                                Vertex D Shift
                            </label>
                            <input 
                                type="range" 
                                min="0.5" max="4" step="0.1" 
                                value={dx} onChange={(e) => setDx(parseFloat(e.target.value))} 
                                className="w-full accent-purple-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer mt-1 mb-3"
                            />
                            <input 
                                type="range" 
                                min="1" max="4" step="0.1" 
                                value={dy} onChange={(e) => setDy(parseFloat(e.target.value))} 
                                className="w-full accent-purple-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer"
                            />
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="grid grid-cols-1 gap-3 mt-auto">
                        <button 
                            onClick={handleNext} 
                            disabled={step >= 2} 
                            className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                ${step >= 2 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                        >
                            {step === 0 ? "Split into Triangles" : step === 1 ? "Evaluate Total Sum" : "Proof Complete"} 
                            {step < 2 && <ArrowRight size={16} />}
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
