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

const updateChevron = (geom: THREE.BufferGeometry, p1: THREE.Vector3, p2: THREE.Vector3, count: number) => {
    const pos = geom.attributes.position.array as Float32Array;
    const dir = new THREE.Vector3().subVectors(p2, p1).normalize();
    const perp = new THREE.Vector3(-dir.y, dir.x, 0);
    const mid = new THREE.Vector3().addVectors(p1, p2).multiplyScalar(0.5);
    const size = 0.25;
    const w = 0.2; 

    if (count === 1) {
        const tip = mid.clone();
        const left = mid.clone().sub(dir.clone().multiplyScalar(size)).add(perp.clone().multiplyScalar(w));
        const right = mid.clone().sub(dir.clone().multiplyScalar(size)).sub(perp.clone().multiplyScalar(w));
        pos.set([
            left.x, left.y, 0, tip.x, tip.y, 0,
            tip.x, tip.y, 0, right.x, right.y, 0
        ]);
        geom.setDrawRange(0, 4);
    } else {
        const tip1 = mid.clone().add(dir.clone().multiplyScalar(0.12));
        const tip2 = mid.clone().sub(dir.clone().multiplyScalar(0.12));
        const l1 = tip1.clone().sub(dir.clone().multiplyScalar(size)).add(perp.clone().multiplyScalar(w));
        const r1 = tip1.clone().sub(dir.clone().multiplyScalar(size)).sub(perp.clone().multiplyScalar(w));
        const l2 = tip2.clone().sub(dir.clone().multiplyScalar(size)).add(perp.clone().multiplyScalar(w));
        const r2 = tip2.clone().sub(dir.clone().multiplyScalar(size)).sub(perp.clone().multiplyScalar(w));
        pos.set([
            l1.x, l1.y, 0, tip1.x, tip1.y, 0,
            tip1.x, tip1.y, 0, r1.x, r1.y, 0,
            l2.x, l2.y, 0, tip2.x, tip2.y, 0,
            tip2.x, tip2.y, 0, r2.x, r2.y, 0
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
    const size = 0.15;

    if (count === 1) {
        const top = mid.clone().add(perp.clone().multiplyScalar(size));
        const bot = mid.clone().sub(perp.clone().multiplyScalar(size));
        pos.set([
            top.x, top.y, 0, bot.x, bot.y, 0
        ]);
        geom.setDrawRange(0, 2);
    } else {
        const m1 = mid.clone().add(dir.clone().multiplyScalar(0.08));
        const m2 = mid.clone().sub(dir.clone().multiplyScalar(0.08));
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

function Scene({ tiltDeg, step }: { tiltDeg: number, step: number }) {
    const { camera } = useThree();
    
    const proxy = useRef({ 
        tiltRad: tiltDeg * Math.PI / 180,
        angleOp: 0,
        diagOp: 0
    }).current;
    
    // Fixed Geometry Params
    const l1 = 6.0;  // Base AB maps to 12cm
    const l2 = 3.5;  // Leg AD maps to 7cm

    const pA = useMemo(() => new THREE.Vector3(-3, -2, 0), []);
    const pB = useMemo(() => new THREE.Vector3(-3 + l1, -2, 0), []);

    // Refs
    const lineABRef = useRef<THREE.Mesh>(null);
    const lineBCRef = useRef<THREE.Mesh>(null);
    const lineCDRef = useRef<THREE.Mesh>(null);
    const lineDARef = useRef<THREE.Mesh>(null);
    
    const diagACRef = useRef<THREE.Mesh>(null);
    const diagBDRef = useRef<THREE.Mesh>(null);
    
    const ptD = useRef<THREE.Group>(null);
    const ptC = useRef<THREE.Group>(null);
    const ptM = useRef<THREE.Group>(null);

    const txtD = useRef<any>(null);
    const txtC = useRef<any>(null);

    // Label Refs
    const txtAB = useRef<any>(null);
    const txtCD = useRef<any>(null);
    const txtAD = useRef<any>(null);
    const txtBC = useRef<any>(null);

    // Geometry buffers
    const makeBuffer = (size: number) => {
        const g = new THREE.BufferGeometry();
        g.setAttribute('position', new THREE.BufferAttribute(new Float32Array(size * 3), 3));
        return g;
    };

    const chevAB = useMemo(() => makeBuffer(4), []);
    const chevCD = useMemo(() => makeBuffer(4), []);
    const chevAD = useMemo(() => makeBuffer(8), []);
    const chevBC = useMemo(() => makeBuffer(8), []);

    const tickAM = useMemo(() => makeBuffer(2), []);
    const tickMC = useMemo(() => makeBuffer(2), []);
    const tickBM = useMemo(() => makeBuffer(4), []);
    const tickMD = useMemo(() => makeBuffer(4), []);

    const arcA = useMemo(() => makeBuffer(65), []);
    const arcB = useMemo(() => makeBuffer(65), []);
    const arcC = useMemo(() => makeBuffer(65), []);
    const arcD = useMemo(() => makeBuffer(65), []);

    // Angle text refs
    const txtAngA = useRef<any>(null);
    const txtAngB = useRef<any>(null);
    const txtAngC = useRef<any>(null);
    const txtAngD = useRef<any>(null);

    // Materials
    const matAngARef = useRef<THREE.LineBasicMaterial>(null);
    const matAngBRef = useRef<THREE.LineBasicMaterial>(null);
    const matDiagRef1 = useRef<THREE.MeshStandardMaterial>(null);
    const matDiagRef2 = useRef<THREE.MeshStandardMaterial>(null);
    const matTickRef = useRef<THREE.LineBasicMaterial>(null);

    const txtMatA = useRef<any>(null);
    const txtMatB = useRef<any>(null);
    const ptMMat = useRef<THREE.MeshStandardMaterial>(null);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 0, z: 12, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    useEffect(() => {
        gsap.to(proxy, {
            angleOp: step >= 1 ? 1 : 0,
            diagOp: step >= 2 ? 1 : 0,
            duration: 1.0,
            ease: "power2.out"
        });
    }, [step, proxy]);

    useFrame(() => {
        proxy.tiltRad = THREE.MathUtils.lerp(proxy.tiltRad, tiltDeg * Math.PI / 180, 0.1);
        
        const T = proxy.tiltRad;
        const vD = new THREE.Vector3(pA.x + l2 * Math.cos(T), pA.y + l2 * Math.sin(T), 0);
        const vC = new THREE.Vector3(vD.x + l1, vD.y, 0);
        const vM = new THREE.Vector3().addVectors(pA, vC).multiplyScalar(0.5);

        if (ptD.current) ptD.current.position.copy(vD);
        if (ptC.current) ptC.current.position.copy(vC);
        if (ptM.current) ptM.current.position.copy(vM);

        if (txtD.current) txtD.current.position.set(-0.4, 0.4, 0);
        if (txtC.current) txtC.current.position.set(0.4, 0.4, 0);

        // Lines
        if (lineABRef.current) updateLine(lineABRef.current, pA, pB);
        if (lineBCRef.current) updateLine(lineBCRef.current, pB, vC);
        if (lineCDRef.current) updateLine(lineCDRef.current, vC, vD);
        if (lineDARef.current) updateLine(lineDARef.current, vD, pA);
        
        if (diagACRef.current) updateLine(diagACRef.current, pA, vC);
        if (diagBDRef.current) updateLine(diagBDRef.current, pB, vD);

        // Side Labels
        if (txtAB.current) txtAB.current.position.set((pA.x + pB.x)/2, pA.y - 0.5, 0);
        if (txtCD.current) txtCD.current.position.set((vC.x + vD.x)/2, vC.y + 0.5, 0);
        if (txtAD.current) {
            const midAD = new THREE.Vector3().addVectors(pA, vD).multiplyScalar(0.5);
            txtAD.current.position.set(midAD.x - 0.7, midAD.y, 0);
        }
        if (txtBC.current) {
            const midBC = new THREE.Vector3().addVectors(pB, vC).multiplyScalar(0.5);
            txtBC.current.position.set(midBC.x + 0.7, midBC.y, 0);
        }

        // Chevrons (Parallel Markers)
        // AB and CD (1 marker)
        updateChevron(chevAB, pA, pB, 1);
        updateChevron(chevCD, vD, vC, 1);
        // AD and BC (2 markers)
        updateChevron(chevAD, pA, vD, 2);
        updateChevron(chevBC, pB, vC, 2);

        // Ticks (Diagonal Bisection)
        updateTick(tickAM, pA, vM, 1);
        updateTick(tickMC, vM, vC, 1);
        updateTick(tickBM, pB, vM, 2);
        updateTick(tickMD, vM, vD, 2);

        // Arcs
        const r = 0.9;
        const tr = 1.4;
        
        // A: 0 to T
        updateArcGeom(arcA, 0, T, r);
        const degA = Math.round(T * 180 / Math.PI);
        if (txtAngA.current) {
            const mid = T/2;
            txtAngA.current.position.set(pA.x + Math.cos(mid)*tr, pA.y + Math.sin(mid)*tr, 0);
            txtAngA.current.text = `${degA}°`;
        }

        // B: T to PI
        updateArcGeom(arcB, T, Math.PI, r);
        const degB = Math.round((Math.PI - T) * 180 / Math.PI);
        if (txtAngB.current) {
            const mid = (T + Math.PI)/2;
            txtAngB.current.position.set(pB.x + Math.cos(mid)*tr, pB.y + Math.sin(mid)*tr, 0);
            txtAngB.current.text = `${degB}°`;
        }

        // C: PI to PI + T
        updateArcGeom(arcC, Math.PI, Math.PI + T, r);
        if (txtAngC.current) {
            const mid = Math.PI + T/2;
            txtAngC.current.position.set(vC.x + Math.cos(mid)*tr, vC.y + Math.sin(mid)*tr, 0);
            txtAngC.current.text = `${degA}°`;
        }

        // D: PI + T to 2PI
        updateArcGeom(arcD, Math.PI + T, Math.PI * 2, r);
        if (txtAngD.current) {
            const mid = (Math.PI + T + Math.PI*2)/2;
            txtAngD.current.position.set(vD.x + Math.cos(mid)*tr, vD.y + Math.sin(mid)*tr, 0);
            txtAngD.current.text = `${degB}°`;
        }

        // Opacities
        if (matAngARef.current) matAngARef.current.opacity = proxy.angleOp * 0.9;
        if (matAngBRef.current) matAngBRef.current.opacity = proxy.angleOp * 0.9;
        
        if (txtMatA.current) txtMatA.current.fillOpacity = proxy.angleOp;
        if (txtMatB.current) txtMatB.current.fillOpacity = proxy.angleOp;

        if (matDiagRef1.current) matDiagRef1.current.opacity = proxy.diagOp * 0.7;
        if (matDiagRef2.current) matDiagRef2.current.opacity = proxy.diagOp * 0.7;
        
        if (matTickRef.current) matTickRef.current.opacity = proxy.diagOp * 0.9;
        if (ptMMat.current) ptMMat.current.opacity = proxy.diagOp;
        if (ptM.current) ptM.current.visible = proxy.diagOp > 0.05;
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

            {/* Diagonals */}
            <mesh ref={diagACRef}>
                <cylinderGeometry args={[0.02, 0.02, 1]} />
                <meshStandardMaterial ref={matDiagRef1} color="#a855f7" transparent opacity={0} roughness={0.3} />
            </mesh>
            <mesh ref={diagBDRef}>
                <cylinderGeometry args={[0.02, 0.02, 1]} />
                <meshStandardMaterial ref={matDiagRef2} color="#a855f7" transparent opacity={0} roughness={0.3} />
            </mesh>

            {/* Chevrons */}
            <lineSegments geometry={chevAB} position={[0,0,0.05]}>
                <lineBasicMaterial color="#facc15" linewidth={4} />
            </lineSegments>
            <lineSegments geometry={chevCD} position={[0,0,0.05]}>
                <lineBasicMaterial color="#facc15" linewidth={4} />
            </lineSegments>
            <lineSegments geometry={chevAD} position={[0,0,0.05]}>
                <lineBasicMaterial color="#f97316" linewidth={4} />
            </lineSegments>
            <lineSegments geometry={chevBC} position={[0,0,0.05]}>
                <lineBasicMaterial color="#f97316" linewidth={4} />
            </lineSegments>

            {/* Ticks */}
            <lineSegments geometry={tickAM} position={[0,0,0.05]}>
                <lineBasicMaterial ref={matTickRef} color="#d946ef" linewidth={4} transparent opacity={0} />
            </lineSegments>
            <lineSegments geometry={tickMC} position={[0,0,0.05]}>
                <lineBasicMaterial color="#d946ef" linewidth={4} /> {/* shares mat if we passed it, but we can just use another or wait - GSAP controls matTickRef. Let's just create one material. */}
            </lineSegments>
            {/* React three fiber materials can be shared natively if defined identically, but we need dynamic opacity. Let's just use ref for all or assume they share if we declare them locally.
            Actually, it's easier to just apply the ref to the first one and rely on R3F not necessarily sharing unless we reuse the object. Let's explicitly wire it up in useFrame?
            We did wire `matTickRef.current.opacity`. If we want it to affect all, we should declare a single material node. */}
            <lineBasicMaterial ref={matTickRef} color="#d946ef" linewidth={4} transparent opacity={0} />

            <lineSegments geometry={tickAM} position={[0,0,0.05]} material={matTickRef.current || undefined} />
            <lineSegments geometry={tickMC} position={[0,0,0.05]} material={matTickRef.current || undefined} />
            <lineSegments geometry={tickBM} position={[0,0,0.05]} material={matTickRef.current || undefined} />
            <lineSegments geometry={tickMD} position={[0,0,0.05]} material={matTickRef.current || undefined} />

            {/* Vertices */}
            <group position={[-3, -2, 0]}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.4, -0.4, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">A</Text>
            </group>
            <group position={[3, -2, 0]}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0.4, -0.4, 0]} fontSize={0.4} color="#ffffff" fontWeight="bold">B</Text>
            </group>
            <group ref={ptC}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text ref={txtC} fontSize={0.4} color="#ffffff" fontWeight="bold">C</Text>
            </group>
            <group ref={ptD}>
                <Sphere args={[0.15, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text ref={txtD} fontSize={0.4} color="#ffffff" fontWeight="bold">D</Text>
            </group>
            <group ref={ptM} visible={false}>
                <Sphere args={[0.1, 16, 16]}>
                    <meshStandardMaterial ref={ptMMat} color="#ffffff" transparent opacity={0} />
                </Sphere>
                <Text position={[0, 0.4, 0]} fontSize={0.35} color="#d946ef" fontWeight="bold">M</Text>
            </group>

            {/* Side Labels */}
            <Text ref={txtAB} fontSize={0.4} color="#facc15" fontWeight="bold">12 cm</Text>
            <Text ref={txtCD} fontSize={0.4} color="#facc15" fontWeight="bold">12 cm</Text>
            <Text ref={txtAD} fontSize={0.4} color="#f97316" fontWeight="bold">7 cm</Text>
            <Text ref={txtBC} fontSize={0.4} color="#f97316" fontWeight="bold">7 cm</Text>

            {/* Arcs */}
            <lineBasicMaterial ref={matAngARef} color="#3b82f6" linewidth={5} transparent opacity={0} />
            <lineBasicMaterial ref={matAngBRef} color="#22c55e" linewidth={5} transparent opacity={0} />

            <line geometry={arcA} position={[0,0,0.05]} material={matAngARef.current || undefined} />
            <line geometry={arcC} position={[0,0,0.05]} material={matAngARef.current || undefined} />
            
            <line geometry={arcB} position={[0,0,0.05]} material={matAngBRef.current || undefined} />
            <line geometry={arcD} position={[0,0,0.05]} material={matAngBRef.current || undefined} />

            {/* Angle Texts */}
            <Text ref={txtAngA} ref-material={txtMatA} fontSize={0.4} color="#3b82f6" fontWeight="bold" anchorX="center" anchorY="middle" transparent fillOpacity={0} />
            <Text ref={txtAngC} material={txtMatA.current || undefined} fontSize={0.4} color="#3b82f6" fontWeight="bold" anchorX="center" anchorY="middle" transparent fillOpacity={0} />
            
            <Text ref={txtAngB} ref-material={txtMatB} fontSize={0.4} color="#22c55e" fontWeight="bold" anchorX="center" anchorY="middle" transparent fillOpacity={0} />
            <Text ref={txtAngD} material={txtMatB.current || undefined} fontSize={0.4} color="#22c55e" fontWeight="bold" anchorX="center" anchorY="middle" transparent fillOpacity={0} />

        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function ParallelogramProperties() {
    const [tiltDeg, setTiltDeg] = useState(110);
    const [step, setStep] = useState(0); 

    const handleNext = () => {
        if (step < 2) setStep(prev => prev + 1);
    };

    const degA = tiltDeg;
    const degB = 180 - tiltDeg;

    const renderMath = () => {
        if (step === 0) {
            return <BlockMath math={`AB \\parallel CD, \\; AD \\parallel BC \\implies AB=CD=12, \\; AD=BC=7`} />;
        }
        if (step === 1) {
            return <BlockMath math={`m\\angle A + m\\angle B = \\color{#3b82f6}${degA}^\\circ \\color{white}+ \\color{#22c55e}${degB}^\\circ \\color{white}= \\color{#facc15}180^\\circ`} />;
        }
        return <BlockMath math={`AM = MC \\quad \\text{and} \\quad BM = MD \\; \\text{(Diagonals Bisect)}`} />;
    };

    const getAlertContent = () => {
        if (step === 0) {
            return { 
                title: "Opposite Sides Property", 
                icon: <Shapes size={18}/>, 
                color: "border-l-yellow-500 text-yellow-300 bg-yellow-900/40", 
                body: <>Because the pairs of opposite sides are perfectly parallel (indicated by the chevron arrows), their lengths are mathematically locked to be exactly equal to each other.</> 
            };
        }
        if (step === 1) {
            return { 
                title: "Consecutive Angles Misconception", 
                icon: <ShieldAlert size={18}/>, 
                color: "border-l-rose-500 text-rose-300 bg-rose-900/40", 
                body: <><b>CRITICAL MISCONCEPTION:</b> Students often guess that all pairs of angles in a parallelogram are equal. While <b>opposite</b> angles (A & C) are equal, <b>consecutive</b> angles (A & B) are supplementary—they always add up to exactly 180°.</> 
            };
        }
        return { 
            title: "Diagonal Bisection", 
            icon: <SplitSquareHorizontal size={18}/>, 
            color: "border-l-purple-500 text-purple-300 bg-purple-900/40", 
            body: <>The diagonals of a parallelogram cross each other at their exact midpoint M. Notice the matching tick marks: segment AM strictly equals MC, and BM strictly equals MD.</> 
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
                            Properties of Parallelograms
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Exploring sides, angles, and diagonals.
                        </p>
                    </div>
                    <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[400px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-all duration-500">
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
                        <Scene tiltDeg={tiltDeg} step={step} />
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
                    
                    <div className="mb-6">
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className="text-slate-400">Parallelogram Tilt / Shear</span>
                        </div>
                        <input 
                            type="range" 
                            min="45" max="135" step="1" 
                            value={tiltDeg} onChange={(e) => setTiltDeg(parseFloat(e.target.value))} 
                            className="w-full accent-blue-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer mt-1"
                        />
                    </div>

                    {/* Actions */}
                    <div className="grid grid-cols-1 gap-3 mt-auto">
                        <button 
                            onClick={handleNext} 
                            disabled={step >= 2} 
                            className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                ${step >= 2 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                        >
                            {step === 0 ? "Reveal Angle Properties" : step === 1 ? "Reveal Diagonal Properties" : "Proof Complete"} 
                            {step < 2 && <ArrowRight size={16} />}
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
