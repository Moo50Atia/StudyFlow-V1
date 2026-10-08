import React, { useState, useRef, useEffect, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Text, Line } from '@react-three/drei';
import * as THREE from 'three';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, CheckCircle, BarChart2, Layers } from 'lucide-react';

// ---------------------------------------------------------
// 3D SCENE COMPONENTS
// ---------------------------------------------------------

const Bar = ({ index, value, targetMean, progress }: { index: number, value: number, targetMean: number, progress: number }) => {
    const meshRef = useRef<THREE.Mesh>(null);
    const topTextRef = useRef<any>(null);
    
    // Proxy for smooth animation
    const proxy = useRef({ val: value, mean: targetMean, p: progress }).current;
    
    useFrame(() => {
        proxy.val = THREE.MathUtils.lerp(proxy.val, value, 0.1);
        proxy.mean = THREE.MathUtils.lerp(proxy.mean, targetMean, 0.1);
        proxy.p = THREE.MathUtils.lerp(proxy.p, progress, 0.1);
        
        const currentH = THREE.MathUtils.lerp(proxy.val, proxy.mean, proxy.p / 100);
        const scaleY = Math.max(0.01, currentH); // Prevent scale 0 error
        
        if (meshRef.current) {
            meshRef.current.scale.y = scaleY;
            meshRef.current.position.y = scaleY / 2;
            
            // Color transitions from Indigo to Green as it equalizes
            const colorOriginal = new THREE.Color("#6366f1");
            const colorTarget = new THREE.Color("#22c55e");
            (meshRef.current.material as THREE.MeshStandardMaterial).color.lerpColors(colorOriginal, colorTarget, proxy.p / 100);
        }
        
        if (topTextRef.current) {
            topTextRef.current.position.y = scaleY + 0.6;
            topTextRef.current.text = currentH.toFixed(1);
            if (typeof topTextRef.current.sync === 'function') {
                topTextRef.current.sync();
            }
        }
    });

    const xPos = (index - 2) * 2.5;

    return (
        <group position={[xPos, 0, 0]}>
            <mesh ref={meshRef} position={[0, 0.5, 0]} castShadow receiveShadow>
                <boxGeometry args={[1.5, 1, 1.5]} />
                <meshStandardMaterial color="#6366f1" roughness={0.3} metalness={0.1} />
            </mesh>
            
            {/* Ground Label */}
            <Text position={[0, -0.6, 1.2]} fontSize={0.5} color="#94a3b8" fontWeight="bold">
                {`#${index + 1}`}
            </Text>
            
            {/* Dynamic Top Label */}
            <Text ref={topTextRef} position={[0, value + 0.6, 0]} fontSize={0.55} color="#f8fafc" fontWeight="bold">
                {value.toFixed(1)}
            </Text>
        </group>
    );
};

const MeanLine = ({ mean }: { mean: number }) => {
    const groupRef = useRef<THREE.Group>(null);
    const proxy = useRef({ mean: mean }).current;
    
    useFrame(() => {
        proxy.mean = THREE.MathUtils.lerp(proxy.mean, mean, 0.1);
        if (groupRef.current) {
            groupRef.current.position.y = proxy.mean;
        }
    });

    return (
        <group ref={groupRef} position={[0, mean, 0]}>
            <Line points={[[-7, 0, 0], [7, 0, 0]]} color="#facc15" lineWidth={4} dashed dashSize={0.2} gapSize={0.2} />
            <mesh position={[0, 0, 0]} rotation={[-Math.PI/2, 0, 0]}>
                <planeGeometry args={[15, 3]} />
                <meshBasicMaterial color="#facc15" transparent opacity={0.15} side={THREE.DoubleSide} />
            </mesh>
            <Text position={[8.5, 0, 0]} fontSize={0.6} color="#facc15" fontWeight="bold">
                Mean
            </Text>
        </group>
    );
};

function EqualizerScene({ values, progress, mean }: { values: number[], progress: number, mean: number }) {
    return (
        <group position={[0, -3, 0]}>
            <ambientLight intensity={0.6} />
            <directionalLight position={[5, 12, 8]} intensity={0.8} castShadow shadow-mapSize={[1024, 1024]} />
            
            {/* Ground Plane */}
            <mesh rotation={[-Math.PI / 2, 0, 0]} receiveShadow position={[0, -0.01, 0]}>
                <planeGeometry args={[60, 40]} />
                <meshStandardMaterial color="#020617" />
            </mesh>
            <gridHelper args={[60, 40, '#1e293b', '#0f172a']} position={[0, 0, 0]} />

            {/* Towers */}
            {values.map((v, i) => (
                <Bar key={i} index={i} value={v} targetMean={mean} progress={progress} />
            ))}

            {/* Equalization Mean Line */}
            <MeanLine mean={mean} />
        </group>
    );
}

