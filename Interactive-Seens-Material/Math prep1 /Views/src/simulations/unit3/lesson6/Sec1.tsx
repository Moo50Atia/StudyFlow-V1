import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Maximize2, GitCompare, Shapes, Layers } from 'lucide-react';

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

const updateLine = (mesh: THREE.Mesh, p1: THREE.Vector3, p2: THREE.Vector3) => {
    const dist = p1.distanceTo(p2);
    mesh.scale.y = dist;
    mesh.position.copy(p1).lerp(p2, 0.5);
    mesh.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), p2.clone().sub(p1).normalize());
};

const updateChevron = (geom: THREE.BufferGeometry, p1: THREE.Vector3, p2: THREE.Vector3, count: number) => {
    const pos = geom.attributes.position.array as Float32Array;
    const dir = new THREE.Vector3().subVectors(p2, p1).normalize();
    const perp = new THREE.Vector3(-dir.y, dir.x, 0);
    const mid = new THREE.Vector3().addVectors(p1, p2).multiplyScalar(0.5);
    const size = 0.3;
    const w = 0.25; 

    if (count === 1) {
        const tip = mid.clone();
        const left = mid.clone().sub(dir.clone().multiplyScalar(size)).add(perp.clone().multiplyScalar(w));
        const right = mid.clone().sub(dir.clone().multiplyScalar(size)).sub(perp.clone().multiplyScalar(w));
        pos.set([left.x, left.y, 0, tip.x, tip.y, 0, tip.x, tip.y, 0, right.x, right.y, 0]);
        geom.setDrawRange(0, 4);
    } else {
        const tip1 = mid.clone().add(dir.clone().multiplyScalar(0.15));
        const tip2 = mid.clone().sub(dir.clone().multiplyScalar(0.15));
        const l1 = tip1.clone().sub(dir.clone().multiplyScalar(size)).add(perp.clone().multiplyScalar(w));
        const r1 = tip1.clone().sub(dir.clone().multiplyScalar(size)).sub(perp.clone().multiplyScalar(w));
        const l2 = tip2.clone().sub(dir.clone().multiplyScalar(size)).add(perp.clone().multiplyScalar(w));
        const r2 = tip2.clone().sub(dir.clone().multiplyScalar(size)).sub(perp.clone().multiplyScalar(w));
        pos.set([
            l1.x, l1.y, 0, tip1.x, tip1.y, 0, tip1.x, tip1.y, 0, r1.x, r1.y, 0,
            l2.x, l2.y, 0, tip2.x, tip2.y, 0, tip2.x, tip2.y, 0, r2.x, r2.y, 0
        ]);
        geom.setDrawRange(0, 8);
    }
    geom.attributes.position.needsUpdate = true;
};

const updateTick = (geom: THREE.BufferGeometry, p1: THREE.Vector3, p2: THREE.Vector3, count: number) => {
    const pos = geom.attributes.position.array as Float32Array;
    const dir = new THREE.Vector3().subVectors(p2, p1).normalize();
    const perp = new THREE.Vector3(-dir.y, dir.x, 0);
    const mid = new THREE.Vector3().addVectors(p1, p2).multiplyScalar(0.5);
    const size = 0.2;

    if (count === 1) {
        const top = mid.clone().add(perp.clone().multiplyScalar(size));
        const bot = mid.clone().sub(perp.clone().multiplyScalar(size));
        pos.set([top.x, top.y, 0, bot.x, bot.y, 0]);
        geom.setDrawRange(0, 2);
    }
    geom.attributes.position.needsUpdate = true;
};

// ---------------------------------------------------------
// 3D SCENE
// ---------------------------------------------------------

