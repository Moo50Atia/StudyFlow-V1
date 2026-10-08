import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, SplitSquareHorizontal, MoveRight, Layers, Shapes } from 'lucide-react';

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

// ---------------------------------------------------------
// 3D SCENE
// ---------------------------------------------------------

function Scene({ tiltDeg }: { tiltDeg: number }) {
    const { camera } = useThree();
    const proxy = useRef({ tiltRad: tiltDeg * Math.PI / 180 }).current;
    
    // Fixed Geometry Params to yield 10cm diagonals at 90 deg
    // Base width = 8, Height = 6. 
    // sqrt(8^2 + 6^2) = 10.
    const w = 8.0;
    const h = 6.0;

    const pA_fixed = useMemo(() => new THREE.Vector3(-4, -3, 0), []);
    const pB_fixed = useMemo(() => new THREE.Vector3(4, -3, 0), []);

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

    const txtD = useRef<any>(null);
    const txtC = useRef<any>(null);

    // Length Labels
    const txtAC = useRef<any>(null);
    const txtBD = useRef<any>(null);
    
    const txtAM = useRef<any>(null);
    const txtMC = useRef<any>(null);
    const txtBM = useRef<any>(null);
    const txtMD = useRef<any>(null);

    // Angle texts
    const txtAngA = useRef<any>(null);
    const txtAngB = useRef<any>(null);
    const txtAngC = useRef<any>(null);
    const txtAngD = useRef<any>(null);
    const txtAngM = useRef<any>(null);

    // Geometries
    const makeBuffer = (size: number) => {
        const g = new THREE.BufferGeometry();
        g.setAttribute('position', new THREE.BufferAttribute(new Float32Array(size * 3), 3));
        return g;
    };

    const arcA = useMemo(() => makeBuffer(65), []);
    const arcB = useMemo(() => makeBuffer(65), []);
    const arcC = useMemo(() => makeBuffer(65), []);
    const arcD = useMemo(() => makeBuffer(65), []);
    const sqA = useMemo(() => makeBuffer(3), []);
    const sqB = useMemo(() => makeBuffer(3), []);
    const sqC = useMemo(() => makeBuffer(3), []);
    const sqD = useMemo(() => makeBuffer(3), []);
    const arcM = useMemo(() => makeBuffer(65), []); // intersection angle arc

    const matArcRef = useRef<THREE.LineBasicMaterial>(null);
    const matSqRef = useRef<THREE.LineBasicMaterial>(null);
    const matDiagRef = useRef<THREE.MeshStandardMaterial>(null);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 1.5, z: 14, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    useFrame(() => {
        proxy.tiltRad = THREE.MathUtils.lerp(proxy.tiltRad, tiltDeg * Math.PI / 180, 0.15);
        const T = proxy.tiltRad;

        const pA = pA_fixed;
        const pB = pB_fixed;
        const pD = new THREE.Vector3(pA.x + h * Math.cos(T), pA.y + h * Math.sin(T), 0);
        const pC = new THREE.Vector3(pD.x + w, pD.y, 0);
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
            // Place near A along AC
            const dirAC = new THREE.Vector3().subVectors(pC, pA).normalize();
            const pos = pA.clone().add(dirAC.multiplyScalar(1.5)).add(new THREE.Vector3(-0.4, 0.4, 0));
            txtAC.current.position.copy(pos);
            txtAC.current.text = `AC = ${lenAC.toFixed(1)} cm`;
        }
        if (txtBD.current) {
            // Place near B along BD
            const dirBD = new THREE.Vector3().subVectors(pD, pB).normalize();
            const pos = pB.clone().add(dirBD.multiplyScalar(1.5)).add(new THREE.Vector3(0.4, 0.4, 0));
            txtBD.current.position.copy(pos);
            txtBD.current.text = `BD = ${lenBD.toFixed(1)} cm`;
        }

        // Half Segments Labels
        const placeHalfLabel = (txtRef: any, pt1: THREE.Vector3, pt2: THREE.Vector3, labelPrefix: string, val: number) => {
            if (!txtRef.current) return;
            const mid = new THREE.Vector3().addVectors(pt1, pt2).multiplyScalar(0.5);
            // offset slightly up
            txtRef.current.position.set(mid.x, mid.y + 0.3, 0.05);
            txtRef.current.text = `${labelPrefix} = ${val.toFixed(1)}`;
        };

        placeHalfLabel(txtAM, pA, pM, "AM", lenAC/2);
        placeHalfLabel(txtMC, pM, pC, "MC", lenAC/2);
        placeHalfLabel(txtBM, pB, pM, "BM", lenBD/2);
        placeHalfLabel(txtMD, pM, pD, "MD", lenBD/2);

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
        
        updateArcGeom(arcM, sM, eM, 0.8);
        const intersectDeg = Math.abs((eM - sM) * 180 / Math.PI);
        
        if (txtAngM.current) {
            const mid = (sM + eM) / 2;
            txtAngM.current.position.set(pM.x + Math.cos(mid)*1.3, pM.y + Math.sin(mid)*1.3, 0);
            txtAngM.current.text = `${intersectDeg.toFixed(1)}°`;
        }


        // Corner Angles
        const degA = Math.round(T * 180 / Math.PI);
        const degB = 180 - degA;
        const isRect = Math.abs(degA - 90) < 1;

        if (matArcRef.current) matArcRef.current.opacity = isRect ? 0 : 0.9;
        if (matSqRef.current) matSqRef.current.opacity = isRect ? 0.9 : 0;
        
        if (isRect) {
            // Draw Squares
            const sqSize = 0.6;
            updateSquareGeom(sqA, pA, new THREE.Vector3(1,0,0), new THREE.Vector3(0,1,0), sqSize);
            updateSquareGeom(sqB, pB, new THREE.Vector3(-1,0,0), new THREE.Vector3(0,1,0), sqSize);
            updateSquareGeom(sqC, pC, new THREE.Vector3(-1,0,0), new THREE.Vector3(0,-1,0), sqSize);
            updateSquareGeom(sqD, pD, new THREE.Vector3(1,0,0), new THREE.Vector3(0,-1,0), sqSize);
        } else {
            // Draw Arcs
            const r = 1.0;
            updateArcGeom(arcA, 0, T, r);
            updateArcGeom(arcB, T, Math.PI, r);
            updateArcGeom(arcC, Math.PI, Math.PI + T, r);
            updateArcGeom(arcD, Math.PI + T, 2*Math.PI, r);
        }

        // Corner Texts
        const tr = 1.5;
        if (txtAngA.current) {
            const mid = T/2;
            txtAngA.current.position.set(pA.x + Math.cos(mid)*tr, pA.y + Math.sin(mid)*tr, 0);
            txtAngA.current.text = isRect ? "" : `${degA}°`;
        }
        if (txtAngB.current) {
            const mid = (T + Math.PI)/2;
            txtAngB.current.position.set(pB.x + Math.cos(mid)*tr, pB.y + Math.sin(mid)*tr, 0);
            txtAngB.current.text = isRect ? "" : `${degB}°`;
        }
        if (txtAngC.current) {
            const mid = Math.PI + T/2;
            txtAngC.current.position.set(pC.x + Math.cos(mid)*tr, pC.y + Math.sin(mid)*tr, 0);
            txtAngC.current.text = isRect ? "" : `${degA}°`;
        }
        if (txtAngD.current) {
            const mid = (Math.PI + T + 2*Math.PI)/2;
            txtAngD.current.position.set(pD.x + Math.cos(mid)*tr, pD.y + Math.sin(mid)*tr, 0);
            txtAngD.current.text = isRect ? "" : `${degB}°`;
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

            {/* Glowing Diagonals */}
            <mesh ref={diagACRef}>
                <cylinderGeometry args={[0.03, 0.03, 1]} />
                <meshStandardMaterial ref={matDiagRef} color="#facc15" roughness={0.3} emissive="#facc15" emissiveIntensity={0.8} />
            </mesh>
            <mesh ref={diagBDRef}>
                <cylinderGeometry args={[0.03, 0.03, 1]} />
                <meshStandardMaterial color="#facc15" roughness={0.3} emissive="#facc15" emissiveIntensity={0.8} />
            </mesh>

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

            {/* Midpoint M */}
            <group ref={ptM}>
                <Sphere args={[0.2, 16, 16]}>
                    <meshStandardMaterial color="#d946ef" emissive="#d946ef" emissiveIntensity={0.5} />
                </Sphere>
                <Text position={[0, -0.6, 0]} fontSize={0.4} color="#d946ef" fontWeight="bold">M</Text>
            </group>

            {/* Diagonal Labels */}
            <Text ref={txtAC} fontSize={0.5} color="#facc15" fontWeight="bold" />
            <Text ref={txtBD} fontSize={0.5} color="#facc15" fontWeight="bold" />
            
            <Text ref={txtAM} fontSize={0.35} color="#fef08a" fontWeight="bold" />
            <Text ref={txtMC} fontSize={0.35} color="#fef08a" fontWeight="bold" />
            <Text ref={txtBM} fontSize={0.35} color="#fef08a" fontWeight="bold" />
            <Text ref={txtMD} fontSize={0.35} color="#fef08a" fontWeight="bold" />

            {/* Angle Arcs and Squares */}
            <lineBasicMaterial ref={matArcRef} color="#3b82f6" linewidth={5} transparent />
            <lineBasicMaterial ref={matSqRef} color="#22c55e" linewidth={5} transparent />

            <line geometry={arcA} position={[0,0,0.05]} material={matArcRef.current || undefined} />
            <line geometry={arcB} position={[0,0,0.05]} material={matArcRef.current || undefined} />
            <line geometry={arcC} position={[0,0,0.05]} material={matArcRef.current || undefined} />
            <line geometry={arcD} position={[0,0,0.05]} material={matArcRef.current || undefined} />

            <line geometry={sqA} position={[-4, -3, 0.05]} material={matSqRef.current || undefined} />
            <line geometry={sqB} position={[4, -3, 0.05]} material={matSqRef.current || undefined} />
            <group ref={ptC}><line geometry={sqC} position={[0,0,0.05]} material={matSqRef.current || undefined} /></group>
            <group ref={ptD}><line geometry={sqD} position={[0,0,0.05]} material={matSqRef.current || undefined} /></group>

            {/* Intersection Arc at M */}
            <line geometry={arcM} position={[0,0,0.05]}>
                <lineBasicMaterial color="#ef4444" linewidth={4} transparent opacity={0.8} />
            </line>
            <Text ref={txtAngM} fontSize={0.45} color="#ef4444" fontWeight="bold" />

            {/* Texts */}
            <Text ref={txtAngA} fontSize={0.5} color="#3b82f6" fontWeight="bold" />
            <Text ref={txtAngB} fontSize={0.5} color="#3b82f6" fontWeight="bold" />
            <Text ref={txtAngC} fontSize={0.5} color="#3b82f6" fontWeight="bold" />
            <Text ref={txtAngD} fontSize={0.5} color="#3b82f6" fontWeight="bold" />

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function RectangleDiagonals() {
    const [tiltDeg, setTiltDeg] = useState(65);

    const handleSliderChange = (val: number) => {
        let snapVal = val;
        // Snap to 90
        if (Math.abs(val - 90) <= 2) snapVal = 90;
        setTiltDeg(snapVal);
    };

    const isRect = tiltDeg === 90;

    const renderMath = () => {
        if (isRect) {
            return <BlockMath math={`m\\angle A = 90^\\circ \\implies \\text{Rectangle} \\implies AC = BD`} />;
        }
        return <BlockMath math={`m\\angle A = ${tiltDeg}^\\circ \\implies \\text{Parallelogram} \\implies AC \\neq BD`} />;
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            The Rectangle Diagonal Theorem
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Comparing diagonal properties as angles reach 90°.
                        </p>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl min-w-[340px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-all duration-500 ${isRect ? 'border-green-500/50' : 'border-slate-800'}`}>
                        <div className={`text-lg px-4 flex items-center justify-center h-full min-h-[4rem] transition-colors ${isRect ? 'text-green-400' : 'text-white'}`}>
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
                        <Scene tiltDeg={tiltDeg} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                <div className={`flex-1 pointer-events-auto max-w-md w-full transition-all duration-500`}>
                    <div className={`border-l-4 border-t border-r border-b border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md bg-slate-900/90 border-l-rose-500 text-rose-300 bg-rose-900/40`}>
                        <h3 className="font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide">
                            <ShieldAlert size={18}/> The 90° Intersection Myth
                        </h3>
                        <p className="text-sm leading-relaxed drop-shadow-md text-slate-300">
                            <b>CRITICAL MISCONCEPTION:</b> Students often assume that because a rectangle has 90° corners, its diagonals must cross at 90° too. Look at the red intersection angle at midpoint M when snapped to 90°. The crossing angle is ~74°, strictly proving that rectangle diagonals are <b>equal</b>, but <b>NOT perpendicular!</b>
                        </p>
                    </div>
                </div>

                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
                    
                    <div className="mb-6">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <SplitSquareHorizontal size={14} /> Interior Angle Tilt (A)
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className={isRect ? "text-green-400" : "text-blue-400"}>Angle = {tiltDeg}°</span>
                            <span className="text-slate-400">{isRect ? 'Rectangle State' : 'Parallelogram State'}</span>
                        </div>
                        <input 
                            type="range" 
                            min="50" max="130" step="1" 
                            value={tiltDeg} onChange={(e) => handleSliderChange(parseFloat(e.target.value))} 
                            className={`w-full h-1.5 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 ${isRect ? 'accent-green-500' : 'accent-blue-500'}`}
                        />
                    </div>

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={() => setTiltDeg(65)} 
                            className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
                        >
                            <Layers size={16} /> Parallelogram
                        </button>
                        <button 
                            onClick={() => setTiltDeg(90)} 
                            className="bg-green-600/20 hover:bg-green-500/30 text-green-400 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-green-600/50 text-sm"
                        >
                            <Shapes size={16} /> Snap to Rectangle
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
