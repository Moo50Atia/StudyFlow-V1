import React, { useState, useRef, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Text, Line, Sphere, Cone } from '@react-three/drei';
import * as THREE from 'three';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, CheckCircle, Navigation, Anchor, AlertTriangle, Crosshair } from 'lucide-react';

// ---------------------------------------------------------
// CONSTANTS & SCALING
// ---------------------------------------------------------

const BASE_DATA = [2, 3, 4, 5, 6];
const BASE_SUM = BASE_DATA.reduce((a, b) => a + b, 0); // 20
const SCALE = 0.15; // Physical scale factor for X coordinates

// ---------------------------------------------------------
// 3D SCENE COMPONENTS
// ---------------------------------------------------------

const DataCluster = () => {
    return (
        <group>
            {BASE_DATA.map((val, i) => (
                <group key={`base-${i}`} position={[val * SCALE, 0, 0]}>
                    <Sphere args={[0.3, 32, 32]}>
                        <meshStandardMaterial color="#3b82f6" roughness={0.3} metalness={0.2} />
                    </Sphere>
                    <Text position={[0, -0.6, 0]} fontSize={0.4} color="#94a3b8" fontWeight="bold">
                        {val}
                    </Text>
                </group>
            ))}
            
            {/* Cluster Highlight Box */}
            <mesh position={[4 * SCALE, 0, -0.5]}>
                <boxGeometry args={[5 * SCALE, 1.5, 0.2]} />
                <meshBasicMaterial color="#3b82f6" transparent opacity={0.15} />
            </mesh>
            <Text position={[4 * SCALE, 1, -0.5]} fontSize={0.3} color="#60a5fa" fontWeight="bold">
                Dense Data Cluster
            </Text>
        </group>
    );
};

const Outlier = ({ value, isActive }: { value: number, isActive: boolean }) => {
    const groupRef = useRef<THREE.Group>(null);
    const proxy = useRef({ x: value * SCALE, active: isActive ? 1 : 0 }).current;

    useFrame(() => {
        proxy.x = THREE.MathUtils.lerp(proxy.x, value * SCALE, 0.1);
        proxy.active = THREE.MathUtils.lerp(proxy.active, isActive ? 1 : 0, 0.1);

        if (groupRef.current) {
            groupRef.current.position.x = proxy.x;
            groupRef.current.scale.setScalar(proxy.active);
            groupRef.current.visible = proxy.active > 0.01;
            
            // Add a pulsing effect to the outlier
            const time = Date.now() * 0.005;
            const pulse = 1 + Math.sin(time) * 0.1;
            groupRef.current.scale.set(proxy.active * pulse, proxy.active * pulse, proxy.active * pulse);
        }
    });

    return (
        <group ref={groupRef} position={[value * SCALE, 0, 0]}>
            <Sphere args={[0.4, 32, 32]}>
                <meshStandardMaterial color="#ef4444" roughness={0.2} metalness={0.5} emissive="#ef4444" emissiveIntensity={0.2} />
            </Sphere>
            <Text position={[0, -0.8, 0]} fontSize={0.5} color="#fca5a5" fontWeight="bold">
                {value}
            </Text>
            <Text position={[0, 0.8, 0]} fontSize={0.3} color="#ef4444" fontWeight="bold">
                EXTREME OUTLIER
            </Text>
        </group>
    );
};

