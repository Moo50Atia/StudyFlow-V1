import React, { useState, useRef, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Text, Line, Box } from '@react-three/drei';
import * as THREE from 'three';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, CheckCircle, AlignCenter, ArrowLeftRight, Layers } from 'lucide-react';

// ---------------------------------------------------------
// DATA CONFIGURATION
// ---------------------------------------------------------

const oddData = [
    { val: 12, start: 0, end: 2 },
    { val: 4, start: 1, end: 0 },
    { val: 19, start: 2, end: 4 },
    { val: 7, start: 3, end: 1 },
    { val: 15, start: 4, end: 3 }
];

const evenData = [
    { val: 14, start: 0, end: 3 },
    { val: 22, start: 1, end: 5 },
    { val: 7, start: 2, end: 1 },
    { val: 10, start: 3, end: 2 },
    { val: 4, start: 4, end: 0 },
    { val: 18, start: 5, end: 4 }
];

// ---------------------------------------------------------
// 3D SCENE COMPONENTS
// ---------------------------------------------------------

const DataBlock = ({ data, totalLen, progress }: { data: any, totalLen: number, progress: number }) => {
    const meshRef = useRef<THREE.Mesh>(null);
    const proxy = useRef({ p: progress }).current;

    useFrame(() => {
        proxy.p = THREE.MathUtils.lerp(proxy.p, progress, 0.1);
        const p = proxy.p;

        // 1. Sorting Phase (0 to 30)
        const pSort = THREE.MathUtils.clamp((p - 0) / 30, 0, 1);
        
        // Easing for smooth sorting slide
        const easeSort = pSort < 0.5 ? 2 * pSort * pSort : 1 - Math.pow(-2 * pSort + 2, 2) / 2;

        const startX = (data.start - (totalLen - 1) / 2) * 2.5;
        const endX = (data.end - (totalLen - 1) / 2) * 2.5;
        const currentX = THREE.MathUtils.lerp(startX, endX, easeSort);
        
        // Add a slight arc to the movement to prevent clipping
        const arcY = Math.sin(easeSort * Math.PI) * 1.5 * Math.abs(data.start - data.end) / totalLen;

        if (meshRef.current) {
            meshRef.current.position.x = currentX;
            meshRef.current.position.y = 0.5 + arcY; // base height 0.5

            // 2. Elimination Phase (40 to 70)
            let pElim = 0;
            if (totalLen === 5) {
                if (data.end === 0 || data.end === 4) pElim = THREE.MathUtils.clamp((p - 40) / 15, 0, 1);
                else if (data.end === 1 || data.end === 3) pElim = THREE.MathUtils.clamp((p - 55) / 15, 0, 1);
            } else if (totalLen === 6) {
                if (data.end === 0 || data.end === 5) pElim = THREE.MathUtils.clamp((p - 40) / 10, 0, 1);
                else if (data.end === 1 || data.end === 4) pElim = THREE.MathUtils.clamp((p - 50) / 10, 0, 1);
            }

            // 3. Highlight Phase (70 to 100) for Median Blocks
            let pHighlight = 0;
            if (totalLen === 5 && data.end === 2) pHighlight = THREE.MathUtils.clamp((p - 70) / 20, 0, 1);
            if (totalLen === 6 && (data.end === 2 || data.end === 3)) pHighlight = THREE.MathUtils.clamp((p - 70) / 20, 0, 1);

            const mat = meshRef.current.material as THREE.MeshStandardMaterial;
            
            // Base Color: Blue
            // Eliminated Color: Slate
            // Highlighted Color: Green
            const cBase = new THREE.Color("#3b82f6");
            const cElim = new THREE.Color("#334155");
            const cHigh = new THREE.Color("#22c55e");
            
            if (pElim > 0) {
                mat.color.lerpColors(cBase, cElim, pElim);
                mat.opacity = THREE.MathUtils.lerp(1, 0.4, pElim);
                mat.transparent = true;
            } else if (pHighlight > 0) {
                mat.color.lerpColors(cBase, cHigh, pHighlight);
                meshRef.current.scale.setScalar(THREE.MathUtils.lerp(1, 1.15, pHighlight));
                meshRef.current.position.y = 0.5 + arcY + THREE.MathUtils.lerp(0, 0.2, pHighlight);
            } else {
                mat.color.copy(cBase);
                mat.opacity = 1;
                meshRef.current.scale.setScalar(1);
            }
        }
    });

    return (
        <mesh ref={meshRef} castShadow receiveShadow>
            <boxGeometry args={[1.8, 1, 1.8]} />
            <meshStandardMaterial color="#3b82f6" roughness={0.3} metalness={0.1} />
            <Text position={[0, 0.6, 0]} rotation={[-Math.PI/2, 0, 0]} fontSize={0.6} color="#ffffff" fontWeight="extrabold">
                {data.val}
            </Text>
        </mesh>
    );
};

