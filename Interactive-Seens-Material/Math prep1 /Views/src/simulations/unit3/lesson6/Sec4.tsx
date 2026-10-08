import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Line } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { ShieldAlert, GitBranch, Pointer, Maximize, RotateCcw } from 'lucide-react';

// ---------------------------------------------------------
// DATA DEFINITIONS & MATH HELPERS
// ---------------------------------------------------------

const SHAPES: Record<string, any> = {
    'Quad': {
        label: 'Quadrilateral', pos: [0, 6.5, 0],
        pts: [[-1.2, -0.6], [1.3, -0.8], [0.8, 0.8], [-1.0, 0.5]],
        features: [],
        paths: [['Quad']]
    },
    'Trap': {
        label: 'Trapezium', pos: [5, 2, 0],
        pts: [[-1.2, -0.7], [1.2, -0.7], [0.6, 0.7], [-0.8, 0.7]],
        features: ['chev-bot-1', 'chev-top-1'],
        paths: [['Quad', 'Trap']]
    },
    'Para': {
        label: 'Parallelogram', pos: [-5, 2, 0],
        pts: [[-1.2, -0.7], [0.8, -0.7], [1.2, 0.7], [-0.8, 0.7]],
        features: ['chev-bot-1', 'chev-top-1', 'chev-left-2', 'chev-right-2'],
        paths: [['Quad', 'Para']]
    },
    'Rect': {
        label: 'Rectangle', pos: [-8, -3, 0],
        pts: [[-1.2, -0.7], [1.2, -0.7], [1.2, 0.7], [-1.2, 0.7]],
        features: ['chev-bot-1', 'chev-top-1', 'chev-left-2', 'chev-right-2', 'right-angles'],
        paths: [['Quad', 'Para', 'Rect']]
    },
    'Rhomb': {
        label: 'Rhombus', pos: [-2, -3, 0],
        pts: [[-1.329, -0.6], [0.271, -0.6], [1.329, 0.6], [-0.271, 0.6]],
        features: ['chev-bot-1', 'chev-top-1', 'chev-left-2', 'chev-right-2', 'tick-all'],
        paths: [['Quad', 'Para', 'Rhomb']]
    },
    'Sq': {
        label: 'Square', pos: [-5, -8, 0],
        pts: [[-0.8, -0.8], [0.8, -0.8], [0.8, 0.8], [-0.8, 0.8]],
        features: ['chev-bot-1', 'chev-top-1', 'chev-left-2', 'chev-right-2', 'right-angles', 'tick-all'],
        paths: [['Quad', 'Para', 'Rect', 'Sq'], ['Quad', 'Para', 'Rhomb', 'Sq']]
    }
};

const EDGES = [
    { id: 'Q-T', from: 'Quad', to: 'Trap' },
    { id: 'Q-P', from: 'Quad', to: 'Para' },
    { id: 'P-Re', from: 'Para', to: 'Rect' },
    { id: 'P-Rh', from: 'Para', to: 'Rhomb' },
    { id: 'Re-S', from: 'Rect', to: 'Sq' },
    { id: 'Rh-S', from: 'Rhomb', to: 'Sq' }
];

const getAngle = (p1: number[], p2: number[]) => Math.atan2(p2[1]-p1[1], p2[0]-p1[0]);

const isNodeActive = (id: string, activeNode: string) => {
    if (activeNode === 'All') return true;
    return SHAPES[activeNode].paths.some((path: string[]) => path.includes(id));
};

const isEdgeActive = (edge: any, activeNode: string) => {
    if (activeNode === 'All') return true;
    return SHAPES[activeNode].paths.some((path: string[]) => {
        const idxF = path.indexOf(edge.from);
        const idxT = path.indexOf(edge.to);
        return idxF !== -1 && idxT !== -1 && (idxT - idxF === 1);
    });
};

// ---------------------------------------------------------
// FEATURE COMPONENTS
// ---------------------------------------------------------

function Tick({ p1, p2, active }: { p1: number[], p2: number[], active: boolean }) {
    const mid = [(p1[0]+p2[0])/2, (p1[1]+p2[1])/2];
    const ang = getAngle(p1, p2);
    const perp = ang + Math.PI/2;
    const len = 0.25;
    const tickP1: [number, number, number] = [mid[0] + Math.cos(perp)*len/2, mid[1] + Math.sin(perp)*len/2, 0];
    const tickP2: [number, number, number] = [mid[0] - Math.cos(perp)*len/2, mid[1] - Math.sin(perp)*len/2, 0];
    return <Line points={[tickP1, tickP2]} color={active ? "#a855f7" : "#475569"} lineWidth={active ? 3 : 2} />;
}

