import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, SplitSquareHorizontal, Shapes, Maximize, Frame } from 'lucide-react';

// ---------------------------------------------------------
// 3D GEOMETRY HELPERS
// ---------------------------------------------------------

const updateArcGeom = (geom: THREE.BufferGeometry, start: number, end: number, r: number, segments: number = 64) => {
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

const updateSquareGeom = (geom: THREE.BufferGeometry, v: THREE.Vector3, d1: THREE.Vector3, d2: THREE.Vector3, size: number) => {
    const pos = geom.attributes.position.array as Float32Array;
    const p1 = new THREE.Vector3().addVectors(v, d1.clone().multiplyScalar(size));
    const p2 = new THREE.Vector3().addVectors(p1, d2.clone().multiplyScalar(size));
    const p3 = new THREE.Vector3().addVectors(v, d2.clone().multiplyScalar(size));

    pos[0] = p1.x - v.x; pos[1] = p1.y - v.y; pos[2] = 0;
    pos[3] = p2.x - v.x; pos[4] = p2.y - v.y; pos[5] = 0;
    pos[6] = p3.x - v.x; pos[7] = p3.y - v.y; pos[8] = 0;
    
    geom.setDrawRange(0, 3);
    geom.attributes.position.needsUpdate = true;
};

const updateTick = (geom: THREE.BufferGeometry, p1: THREE.Vector3, p2: THREE.Vector3, count: number) => {
    const pos = geom.attributes.position.array as Float32Array;
    const dir = new THREE.Vector3().subVectors(p2, p1).normalize();
    const perp = new THREE.Vector3(-dir.y, dir.x, 0);
    const mid = new THREE.Vector3().addVectors(p1, p2).multiplyScalar(0.5);
    const size = 0.25;
    const gap = 0.2;

    if (count === 1) {
        const top = mid.clone().add(perp.clone().multiplyScalar(size));
        const bot = mid.clone().sub(perp.clone().multiplyScalar(size));
        pos.set([top.x, top.y, 0, bot.x, bot.y, 0]);
        geom.setDrawRange(0, 2);
    } else {
        const m1 = mid.clone().add(dir.clone().multiplyScalar(gap/2));
        const m2 = mid.clone().sub(dir.clone().multiplyScalar(gap/2));
        const t1 = m1.clone().add(perp.clone().multiplyScalar(size));
        const b1 = m1.clone().sub(perp.clone().multiplyScalar(size));
        const t2 = m2.clone().add(perp.clone().multiplyScalar(size));
        const b2 = m2.clone().sub(perp.clone().multiplyScalar(size));
        pos.set([t1.x, t1.y, 0, b1.x, b1.y, 0, t2.x, t2.y, 0, b2.x, b2.y, 0]);
        geom.setDrawRange(0, 4);
    }
    geom.attributes.position.needsUpdate = true;
};

const updateLine = (mesh: THREE.Mesh, p1: THREE.Vector3, p2: THREE.Vector3) => {
    const dist = p1.distanceTo(p2);
    mesh.scale.y = dist;
    mesh.position.copy(p1).lerp(p2, 0.5);
    mesh.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), p2.clone().sub(p1).normalize());
};

// ---------------------------------------------------------
// 3D SCENE
// ---------------------------------------------------------