function Scene({ angleDeg, mode }: { angleDeg: number, mode: 'para' | 'trap' }) {
    const { camera } = useThree();
    
    // Internal animation proxy
    const proxy = useRef({ dxC: 0, dxD: 0 }).current;
    
    const h = 6.0;
    const w = 8.0;
    const pA = useMemo(() => new THREE.Vector3(-4, -3, 0), []);
    const pB = useMemo(() => new THREE.Vector3(4, -3, 0), []);

    // Refs
    const lineABRef = useRef<THREE.Mesh>(null);
    const lineBCRef = useRef<THREE.Mesh>(null);
    const lineCDRef = useRef<THREE.Mesh>(null);
    const lineDARef = useRef<THREE.Mesh>(null);
    const diagACRef = useRef<THREE.Mesh>(null);
    const diagBDRef = useRef<THREE.Mesh>(null);
    
    const ptC = useRef<THREE.Group>(null);
    const ptD = useRef<THREE.Group>(null);

    // Text Refs
    const txtAC = useRef<any>(null);
    const txtBD = useRef<any>(null);
    const txtD = useRef<any>(null);
    const txtC = useRef<any>(null);

    // Geometries
    const makeBuffer = (size: number) => {
        const g = new THREE.BufferGeometry();
        g.setAttribute('position', new THREE.BufferAttribute(new Float32Array(size * 3), 3));
        return g;
    };

    const sqA = useMemo(() => makeBuffer(3), []);
    const sqB = useMemo(() => makeBuffer(3), []);
    const sqC = useMemo(() => makeBuffer(3), []);
    const sqD = useMemo(() => makeBuffer(3), []);
    
    const arcA = useMemo(() => makeBuffer(65), []);
    const arcB = useMemo(() => makeBuffer(65), []);

    const chevTop = useMemo(() => makeBuffer(8), []);
    const chevBot = useMemo(() => makeBuffer(8), []);
    const chevL = useMemo(() => makeBuffer(4), []);
    const chevR = useMemo(() => makeBuffer(4), []);
    
    const tickL = useMemo(() => makeBuffer(2), []);
    const tickR = useMemo(() => makeBuffer(2), []);

    const matSqRef = useRef<THREE.LineBasicMaterial>(null);
    const matArcRef = useRef<THREE.LineBasicMaterial>(null);
    const matDiagACRef = useRef<THREE.MeshStandardMaterial>(null);
    const matDiagBDRef = useRef<THREE.MeshStandardMaterial>(null);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 0.5, z: 15, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    useFrame(() => {
        // Target calculation
        const rad = angleDeg * Math.PI / 180;
        const targetDxD = h / Math.tan(rad);
        const targetDxC = mode === 'para' ? targetDxD : -targetDxD;

        proxy.dxD = THREE.MathUtils.lerp(proxy.dxD, targetDxD, 0.1);
        proxy.dxC = THREE.MathUtils.lerp(proxy.dxC, targetDxC, 0.1);

        const pD = new THREE.Vector3(-4 + proxy.dxD, 3, 0);
        const pC = new THREE.Vector3(4 + proxy.dxC, 3, 0);

        if (ptD.current) ptD.current.position.copy(pD);
        if (ptC.current) ptC.current.position.copy(pC);

        if (txtD.current) txtD.current.position.set(-0.5, 0.5, 0);
        if (txtC.current) txtC.current.position.set(0.5, 0.5, 0);

        // Lines
        if (lineABRef.current) updateLine(lineABRef.current, pA, pB);
        if (lineBCRef.current) updateLine(lineBCRef.current, pB, pC);
        if (lineCDRef.current) updateLine(lineCDRef.current, pC, pD);
        if (lineDARef.current) updateLine(lineDARef.current, pD, pA);
        
        if (diagACRef.current) updateLine(diagACRef.current, pA, pC);
        if (diagBDRef.current) updateLine(diagBDRef.current, pB, pD);

        // Calculate actual current angles
        const vDA = new THREE.Vector3().subVectors(pD, pA).normalize();
        const vBA = new THREE.Vector3().subVectors(pB, pA).normalize();
        const aRad = Math.acos(vDA.dot(vBA));
        const aDeg = Math.round(aRad * 180 / Math.PI);
        const isRect = Math.abs(aDeg - 90) < 1 && mode === 'para';

        // Update squares and arcs
        if (matSqRef.current) matSqRef.current.opacity = isRect ? 0.9 : 0;
        if (matArcRef.current) matArcRef.current.opacity = isRect ? 0 : 0.8;

        if (isRect) {
            updateSquareGeom(sqA, pA, new THREE.Vector3(1,0,0), new THREE.Vector3(0,1,0), 0.8);
            updateSquareGeom(sqB, pB, new THREE.Vector3(-1,0,0), new THREE.Vector3(0,1,0), 0.8);
            updateSquareGeom(sqC, pC, new THREE.Vector3(-1,0,0), new THREE.Vector3(0,-1,0), 0.8);
            updateSquareGeom(sqD, pD, new THREE.Vector3(1,0,0), new THREE.Vector3(0,-1,0), 0.8);
        } else {
            updateArcGeom(arcA, 0, aRad, 1.2);
            const vCB = new THREE.Vector3().subVectors(pC, pB).normalize();
            const vAB_rev = new THREE.Vector3().subVectors(pA, pB).normalize();
            let bRadStart = Math.atan2(vCB.y, vCB.x);
            if (bRadStart < 0) bRadStart += 2*Math.PI;
            updateArcGeom(arcB, bRadStart, Math.PI, 1.2);
        }

        // Diagonals Lengths
        const lenAC = pA.distanceTo(pC);
        const lenBD = pB.distanceTo(pD);
        
        if (txtAC.current) {
            const dirAC = new THREE.Vector3().subVectors(pC, pA).normalize();
            txtAC.current.position.copy(pA.clone().add(dirAC.multiplyScalar(lenAC*0.25)).add(new THREE.Vector3(-0.8, 0.4, 0)));
            txtAC.current.text = `AC = ${lenAC.toFixed(1)}`;
        }
        if (txtBD.current) {
            const dirBD = new THREE.Vector3().subVectors(pD, pB).normalize();
            txtBD.current.position.copy(pB.clone().add(dirBD.multiplyScalar(lenBD*0.25)).add(new THREE.Vector3(0.8, 0.4, 0)));
            txtBD.current.text = `BD = ${lenBD.toFixed(1)}`;
        }

        // Color coding for diagonals based on equality
        const diagsEqual = Math.abs(lenAC - lenBD) < 0.1;
        if (matDiagACRef.current && matDiagBDRef.current) {
            const color = diagsEqual ? 0xfacc15 : 0x0ea5e9;
            matDiagACRef.current.color.setHex(color);
            matDiagACRef.current.emissive.setHex(color);
            const colorBD = diagsEqual ? 0xfacc15 : 0xd946ef;
            matDiagBDRef.current.color.setHex(colorBD);
            matDiagBDRef.current.emissive.setHex(colorBD);
        }

        // Markers (Chevrons / Ticks) based on Mode
        updateChevron(chevBot, pA, pB, 2);
        updateChevron(chevTop, pD, pC, 2);
        
        if (mode === 'para') {
            updateChevron(chevL, pA, pD, 1);
            updateChevron(chevR, pB, pC, 1);
            // Hide ticks
            updateTick(tickL, pA, pA, 1);
            updateTick(tickR, pB, pB, 1);
        } else {
            // Hide side chevrons
            updateChevron(chevL, pA, pA, 1);
            updateChevron(chevR, pB, pB, 1);
            // Show side ticks
            updateTick(tickL, pA, pD, 1);
            updateTick(tickR, pB, pC, 1);
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

            {/* Perimeter */}
            <mesh ref={lineABRef} castShadow><cylinderGeometry args={[0.05, 0.05, 1]} /><meshStandardMaterial color="#94a3b8" roughness={0.3} /></mesh>
            <mesh ref={lineBCRef} castShadow><cylinderGeometry args={[0.05, 0.05, 1]} /><meshStandardMaterial color="#94a3b8" roughness={0.3} /></mesh>
            <mesh ref={lineCDRef} castShadow><cylinderGeometry args={[0.05, 0.05, 1]} /><meshStandardMaterial color="#94a3b8" roughness={0.3} /></mesh>
            <mesh ref={lineDARef} castShadow><cylinderGeometry args={[0.05, 0.05, 1]} /><meshStandardMaterial color="#94a3b8" roughness={0.3} /></mesh>

            {/* Glowing Diagonals */}
            <mesh ref={diagACRef}>
                <cylinderGeometry args={[0.03, 0.03, 1]} />
                <meshStandardMaterial ref={matDiagACRef} color="#0ea5e9" roughness={0.3} emissive="#0ea5e9" emissiveIntensity={0.8} />
            </mesh>
            <mesh ref={diagBDRef}>
                <cylinderGeometry args={[0.03, 0.03, 1]} />
                <meshStandardMaterial ref={matDiagBDRef} color="#d946ef" roughness={0.3} emissive="#d946ef" emissiveIntensity={0.8} />
            </mesh>

            {/* Geometry Markers */}
            <lineSegments geometry={chevBot} position={[0,0,0.05]}><lineBasicMaterial color="#facc15" linewidth={4} /></lineSegments>
            <lineSegments geometry={chevTop} position={[0,0,0.05]}><lineBasicMaterial color="#facc15" linewidth={4} /></lineSegments>
            <lineSegments geometry={chevL} position={[0,0,0.05]}><lineBasicMaterial color="#f97316" linewidth={4} /></lineSegments>
            <lineSegments geometry={chevR} position={[0,0,0.05]}><lineBasicMaterial color="#f97316" linewidth={4} /></lineSegments>
            
            <lineSegments geometry={tickL} position={[0,0,0.05]}><lineBasicMaterial color="#22c55e" linewidth={4} /></lineSegments>
            <lineSegments geometry={tickR} position={[0,0,0.05]}><lineBasicMaterial color="#22c55e" linewidth={4} /></lineSegments>

            {/* Vertices */}
            <group position={[-4, -3, 0]}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.5, -0.5, 0]} fontSize={0.5} color="#ffffff" fontWeight="bold">A</Text>
            </group>
            <group position={[4, -3, 0]}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0.5, -0.5, 0]} fontSize={0.5} color="#ffffff" fontWeight="bold">B</Text>
            </group>
            <group ref={ptC}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text ref={txtC} fontSize={0.5} color="#ffffff" fontWeight="bold">C</Text>
            </group>
            <group ref={ptD}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text ref={txtD} fontSize={0.5} color="#ffffff" fontWeight="bold">D</Text>
            </group>

            {/* Labels */}
            <Text ref={txtAC} fontSize={0.45} color="#0ea5e9" fontWeight="bold" />
            <Text ref={txtBD} fontSize={0.45} color="#d946ef" fontWeight="bold" />

            {/* Angles / Squares */}
            <lineBasicMaterial ref={matSqRef} color="#22c55e" linewidth={5} transparent />
            <lineBasicMaterial ref={matArcRef} color="#3b82f6" linewidth={4} transparent />

            <line geometry={sqA} position={[-4, -3, 0.05]} material={matSqRef.current || undefined} />
            <line geometry={sqB} position={[4, -3, 0.05]} material={matSqRef.current || undefined} />
            <group ref={ptC}><line geometry={sqC} position={[0,0,0.05]} material={matSqRef.current || undefined} /></group>
            <group ref={ptD}><line geometry={sqD} position={[0,0,0.05]} material={matSqRef.current || undefined} /></group>

            <line geometry={arcA} position={[-4, -3, 0.05]} material={matArcRef.current || undefined} />
            <line geometry={arcB} position={[4, -3, 0.05]} material={matArcRef.current || undefined} />

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function RectangleTheorem() {
    const [angleDeg, setAngleDeg] = useState(65);
    const [mode, setMode] = useState<'para' | 'trap'>('para');

    const handleSliderChange = (val: number) => {
        let snapVal = val;
        // Snap to 90 (Rectangle)
        if (Math.abs(val - 90) <= 2) snapVal = 90;
        setAngleDeg(snapVal);
    };

    const isRect = angleDeg === 90 && mode === 'para';
    const isEqualDiags = angleDeg === 90 || mode === 'trap';

    const renderMath = () => {
        if (isRect) {
            return <BlockMath math={`\\text{Parallelogram} + \\text{Equal Diagonals} = \\textbf{Rectangle}`} />;
        }
        if (mode === 'trap') {
            return <BlockMath math={`\\text{Trapezium} + \\text{Equal Diagonals} \\neq \\text{Rectangle}`} />;
        }
        return <BlockMath math={`AC \\neq BD \\implies \\text{Standard Parallelogram}`} />;
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col lg:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            The Rectangle Theorem
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Do equal diagonals always guarantee a rectangle?
                        </p>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl min-w-[380px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-all duration-500 ${isEqualDiags ? 'border-yellow-500/50' : 'border-slate-800'}`}>
                        <div className={`text-lg px-4 flex items-center justify-center h-full min-h-[4rem] transition-colors ${isEqualDiags ? 'text-yellow-400' : 'text-slate-300'}`}>
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
                        <Scene angleDeg={angleDeg} mode={mode} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                <div className={`flex-1 pointer-events-auto max-w-md w-full transition-all duration-500`}>
                    <div className={`border-l-4 border-t border-r border-b border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md bg-slate-900/90 ${mode === 'trap' ? 'border-l-rose-500 text-rose-300 bg-rose-900/40' : 'border-l-blue-500 text-blue-300 bg-blue-900/40'}`}>
                        <h3 className="font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide">
                            {mode === 'trap' ? <ShieldAlert size={18}/> : <Shapes size={18}/>} 
                            {mode === 'trap' ? "The Equal Diagonals Myth" : "Parallelogram Constraint"}
                        </h3>
                        <p className="text-sm leading-relaxed drop-shadow-md text-slate-300">
                            {mode === 'trap' ? 
                                <><b>CRITICAL MISCONCEPTION:</b> Students often assume that ANY quadrilateral with equal diagonals is a rectangle. Look at the shape now! Its diagonals (AC and BD) illuminate yellow because they perfectly match in length, but the shape is heavily sloped. It is an Isosceles Trapezium, not a rectangle!</> :
                                <>For a shape to be a rectangle, equal diagonals are only half the requirement. The shape must <b>FIRST</b> be a parallelogram (having two sets of parallel sides). Try adjusting the interior angle slider to 90° to equalize the diagonals and form the true Rectangle.</>}
                        </p>
                    </div>
                </div>

                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
                    
                    <div className="mb-6">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <Maximize2 size={14} /> Interior Angle Tilt (A)
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className={isRect ? "text-green-400" : "text-blue-400"}>Angle = {angleDeg}°</span>
                            <span className="text-slate-400">{isRect ? 'Rectangle State' : (mode === 'trap' ? 'Trapezium State' : 'Parallelogram State')}</span>
                        </div>
                        <input 
                            type="range" 
                            min="50" max="130" step="1" 
                            value={angleDeg} onChange={(e) => handleSliderChange(parseFloat(e.target.value))} 
                            className={`w-full h-1.5 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 ${isRect ? 'accent-green-500' : 'accent-blue-500'}`}
                        />
                    </div>

                    <div className="border-t border-slate-800 pt-4 mb-2">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-3 flex items-center gap-2">
                            <GitCompare size={14} /> Base Shape Constraint
                        </label>
                        <div className="grid grid-cols-2 gap-3">
                            <button 
                                onClick={() => { setMode('para'); if(angleDeg===90) setAngleDeg(65); }} 
                                className={`font-bold py-2 px-3 rounded-xl flex items-center justify-center gap-2 transition-all border text-xs ${
                                    mode === 'para' ? 'bg-blue-600/20 border-blue-600/50 text-blue-400' : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700'
                                }`}
                            >
                                <Layers size={14} /> Parallelogram
                            </button>
                            <button 
                                onClick={() => { setMode('trap'); if(angleDeg===90) setAngleDeg(65); }} 
                                className={`font-bold py-2 px-3 rounded-xl flex items-center justify-center gap-2 transition-all border text-xs ${
                                    mode === 'trap' ? 'bg-rose-600/20 border-rose-600/50 text-rose-400' : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700'
                                }`}
                            >
                                <ShieldAlert size={14} /> Trap. (Equal Diags)
                            </button>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