const MedianAverageBridge = ({ progress }: { progress: number }) => {
    const groupRef = useRef<THREE.Group>(null);
    const proxy = useRef({ p: progress }).current;

    useFrame(() => {
        proxy.p = THREE.MathUtils.lerp(proxy.p, progress, 0.1);
        const pBridge = THREE.MathUtils.clamp((proxy.p - 75) / 20, 0, 1);
        
        if (groupRef.current) {
            groupRef.current.scale.setScalar(pBridge);
            groupRef.current.visible = pBridge > 0;
        }
    });

    // Positions for middle blocks of even dataset (indices 2 and 3)
    const totalLen = 6;
    const xLeft = (2 - (totalLen - 1) / 2) * 2.5;
    const xRight = (3 - (totalLen - 1) / 2) * 2.5;

    return (
        <group ref={groupRef} position={[0, 1.5, 0]}>
            <Line points={[[xLeft, 0, 0], [xRight, 0, 0]]} color="#22c55e" lineWidth={5} />
            <Line points={[[xLeft, 0, 0], [xLeft, -0.5, 0]]} color="#22c55e" lineWidth={5} />
            <Line points={[[xRight, 0, 0], [xRight, -0.5, 0]]} color="#22c55e" lineWidth={5} />
            <mesh position={[0, 0.5, 0]}>
                <boxGeometry args={[2, 0.8, 0.2]} />
                <meshBasicMaterial color="#22c55e" />
                <Text position={[0, 0, 0.12]} fontSize={0.5} color="#ffffff" fontWeight="bold">
                    12
                </Text>
            </mesh>
            <Text position={[0, 1.2, 0]} fontSize={0.4} color="#4ade80" fontWeight="bold">
                (10 + 14) / 2
            </Text>
        </group>
    );
};

function ConveyorScene({ mode, progress }: { mode: 'odd' | 'even', progress: number }) {
    const data = mode === 'odd' ? oddData : evenData;
    const totalLen = data.length;

    return (
        <group position={[0, -1, 0]}>
            <ambientLight intensity={0.6} />
            <directionalLight position={[5, 12, 8]} intensity={0.8} castShadow shadow-mapSize={[1024, 1024]} />
            
            {/* Conveyor Belt / Ground Plane */}
            <mesh rotation={[-Math.PI / 2, 0, 0]} receiveShadow position={[0, -0.01, 0]}>
                <planeGeometry args={[30, 10]} />
                <meshStandardMaterial color="#0f172a" roughness={0.8} />
            </mesh>
            
            {/* Conveyor Tracks */}
            <Line points={[[-15, 0, 2], [15, 0, 2]]} color="#334155" lineWidth={3} />
            <Line points={[[-15, 0, -2], [15, 0, -2]]} color="#334155" lineWidth={3} />

            {/* Data Blocks */}
            {data.map((d, i) => (
                <DataBlock key={`${mode}-${i}`} data={d} totalLen={totalLen} progress={progress} />
            ))}

            {/* Average Bridge (Only for Even Dataset) */}
            {mode === 'even' && <MedianAverageBridge progress={progress} />}
            
            {/* Index Labels (Rank) */}
            {Array.from({ length: totalLen }).map((_, i) => (
                <Text 
                    key={`rank-${i}`} 
                    position={[(i - (totalLen - 1) / 2) * 2.5, 0, 2.5]} 
                    rotation={[-Math.PI/4, 0, 0]} 
                    fontSize={0.4} 
                    color="#64748b" 
                    fontWeight="bold"
                >
                    Rank {i + 1}
                </Text>
            ))}
        </group>
    );
}

// ---------------------------------------------------------
// MAIN UI COMPONENT
// ---------------------------------------------------------