function Chevron({ p1, p2, count, active }: { p1: number[], p2: number[], count: number, active: boolean }) {
    const mid = [(p1[0]+p2[0])/2, (p1[1]+p2[1])/2];
    const ang = getAngle(p1, p2);
    const dirX = Math.cos(ang);
    const dirY = Math.sin(ang);
    const perpX = -dirY;
    const perpY = dirX;
    const size = 0.2;
    const w = 0.15;
    const color = active ? "#facc15" : "#475569";
    const lW = active ? 3 : 2;

    const drawSingle = (offset: number) => {
        const center = [mid[0] + dirX*offset, mid[1] + dirY*offset];
        const tip: [number, number, number] = [center[0], center[1], 0];
        const left: [number, number, number] = [center[0] - dirX*size + perpX*w, center[1] - dirY*size + perpY*w, 0];
        const right: [number, number, number] = [center[0] - dirX*size - perpX*w, center[1] - dirY*size - perpY*w, 0];
        return <Line points={[left, tip, right]} color={color} lineWidth={lW} key={offset} />;
    };

    if (count === 1) return drawSingle(0);
    return <group>{drawSingle(0.12)}{drawSingle(-0.12)}</group>;
}

function RightAngle({ corner, pLeft, pRight, active }: { corner: number[], pLeft: number[], pRight: number[], active: boolean }) {
    const vL = [pLeft[0]-corner[0], pLeft[1]-corner[1]];
    const lenL = Math.hypot(vL[0], vL[1]);
    const dL = [vL[0]/lenL, vL[1]/lenL];

    const vR = [pRight[0]-corner[0], pRight[1]-corner[1]];
    const lenR = Math.hypot(vR[0], vR[1]);
    const dR = [vR[0]/lenR, vR[1]/lenR];

    const size = 0.3;
    const pt1: [number, number, number] = [corner[0] + dL[0]*size, corner[1] + dL[1]*size, 0];
    const pt2: [number, number, number] = [corner[0] + dL[0]*size + dR[0]*size, corner[1] + dL[1]*size + dR[1]*size, 0];
    const pt3: [number, number, number] = [corner[0] + dR[0]*size, corner[1] + dR[1]*size, 0];

    return <Line points={[pt1, pt2, pt3]} color={active ? "#22c55e" : "#475569"} lineWidth={active ? 3 : 2} />;
}

// ---------------------------------------------------------
// NODE & EDGE COMPONENTS
// ---------------------------------------------------------

function ShapeNode({ id, activeNode, onSelect }: { id: string, activeNode: string, onSelect: (id: string) => void }) {
    const data = SHAPES[id];
    const active = isNodeActive(id, activeNode);
    const isTarget = activeNode === id;
    
    const groupRef = useRef<THREE.Group>(null);
    
    useFrame(() => {
        if (!groupRef.current) return;
        const targetScale = isTarget ? 1.15 : (active ? 1.05 : 0.95);
        groupRef.current.scale.lerp(new THREE.Vector3(targetScale, targetScale, targetScale), 0.1);
    });

    const loopPts = [...data.pts, data.pts[0]].map(p => [p[0], p[1], 0] as [number, number, number]);
    const f = data.features;

    // Glowing highlight box behind the target node
    const highlightOpacity = isTarget ? 0.3 : 0.0;

    return (
        <group position={data.pos} ref={groupRef}>
            {/* Click Catcher */}
            <mesh onClick={(e) => { e.stopPropagation(); onSelect(id); }} visible={false}>
                <planeGeometry args={[4.5, 4.5]} />
                <meshBasicMaterial />
            </mesh>
            
            {/* Target Highlight Glow */}
            <mesh position={[0,0,-0.1]}>
                <planeGeometry args={[4, 4]} />
                <meshBasicMaterial color="#3b82f6" transparent opacity={highlightOpacity} depthWrite={false} />
            </mesh>

            {/* Label */}
            <Text position={[0, 1.4, 0]} fontSize={0.45} color={active ? "#ffffff" : "#64748b"} fontWeight="bold">
                {data.label}
            </Text>

            {/* Base Shape outline */}
            <Line points={loopPts} color={active ? "#3b82f6" : "#475569"} lineWidth={active ? 4 : 2} />

            {/* Features Overlay */}
            {f.includes('chev-bot-1') && <Chevron p1={data.pts[0]} p2={data.pts[1]} count={1} active={active} />}
            {f.includes('chev-top-1') && <Chevron p1={data.pts[3]} p2={data.pts[2]} count={1} active={active} />}
            {f.includes('chev-left-2') && <Chevron p1={data.pts[0]} p2={data.pts[3]} count={2} active={active} />}
            {f.includes('chev-right-2') && <Chevron p1={data.pts[1]} p2={data.pts[2]} count={2} active={active} />}

            {f.includes('tick-all') && (
                <group>
                    <Tick p1={data.pts[0]} p2={data.pts[1]} active={active} />
                    <Tick p1={data.pts[1]} p2={data.pts[2]} active={active} />
                    <Tick p1={data.pts[2]} p2={data.pts[3]} active={active} />
                    <Tick p1={data.pts[3]} p2={data.pts[0]} active={active} />
                </group>
            )}

            {f.includes('right-angles') && (
                <group>
                    <RightAngle corner={data.pts[0]} pLeft={data.pts[3]} pRight={data.pts[1]} active={active} />
                    <RightAngle corner={data.pts[1]} pLeft={data.pts[0]} pRight={data.pts[2]} active={active} />
                    <RightAngle corner={data.pts[2]} pLeft={data.pts[1]} pRight={data.pts[3]} active={active} />
                    <RightAngle corner={data.pts[3]} pLeft={data.pts[2]} pRight={data.pts[0]} active={active} />
                </group>
            )}
        </group>
    );
}

