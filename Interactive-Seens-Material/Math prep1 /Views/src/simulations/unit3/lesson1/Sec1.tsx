import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere, Line as DreiLine } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { MoveRight, Ruler, Spline, ShieldAlert, CheckCircle2, RotateCcw, ArrowRightLeft } from 'lucide-react';

// ---------------------------------------------------------
// 3D COMPONENTS
// ---------------------------------------------------------

function DynamicScene({ step, angleDeg }: { step: number, angleDeg: number }) {
    const { camera } = useThree();
    
    // Proxy for animating values smoothly with GSAP
    const proxy = useRef({ 
        leftExtend: 0, 
        rightExtend: 0, 
        leftArrowScale: 0, 
        rightArrowScale: 0, 
        angle: 0, 
        showAngleRay: 0 
    }).current;

    // Refs for meshes
    const baseLineCylRef = useRef<THREE.Mesh>(null);
    const leftArrowRef = useRef<THREE.Mesh>(null);
    const rightArrowRef = useRef<THREE.Mesh>(null);
    
    const angleRayGroupRef = useRef<THREE.Group>(null);
    const angleRayCylRef = useRef<THREE.Mesh>(null);
    const angleRayArrowRef = useRef<THREE.Mesh>(null);
    const angleNodeGroupRef = useRef<THREE.Group>(null);
    const arcLineRef = useRef<THREE.Line>(null);
    const angleTextGroupRef = useRef<THREE.Group>(null);
    const angleTextRef = useRef<any>(null);

    const rulerRef = useRef<THREE.Group>(null);
    const nodeLTextRef = useRef<any>(null);

    // Prepare buffer geometry for the arc
    const arcGeometry = useMemo(() => {
        const geom = new THREE.BufferGeometry();
        const positions = new Float32Array(33 * 3);
        geom.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        return geom;
    }, []);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: 0, y: 1, z: 14, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    // Handle step transitions
    useEffect(() => {
        const tl = gsap.timeline();
        
        if (step === 0) { // Segment
            tl.to(proxy, { leftExtend: 0, rightExtend: 0, leftArrowScale: 0, rightArrowScale: 0, showAngleRay: 0, duration: 1, ease: "power2.out" }, 0);
            tl.to(rulerRef.current!.position, { y: -1, duration: 0.5, ease: "back.out(1.5)" }, 0);
            tl.to(rulerRef.current!.scale, { x: 1, y: 1, z: 1, duration: 0.5 }, 0);
            if (nodeLTextRef.current) nodeLTextRef.current.text = "A";
        } 
        else if (step === 1) { // Ray
            tl.to(proxy, { leftExtend: 0, rightExtend: 15, leftArrowScale: 0, rightArrowScale: 1, showAngleRay: 0, duration: 1.5, ease: "power1.inOut" }, 0);
            tl.to(rulerRef.current!.position, { y: -4, duration: 0.5, ease: "power2.in" }, 0);
            tl.to(rulerRef.current!.scale, { x: 0, y: 0, z: 0, duration: 0.5 }, 0);
            if (nodeLTextRef.current) nodeLTextRef.current.text = "A";
        } 
        else if (step === 2) { // Line
            tl.to(proxy, { leftExtend: 15, rightExtend: 15, leftArrowScale: 1, rightArrowScale: 1, showAngleRay: 0, duration: 1.5, ease: "power1.inOut" }, 0);
            if (nodeLTextRef.current) nodeLTextRef.current.text = "A";
        } 
        else if (step === 3) { // Angle
            tl.to(proxy, { leftExtend: 0, rightExtend: 10, leftArrowScale: 0, rightArrowScale: 1, showAngleRay: 1, duration: 1.2, ease: "power2.inOut" }, 0);
            if (nodeLTextRef.current) nodeLTextRef.current.text = "O";
        }
    }, [step, proxy]);

    // Update geometry per frame based on proxy values
    useFrame(() => {
        // Smooth target tracking for angle
        const targetAngle = step === 3 ? (angleDeg * Math.PI / 180) : 0;
        proxy.angle = THREE.MathUtils.lerp(proxy.angle, targetAngle, 0.1);

        // 1. Base Line Update
        const baseD = 6 + proxy.leftExtend + proxy.rightExtend;
        const cx = -3 - proxy.leftExtend + baseD / 2;
        
        if (baseLineCylRef.current) {
            baseLineCylRef.current.scale.y = Math.max(0.001, baseD);
            baseLineCylRef.current.position.x = cx;
        }

        // 2. Base Arrows Update
        if (rightArrowRef.current) {
            rightArrowRef.current.position.x = -3 + 6 + proxy.rightExtend;
            rightArrowRef.current.scale.setScalar(proxy.rightArrowScale);
        }
        if (leftArrowRef.current) {
            leftArrowRef.current.position.x = -3 - proxy.leftExtend;
            leftArrowRef.current.scale.setScalar(proxy.leftArrowScale);
        }

        // 3. Angle Ray Update
        if (angleRayGroupRef.current) {
            angleRayGroupRef.current.rotation.z = proxy.angle;
            angleRayGroupRef.current.scale.setScalar(Math.max(0.001, proxy.showAngleRay));
        }

        const angleD = 6 + proxy.rightExtend;
        if (angleRayCylRef.current && angleRayArrowRef.current) {
            angleRayCylRef.current.scale.y = Math.max(0.001, angleD);
            angleRayCylRef.current.position.x = angleD / 2;
            angleRayArrowRef.current.position.x = angleD;
        }

        // Keep node A on the angle ray upright by counter-rotating
        if (angleNodeGroupRef.current) {
            angleNodeGroupRef.current.rotation.z = -proxy.angle;
        }

        // 4. Arc Line Update
        if (proxy.showAngleRay > 0.01 && arcLineRef.current) {
            const positions = arcLineRef.current.geometry.attributes.position.array as Float32Array;
            for (let i = 0; i <= 32; i++) {
                const theta = (i / 32) * proxy.angle;
                positions[i * 3] = Math.cos(theta) * 1.5;
                positions[i * 3 + 1] = Math.sin(theta) * 1.5;
                positions[i * 3 + 2] = 0;
            }
            arcLineRef.current.geometry.attributes.position.needsUpdate = true;
            arcLineRef.current.visible = true;
        } else if (arcLineRef.current) {
            arcLineRef.current.visible = false;
        }

        // 5. Angle Text Update
        if (angleTextGroupRef.current && angleTextRef.current) {
            const midTheta = proxy.angle / 2;
            const rad = 2.4;
            angleTextGroupRef.current.position.set(
                Math.cos(midTheta) * rad,
                Math.sin(midTheta) * rad,
                0
            );
            angleTextGroupRef.current.scale.setScalar(proxy.showAngleRay);
            angleTextRef.current.text = `${Math.round(proxy.angle * 180 / Math.PI)}°`;
        }
    });

    return (
        <group>
            {/* Ambient & Lights */}
            <ambientLight intensity={0.6} />
            <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow />
            <Environment preset="city" opacity={0.3} />
            
            {/* Background Grid */}
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[50, 50]} />
                <meshStandardMaterial color="#020617" />
            </mesh>
            <gridHelper args={[50, 50, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* Ruler Overlay (Step 0) */}
            <group ref={rulerRef} position={[0, -1, 0]}>
                <DreiLine points={[[-3, 0.3, 0], [-3, -0.3, 0]]} color="#94a3b8" lineWidth={2} />
                <DreiLine points={[[3, 0.3, 0], [3, -0.3, 0]]} color="#94a3b8" lineWidth={2} />
                <DreiLine points={[[-3, 0, 0], [3, 0, 0]]} color="#94a3b8" lineWidth={2} />
                <Text position={[0, -0.6, 0]} fontSize={0.5} color="#cbd5e1" fontWeight="bold">6 cm</Text>
            </group>

            {/* Base Line Cylinder */}
            <mesh ref={baseLineCylRef} rotation={[0, 0, -Math.PI/2]} castShadow>
                <cylinderGeometry args={[0.06, 0.06, 1]} />
                <meshStandardMaterial color="#10b981" roughness={0.3} />
            </mesh>
            
            {/* Left Arrow (For Line) */}
            <mesh ref={leftArrowRef} rotation={[0, 0, Math.PI/2]} castShadow>
                <coneGeometry args={[0.25, 0.5]} />
                <meshStandardMaterial color="#10b981" roughness={0.3} />
            </mesh>
            
            {/* Right Arrow (For Ray and Line) */}
            <mesh ref={rightArrowRef} rotation={[0, 0, -Math.PI/2]} castShadow>
                <coneGeometry args={[0.25, 0.5]} />
                <meshStandardMaterial color="#10b981" roughness={0.3} />
            </mesh>

            {/* NODE L (Origin / Vertex) */}
            <group position={[-3, 0, 0.1]}>
                <Sphere args={[0.15, 32, 32]}>
                    <meshStandardMaterial color="#3b82f6" roughness={0.2} metalness={0.8} />
                </Sphere>
                <Text ref={nodeLTextRef} position={[-0.4, 0.4, 0]} fontSize={0.5} color="#ffffff" fontWeight="bold">A</Text>

                {/* Dynamic Arc Line */}
                <line ref={arcLineRef as any} geometry={arcGeometry}>
                    <lineBasicMaterial color="#facc15" linewidth={3} transparent opacity={0.8} />
                </line>

                {/* Dynamic Angle Text */}
                <group ref={angleTextGroupRef}>
                    <Text ref={angleTextRef} fontSize={0.5} color="#facc15" fontWeight="bold">0°</Text>
                </group>

                {/* Angle Ray Group (Pivots at Node L) */}
                <group ref={angleRayGroupRef}>
                    <mesh ref={angleRayCylRef} rotation={[0, 0, -Math.PI/2]} castShadow>
                        <cylinderGeometry args={[0.06, 0.06, 1]} />
                        <meshStandardMaterial color="#38bdf8" roughness={0.3} />
                    </mesh>
                    <mesh ref={angleRayArrowRef} rotation={[0, 0, -Math.PI/2]} castShadow>
                        <coneGeometry args={[0.25, 0.5]} />
                        <meshStandardMaterial color="#38bdf8" roughness={0.3} />
                    </mesh>
                    {/* Node Top (A in Step 3) */}
                    <group position={[6, 0, 0]} ref={angleNodeGroupRef}>
                        <Sphere args={[0.15, 32, 32]}>
                            <meshStandardMaterial color="#38bdf8" roughness={0.2} metalness={0.8} />
                        </Sphere>
                        <Text position={[-0.4, 0.4, 0]} fontSize={0.5} color="#ffffff" fontWeight="bold">A</Text>
                    </group>
                </group>
            </group>

            {/* NODE R (Fixed) */}
            <group position={[3, 0, 0.1]}>
                <Sphere args={[0.15, 32, 32]}>
                    <meshStandardMaterial color="#3b82f6" roughness={0.2} metalness={0.8} />
                </Sphere>
                <Text position={[0.4, 0.4, 0]} fontSize={0.5} color="#ffffff" fontWeight="bold">B</Text>
            </group>
            
        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function GeometricHierarchySimulation() {
    const [step, setStep] = useState(0);
    const [angleDeg, setAngleDeg] = useState(45); // Default sweep angle

    const handleNextStep = () => {
        if (step < 3) setStep(s => s + 1);
    };

    const handleReset = () => {
        setStep(0);
        setAngleDeg(45);
    };

    const renderMath = () => {
        if (step === 0) return <BlockMath math={`\\overline{AB} \\text{ (Line Segment)}`} />;
        if (step === 1) return <BlockMath math={`\\overrightarrow{AB} \\text{ (Ray)}`} />;
        if (step === 2) return <BlockMath math={`\\overleftrightarrow{AB} \\text{ (Straight Line)}`} />;
        if (step === 3) return <BlockMath math={`\\angle AOB \\text{ (Angle)}`} />;
    };

    const getAlertContent = () => {
        if (step === 0) return { title: "Finite Measurement", icon: <Ruler size={18}/>, color: "border-l-blue-500 text-blue-300 bg-blue-900/40", body: <>A <b>Line Segment</b> is bounded by two precise endpoints (<span className="font-mono text-white">A</span> and <span className="font-mono text-white">B</span>). Because it is bounded, it has a definite, measurable length (6 cm).</> };
        if (step === 1) return { title: "Infinite Extension (One-Way)", icon: <ShieldAlert size={18}/>, color: "border-l-rose-500 text-rose-300 bg-rose-900/40", body: <><b>CRITICAL MISCONCEPTION:</b> A <b>Ray</b> cannot be measured! It starts at endpoint <span className="font-mono text-white">A</span> and extends infinitely through <span className="font-mono text-white">B</span>. Notice the ruler shatters and vanishes as the line extends forever.</> };
        if (step === 2) return { title: "Infinite Extension (Two-Way)", icon: <ArrowRightLeft size={18}/>, color: "border-l-purple-500 text-purple-300 bg-purple-900/40", body: <>A <b>Straight Line</b> extends infinitely in BOTH directions. It has zero endpoints. Like a ray, it is impossible to measure its length.</> };
        if (step === 3) return { title: "Measuring Rotation, Not Length", icon: <Spline size={18}/>, color: "border-l-emerald-500 text-emerald-300 bg-emerald-900/40", body: <>An <b>Angle</b> is formed by two rays sharing a common vertex (<span className="font-mono text-white">O</span>). We do not measure how "long" the rays are (they are infinite). We only measure the <i>rotational sweep</i> between them.</> };
        return { title: "", icon: null, color: "", body: <></> };
    };

    const alertContent = getAlertContent();

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col md:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            Geometric Foundations
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Segments, Rays, Lines, and Angles.
                        </p>
                    </div>
                    <div className="bg-slate-950/80 border border-slate-800 p-3 rounded-xl min-w-[340px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto">
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
                        <OrbitControls enablePan={false} enableZoom={false} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <DynamicScene step={step} angleDeg={angleDeg} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                <div className={`flex-1 pointer-events-auto max-w-md w-full transition-all duration-500 origin-bottom-left`}>
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
                    
                    {/* Steps Map */}
                    <div className="flex flex-col gap-2 mb-4">
                        <div className="flex justify-between text-[10px] font-bold uppercase tracking-wider text-slate-400 px-1">
                            <span className="text-blue-400">Segment</span>
                            <span className="text-rose-400">Ray</span>
                            <span className="text-purple-400">Line</span>
                            <span className="text-emerald-400">Angle</span>
                        </div>
                        <div className="flex gap-1">
                            {[0, 1, 2, 3].map(s => (
                                <div 
                                    key={s} 
                                    className={`h-2 flex-1 rounded-full transition-all duration-500 ${
                                        s === step ? 'bg-white shadow-[0_0_10px_rgba(255,255,255,0.8)] scale-y-125' : 
                                        s < step ? 'bg-slate-500' : 'bg-slate-800'
                                    }`} 
                                />
                            ))}
                        </div>
                    </div>

                    {/* Angle Sandbox Control */}
                    {step === 3 && (
                        <div className="mb-4 animate-fade-in">
                            <label className="text-[10px] font-bold uppercase tracking-wider text-emerald-400 mb-1 flex justify-between">
                                <span>Sweep Angle: {angleDeg}°</span>
                            </label>
                            <input 
                                type="range" 
                                min="15" 
                                max="165" 
                                step="1" 
                                value={angleDeg} 
                                onChange={(e) => setAngleDeg(parseInt(e.target.value))} 
                                className="w-full accent-emerald-500"
                            />
                            <div className="flex justify-between text-xs text-slate-500 mt-1">
                                <span>Acute</span>
                                <span>Right</span>
                                <span>Obtuse</span>
                            </div>
                        </div>
                    )}

                    {/* Actions */}
                    <div className="grid grid-cols-2 gap-3 mt-auto">
                        <button 
                            onClick={handleNextStep} 
                            disabled={step === 3} 
                            className={`font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg text-sm 
                                ${step === 3 ? 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50 border border-slate-700' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-900/20'}`}
                        >
                            {step === 0 ? "Extend to Ray" : 
                             step === 1 ? "Extend to Line" : 
                             step === 2 ? "Form Angle" : "Sandbox Active"} 
                            {step < 3 && <MoveRight size={16} />}
                        </button>
                        <button 
                            onClick={handleReset} 
                            className="bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border border-slate-700 text-sm"
                        >
                            <RotateCcw size={16} /> Reset
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