const MetricPointer = ({ type, value, color }: { type: 'Mean' | 'Median', value: number, color: string }) => {
    const groupRef = useRef<THREE.Group>(null);
    const textRef = useRef<any>(null);
    const proxy = useRef({ x: value * SCALE, val: value }).current;
    
    const isMean = type === 'Mean';
    const yPos = isMean ? 2 : -2;
    const rotZ = isMean ? Math.PI : 0;

    useFrame(() => {
        proxy.x = THREE.MathUtils.lerp(proxy.x, value * SCALE, 0.1);
        proxy.val = THREE.MathUtils.lerp(proxy.val, value, 0.1);

        if (groupRef.current) {
            groupRef.current.position.x = proxy.x;
        }
        if (textRef.current) {
            textRef.current.text = `${type}: ${proxy.val.toFixed(1)}`;
            if (typeof textRef.current.sync === 'function') textRef.current.sync();
        }
    });

    return (
        <group ref={groupRef} position={[value * SCALE, yPos, 0]}>
            <Cone args={[0.3, 0.8, 4]} rotation={[0, 0, rotZ]}>
                <meshStandardMaterial color={color} emissive={color} emissiveIntensity={0.4} />
            </Cone>
            <Line points={[[0, 0, 0], [0, isMean ? -1.5 : 1.5, 0]]} color={color} lineWidth={2} dashed dashSize={0.1} gapSize={0.1} />
            <Text 
                ref={textRef}
                position={[0, isMean ? 0.8 : -0.8, 0]} 
                fontSize={0.5} 
                color={color} 
                fontWeight="extrabold"
            >
                {`${type}: ${value.toFixed(1)}`}
            </Text>
        </group>
    );
};

const CameraRig = ({ hasOutlier, outlierVal }: { hasOutlier: boolean, outlierVal: number }) => {
    useFrame((state) => {
        // Calculate the center point between the cluster (approx X=4) and the outlier
        const currentMaxVal = hasOutlier ? Math.max(10, outlierVal) : 10;
        const targetX = hasOutlier ? (4 * SCALE + currentMaxVal * SCALE) / 2 : 4 * SCALE;
        
        // Dynamically adjust Z to fit the width of the data spread
        const spread = hasOutlier ? currentMaxVal * SCALE : 10 * SCALE;
        const targetZ = Math.max(12, spread * 1.5);
        
        state.camera.position.lerp(new THREE.Vector3(targetX, 4, targetZ), 0.05);
        state.camera.lookAt(targetX, 0, 0);
    });
    return null;
};

function ComparisonScene({ hasOutlier, outlierVal, mean, median }: { hasOutlier: boolean, outlierVal: number, mean: number, median: number }) {
    return (
        <group position={[0, -0.5, 0]}>
            <ambientLight intensity={0.7} />
            <directionalLight position={[10, 20, 15]} intensity={1} castShadow />
            
            <CameraRig hasOutlier={hasOutlier} outlierVal={outlierVal} />

            {/* Base Grid & Number Line Track */}
            <mesh position={[0, -0.2, 0]} rotation={[-Math.PI/2, 0, 0]}>
                <planeGeometry args={[200, 40]} />
                <meshStandardMaterial color="#020617" />
            </mesh>
            <gridHelper args={[200, 100, '#1e293b', '#0f172a']} position={[0, -0.19, 0]} />
            
            <Line points={[[-10, 0, 0], [100, 0, 0]]} color="#475569" lineWidth={4} />

            {/* Data */}
            <DataCluster />
            <Outlier value={outlierVal} isActive={hasOutlier} />

            {/* Dynamic Metric Indicators */}
            <MetricPointer type="Mean" value={mean} color="#facc15" />
            <MetricPointer type="Median" value={median} color="#22c55e" />
        </group>
    );
}

// ---------------------------------------------------------
// MAIN UI COMPONENT
// ---------------------------------------------------------