function TreeEdge({ edge, activeNode }: { edge: any, activeNode: string }) {
    const active = isEdgeActive(edge, activeNode);
    const pF = SHAPES[edge.from].pos;
    const pT = SHAPES[edge.to].pos;
    
    // Connect from bottom of parent to top of child to avoid crossing shapes
    const start: [number, number, number] = [pF[0], pF[1] - 1.0, -0.5];
    const end: [number, number, number] = [pT[0], pT[1] + 1.8, -0.5];

    return <Line points={[start, end]} color={active ? "#0ea5e9" : "#1e293b"} lineWidth={active ? 3 : 2} />;
}

function TreeScene({ activeNode, setActiveNode }: { activeNode: string, setActiveNode: (s: string) => void }) {
    const { camera } = useThree();

    useEffect(() => {
        gsap.to(camera.position, { 
            x: -1, y: -0.5, z: 22, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    return (
        <group>
            <ambientLight intensity={0.8} />
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[100, 100]} />
                <meshBasicMaterial color="#020617" />
            </mesh>
            
            {EDGES.map(e => <TreeEdge key={e.id} edge={e} activeNode={activeNode} />)}
            {Object.keys(SHAPES).map(id => (
                <ShapeNode key={id} id={id} activeNode={activeNode} onSelect={setActiveNode} />
            ))}
        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function QuadrilateralFamilyTree() {
    const [activeNode, setActiveNode] = useState('All');

    const getAlertContent = () => {
        switch (activeNode) {
            case 'Sq':
                return {
                    title: "The Isolated Square Myth",
                    text: "Notice how the Square node must receive paths from BOTH the Rectangle (bringing 90° corners) AND the Rhombus (bringing 4 equal sides). A square is not a standalone, unrelated shape; it is mathematically defined as a quadrilateral that is simultaneously a Rectangle and a Rhombus!"
                };
            case 'Rect':
                return {
                    title: "Rectangle Inheritance",
                    text: "A Rectangle inherits all base properties of a Parallelogram (like parallel opposite sides), and specifically adds the constraint of four 90° right angles."
                };
            case 'Rhomb':
                return {
                    title: "Rhombus Inheritance",
                    text: "A Rhombus inherits all base properties of a Parallelogram, adding the specific constraint of four equal side lengths."
                };
            case 'Trap':
                return {
                    title: "The Trapezium Divergence",
                    text: "The Trapezium splits off completely from the Parallelogram branch because it is strictly defined by having exactly ONE pair of parallel sides, terminating its inheritance early."
                };
            case 'Para':
                return {
                    title: "Parallelogram Branch",
                    text: "The Parallelogram branch requires exactly TWO pairs of parallel sides. This is the critical ancestor node for Rectangles, Rhombuses, and Squares."
                };
            case 'Quad':
                return {
                    title: "The Root: Quadrilateral",
                    text: "The most general 4-sided polygon. It possesses no specific requirements for parallel sides, equal angles, or equal lengths."
                };
            default:
                return {
                    title: "The Family Tree",
                    text: "Every special quadrilateral descends from the general 4-sided polygon, accumulating specific structural properties (parallelism, equal angles, equal sides) as they move down the hierarchy. Click any shape to isolate its ancestral inheritance path."
                };
        }
    };

    const alert = getAlertContent();

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans text-slate-200">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-center max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            <GitBranch className="text-blue-500" /> Quadrilateral Inheritance Tree
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Click on any node in the tree to trace its ancestral properties.
                        </p>
                    </div>
                    <button 
                        onClick={() => setActiveNode('All')}
                        className="pointer-events-auto flex items-center gap-2 bg-slate-800 hover:bg-slate-700 border border-slate-600 px-4 py-2 rounded-lg text-sm font-bold transition-colors"
                    >
                        <RotateCcw size={16} /> Show Full Tree
                    </button>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas>
                        <OrbitControls enablePan={true} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <TreeScene activeNode={activeNode} setActiveNode={setActiveNode} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Instruction / Alert Overlay */}
            <div className="absolute bottom-6 inset-x-6 z-10 flex justify-center pointer-events-none max-w-4xl mx-auto">
                <div className={`pointer-events-auto w-full transition-all duration-500 border-l-4 border-t border-r border-b p-5 rounded-2xl shadow-2xl backdrop-blur-md ${activeNode === 'Sq' ? 'border-l-cyan-500 bg-cyan-950/80 border-cyan-800' : 'border-l-blue-500 bg-slate-900/90 border-slate-700'}`}>
                    <h3 className={`font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide ${activeNode === 'Sq' ? 'text-cyan-400' : 'text-blue-400'}`}>
                        {activeNode === 'Sq' ? <ShieldAlert size={18}/> : <Pointer size={18}/>} 
                        {alert.title}
                    </h3>
                    <p className="text-sm leading-relaxed drop-shadow-md">
                        {alert.text}
                    </p>
                </div>
            </div>
        </div>
    );
}