// ---------------------------------------------------------
// MAIN UI COMPONENT
// ---------------------------------------------------------

export default function MeanEqualizerSim() {
    const [values, setValues] = useState<number[]>([3, 5, 7, 9, 6]);
    const [progress, setProgress] = useState(0);

    const sum = values.reduce((a, b) => a + b, 0);
    const mean = sum / values.length;
    const isComplete = progress === 100;

    const getAlertConfig = () => {
        if (progress < 10) {
            return {
                title: "The Middle Value Fallacy",
                desc: "CRITICAL MISCONCEPTION: It's easy to assume the mean is just the 'middle number' in a list (like 7). But the mean isn't about position—it's about equal distribution. Let's level these towers.",
                colorClass: "bg-purple-900/40 border-l-purple-500 border-purple-800 text-purple-100",
                icon: <ShieldAlert size={18} className="text-purple-400" />
            };
        } else if (progress < 100) {
            return {
                title: "Redistributing Values",
                desc: "Watch as height is shaved off the tallest towers and poured into the shortest ones. The total sum (volume) never changes, it is simply being shared equally among all 5 bars.",
                colorClass: "bg-sky-900/40 border-l-sky-500 border-sky-800 text-sky-100",
                icon: <Layers size={18} className="text-sky-400" />
            };
        } else {
            return {
                title: "Perfectly Equalized",
                desc: `SUCCESS: The dataset has been perfectly leveled to a height of ${mean.toFixed(1)}. This leveled height is exactly what the "Mean" represents geometrically!`,
                colorClass: "bg-green-900/40 border-l-green-500 border-green-800 text-green-100",
                icon: <CheckCircle size={18} className="text-green-400" />
            };
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
                                Measures of Central Tendency: The Mean
                            </h1>
                            <p className="text-slate-400 text-sm mt-1">
                                Understanding the mean as the geometric equalizer.
                            </p>
                        </div>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl w-full flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-colors duration-500 ${isComplete ? 'border-green-500/50' : 'border-slate-700'}`}>
                        <div className={`text-sm md:text-lg px-4 flex items-center justify-center h-full min-h-[3.5rem] w-full ${isComplete ? 'text-green-400' : 'text-slate-300'}`}>
                            <BlockMath math={`\\text{Mean} (\\mu) = \\frac{\\text{Sum of Values}}{\\text{Count}} = \\frac{${values.join(' + ')}}{5} = \\frac{${sum}}{5} = ${mean.toFixed(1)}`} />
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas camera={{ position: [0, 4, 18], fov: 45 }} shadows>
                        <OrbitControls enablePan={false} enableZoom={true} maxPolarAngle={Math.PI / 2 - 0.05} minAzimuthAngle={-0.5} maxAzimuthAngle={0.5} />
                        <EqualizerScene values={values} progress={progress} mean={mean} />
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
                    
                    {/* Individual Value Controls */}
                    <div className="mb-4 border-b border-slate-800 pb-4">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <Layers size={14} /> Data Points
                        </label>
                        <div className="flex flex-col gap-2 max-h-[20vh] overflow-y-auto pr-2">
                            {values.map((v, i) => (
                                <div key={i} className="flex items-center gap-3">
                                    <span className="text-xs text-slate-400 font-bold w-6">#{i + 1}</span>
                                    <input 
                                        type="range" min="1" max="15" step="1" 
                                        value={v}
                                        onChange={(e) => {
                                            const newVals = [...values];
                                            newVals[i] = parseInt(e.target.value);
                                            setValues(newVals);
                                            setProgress(0); // Auto-reset progress so student must re-equalize
                                        }}
                                        className="flex-1 h-2 rounded-lg appearance-none cursor-pointer bg-slate-800 accent-sky-500"
                                    />
                                    <span className="text-xs text-sky-400 font-bold w-6 text-right">{v}</span>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="mb-6 border-b border-slate-800 pb-5">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <BarChart2 size={14} /> Equalization Progress
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className={isComplete ? "text-green-400" : "text-purple-400"}>{progress}% Leveled</span>
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
                        <Layers size={18} /> {isComplete ? 'Reset Towers' : 'Auto-Level Data'}
                    </button>
                    
                </div>
            </div>
        </div>
    );
}
