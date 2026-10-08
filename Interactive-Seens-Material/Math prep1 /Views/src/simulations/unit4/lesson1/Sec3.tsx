import React, { useState, useRef, Suspense } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame } from '@react-three/fiber';
import { OrbitControls, Text } from '@react-three/drei';
import * as THREE from 'three';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, CheckCircle, BarChart2, Layers, Plus, Minus, Info } from 'lucide-react';

// ---------------------------------------------------------
// DATA CONSTANTS
// ---------------------------------------------------------

const BINS = [3, 5, 7, 9];
const X_POSITIONS = [-4.5, -1.5, 1.5, 4.5];
const INITIAL_FREQUENCIES = { 3: 1, 5: 3, 7: 1, 9: 1 };

// ---------------------------------------------------------
// 3D SCENE COMPONENTS
// ---------------------------------------------------------

const DataBlock = ({ index, isMode }: { index: number, isMode: boolean }) => {
    const meshRef = useRef<THREE.Mesh>(null);
    // Proxy for smooth scaling and color lerping
    const proxy = useRef({ scale: 0, yOffset: 3, pMode: isMode ? 1 : 0 }).current;

    useFrame(() => {
        proxy.scale = THREE.MathUtils.lerp(proxy.scale, 1, 0.15);
        proxy.yOffset = THREE.MathUtils.lerp(proxy.yOffset, 0, 0.15);
        proxy.pMode = THREE.MathUtils.lerp(proxy.pMode, isMode ? 1 : 0, 0.1);
        
        if (meshRef.current) {
            meshRef.current.scale.setScalar(proxy.scale);
            meshRef.current.position.y = index * 1.05 + 0.525 + proxy.yOffset;
            
            const mat = meshRef.current.material as THREE.MeshStandardMaterial;
            mat.color.lerpColors(new THREE.Color("#3b82f6"), new THREE.Color("#facc15"), proxy.pMode);
            mat.emissive.lerpColors(new THREE.Color("#000000"), new THREE.Color("#ca8a04"), proxy.pMode);
            mat.emissiveIntensity = proxy.pMode * 0.6;
            mat.roughness = THREE.MathUtils.lerp(0.4, 0.2, proxy.pMode);
            mat.metalness = THREE.MathUtils.lerp(0.1, 0.8, proxy.pMode);
        }
    });

    return (
        <mesh ref={meshRef} position={[0, index * 1.05 + 0.525, 0]} castShadow receiveShadow>
            <boxGeometry args={[1, 1, 1]} />
            <meshStandardMaterial color="#3b82f6" roughness={0.4} metalness={0.1} />
            <lineSegments>
                <edgesGeometry args={[new THREE.BoxGeometry(1, 1, 1)]} />
                <lineBasicMaterial color="#0f172a" transparent opacity={0.3} />
            </lineSegments>
        </mesh>
    );
};

const BinTower = ({ value, freq, isMode, isHighest, xPos }: { value: number, freq: number, isMode: boolean, isHighest: boolean, xPos: number }) => {
    const groupRef = useRef<THREE.Group>(null);
    const proxy = useRef({ elev: isMode ? 1 : 0 }).current;
    
    useFrame(() => {
        proxy.elev = THREE.MathUtils.lerp(proxy.elev, isMode ? 1 : 0, 0.1);
        if (groupRef.current) {
            groupRef.current.position.y = proxy.elev * 0.8;
        }
    });

    // Create an array of blocks based on frequency
    const blocks = Array.from({ length: freq });

    return (
        <group position={[xPos, 0, 0]}>
            {/* Base Pedestal */}
            <mesh position={[0, 0.1, 0]} castShadow receiveShadow>
                <cylinderGeometry args={[1.2, 1.4, 0.2, 32]} />
                <meshStandardMaterial color="#1e293b" />
            </mesh>
            
            {/* Ground Value Label */}
            <Text position={[0, -0.5, 1.5]} fontSize={0.6} color="#94a3b8" fontWeight="bold">
                Val: {value}
            </Text>
            
            {/* Misconception Tag */}
            {isHighest && !isMode && (
                <Text position={[0, -1.0, 1.5]} fontSize={0.35} color="#ef4444" fontWeight="bold">
                    (Highest Value)
                </Text>
            )}

            {/* Elevating Block Group */}
            <group ref={groupRef}>
                {blocks.map((_, i) => (
                    <DataBlock key={`${value}-${i}`} index={i} isMode={isMode} />
                ))}
                
                {/* Dynamic Frequency Gauge */}
                <Text 
                    position={[0, Math.max(freq, 0) * 1.05 + 1.2, 0]} 
                    fontSize={0.6} 
                    color={isMode ? "#facc15" : "#f8fafc"} 
                    fontWeight="extrabold"
                >
                    Freq: {freq}
                </Text>
                
                {/* Mode Crown/Badge */}
                {isMode && (
                    <group position={[0, Math.max(freq, 0) * 1.05 + 2.2, 0]}>
                        <Text fontSize={0.5} color="#facc15" fontWeight="extrabold">
                            ★ MODE ★
                        </Text>
                    </group>
                )}
            </group>
        </group>
    );
};

