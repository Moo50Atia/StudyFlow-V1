import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, SplitSquareHorizontal, Shapes, Layers, Ruler } from 'lucide-react';

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

function Scene({ legLen, tiltDeg }: { legLen: number, tiltDeg: number }) {
    const { camera } = useThree();
    
    // Internal animation proxy
    const proxy = useRef({ legLen: legLen, tiltRad: tiltDeg * Math.PI / 180 }).current;
    
    const baseLen = 10.0;
    const pA = useMemo(() => new THREE.Vector3(-5, -4, 0), []);
    const pB = useMemo(() => new THREE.Vector3(5, -4, 0), []);

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
    const txtBC = useRef<any>(null);
    const txtCD = useRef<any>(null);
    const txtDA = useRef<any>(null);
    const txtAngM = useRef<any>(null);
    const txtAngA = useRef<any>(null);

    // Geometries
    const makeBuffer = (size: number) => {
        const g = new THREE.BufferGeometry();
        g.setAttribute('position', new THREE.BufferAttribute(new Float32Array(size * 3), 3));
        return g;
    };

    const sqM = useMemo(() => makeBuffer(3), []);
    const arcM = useMemo(() => makeBuffer(65), []);
    const arcA = useMemo(() => makeBuffer(65), []);
    
    const tickAB = useMemo(() => makeBuffer(4), []);
    const tickBC = useMemo(() => makeBuffer(4), []);
    const tickCD = useMemo(() => makeBuffer(4), []);
    const tickDA = useMemo(() => makeBuffer(4), []);

    const matSqRef = useRef<THREE.LineBasicMaterial>(null);
    const matArcRef = useRef<THREE.LineBasicMaterial>(null);
    const matTickBCRef = useRef<THREE.LineBasicMaterial>(null);
    const matTickDARef = useRef<THREE.LineBasicMaterial>(null);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 1.0, z: 20, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    useFrame(() => {
        proxy.legLen = THREE.MathUtils.lerp(proxy.legLen, legLen, 0.1);
        proxy.tiltRad = THREE.MathUtils.lerp(proxy.tiltRad, tiltDeg * Math.PI / 180, 0.1);

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

        // Side Texts
        if (txtAB.current) {
            txtAB.current.position.set(0, pA.y - 0.8, 0);
            txtAB.current.text = `AB = 10 cm`;
        }
        if (txtCD.current) {
            txtCD.current.position.set(pD.x + 5, pD.y + 0.8, 0);
            txtCD.current.text = `CD = 10 cm`;
        }
        if (txtDA.current) {
            const dir = new THREE.Vector3().subVectors(pD, pA).normalize();
            const perp = new THREE.Vector3(-dir.y, dir.x, 0);
            const mid = new THREE.Vector3().addVectors(pA, pD).multiplyScalar(0.5);
            txtDA.current.position.copy(mid.add(perp.multiplyScalar(1.2)));
            txtDA.current.text = `AD = ${L.toFixed(1)} cm`;
        }
        if (txtBC.current) {
            const dir = new THREE.Vector3().subVectors(pC, pB).normalize();
            const perp = new THREE.Vector3(dir.y, -dir.x, 0);
            const mid = new THREE.Vector3().addVectors(pB, pC).multiplyScalar(0.5);
            txtBC.current.position.copy(mid.add(perp.multiplyScalar(1.2)));
            txtBC.current.text = `BC = ${L.toFixed(1)} cm`;
        }

        const isRhombus = Math.abs(L - baseLen) < 0.1;

        // Ticks: Base AB and CD are always 1 tick. Leg AD and BC are 2 ticks unless Rhombus.
        updateTick(tickAB, pA, pB, 1);
        updateTick(tickCD, pC, pD, 1);
        updateTick(tickDA, pA, pD, isRhombus ? 1 : 2);
        updateTick(tickBC, pB, pC, isRhombus ? 1 : 2);

        if (matTickBCRef.current && matTickDARef.current) {
            const color = isRhombus ? 0xfacc15 : 0xa855f7; // Gold if equal, Purple if unequal
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
        const isPerp = Math.abs(intersectDeg - 90) < 1;

        if (matSqRef.current) matSqRef.current.opacity = isPerp ? 0.9 : 0;
        if (matArcRef.current) matArcRef.current.opacity = isPerp ? 0 : 0.8;

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

        // Interior Angle A Arc
        updateArcGeom(arcA, 0, T, 1.5);
        if (txtAngA.current) {
            const mid = T / 2;
            txtAngA.current.position.set(pA.x + Math.cos(mid)*2.2, pA.y + Math.sin(mid)*2.2, 0);
            txtAngA.current.text = `${Math.round(T * 180 / Math.PI)}°`;
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
            <mesh ref={lineABRef} castShadow><cylinderGeometry args={[0.06, 0.06, 1]} /><meshStandardMaterial color="#3b82f6" roughness={0.3} /></mesh>
            <mesh ref={lineBCRef} castShadow><cylinderGeometry args={[0.06, 0.06, 1]} /><meshStandardMaterial color="#3b82f6" roughness={0.3} /></mesh>
            <mesh ref={lineCDRef} castShadow><cylinderGeometry args={[0.06, 0.06, 1]} /><meshStandardMaterial color="#3b82f6" roughness={0.3} /></mesh>
            <mesh ref={lineDARef} castShadow><cylinderGeometry args={[0.06, 0.06, 1]} /><meshStandardMaterial color="#3b82f6" roughness={0.3} /></mesh>

            {/* Glowing Diagonals */}
            <mesh ref={diagACRef}>
                <cylinderGeometry args={[0.03, 0.03, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} emissive="#94a3b8" emissiveIntensity={0.5} />
            </mesh>
            <mesh ref={diagBDRef}>
                <cylinderGeometry args={[0.03, 0.03, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} emissive="#94a3b8" emissiveIntensity={0.5} />
            </mesh>

            {/* Geometry Markers */}
            <lineSegments geometry={tickAB} position={[0,0,0.05]}><lineBasicMaterial color="#facc15" linewidth={5} /></lineSegments>
            <lineSegments geometry={tickCD} position={[0,0,0.05]}><lineBasicMaterial color="#facc15" linewidth={5} /></lineSegments>
            <lineSegments geometry={tickBC} position={[0,0,0.05]}><lineBasicMaterial ref={matTickBCRef} color="#a855f7" linewidth={5} /></lineSegments>
            <lineSegments geometry={tickDA} position={[0,0,0.05]}><lineBasicMaterial ref={matTickDARef} color="#a855f7" linewidth={5} /></lineSegments>

            {/* Vertices */}
            <group position={[-5, -4, 0]}>
                <Sphere args={[0.2, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.6, -0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">A</Text>
            </group>
            <group position={[5, -4, 0]}>
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
            <Text ref={txtCD} fontSize={0.5} color="#facc15" fontWeight="bold" />
            <Text ref={txtBC} fontSize={0.5} color="#a855f7" fontWeight="bold" />
            <Text ref={txtDA} fontSize={0.5} color="#a855f7" fontWeight="bold" />

            {/* Angles / Squares */}
            <lineBasicMaterial ref={matSqRef} color="#22c55e" linewidth={5} transparent />
            <lineBasicMaterial ref={matArcRef} color="#ef4444" linewidth={5} transparent />

            <line geometry={sqM} position={[0, 0, 0.05]} material={matSqRef.current || undefined} />
            <line geometry={arcM} position={[0, 0, 0.05]} material={matArcRef.current || undefined} />
            <Text ref={txtAngM} fontSize={0.5} color="#ef4444" fontWeight="bold" />

            <line geometry={arcA} position={[-5, -4, 0.05]}><lineBasicMaterial color="#3b82f6" linewidth={4} /></line>
            <Text ref={txtAngA} fontSize={0.5} color="#3b82f6" fontWeight="bold" />

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function RhombusAngleTheorem() {
    const [legLen, setLegLen] = useState(6.0);
    const [tiltDeg, setTiltDeg] = useState(60);

    const handleLegSliderChange = (val: number) => {
        let snapVal = val;
        // Snap to 10 (Rhombus)
        if (Math.abs(val - 10.0) <= 0.4) snapVal = 10.0;
        setLegLen(snapVal);
    };

    const isRhombus = legLen === 10.0;
    const isSquare = isRhombus && tiltDeg === 90;

    const renderMath = () => {
        if (isSquare) {
            return <BlockMath math={`AB = AD = 10 \\implies AC \\perp BD \\; (\\text{Square})`} />;
        }
        if (isRhombus) {
            return <BlockMath math={`AB = AD = 10 \\implies AC \\perp BD \\; (\\text{Rhombus})`} />;
        }
        return <BlockMath math={`AB \\neq AD \\implies AC \\not\\perp BD \\; (\\text{Parallelogram})`} />;
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col lg:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            Rhombus Diagonals Theorem
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Does a rhombus need right-angled interior corners?
                        </p>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl min-w-[420px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-all duration-500 ${isRhombus ? 'border-green-500/50' : 'border-slate-800'}`}>
                        <div className={`text-lg px-4 flex items-center justify-center h-full min-h-[4rem] transition-colors ${isRhombus ? 'text-green-400' : 'text-slate-300'}`}>
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
                        <Scene legLen={legLen} tiltDeg={tiltDeg} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                <div className={`flex-1 pointer-events-auto max-w-md w-full transition-all duration-500`}>
                    <div className={`border-l-4 border-t border-r border-b border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md bg-slate-900/90 border-l-rose-500 text-rose-300 bg-rose-900/40`}>
                        <h3 className="font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide">
                            <ShieldAlert size={18}/> The Right Angle Misconception
                        </h3>
                        <p className="text-sm leading-relaxed drop-shadow-md text-slate-300">
                            <b>CRITICAL MISCONCEPTION:</b> Students often confuse a Rhombus with a Square, assuming a Rhombus must have 90° interior corners. Adjust the sliders to form a Rhombus (Side = 10cm). Notice the blue interior corner A remains acute ({tiltDeg}°)! The defining 90° right angle exists <b>exclusively at the diagonal intersection (M)</b>, completely independent of the outer corners.
                        </p>
                    </div>
                </div>

                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
                    
                    <div className="mb-4">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <Ruler size={14} /> Side Length (Leg AD / BC)
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className={isRhombus ? "text-green-400" : "text-purple-400"}>Leg = {legLen.toFixed(1)} cm</span>
                            <span className="text-slate-400">{isRhombus ? 'Rhombus State (All 10cm)' : 'Parallelogram State'}</span>
                        </div>
                        <input 
                            type="range" 
                            min="5" max="13" step="0.1" 
                            value={legLen} onChange={(e) => handleLegSliderChange(parseFloat(e.target.value))} 
                            className={`w-full h-1.5 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 ${isRhombus ? 'accent-green-500' : 'accent-purple-500'}`}
                        />
                    </div>

                    <div className="mb-6">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <SplitSquareHorizontal size={14} /> Interior Corner Tilt (Angle A)
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className="text-blue-400">Angle A = {tiltDeg}°</span>
                        </div>
                        <input 
                            type="range" 
                            min="45" max="135" step="1" 
                            value={tiltDeg} onChange={(e) => setTiltDeg(parseFloat(e.target.value))} 
                            className={`w-full h-1.5 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 accent-blue-500`}
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-3 mt-auto border-t border-slate-800 pt-4">
                        <button 
                            onClick={() => { setLegLen(6); setTiltDeg(60); }} 
                            className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
                        >
                            <Layers size={16} /> Reset Unequal
                        </button>
                        <button 
                            onClick={() => { setLegLen(10); setTiltDeg(60); }} 
                            className="bg-green-600/20 hover:bg-green-500/30 text-green-400 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-green-600/50 text-sm"
                        >
                            <Shapes size={16} /> Snap to Rhombus
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