export default function MeanVsMedianSim() {
    const [hasOutlier, setHasOutlier] = useState(false);
    const [outlierVal, setOutlierVal] = useState(100);

    const currentData = hasOutlier ? [...BASE_DATA, outlierVal] : BASE_DATA;
    const sum = currentData.reduce((a, b) => a + b, 0);
    const mean = sum / currentData.length;
    
    // Median calculation: 
    // Without outlier (5 items): [2, 3, 4, 5, 6] -> median is 4
    // With outlier (6 items): [2, 3, 4, 5, 6, X] -> median is average of 4 and 5 -> 4.5
    const median = hasOutlier ? 4.5 : 4;

    const getAlertConfig = () => {
        if (!hasOutlier) {
            return {
                title: "Uniform Distribution",
                desc: "When data is clustered closely together without extreme values, the Mean and Median are virtually identical (4.0). Both perfectly represent the 'center' of the dataset.",
                colorClass: "bg-slate-900/40 border-l-slate-500 border-slate-800 text-slate-100",
                icon: <CheckCircle size={18} className="text-slate-400" />
            };
        } else if (outlierVal < 50) {
            return {
                title: "The Pull of the Outlier",
                desc: "An outlier has entered! Because the Mean calculates total weight, it is instantly dragged to the right. The Median only shifts slightly, remaining safely inside the main cluster.",
                colorClass: "bg-orange-900/40 border-l-orange-500 border-orange-800 text-orange-100",
                icon: <AlertTriangle size={18} className="text-orange-400" />
            };
        } else {
            return {
                title: "Extreme Skew",
                desc: "CRITICAL: The Mean is now completely detached from the data cluster. It represents nobody! The Median remains a robust, accurate anchor. ALWAYS use the Median when your data has extreme outliers.",
                colorClass: "bg-red-900/40 border-l-red-500 border-red-800 text-red-100",
                icon: <ShieldAlert size={18} className="text-red-400" />
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
                                Mean vs. Median: The Outlier Effect
                            </h1>
                            <p className="text-slate-400 text-sm mt-1">
                                Discovering which metric survives extreme, skewed data.
                            </p>
                        </div>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl w-full flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-colors duration-500 ${hasOutlier ? 'border-red-500/50' : 'border-slate-700'}`}>
                        <div className="text-sm md:text-lg px-4 flex flex-col md:flex-row items-center justify-center gap-6 h-full min-h-[3.5rem] w-full text-slate-300">
                            <div className="flex items-center gap-2 text-yellow-400">
                                <BlockMath math={`\\mu = \\frac{${sum}}{${currentData.length}} = ${mean.toFixed(1)}`} />
                            </div>
                            <div className="hidden md:block w-px h-8 bg-slate-700"></div>
                            <div className="flex items-center gap-2 text-green-400">
                                <BlockMath math={`\\text{Med} = ${median.toFixed(1)}`} />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas>
                        <ComparisonScene hasOutlier={hasOutlier} outlierVal={outlierVal} mean={mean} median={median} />
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
                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[400px]">
                    
                    <div className="flex items-center justify-between bg-slate-950 p-3 rounded-xl mb-6 border border-slate-800">
                        <label className="text-sm font-bold text-slate-300 flex items-center gap-2 cursor-pointer">
                            <Crosshair size={16} className={hasOutlier ? "text-red-400" : "text-slate-500"} />
                            Inject Extreme Outlier
                        </label>
                        <div 
                            className={`w-12 h-6 rounded-full p-1 cursor-pointer transition-colors ${hasOutlier ? 'bg-red-500' : 'bg-slate-700'}`}
                            onClick={() => setHasOutlier(!hasOutlier)}
                        >
                            <div className={`w-4 h-4 rounded-full bg-white transition-transform ${hasOutlier ? 'translate-x-6' : 'translate-x-0'}`} />
                        </div>
                    </div>

                    <div className={`transition-opacity duration-300 ${hasOutlier ? 'opacity-100 pointer-events-auto' : 'opacity-30 pointer-events-none'}`}>
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <Navigation size={14} /> Outlier Magnitude
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className="text-red-400">Value: {outlierVal}</span>
                        </div>
                        <input 
                            type="range" min="10" max="500" step="1" 
                            value={outlierVal} 
                            onChange={(e) => setOutlierVal(parseFloat(e.target.value))} 
                            className="w-full h-2 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 accent-red-500"
                        />
                        <div className="flex justify-between text-[10px] text-slate-500 font-bold mt-2">
                            <span>10</span>
                            <span>500</span>
                        </div>
                    </div>

                    <div className="mt-4 flex gap-2">
                        <div className="flex-1 bg-yellow-900/20 border border-yellow-700/50 p-2 rounded-lg flex items-center justify-center gap-2">
                            <Navigation size={14} className="text-yellow-400" />
                            <span className="text-xs font-bold text-yellow-400">Mean pulls Right</span>
                        </div>
                        <div className="flex-1 bg-green-900/20 border border-green-700/50 p-2 rounded-lg flex items-center justify-center gap-2">
                            <Anchor size={14} className="text-green-400" />
                            <span className="text-xs font-bold text-green-400">Median Anchors</span>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