function Scene({ isRectUpgrade, isRhombUpgrade }: { isRectUpgrade: boolean, isRhombUpgrade: boolean }) {
    const { camera } = useThree();
    
    // Target parameters
    const targetLegLen = isRhombUpgrade ? 8.0 : 5.0;
    const targetTiltDeg = isRectUpgrade ? 90 : 60;
    
    // Internal animation proxy
    const proxy = useRef({ legLen: targetLegLen, tiltRad: targetTiltDeg * Math.PI / 180 }).current;
    
    const baseLen = 8.0;
    const pA = useMemo(() => new THREE.Vector3(-4, -4, 0), []);
    const pB = useMemo(() => new THREE.Vector3(4, -4, 0), []);

    // Refs
    const lineABRef = useRef<THREE.Mesh>(null);
    const lineBCRef = useRef<THREE.Mesh>(null);
    const lineCDRef = useRef<THREE.Mesh>(null);
    const lineDARef = useRef<THREE.Mesh>(null);
    const diagACRef = useRef<THREE.Mesh>(null);
    const diagBDRef = useRef<THREE.Mesh>(null);
    
    const ptC = useRef<THREE.Group>(null);
    const ptD = useRef<THREE.Group>(null);
    const ptM = useRef<THREE.Group>(null);

    // Text Refs
    const txtAB = useRef<any>(null);
    const txtDA = useRef<any>(null);
    const txtAC = useRef<any>(null);
    const txtBD = useRef<any>(null);

    const txtAngM = useRef<any>(null);
    const txtAngA = useRef<any>(null);
    const txtAngC = useRef<any>(null);

    // Geometries
    const makeBuffer = (size: number) => {
        const g = new THREE.BufferGeometry();
        g.setAttribute('position', new THREE.BufferAttribute(new Float32Array(size * 3), 3));
        return g;
    };

    const sqM = useMemo(() => makeBuffer(3), []);
    const arcM = useMemo(() => makeBuffer(65), []);
    
    const sqA = useMemo(() => makeBuffer(3), []);
    const sqB = useMemo(() => makeBuffer(3), []);
    const sqC = useMemo(() => makeBuffer(3), []);
    const sqD = useMemo(() => makeBuffer(3), []);
    
    const arcA = useMemo(() => makeBuffer(65), []);
    const arcB = useMemo(() => makeBuffer(65), []);
    const arcC = useMemo(() => makeBuffer(65), []);
    const arcD = useMemo(() => makeBuffer(65), []);
    
    const tickAB = useMemo(() => makeBuffer(4), []);
    const tickBC = useMemo(() => makeBuffer(4), []);
    const tickCD = useMemo(() => makeBuffer(4), []);
    const tickDA = useMemo(() => makeBuffer(4), []);

    const matSqMRef = useRef<THREE.LineBasicMaterial>(null);
    const matArcMRef = useRef<THREE.LineBasicMaterial>(null);
    const matSqCornerRef = useRef<THREE.LineBasicMaterial>(null);
    const matArcCornerRef = useRef<THREE.LineBasicMaterial>(null);

    const matTickBCRef = useRef<THREE.LineBasicMaterial>(null);
    const matTickDARef = useRef<THREE.LineBasicMaterial>(null);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 1.0, z: 18, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    useFrame(() => {
        // Tween
        proxy.legLen = THREE.MathUtils.lerp(proxy.legLen, targetLegLen, 0.1);
        const targetRad = targetTiltDeg * Math.PI / 180;
        proxy.tiltRad = THREE.MathUtils.lerp(proxy.tiltRad, targetRad, 0.1);

        const L = proxy.legLen;
        const T = proxy.tiltRad;

        const pD = new THREE.Vector3(pA.x + L * Math.cos(T), pA.y + L * Math.sin(T), 0);
        const pC = new THREE.Vector3(pD.x + baseLen, pD.y, 0);
        const pM = new THREE.Vector3().addVectors(pA, pC).multiplyScalar(0.5);

        if (ptD.current) ptD.current.position.copy(pD);
        if (ptC.current) ptC.current.position.copy(pC);
        if (ptM.current) ptM.current.position.copy(pM);

        // Lines
        if (lineABRef.current) updateLine(lineABRef.current, pA, pB);
        if (lineBCRef.current) updateLine(lineBCRef.current, pB, pC);
        if (lineCDRef.current) updateLine(lineCDRef.current, pC, pD);
        if (lineDARef.current) updateLine(lineDARef.current, pD, pA);
        
        if (diagACRef.current) updateLine(diagACRef.current, pA, pC);
        if (diagBDRef.current) updateLine(diagBDRef.current, pB, pD);

        // Length texts
        if (txtAB.current) {
            txtAB.current.position.set(0, pA.y - 1.0, 0);
            txtAB.current.text = `AB = 8.0 cm`;
        }
        if (txtDA.current) {
            const dir = new THREE.Vector3().subVectors(pD, pA).normalize();
            const perp = new THREE.Vector3(-dir.y, dir.x, 0);
            const mid = new THREE.Vector3().addVectors(pA, pD).multiplyScalar(0.5);
            txtDA.current.position.copy(mid.add(perp.multiplyScalar(1.2)));
            txtDA.current.text = `AD = ${L.toFixed(1)} cm`;
        }
        
        const lenAC = pA.distanceTo(pC);
        const lenBD = pB.distanceTo(pD);

        if (txtAC.current) {
            const dir = new THREE.Vector3().subVectors(pC, pA).normalize();
            txtAC.current.position.copy(pA.clone().add(dir.multiplyScalar(lenAC*0.8)).add(new THREE.Vector3(0.5, -0.6, 0)));
            txtAC.current.text = `AC = ${lenAC.toFixed(1)}`;
        }
        if (txtBD.current) {
            const dir = new THREE.Vector3().subVectors(pD, pB).normalize();
            txtBD.current.position.copy(pB.clone().add(dir.multiplyScalar(lenBD*0.8)).add(new THREE.Vector3(-0.5, -0.6, 0)));
            txtBD.current.text = `BD = ${lenBD.toFixed(1)}`;
        }

        const isRhombus = Math.abs(L - baseLen) < 0.1;
        const isRect = Math.abs(T - Math.PI/2) < 0.05;

        // Ticks
        updateTick(tickAB, pA, pB, 1);
        updateTick(tickCD, pC, pD, 1);
        updateTick(tickDA, pA, pD, isRhombus ? 1 : 2);
        updateTick(tickBC, pB, pC, isRhombus ? 1 : 2);

        if (matTickBCRef.current && matTickDARef.current) {
            const color = isRhombus ? 0xfacc15 : 0xa855f7;
            matTickBCRef.current.color.setHex(color);
            matTickDARef.current.color.setHex(color);
        }

        // Intersection Angle at M
        const vMD = new THREE.Vector3().subVectors(pD, pM).normalize();
        const vMC = new THREE.Vector3().subVectors(pC, pM).normalize();
        let aC = Math.atan2(vMC.y, vMC.x);
        let aD = Math.atan2(vMD.y, vMD.x);
        if (aC < 0) aC += 2*Math.PI;
        if (aD < 0) aD += 2*Math.PI;

        let sM = Math.min(aC, aD);
        let eM = Math.max(aC, aD);
        if (eM - sM > Math.PI) {
            const temp = sM; sM = eM; eM = temp + 2*Math.PI;
        }

        const intersectDeg = Math.abs((eM - sM) * 180 / Math.PI);
        const isPerp = Math.abs(intersectDeg - 90) < 2;

        if (matSqMRef.current) matSqMRef.current.opacity = isPerp ? 0.9 : 0;
        if (matArcMRef.current) matArcMRef.current.opacity = isPerp ? 0 : 0.8;

        if (isPerp) {
            updateSquareGeom(sqM, pM, vMC, vMD, 0.7);
        } else {
            updateArcGeom(arcM, sM, eM, 1.2);
        }

        if (txtAngM.current) {
            const mid = (sM + eM) / 2;
            txtAngM.current.position.set(pM.x + Math.cos(mid)*1.8, pM.y + Math.sin(mid)*1.8, 0);
            txtAngM.current.text = `${intersectDeg.toFixed(1)}°`;
            txtAngM.current.color = isPerp ? "#22c55e" : "#ef4444";
        }

        // Corner Angles
        if (matSqCornerRef.current) matSqCornerRef.current.opacity = isRect ? 0.9 : 0;
        if (matArcCornerRef.current) matArcCornerRef.current.opacity = isRect ? 0 : 0.8;

        if (isRect) {
            updateSquareGeom(sqA, pA, new THREE.Vector3(1,0,0), new THREE.Vector3(0,1,0), 0.8);
            updateSquareGeom(sqB, pB, new THREE.Vector3(-1,0,0), new THREE.Vector3(0,1,0), 0.8);
            updateSquareGeom(sqC, pC, new THREE.Vector3(-1,0,0), new THREE.Vector3(0,-1,0), 0.8);
            updateSquareGeom(sqD, pD, new THREE.Vector3(1,0,0), new THREE.Vector3(0,-1,0), 0.8);
        } else {
            updateArcGeom(arcA, 0, T, 1.5);
            updateArcGeom(arcB, T, Math.PI, 1.5);
            updateArcGeom(arcC, Math.PI, Math.PI + T, 1.5);
            updateArcGeom(arcD, Math.PI + T, 2*Math.PI, 1.5);
        }

        if (txtAngA.current) {
            const mid = T / 2;
            txtAngA.current.position.set(pA.x + Math.cos(mid)*2.0, pA.y + Math.sin(mid)*2.0, 0);
            txtAngA.current.text = isRect ? "" : `${Math.round(T * 180 / Math.PI)}°`;
        }
        if (txtAngC.current) {
            const mid = Math.PI + T / 2;
            txtAngC.current.position.set(pC.x + Math.cos(mid)*2.0, pC.y + Math.sin(mid)*2.0, 0);
            txtAngC.current.text = isRect ? "" : `${Math.round(T * 180 / Math.PI)}°`;
        }

    });

    return (
        <group position={[0, -0.5, 0]}>
            <ambientLight intensity={0.6} />
            <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow />
            <Environment preset="city" opacity={0.3} />
            
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[60, 60]} />
                <meshStandardMaterial color="#020617" />
            </mesh>
            <gridHelper args={[60, 60, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* Perimeter */}
            <mesh ref={lineABRef} castShadow><cylinderGeometry args={[0.06, 0.06, 1]} /><meshStandardMaterial color="#94a3b8" roughness={0.3} /></mesh>
            <mesh ref={lineBCRef} castShadow><cylinderGeometry args={[0.06, 0.06, 1]} /><meshStandardMaterial color="#94a3b8" roughness={0.3} /></mesh>
            <mesh ref={lineCDRef} castShadow><cylinderGeometry args={[0.06, 0.06, 1]} /><meshStandardMaterial color="#94a3b8" roughness={0.3} /></mesh>
            <mesh ref={lineDARef} castShadow><cylinderGeometry args={[0.06, 0.06, 1]} /><meshStandardMaterial color="#94a3b8" roughness={0.3} /></mesh>

            {/* Diagonals */}
            <mesh ref={diagACRef}>
                <cylinderGeometry args={[0.03, 0.03, 1]} />
                <meshStandardMaterial color="#0ea5e9" roughness={0.3} />
            </mesh>
            <mesh ref={diagBDRef}>
                <cylinderGeometry args={[0.03, 0.03, 1]} />
                <meshStandardMaterial color="#d946ef" roughness={0.3} />
            </mesh>

            {/* Geometry Markers */}
            <lineSegments geometry={tickAB} position={[0,0,0.05]}><lineBasicMaterial color="#facc15" linewidth={5} /></lineSegments>
            <lineSegments geometry={tickCD} position={[0,0,0.05]}><lineBasicMaterial color="#facc15" linewidth={5} /></lineSegments>
            <lineSegments geometry={tickBC} position={[0,0,0.05]}><lineBasicMaterial ref={matTickBCRef} color="#a855f7" linewidth={5} /></lineSegments>
            <lineSegments geometry={tickDA} position={[0,0,0.05]}><lineBasicMaterial ref={matTickDARef} color="#a855f7" linewidth={5} /></lineSegments>

            {/* Vertices */}
            <group position={[-4, -4, 0]}>
                <Sphere args={[0.2, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.6, -0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">A</Text>
            </group>
            <group position={[4, -4, 0]}>
                <Sphere args={[0.2, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0.6, -0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">B</Text>
            </group>
            <group ref={ptC}>
                <Sphere args={[0.2, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0.6, 0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">C</Text>
            </group>
            <group ref={ptD}>
                <Sphere args={[0.2, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.6, 0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">D</Text>
            </group>
            <group ref={ptM}>
                <Sphere args={[0.15, 16, 16]}><meshStandardMaterial color="#f8fafc" /></Sphere>
                <Text position={[0, -0.5, 0]} fontSize={0.4} color="#f8fafc" fontWeight="bold">M</Text>
            </group>

            {/* Labels */}
            <Text ref={txtAB} fontSize={0.5} color="#facc15" fontWeight="bold" />
            <Text ref={txtDA} fontSize={0.5} color="#a855f7" fontWeight="bold" />
            
            <Text ref={txtAC} fontSize={0.4} color="#0ea5e9" fontWeight="bold" />
            <Text ref={txtBD} fontSize={0.4} color="#d946ef" fontWeight="bold" />

            {/* Intersection M Angles / Squares */}
            <lineBasicMaterial ref={matSqMRef} color="#22c55e" linewidth={5} transparent />
            <lineBasicMaterial ref={matArcMRef} color="#ef4444" linewidth={5} transparent />

            <line geometry={sqM} position={[0, 0, 0.05]} material={matSqMRef.current || undefined} />
            <line geometry={arcM} position={[0, 0, 0.05]} material={matArcMRef.current || undefined} />
            <Text ref={txtAngM} fontSize={0.5} color="#ef4444" fontWeight="bold" />

            {/* Corner Angles */}
            <lineBasicMaterial ref={matSqCornerRef} color="#22c55e" linewidth={5} transparent />
            <lineBasicMaterial ref={matArcCornerRef} color="#3b82f6" linewidth={4} transparent />

            <line geometry={sqA} position={[-4, -4, 0.05]} material={matSqCornerRef.current || undefined} />
            <line geometry={sqB} position={[4, -4, 0.05]} material={matSqCornerRef.current || undefined} />
            <group ref={ptC}><line geometry={sqC} position={[0, 0, 0.05]} material={matSqCornerRef.current || undefined} /></group>
            <group ref={ptD}><line geometry={sqD} position={[0, 0, 0.05]} material={matSqCornerRef.current || undefined} /></group>

            <line geometry={arcA} position={[-4, -4, 0.05]} material={matArcCornerRef.current || undefined} />
            <line geometry={arcB} position={[4, -4, 0.05]} material={matArcCornerRef.current || undefined} />
            <group ref={ptC}><line geometry={arcC} position={[0, 0, 0.05]} material={matArcCornerRef.current || undefined} /></group>
            <group ref={ptD}><line geometry={arcD} position={[0, 0, 0.05]} material={matArcCornerRef.current || undefined} /></group>

            <Text ref={txtAngA} fontSize={0.5} color="#3b82f6" fontWeight="bold" />
            <Text ref={txtAngC} fontSize={0.5} color="#3b82f6" fontWeight="bold" />
        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function DualUpgradeChamber() {
    const [isRectUpgrade, setIsRectUpgrade] = useState(false);
    const [isRhombUpgrade, setIsRhombUpgrade] = useState(false);

    const isSquare = isRectUpgrade && isRhombUpgrade;

    const renderMath = () => {
        if (isSquare) {
            return <BlockMath math={`\\text{Rectangle} \\cap \\text{Rhombus} \\implies \\textbf{Square}`} />;
        }
        if (isRectUpgrade) {
            return <BlockMath math={`\\text{Parallelogram} + \\angle 90^\\circ = \\text{Rectangle}`} />;
        }
        if (isRhombUpgrade) {
            return <BlockMath math={`\\text{Parallelogram} + \\text{Adj. Sides} = \\text{Rhombus}`} />;
        }
        return <BlockMath math={`\\text{General Parallelogram}`} />;
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col lg:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            The Dual Upgrade Chamber
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Creating the ultimate quadrilateral: The Square.
                        </p>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl min-w-[360px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-all duration-500 ${isSquare ? 'border-cyan-500/50' : 'border-slate-800'}`}>
                        <div className={`text-lg px-4 flex items-center justify-center h-full min-h-[4rem] transition-colors ${isSquare ? 'text-cyan-400' : 'text-slate-300'}`}>
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
                        <Scene isRectUpgrade={isRectUpgrade} isRhombUpgrade={isRhombUpgrade} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                <div className={`flex-1 pointer-events-auto max-w-md w-full transition-all duration-500`}>
                    <div className={`border-l-4 border-t border-r border-b border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md bg-slate-900/90 ${isSquare ? 'border-l-cyan-500 text-cyan-200 bg-cyan-900/30' : 'border-l-rose-500 text-rose-300 bg-rose-900/40'}`}>
                        <h3 className="font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide">
                            {isSquare ? <Shapes size={18}/> : <ShieldAlert size={18}/>} 
                            {isSquare ? "The Square: Ultimate Form" : "The Single Upgrade Myth"}
                        </h3>
                        <p className="text-sm leading-relaxed drop-shadow-md text-slate-300">
                            {isSquare ? 
                                <><b>SUCCESS:</b> By applying BOTH the Rectangle upgrade (90° corners, equal diagonals) and the Rhombus upgrade (equal sides, perpendicular diagonals), the shape morphs into a perfect <b>Square</b>. A square must inherit all properties from both parent lineages simultaneously.</> :
                                <><b>CRITICAL MISCONCEPTION:</b> Students often assume making a parallelogram's sides equal automatically makes it a square, or giving it 90° corners automatically makes it a square. This is false! A single upgrade only creates a Rhombus OR a Rectangle. You must activate BOTH cards to form a Square.</>}
                        </p>
                    </div>
                </div>

                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
                    
                    <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-3 flex items-center gap-2">
                        <Frame size={14} /> Quadrilateral Upgrade Cards
                    </label>

                    <div className="flex flex-col gap-3">
                        <button 
                            onClick={() => setIsRectUpgrade(!isRectUpgrade)} 
                            className={`p-4 rounded-xl flex items-center justify-between transition-all border text-sm font-bold shadow-sm ${
                                isRectUpgrade ? 'bg-blue-600/20 border-blue-500/50 text-blue-400' : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700'
                            }`}
                        >
                            <span className="flex items-center gap-2"><SplitSquareHorizontal size={18} /> Rectangle Upgrade</span>
                            <span className="text-[10px] uppercase bg-slate-950/50 px-2 py-1 rounded">One 90° Angle</span>
                        </button>

                        <button 
                            onClick={() => setIsRhombUpgrade(!isRhombUpgrade)} 
                            className={`p-4 rounded-xl flex items-center justify-between transition-all border text-sm font-bold shadow-sm ${
                                isRhombUpgrade ? 'bg-green-600/20 border-green-500/50 text-green-400' : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700'
                            }`}
                        >
                            <span className="flex items-center gap-2"><Maximize size={18} /> Rhombus Upgrade</span>
                            <span className="text-[10px] uppercase bg-slate-950/50 px-2 py-1 rounded">Adj. Sides Equal</span>
                        </button>
                    </div>

                    {isSquare && (
                        <div className="mt-4 bg-cyan-900/20 border border-cyan-800/50 p-3 rounded-xl text-center">
                            <span className="text-cyan-400 font-bold uppercase tracking-widest text-xs flex items-center justify-center gap-2">
                                <Shapes size={14} /> Square Generated
                            </span>
                        </div>
                    )}
                    
                </div>
            </div>
        </div>
    );
}
