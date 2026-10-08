import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, SplitSquareHorizontal, ArrowRight, Shapes, Layers } from 'lucide-react';

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
    const size = 0.2;

    if (count === 1) {
        const top = mid.clone().add(perp.clone().multiplyScalar(size));
        const bot = mid.clone().sub(perp.clone().multiplyScalar(size));
        pos.set([
            top.x, top.y, 0, bot.x, bot.y, 0
        ]);
        geom.setDrawRange(0, 2);
    } else {
        const m1 = mid.clone().add(dir.clone().multiplyScalar(0.1));
        const m2 = mid.clone().sub(dir.clone().multiplyScalar(0.1));
        const t1 = m1.clone().add(perp.clone().multiplyScalar(size));
        const b1 = m1.clone().sub(perp.clone().multiplyScalar(size));
        const t2 = m2.clone().add(perp.clone().multiplyScalar(size));
        const b2 = m2.clone().sub(perp.clone().multiplyScalar(size));
        pos.set([
            t1.x, t1.y, 0, b1.x, b1.y, 0,
            t2.x, t2.y, 0, b2.x, b2.y, 0
        ]);
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

function Scene({ legLen }: { legLen: number }) {
    const { camera } = useThree();
    
    // Base AB maps to 8cm (visual length = 8)
    const baseLen = 8.0; 
    const tiltDeg = 70; // Fixed tilt for the parallelogram to show rhombus nicely
    
    const proxy = useRef({ legLen: legLen }).current;
    
    const pA = useMemo(() => new THREE.Vector3(-4, -3, 0), []);
    const pB = useMemo(() => new THREE.Vector3(-4 + baseLen, -3, 0), []);

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

    // Labels
    const txtAB = useRef<any>(null);
    const txtAD = useRef<any>(null);
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

    const tickAB = useMemo(() => makeBuffer(4), []);
    const tickBC = useMemo(() => makeBuffer(4), []);
    const tickCD = useMemo(() => makeBuffer(4), []);
    const tickDA = useMemo(() => makeBuffer(4), []);

    const arcM = useMemo(() => makeBuffer(65), []); 
    const sqM = useMemo(() => makeBuffer(3), []); 

    const arcA1 = useMemo(() => makeBuffer(65), []);
    const arcA2 = useMemo(() => makeBuffer(65), []);
    
    const txtAngA1 = useRef<any>(null);
    const txtAngA2 = useRef<any>(null);

    // Mats
    const matTickRef = useRef<THREE.LineBasicMaterial>(null);
    const matArcMRef = useRef<THREE.LineBasicMaterial>(null);
    const matSqMRef = useRef<THREE.LineBasicMaterial>(null);
    const matBisectRef = useRef<THREE.MeshStandardMaterial>(null);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 1.5, z: 14, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    useFrame(() => {
        proxy.legLen = THREE.MathUtils.lerp(proxy.legLen, legLen, 0.15);
        const L = proxy.legLen;
        const T = tiltDeg * Math.PI / 180;

        const pD = new THREE.Vector3(pA.x + L * Math.cos(T), pA.y + L * Math.sin(T), 0);
        const pC = new THREE.Vector3(pD.x + baseLen, pD.y, 0);
        const pM = new THREE.Vector3().addVectors(pA, pC).multiplyScalar(0.5);

        if (ptD.current) ptD.current.position.copy(pD);
        if (ptC.current) ptC.current.position.copy(pC);
        if (ptM.current) ptM.current.position.copy(pM);

        if (txtD.current) txtD.current.position.set(-0.5, 0.5, 0);
        if (txtC.current) txtC.current.position.set(0.5, 0.5, 0);

        // Lines
        if (lineABRef.current) updateLine(lineABRef.current, pA, pB);
        if (lineBCRef.current) updateLine(lineBCRef.current, pB, pC);
        if (lineCDRef.current) updateLine(lineCDRef.current, pC, pD);
        if (lineDARef.current) updateLine(lineDARef.current, pD, pA);
        
        if (diagACRef.current) updateLine(diagACRef.current, pA, pC);
        if (diagBDRef.current) updateLine(diagBDRef.current, pB, pD);

        // Diagonals Lengths
        const lenAC = pA.distanceTo(pC);
        const lenBD = pB.distanceTo(pD);
        
        if (txtAC.current) {
            const dirAC = new THREE.Vector3().subVectors(pC, pA).normalize();
            const pos = pA.clone().add(dirAC.multiplyScalar(lenAC/2 - 1)).add(new THREE.Vector3(-0.4, 0.4, 0));
            txtAC.current.position.copy(pos);
            txtAC.current.text = `AC = ${lenAC.toFixed(1)} cm`;
        }
        if (txtBD.current) {
            const dirBD = new THREE.Vector3().subVectors(pD, pB).normalize();
            const pos = pB.clone().add(dirBD.multiplyScalar(lenBD/2 - 1)).add(new THREE.Vector3(0.4, 0.4, 0));
            txtBD.current.position.copy(pos);
            txtBD.current.text = `BD = ${lenBD.toFixed(1)} cm`;
        }

        // Side Labels
        if (txtAB.current) {
            txtAB.current.position.set((pA.x + pB.x)/2, pA.y - 0.6, 0);
            txtAB.current.text = `AB = ${baseLen.toFixed(1)} cm`;
        }
        if (txtAD.current) {
            const midAD = new THREE.Vector3().addVectors(pA, pD).multiplyScalar(0.5);
            txtAD.current.position.set(midAD.x - 1.0, midAD.y, 0);
            txtAD.current.text = `AD = ${L.toFixed(1)} cm`;
        }

        const isRhombus = Math.abs(L - baseLen) < 0.1;

        // Ticks
        updateTick(tickAB, pA, pB, 1);
        updateTick(tickCD, pC, pD, 1);
        updateTick(tickDA, pD, pA, isRhombus ? 1 : 2);
        updateTick(tickBC, pB, pC, isRhombus ? 1 : 2);

        // Intersection Angle at M (between MD and MC)
        const vMD = new THREE.Vector3().subVectors(pD, pM).normalize();
        const vMC = new THREE.Vector3().subVectors(pC, pM).normalize();
        let angMD = Math.atan2(vMD.y, vMD.x);
        let angMC = Math.atan2(vMC.y, vMC.x);
        if (angMD < 0) angMD += 2*Math.PI;
        if (angMC < 0) angMC += 2*Math.PI;
        
        let sM = Math.min(angMD, angMC);
        let eM = Math.max(angMD, angMC);
        if (eM - sM > Math.PI) {
            const temp = sM; sM = eM; eM = temp + 2*Math.PI;
        }

        const intersectDeg = Math.abs((eM - sM) * 180 / Math.PI);
        const isPerp = Math.abs(intersectDeg - 90) < 1;

        if (matArcMRef.current) matArcMRef.current.opacity = isPerp ? 0 : 0.8;
        if (matSqMRef.current) matSqMRef.current.opacity = isPerp ? 0.9 : 0;
        
        if (isPerp) {
            updateSquareGeom(sqM, pM, vMC, vMD, 0.6);
        } else {
            updateArcGeom(arcM, sM, eM, 0.8);
        }

        // Angle Bisector at A
        // Base is at angle 0. Line AD is at angle T.
        // Diagonal AC is at angle angAC.
        const vAC = new THREE.Vector3().subVectors(pC, pA).normalize();
        const angAC = Math.atan2(vAC.y, vAC.x);

        const r = 1.0;
        updateArcGeom(arcA1, 0, angAC, r);
        updateArcGeom(arcA2, angAC, T, r+0.2); // slight offset so they don't overlap if unequal

        const deg1 = Math.round(angAC * 180 / Math.PI);
        const deg2 = Math.round((T - angAC) * 180 / Math.PI);

        if (txtAngA1.current) {
            const mid = angAC/2;
            txtAngA1.current.position.set(pA.x + Math.cos(mid)*1.5, pA.y + Math.sin(mid)*1.5, 0);
            txtAngA1.current.text = `${deg1}°`;
        }
        if (txtAngA2.current) {
            const mid = (angAC + T)/2;
            txtAngA2.current.position.set(pA.x + Math.cos(mid)*1.7, pA.y + Math.sin(mid)*1.7, 0);
            txtAngA2.current.text = `${deg2}°`;
        }

        if (matBisectRef.current) {
            if (isRhombus) {
                matBisectRef.current.color.setHex(0x0ea5e9); // highlight diagonal as bisector
                matBisectRef.current.emissive.setHex(0x0ea5e9);
            } else {
                matBisectRef.current.color.setHex(0x94a3b8); 
                matBisectRef.current.emissive.setHex(0x000000);
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

            {/* Perimeter */}
            <mesh ref={lineABRef} castShadow>
                <cylinderGeometry args={[0.05, 0.05, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} />
            </mesh>
            <mesh ref={lineBCRef} castShadow>
                <cylinderGeometry args={[0.05, 0.05, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} />
            </mesh>
            <mesh ref={lineCDRef} castShadow>
                <cylinderGeometry args={[0.05, 0.05, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} />
            </mesh>
            <mesh ref={lineDARef} castShadow>
                <cylinderGeometry args={[0.05, 0.05, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} />
            </mesh>

            {/* Diagonals */}
            <mesh ref={diagACRef}>
                <cylinderGeometry args={[0.03, 0.03, 1]} />
                <meshStandardMaterial ref={matBisectRef} color="#94a3b8" roughness={0.3} />
            </mesh>
            <mesh ref={diagBDRef}>
                <cylinderGeometry args={[0.03, 0.03, 1]} />
                <meshStandardMaterial color="#64748b" roughness={0.3} />
            </mesh>

            {/* Ticks */}
            <lineSegments geometry={tickAB} position={[0,0,0.05]}>
                <lineBasicMaterial color="#facc15" linewidth={4} />
            </lineSegments>
            <lineSegments geometry={tickCD} position={[0,0,0.05]}>
                <lineBasicMaterial color="#facc15" linewidth={4} />
            </lineSegments>
            <lineSegments geometry={tickDA} position={[0,0,0.05]}>
                <lineBasicMaterial color="#facc15" linewidth={4} />
            </lineSegments>
            <lineSegments geometry={tickBC} position={[0,0,0.05]}>
                <lineBasicMaterial color="#facc15" linewidth={4} />
            </lineSegments>

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
            <group ref={ptM}>
                <Sphere args={[0.1, 16, 16]}>
                    <meshStandardMaterial color="#d946ef" />
                </Sphere>
                <Text position={[0, -0.4, 0]} fontSize={0.4} color="#d946ef" fontWeight="bold">M</Text>
            </group>

            {/* Labels */}
            <Text ref={txtAB} fontSize={0.45} color="#facc15" fontWeight="bold" />
            <Text ref={txtAD} fontSize={0.45} color="#facc15" fontWeight="bold" />
            <Text ref={txtAC} fontSize={0.45} color="#0ea5e9" fontWeight="bold" />
            <Text ref={txtBD} fontSize={0.45} color="#94a3b8" fontWeight="bold" />

            {/* Intersection M marker */}
            <lineBasicMaterial ref={matArcMRef} color="#ef4444" linewidth={4} transparent />
            <lineBasicMaterial ref={matSqMRef} color="#22c55e" linewidth={4} transparent />
            
            <line geometry={arcM} position={[0,0,0.05]} material={matArcMRef.current || undefined} />
            <line geometry={sqM} position={[0,0,0.05]} material={matSqMRef.current || undefined} />

            {/* Angle Bisector Arcs at A */}
            <line geometry={arcA1} position={[0,0,0.05]}>
                <lineBasicMaterial color="#3b82f6" linewidth={4} />
            </line>
            <line geometry={arcA2} position={[0,0,0.05]}>
                <lineBasicMaterial color="#a855f7" linewidth={4} />
            </line>

            <Text ref={txtAngA1} fontSize={0.4} color="#3b82f6" fontWeight="bold" />
            <Text ref={txtAngA2} fontSize={0.4} color="#a855f7" fontWeight="bold" />

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function RhombusProperties() {
    const [legLen, setLegLen] = useState(5.5);

    const handleSliderChange = (val: number) => {
        let snapVal = val;
        // Snap to Rhombus (Base = 8)
        if (Math.abs(val - 8.0) <= 0.3) snapVal = 8.0;
        setLegLen(snapVal);
    };

    const isRhombus = legLen === 8.0;

    const renderMath = () => {
        if (isRhombus) {
            return <BlockMath math={`AB = AD = 8 \\implies \\text{Rhombus} \\implies AC \\perp BD`} />;
        }
        return <BlockMath math={`AB \\neq AD \\implies \\text{Parallelogram} \\implies AC \\not\\perp BD`} />;
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            The Rhombus Theorem
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Diagonals of a rhombus are perpendicular bisectors.
                        </p>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl min-w-[340px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-all duration-500 ${isRhombus ? 'border-green-500/50' : 'border-slate-800'}`}>
                        <div className={`text-lg px-4 flex items-center justify-center h-full min-h-[4rem] transition-colors ${isRhombus ? 'text-green-400' : 'text-white'}`}>
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
                        <Scene legLen={legLen} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                <div className={`flex-1 pointer-events-auto max-w-md w-full transition-all duration-500`}>
                    <div className={`border-l-4 border-t border-r border-b border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md bg-slate-900/90 border-l-rose-500 text-rose-300 bg-rose-900/40`}>
                        <h3 className="font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide">
                            <ShieldAlert size={18}/> Equal Diagonals Myth
                        </h3>
                        <p className="text-sm leading-relaxed drop-shadow-md text-slate-300">
                            <b>CRITICAL MISCONCEPTION:</b> Students often mistake a rhombus for a rectangle, assuming its diagonals are equal in length. Notice how AC ({isRhombus ? '13.1' : '<13'}) and BD ({isRhombus ? '9.1' : '>9'}) remain completely unequal! The true special property of a rhombus is that its diagonals cross at exactly <b>90° (perpendicular)</b> and precisely <b>bisect the corner angles</b>.
                        </p>
                    </div>
                </div>

                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
                    
                    <div className="mb-6">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <SplitSquareHorizontal size={14} /> Side Length Adjustment (Leg AD)
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className={isRhombus ? "text-green-400" : "text-blue-400"}>AD = {legLen.toFixed(1)} cm</span>
                            <span className="text-slate-400">{isRhombus ? 'Rhombus State' : 'Parallelogram State'}</span>
                        </div>
                        <input 
                            type="range" 
                            min="4" max="11" step="0.1" 
                            value={legLen} onChange={(e) => handleSliderChange(parseFloat(e.target.value))} 
                            className={`w-full h-1.5 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 ${isRhombus ? 'accent-green-500' : 'accent-blue-500'}`}
                        />
                        <div className="flex justify-between mt-2 text-[10px] text-slate-500">
                            <span>Base AB is fixed at 8.0 cm</span>
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={() => setLegLen(5.5)} 
                            className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
                        >
                            <Layers size={16} /> Parallelogram
                        </button>
                        <button 
                            onClick={() => setLegLen(8.0)} 
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