export default function MedianSim() {
    const [mode, setMode] = useState<'odd'|'even'>('odd');
    const [progress, setProgress] = useState(0);

    const isComplete = progress === 100;

    const getAlertConfig = () => {
        if (progress < 30) {
            return {
                title: "The Unsorted Fallacy",
                desc: "CRITICAL MISCONCEPTION: If we pick the middle block right now, we get the wrong answer! The Median represents the physical middle of an ordered set. We must sort the values first.",
                colorClass: "bg-rose-900/40 border-l-rose-500 border-rose-800 text-rose-100",
                icon: <ShieldAlert size={18} className="text-rose-400" />
            };
        } else if (progress < 70) {
            return {
                title: "Isolating the Center",
                desc: "Now that the blocks are sorted in ascending order, we systematically eliminate the lowest and highest values in pairs to close in on the absolute center.",
                colorClass: "bg-sky-900/40 border-l-sky-500 border-sky-800 text-sky-100",
                icon: <ArrowLeftRight size={18} className="text-sky-400" />
            };
        } else {
            if (mode === 'odd') {
                return {
                    title: "Median Discovered (Odd Dataset)",
                    desc: "SUCCESS: Because there are 5 items, eliminating outer pairs leaves exactly ONE block in the center (Rank 3). The median is 12.",
                    colorClass: "bg-green-900/40 border-l-green-500 border-green-800 text-green-100",
                    icon: <CheckCircle size={18} className="text-green-400" />
                };
            } else {
                return {
                    title: "Median Discovered (Even Dataset)",
                    desc: "SUCCESS: Because there are 6 items, we are left with TWO central blocks (Ranks 3 and 4). We calculate the median by taking the exact average of these two values.",
                    colorClass: "bg-green-900/40 border-l-green-500 border-green-800 text-green-100",
                    icon: <CheckCircle size={18} className="text-green-400" />
                };
            }
        }
    };

    const alertConfig = getAlertConfig();

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex flex-col gap-4 max-w-7xl mx-auto">
                    <div className="flex justify-between items-start md:items-center w-full">
                        <div>
                            <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                                Measures of Central Tendency: The Median
                            </h1>
                            <p className="text-slate-400 text-sm mt-1">
                                Finding the absolute geometric center of ordered data.
                            </p>
                        </div>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl w-full flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-colors duration-500 ${isComplete ? 'border-green-500/50' : 'border-slate-700'}`}>
                        <div className={`text-sm md:text-lg px-4 flex items-center justify-center h-full min-h-[3.5rem] w-full ${isComplete ? 'text-green-400' : 'text-slate-300'}`}>
                            {progress < 30 ? (
                                <BlockMath math={`\\text{Median} \\neq \\text{Middle of Unsorted Data}`} />
                            ) : mode === 'odd' ? (
                                <BlockMath math={`\\text{Position} = \\frac{n+1}{2} = \\frac{5+1}{2} = 3^{\\text{rd}} \\text{ Value} = 12`} />
                            ) : (
                                <BlockMath math={`\\text{Middle Values: } 3^{\\text{rd}} \\text{ and } 4^{\\text{th}} \\implies \\frac{10 + 14}{2} = 12`} />
                            )}
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas camera={{ position: [0, 8, 14], fov: 45 }} shadows>
                        <OrbitControls enablePan={false} enableZoom={true} maxPolarAngle={Math.PI / 2 - 0.1} minAzimuthAngle={-0.3} maxAzimuthAngle={0.3} />
                        <ConveyorScene mode={mode} progress={progress} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                {/* Dynamic Alert Panel */}
                <div className="flex-1 pointer-events-auto max-w-md w-full">
                    <div className={`border-l-4 border-t border-r border-b p-5 rounded-2xl shadow-2xl backdrop-blur-md transition-all duration-500 ${alertConfig.colorClass}`}>
                        <h3 className="font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide">
                            {alertConfig.icon} 
                            {alertConfig.title}
                        </h3>
                        <p className="text-sm leading-relaxed drop-shadow-md">
                            {alertConfig.desc}
                        </p>
                    </div>
                </div>

                {/* Control Panel */}
                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
                    
                    <div className="flex bg-slate-950 p-1 rounded-lg mb-6 border border-slate-800">
                        <button 
                            onClick={() => { setMode('odd'); setProgress(0); }} 
                            className={`flex-1 py-2 text-xs font-bold rounded-md transition-colors ${mode === 'odd' ? 'bg-indigo-600/80 text-white shadow-sm' : 'text-slate-400 hover:bg-slate-800'}`}
                        >
                            Odd Dataset (n = 5)
                        </button>
                        <button 
                            onClick={() => { setMode('even'); setProgress(0); }} 
                            className={`flex-1 py-2 text-xs font-bold rounded-md transition-colors ${mode === 'even' ? 'bg-cyan-600/80 text-white shadow-sm' : 'text-slate-400 hover:bg-slate-800'}`}
                        >
                            Even Dataset (n = 6)
                        </button>
                    </div>

                    <div className="mb-6 border-b border-slate-800 pb-5">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <AlignCenter size={14} /> Evaluation Progress
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className={isComplete ? "text-green-400" : "text-purple-400"}>{progress}% Evaluated</span>
                        </div>
                        <input 
                            type="range" min="0" max="100" step="1" 
                            value={progress} onChange={(e) => setProgress(parseFloat(e.target.value))} 
                            className={`w-full h-2 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 ${isComplete ? 'accent-green-500' : 'accent-purple-500'}`}
                        />
                    </div>

                    <button 
                        onClick={() => setProgress(isComplete ? 0 : 100)} 
                        className={`p-4 rounded-xl flex items-center justify-center gap-2 transition-all border text-sm font-bold shadow-sm ${
                            isComplete ? 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700' : 'bg-slate-800 border-slate-600 text-white hover:bg-slate-700'
                        }`}
                    >
                        <Layers size={18} /> {isComplete ? 'Reset Data' : 'Auto-Evaluate Median'}
                    </button>
                    
                </div>
            </div>
        </div>
    );
}