function FrequencyScene({ frequencies, activeModes, maxVal }: { frequencies: Record<number, number>, activeModes: number[], maxVal: number }) {
    return (
        <group position={[0, -2, 0]}>
            <ambientLight intensity={0.6} />
            <directionalLight position={[5, 12, 8]} intensity={0.8} castShadow shadow-mapSize={[1024, 1024]} />
            
            <mesh rotation={[-Math.PI / 2, 0, 0]} receiveShadow position={[0, 0, 0]}>
                <planeGeometry args={[60, 40]} />
                <meshStandardMaterial color="#020617" />
            </mesh>
            <gridHelper args={[60, 40, '#1e293b', '#0f172a']} position={[0, 0.01, 0]} />

            {BINS.map((val, i) => (
                <BinTower 
                    key={val} 
                    value={val} 
                    freq={frequencies[val]} 
                    isMode={activeModes.includes(val)} 
                    isHighest={val === maxVal} 
                    xPos={X_POSITIONS[i]} 
                />
            ))}
        </group>
    );
}

// ---------------------------------------------------------
// MAIN UI COMPONENT
// ---------------------------------------------------------

export default function ModeSim() {
    const [frequencies, setFrequencies] = useState<Record<number, number>>(INITIAL_FREQUENCIES);

    const handleAdd = (val: number) => {
        setFrequencies(prev => ({ ...prev, [val]: Math.min(prev[val] + 1, 8) }));
    };

    const handleRemove = (val: number) => {
        setFrequencies(prev => ({ ...prev, [val]: Math.max(prev[val] - 1, 0) }));
    };

    // Calculate Statistical Metrics
    const maxFreq = Math.max(...Object.values(frequencies));
    const modes = BINS.filter(b => frequencies[b] === maxFreq);
    
    const presentValues = BINS.filter(b => frequencies[b] > 0);
    const maxVal = presentValues.length > 0 ? Math.max(...presentValues) : 0;

    const isUniform = modes.length === BINS.length && maxFreq > 0;
    const isEmpty = maxFreq === 0;
    
    // Only highlight modes if it's not a uniform distribution (all equal)
    const activeModes = (isUniform || isEmpty) ? [] : modes;

    // Dynamic Pedagogical Content
    let mathExpr = '';
    if (isEmpty) {
        mathExpr = `\\text{Empty Dataset}`;
    } else if (isUniform) {
        mathExpr = `\\text{Uniform Distribution} \\implies \\text{No Unique Mode}`;
    } else {
        mathExpr = `\\text{Mode} = \\text{Value with Highest Frequency} \\implies \\mathbf{${activeModes.join(', ')}}`;
    }

    let alertConfig;
    if (isEmpty) {
        alertConfig = {
            title: "Empty Dataset",
            desc: "There are no data points present. Add some blocks using the control panel to find the mode.",
            colorClass: "bg-slate-900/40 border-l-slate-500 border-slate-800 text-slate-100",
            icon: <Info size={18} className="text-slate-400" />
        };
    } else if (isUniform) {
        alertConfig = {
            title: "Uniform Distribution",
            desc: "All values appear with the exact same frequency. Because no single value stands out as the most popular, we say this dataset has no unique mode.",
            colorClass: "bg-slate-900/40 border-l-slate-500 border-slate-800 text-slate-100",
            icon: <Layers size={18} className="text-slate-400" />
        };
    } else if (activeModes.includes(maxVal) && activeModes.length === 1) {
        alertConfig = {
            title: "Coincidental Overlap",
            desc: `The value ${maxVal} is currently both the highest number AND the most frequent (Mode). This can happen, but remember: it's the height of the tower (frequency) that makes it the mode, not the numerical value!`,
            colorClass: "bg-sky-900/40 border-l-sky-500 border-sky-800 text-sky-100",
            icon: <ShieldAlert size={18} className="text-sky-400" />
        };
    } else if (activeModes.length > 1) {
        alertConfig = {
            title: "Multimodal Data",
            desc: `We have a tie! The values ${activeModes.join(' and ')} both appear ${maxFreq} times. A dataset can have multiple modes (bimodal or multimodal) if they share the highest frequency.`,
            colorClass: "bg-purple-900/40 border-l-purple-500 border-purple-800 text-purple-100",
            icon: <Layers size={18} className="text-purple-400" />
        };
    } else {
        alertConfig = {
            title: "Frequency vs Value",
            desc: `Notice that ${maxVal} is the highest numerical value, but ${activeModes[0]} is the MODE because it has the tallest tower (highest frequency: ${maxFreq}). The Mode is always about popularity, not magnitude.`,
            colorClass: "bg-green-900/40 border-l-green-500 border-green-800 text-green-100",
            icon: <CheckCircle size={18} className="text-green-400" />
        };
    }

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex flex-col gap-4 max-w-7xl mx-auto">
                    <div className="flex justify-between items-start md:items-center w-full">
                        <div>
                            <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                                Measures of Central Tendency: The Mode
                            </h1>
                            <p className="text-slate-400 text-sm mt-1">
                                Identifying the most frequently occurring value in a dataset.
                            </p>
                        </div>
                    </div>
                    <div className={`bg-slate-950/80 border p-3 rounded-xl w-full flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-colors duration-500 ${activeModes.length > 0 ? 'border-yellow-500/50' : 'border-slate-700'}`}>
                        <div className={`text-sm md:text-lg px-4 flex items-center justify-center h-full min-h-[3.5rem] w-full ${activeModes.length > 0 ? 'text-yellow-400' : 'text-slate-300'}`}>
                            <BlockMath math={mathExpr} />
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas camera={{ position: [0, 8, 20], fov: 40 }} shadows>
                        <OrbitControls enablePan={false} enableZoom={true} maxPolarAngle={Math.PI / 2 - 0.05} minAzimuthAngle={-0.3} maxAzimuthAngle={0.3} />
                        <FrequencyScene frequencies={frequencies} activeModes={activeModes} maxVal={maxVal} />
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
                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[350px]">
                    
                    <div className="mb-2">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <BarChart2 size={14} /> Frequency Data Points
                        </label>
                        <div className="flex flex-col gap-2 max-h-[30vh] overflow-y-auto pr-2">
                            {BINS.map(val => (
                                <div key={val} className="flex items-center justify-between bg-slate-950 p-2 rounded-lg border border-slate-800">
                                    <span className="font-bold text-slate-300 w-16 text-sm">Value {val}</span>
                                    <div className="flex items-center gap-4">
                                        <button 
                                            onClick={() => handleRemove(val)}
                                            disabled={frequencies[val] === 0}
                                            className="p-1.5 rounded bg-slate-800 text-slate-400 hover:bg-slate-700 disabled:opacity-50 transition-colors"
                                        >
                                            <Minus size={14} />
                                        </button>
                                        <span className={`font-bold w-4 text-center text-sm ${activeModes.includes(val) ? 'text-yellow-400' : 'text-sky-400'}`}>
                                            {frequencies[val]}
                                        </span>
                                        <button 
                                            onClick={() => handleAdd(val)}
                                            disabled={frequencies[val] >= 8}
                                            className="p-1.5 rounded bg-slate-800 text-slate-400 hover:bg-slate-700 disabled:opacity-50 transition-colors"
                                        >
                                            <Plus size={14} />
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <button 
                        onClick={() => setFrequencies(INITIAL_FREQUENCIES)} 
                        className="mt-4 p-3 rounded-xl flex items-center justify-center gap-2 transition-all border text-sm font-bold shadow-sm bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700"
                    >
                        Reset to Default
                    </button>
                    
                </div>
            </div>
        </div>
    );
}
