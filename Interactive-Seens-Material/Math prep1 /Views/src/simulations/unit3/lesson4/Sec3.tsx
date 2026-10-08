import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, Maximize2, MoveRight, ArrowRight, Shapes, Triangle, Ruler } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function Scene({ ax, ay }: { ax: number, ay: number }) {
    const { camera } = useThree();
    
    // Animation Proxy
    const proxy = useRef({ ax: ax, ay: ay }).current;
    
    // Fixed base vertices
    const bx = -3;
    const by = -2;
    const cx = 3;
    const cy = -2;

    const arcRadius = 0.8;
    const textRadius = 1.4;

    // Geometry refs
    const lineABRef = useRef<THREE.Mesh>(null);
    const lineBCRef = useRef<THREE.Mesh>(null);
    const lineCARef = useRef<THREE.Mesh>(null);
    const ptA = useRef<THREE.Group>(null);
    
    // Arcs
    const arcARef = useRef<THREE.Line>(null);
    const arcBRef = useRef<THREE.Line>(null);
    const arcCRef = useRef<THREE.Line>(null);
    const sqARef = useRef<THREE.Line>(null);
    const sqBRef = useRef<THREE.Line>(null);
    const sqCRef = useRef<THREE.Line>(null);

    // Texts
    const textARef = useRef<any>(null);
    const textBRef = useRef<any>(null);
    const textCRef = useRef<any>(null);
    
    // Calipers
    const calABGroupRef = useRef<THREE.Group>(null);
    const calBCGroupRef = useRef<THREE.Group>(null);
    const calCAGroupRef = useRef<THREE.Group>(null);
    
    const calABTextRef = useRef<any>(null);
    const calBCTextRef = useRef<any>(null);
    const calCATextRef = useRef<any>(null);

    const makeGeom = () => {
        const geom = new THREE.BufferGeometry();
        geom.setAttribute('position', new THREE.BufferAttribute(new Float32Array(65 * 3), 3));
        return geom;
    };

    const arcAGeom = useMemo(makeGeom, []);
    const arcBGeom = useMemo(makeGeom, []);
    const arcCGeom = useMemo(makeGeom, []);
    const sqAGeom = useMemo(makeGeom, []);
    const sqBGeom = useMemo(makeGeom, []);
    const sqCGeom = useMemo(makeGeom, []);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 1, z: 12, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

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

    const updateSquare = (geom: THREE.BufferGeometry, v: THREE.Vector3, d1: THREE.Vector3, d2: THREE.Vector3, size: number) => {
        const positions = geom.attributes.position.array as Float32Array;
        // P1: d1*size
        // P2: d1*size + d2*size
        // P3: d2*size
        
        const p1 = new THREE.Vector3().addVectors(v, d1.clone().multiplyScalar(size));
        const p2 = new THREE.Vector3().addVectors(p1, d2.clone().multiplyScalar(size));
        const p3 = new THREE.Vector3().addVectors(v, d2.clone().multiplyScalar(size));

        positions[0] = p1.x - v.x; positions[1] = p1.y - v.y; positions[2] = 0;
        positions[3] = p2.x - v.x; positions[4] = p2.y - v.y; positions[5] = 0;
        positions[6] = p3.x - v.x; positions[7] = p3.y - v.y; positions[8] = 0;
        
        geom.setDrawRange(0, 3);
        geom.attributes.position.needsUpdate = true;
    };

    const updateLine = (mesh: THREE.Mesh, p1: THREE.Vector3, p2: THREE.Vector3) => {
        const dist = p1.distanceTo(p2);
        mesh.scale.y = dist;
        mesh.position.copy(p1).lerp(p2, 0.5);
        mesh.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), p2.clone().sub(p1).normalize());
    };

    useFrame(() => {
        proxy.ax = THREE.MathUtils.lerp(proxy.ax, ax, 0.1);
        proxy.ay = THREE.MathUtils.lerp(proxy.ay, ay, 0.1);
        
        const pA = new THREE.Vector3(proxy.ax, proxy.ay, 0);
        const pB = new THREE.Vector3(bx, by, 0);
        const pC = new THREE.Vector3(cx, cy, 0);

        if (ptA.current) ptA.current.position.copy(pA);
        
        if (lineABRef.current) updateLine(lineABRef.current, pA, pB);
        if (lineCARef.current) updateLine(lineCARef.current, pC, pA);
        if (lineBCRef.current) updateLine(lineBCRef.current, pB, pC);

        const centroid = new THREE.Vector3((pA.x + pB.x + pC.x)/3, (pA.y + pB.y + pC.y)/3, 0);

        // Angle calculations
        const angAB = Math.atan2(pB.y - pA.y, pB.x - pA.x);
        const angAC = Math.atan2(pC.y - pA.y, pC.x - pA.x);
        const angBA = Math.atan2(pA.y - pB.y, pA.x - pB.x);
        const angBC = Math.atan2(pC.y - pB.y, pC.x - pB.x);
        const angCA = Math.atan2(pA.y - pC.y, pA.x - pC.x);
        const angCB = Math.atan2(pB.y - pC.y, pB.x - pC.x);

        let startA = angAC; let endA = angAB;
        if (startA < 0) startA += Math.PI * 2;
        if (endA < 0) endA += Math.PI * 2;
        if (endA < startA) endA += Math.PI * 2;
        let degA = Math.round((endA - startA) * 180 / Math.PI);
        if (degA > 180) {
            degA = 360 - degA;
            const temp = startA; startA = endA; endA = temp + Math.PI*2;
        }

        let startB = angBC; let endB = angBA;
        if (startB < 0) startB += Math.PI * 2;
        if (endB < 0) endB += Math.PI * 2;
        if (endB < startB) endB += Math.PI * 2;
        let degB = Math.round((endB - startB) * 180 / Math.PI);
        if (degB > 180) {
            degB = 360 - degB;
            const temp = startB; startB = endB; endB = temp + Math.PI*2;
        }

        let startC = angCA; let endC = angCB;
        if (startC < 0) startC += Math.PI * 2;
        if (endC < 0) endC += Math.PI * 2;
        if (endC < startC) endC += Math.PI * 2;
        let degC = Math.round((endC - startC) * 180 / Math.PI);
        if (degC > 180) {
            degC = 360 - degC;
            const temp = startC; startC = endC; endC = temp + Math.PI*2;
        }

        // Fix rounding drift
        if (degA + degB + degC !== 180) {
            const diff = 180 - (degA + degB + degC);
            if (degA >= degB && degA >= degC) degA += diff;
            else if (degB >= degA && degB >= degC) degB += diff;
            else degC += diff;
        }

        // Draw Arcs / Squares
        const sqTol = 1;
        const sqSize = 0.6;
        
        // A Angle
        if (arcARef.current && sqARef.current) {
            arcARef.current.position.copy(pA);
            sqARef.current.position.copy(pA);
            if (Math.abs(degA - 90) <= sqTol) {
                arcARef.current.visible = false; sqARef.current.visible = true;
                const d1 = new THREE.Vector3().subVectors(pC, pA).normalize();
                const d2 = new THREE.Vector3().subVectors(pB, pA).normalize();
                updateSquare(sqARef.current.geometry, pA, d1, d2, sqSize);
            } else {
                arcARef.current.visible = true; sqARef.current.visible = false;
                updateArc(arcARef.current.geometry, startA, endA, arcRadius);
            }
        }
        if (textARef.current) {
            const mid = (startA + endA)/2;
            textARef.current.position.set(pA.x + Math.cos(mid)*textRadius, pA.y + Math.sin(mid)*textRadius, 0);
            textARef.current.text = `${degA}°`;
        }

        // B Angle
        if (arcBRef.current && sqBRef.current) {
            arcBRef.current.position.copy(pB);
            sqBRef.current.position.copy(pB);
            if (Math.abs(degB - 90) <= sqTol) {
                arcBRef.current.visible = false; sqBRef.current.visible = true;
                const d1 = new THREE.Vector3().subVectors(pC, pB).normalize();
                const d2 = new THREE.Vector3().subVectors(pA, pB).normalize();
                updateSquare(sqBRef.current.geometry, pB, d1, d2, sqSize);
            } else {
                arcBRef.current.visible = true; sqBRef.current.visible = false;
                updateArc(arcBRef.current.geometry, startB, endB, arcRadius);
            }
        }
        if (textBRef.current) {
            const mid = (startB + endB)/2;
            textBRef.current.position.set(pB.x + Math.cos(mid)*textRadius, pB.y + Math.sin(mid)*textRadius, 0);
            textBRef.current.text = `${degB}°`;
        }

        // C Angle
        if (arcCRef.current && sqCRef.current) {
            arcCRef.current.position.copy(pC);
            sqCRef.current.position.copy(pC);
            if (Math.abs(degC - 90) <= sqTol) {
                arcCRef.current.visible = false; sqCRef.current.visible = true;
                const d1 = new THREE.Vector3().subVectors(pA, pC).normalize();
                const d2 = new THREE.Vector3().subVectors(pB, pC).normalize();
                updateSquare(sqCRef.current.geometry, pC, d1, d2, sqSize);
            } else {
                arcCRef.current.visible = true; sqCRef.current.visible = false;
                updateArc(arcCRef.current.geometry, startC, endC, arcRadius);
            }
        }
        if (textCRef.current) {
            const mid = (startC + endC)/2;
            textCRef.current.position.set(pC.x + Math.cos(mid)*textRadius, pC.y + Math.sin(mid)*textRadius, 0);
            textCRef.current.text = `${degC}°`;
        }

        // Update Calipers (Side Lengths)
        const aLen = 6.0;
        const cLen = pA.distanceTo(pB);
        const bLen = pA.distanceTo(pC);

        const positionCaliper = (group: any, textRef: any, p1: THREE.Vector3, p2: THREE.Vector3, len: number) => {
            if (!group.current || !textRef.current) return;
            const mid = new THREE.Vector3().addVectors(p1, p2).multiplyScalar(0.5);
            
            // Push outward from centroid
            const outDir = new THREE.Vector3().subVectors(mid, centroid).normalize();
            
            // Just place text slightly outside the edge
            const textPos = new THREE.Vector3().addVectors(mid, outDir.multiplyScalar(0.6));
            group.current.position.copy(textPos);
            textRef.current.text = `${len.toFixed(1)} cm`;
        };

        positionCaliper(calBCGroupRef, calBCTextRef, pB, pC, aLen);
        positionCaliper(calCAGroupRef, calCATextRef, pC, pA, bLen);
        positionCaliper(calABGroupRef, calABTextRef, pA, pB, cLen);
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
                <cylinderGeometry args={[0.06, 0.06, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} metalness={0.2} />
            </mesh>
            <mesh ref={lineBCRef} castShadow>
                <cylinderGeometry args={[0.06, 0.06, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} metalness={0.2} />
            </mesh>
            <mesh ref={lineCARef} castShadow>
                <cylinderGeometry args={[0.06, 0.06, 1]} />
                <meshStandardMaterial color="#94a3b8" roughness={0.3} metalness={0.2} />
            </mesh>

            {/* Vertices */}
            <group ref={ptA}>
                <Sphere args={[0.2, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0, 0.6, 0]} fontSize={0.5} color="#ffffff" fontWeight="bold">A</Text>
            </group>
            <group position={[bx, by, 0]}>
                <Sphere args={[0.2, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.5, -0.5, 0]} fontSize={0.5} color="#ffffff" fontWeight="bold">B</Text>
            </group>
            <group position={[cx, cy, 0]}>
                <Sphere args={[0.2, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0.5, -0.5, 0]} fontSize={0.5} color="#ffffff" fontWeight="bold">C</Text>
            </group>

            {/* Arcs & Squares */}
            <line ref={arcARef as any} geometry={arcAGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#3b82f6" linewidth={5} transparent opacity={0.9} />
            </line>
            <line ref={sqARef as any} geometry={sqAGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#3b82f6" linewidth={5} transparent opacity={0.9} />
            </line>

            <line ref={arcBRef as any} geometry={arcBGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#22c55e" linewidth={5} transparent opacity={0.9} />
            </line>
            <line ref={sqBRef as any} geometry={sqBGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#22c55e" linewidth={5} transparent opacity={0.9} />
            </line>

            <line ref={arcCRef as any} geometry={arcCGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#f97316" linewidth={5} transparent opacity={0.9} />
            </line>
            <line ref={sqCRef as any} geometry={sqCGeom} position={[0,0,0.05]}>
                <lineBasicMaterial color="#f97316" linewidth={5} transparent opacity={0.9} />
            </line>

            {/* Angle Texts */}
            <Text ref={textARef} fontSize={0.5} color="#3b82f6" fontWeight="bold" anchorX="center" anchorY="middle" />
            <Text ref={textBRef} fontSize={0.5} color="#22c55e" fontWeight="bold" anchorX="center" anchorY="middle" />
            <Text ref={textCRef} fontSize={0.5} color="#f97316" fontWeight="bold" anchorX="center" anchorY="middle" />

            {/* Side Texts (Calipers) */}
            <group ref={calABGroupRef}>
                <Text ref={calABTextRef} fontSize={0.4} color="#facc15" fontWeight="bold" anchorX="center" anchorY="middle" />
            </group>
            <group ref={calBCGroupRef}>
                <Text ref={calBCTextRef} fontSize={0.4} color="#facc15" fontWeight="bold" anchorX="center" anchorY="middle" />
            </group>
            <group ref={calCAGroupRef}>
                <Text ref={calCATextRef} fontSize={0.4} color="#facc15" fontWeight="bold" anchorX="center" anchorY="middle" />
            </group>
        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function TriangleClassification() {
    const [ax, setAx] = useState(-1);
    const [ay, setAy] = useState(4);

    const handleSliderChange = (axis: 'x'|'y', val: number) => {
        let snapVal = val;
        
        // Snapping logic for easy discovery of key geometric states
        if (axis === 'x') {
            if (Math.abs(val - 0) < 0.2) snapVal = 0; // Isosceles
            if (Math.abs(val - (-3)) < 0.2) snapVal = -3; // Right at B
            if (Math.abs(val - 3) < 0.2) snapVal = 3; // Right at C
            setAx(snapVal);
        } else {
            const eqHeight = 5.196; // sqrt(36 - 9)
            if (Math.abs(val - eqHeight) < 0.2) snapVal = eqHeight; // Equilateral height
            setAy(snapVal);
        }
    };

    // Calculate classes based on current ax, ay
    const bx = -3, by = -2;
    const cx = 3, cy = -2;
    const aLen = 6;
    const bLen = Math.hypot(cx - ax, cy - ay);
    const cLen = Math.hypot(ax - bx, ay - by);

    const angleARad = Math.acos((bLen*bLen + cLen*cLen - aLen*aLen) / (2 * bLen * cLen));
    const angleBRad = Math.acos((aLen*aLen + cLen*cLen - bLen*bLen) / (2 * aLen * cLen));
    const angleCRad = Math.acos((aLen*aLen + bLen*bLen - cLen*cLen) / (2 * aLen * bLen));
    
    let degA = Math.round(angleARad * 180 / Math.PI);
    let degB = Math.round(angleBRad * 180 / Math.PI);
    let degC = Math.round(angleCRad * 180 / Math.PI);
    
    // Fix float rounding
    if (degA + degB + degC !== 180) {
        const diff = 180 - (degA + degB + degC);
        if (degA >= degB && degA >= degC) degA += diff;
        else if (degB >= degA && degB >= degC) degB += diff;
        else degC += diff;
    }

    const isRight = degA === 90 || degB === 90 || degC === 90;
    const isObtuse = degA > 90 || degB > 90 || degC > 90;
    const isAcute = degA < 90 && degB < 90 && degC < 90;

    const lenTol = 0.1;
    const isEquilateral = Math.abs(aLen - bLen) < lenTol && Math.abs(bLen - cLen) < lenTol;
    const isIsosceles = !isEquilateral && (Math.abs(aLen - bLen) < lenTol || Math.abs(bLen - cLen) < lenTol || Math.abs(aLen - cLen) < lenTol);
    const isScalene = !isEquilateral && !isIsosceles;

    const renderMath = () => {
        return <BlockMath math={`a = ${aLen.toFixed(1)}, b = ${bLen.toFixed(1)}, c = ${cLen.toFixed(1)}`} />;
    };

    const getBadgeUI = () => {
        let angleBadge = { label: 'Acute', color: 'bg-green-500/20 text-green-400 border-green-500/50' };
        if (isRight) angleBadge = { label: 'Right (90°)', color: 'bg-blue-500/20 text-blue-400 border-blue-500/50' };
        if (isObtuse) angleBadge = { label: 'Obtuse (>90°)', color: 'bg-orange-500/20 text-orange-400 border-orange-500/50' };

        let sideBadge = { label: 'Scalene', color: 'bg-slate-500/20 text-slate-300 border-slate-500/50' };
        if (isIsosceles) sideBadge = { label: 'Isosceles', color: 'bg-purple-500/20 text-purple-400 border-purple-500/50' };
        if (isEquilateral) sideBadge = { label: 'Equilateral', color: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/50' };

        return (
            <div className="flex gap-2">
                <div className={`px-3 py-1 rounded-full border text-xs font-bold uppercase tracking-wider ${angleBadge.color}`}>
                    {angleBadge.label}
                </div>
                <div className={`px-3 py-1 rounded-full border text-xs font-bold uppercase tracking-wider ${sideBadge.color}`}>
                    {sideBadge.label}
                </div>
            </div>
        );
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            Triangle Classification
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Classifying by side lengths and interior angles.
                        </p>
                    </div>
                    <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-all duration-500 items-center gap-4">
                        {getBadgeUI()}
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas shadows>
                        <OrbitControls enablePan={false} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <Scene ax={ax} ay={ay} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                <div className={`flex-1 pointer-events-auto max-w-md w-full transition-all duration-500`}>
                    <div className={`border-l-4 border-t border-r border-b border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md bg-slate-900/90 border-l-rose-500 text-rose-300 bg-rose-900/40`}>
                        <h3 className="font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide">
                            <ShieldAlert size={18}/> Multiple Right Angles?
                        </h3>
                        <p className="text-sm leading-relaxed drop-shadow-md text-slate-300">
                            <b>CRITICAL MISCONCEPTION:</b> Try dragging the handles to make TWO angles $\ge 90^\circ$. You'll see it's mathematically impossible! Because the three angles must sum exactly to 180°, if one angle is 90° or larger, the remaining two <b>MUST</b> be acute.
                        </p>
                    </div>
                </div>

                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
                    
                    <div className="mb-4">
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className="text-slate-400">Vertex A (Horizontal Shift)</span>
                        </div>
                        <input 
                            type="range" 
                            min="-5" 
                            max="5" 
                            step="0.1" 
                            value={ax} 
                            onChange={(e) => handleSliderChange('x', parseFloat(e.target.value))} 
                            className="w-full accent-blue-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer mt-1"
                        />
                    </div>
                    <div className="mb-6">
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className="text-slate-400">Vertex A (Height Shift)</span>
                        </div>
                        <input 
                            type="range" 
                            min="0.5" 
                            max="6" 
                            step="0.1" 
                            value={ay} 
                            onChange={(e) => handleSliderChange('y', parseFloat(e.target.value))} 
                            className="w-full accent-blue-500 h-1.5 bg-slate-800 rounded-lg appearance-none cursor-pointer mt-1"
                        />
                    </div>

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={() => { setAx(0); setAy(5.196); }} 
                            className="bg-yellow-600/20 hover:bg-yellow-500/30 text-yellow-500 font-bold py-2 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-yellow-600/50 text-sm"
                        >
                            <Triangle size={16} /> Equilateral
                        </button>
                        <button 
                            onClick={() => { setAx(-3); setAy(4); }} 
                            className="bg-blue-600/20 hover:bg-blue-500/30 text-blue-400 font-bold py-2 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-blue-600/50 text-sm"
                        >
                            <Shapes size={16} /> Right Isosceles
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
