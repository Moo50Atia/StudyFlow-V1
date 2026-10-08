import React, { useState, useRef } from 'react';
import { useSceneAnimation } from '../../../hooks/useSceneAnimation';
import { Canvas } from '../../../components/SceneCanvas';
import { OrbitControls, Box, } from '@react-three/drei';
import { Text } from '../../../components/SceneText';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath, InlineMath } from 'react-katex';
import { Play, RotateCcw, Check, Calculator, Info, X as Close, Network, ArrowDown, GitBranch } from 'lucide-react';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

// --- 3D Scene Components ---

function Pipe({ position, args, matRef }: { position: [number, number, number], args: [number, number, number], matRef: React.RefObject<THREE.MeshStandardMaterial | null> }) {
  return (
    <Box position={position} args={args}>
      <meshStandardMaterial ref={matRef} color="#334155" emissive="#000000" emissiveIntensity={0} />
    </Box>
  );
}

function FlowchartScene({ scenario, step, onStepComplete }: any) {
  // Material Refs for coloring pipes and nodes
  const rootNodeMat = useRef<THREE.MeshStandardMaterial>(null!);
  const lNodeMat = useRef<THREE.MeshStandardMaterial>(null!);
  const cNodeMat = useRef<THREE.MeshStandardMaterial>(null!);
  const rNodeMat = useRef<THREE.MeshStandardMaterial>(null!);
  const finalNodeMat = useRef<THREE.MeshStandardMaterial>(null!);

  const pRoot = useRef<THREE.MeshStandardMaterial>(null!);
  const pHL = useRef<THREE.MeshStandardMaterial>(null!);
  const pHC = useRef<THREE.MeshStandardMaterial>(null!); // The center junction
  const pHR = useRef<THREE.MeshStandardMaterial>(null!);
  const pDropL = useRef<THREE.MeshStandardMaterial>(null!);
  const pDropC = useRef<THREE.MeshStandardMaterial>(null!);
  const pDropR = useRef<THREE.MeshStandardMaterial>(null!);
  
  const pBotDropL = useRef<THREE.MeshStandardMaterial>(null!);
  const pBotDropC = useRef<THREE.MeshStandardMaterial>(null!);
  const pBotDropR = useRef<THREE.MeshStandardMaterial>(null!);
  const pBotHL = useRef<THREE.MeshStandardMaterial>(null!);
  const pBotHR = useRef<THREE.MeshStandardMaterial>(null!);
  const pBotFinal = useRef<THREE.MeshStandardMaterial>(null!);

  // Text refs for dynamic updates
  const lNodeText1 = useRef<any>(null!);
  const lNodeText2 = useRef<any>(null!);
  const cNodeText1 = useRef<any>(null!);
  const cNodeText2 = useRef<any>(null!);
  const rNodeText1 = useRef<any>(null!);
  const rNodeText2 = useRef<any>(null!);

  const rootText = useRef<any>(null!);
  const finalText = useRef<any>(null!);

  const activeColor = '#10b981'; // Emerald
  const activeEmissive = '#059669';

  useSceneAnimation(() => {
    // Kill existing tweens
    const allMats = [
      rootNodeMat, lNodeMat, cNodeMat, rNodeMat, finalNodeMat,
      pRoot, pHL, pHC, pHR, pDropL, pDropC, pDropR,
      pBotDropL, pBotDropC, pBotDropR, pBotHL, pBotHR, pBotFinal
    ].map(ref => ref.current).filter(Boolean);

    gsap.killTweensOf(allMats);

    // Initial Setup / Reset all to dormant
    gsap.set(allMats, { color: '#334155', emissive: '#000000', emissiveIntensity: 0 });
    
    // Set text contents based on scenario
    if (rootText.current) {
      if (scenario === 1) rootText.current.text = "2^(x-1) = 2^3";
      if (scenario === 2) rootText.current.text = "4^x = 8";
      if (scenario === 3) rootText.current.text = "3^(x-2) = 5^(x-2)";
    }
    
    if (finalText.current) {
      if (scenario === 1) finalText.current.text = "x = 4";
      if (scenario === 2) finalText.current.text = "x = 1.5";
      if (scenario === 3) finalText.current.text = "x = 2";
    }
    
    // Reset Branch Texts
    if (lNodeText1.current && lNodeText2.current) {
      lNodeText1.current.text = "Equal Bases";
      lNodeText2.current.text = "f(x) = g(x)";
    }
    if (cNodeText1.current && cNodeText2.current) {
      cNodeText1.current.text = "Factorize";
      cNodeText2.current.text = "To Primes";
    }
    if (rNodeText1.current && rNodeText2.current) {
      rNodeText1.current.text = "Unequal/Coprime";
      rNodeText2.current.text = "f(x) = 0";
    }

    if (step === 0) {
      // Just highlight root
      gsap.to(rootNodeMat.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 0.8, duration: 0.5 });
    }
    else if (step === 1) {
      // Keep root lit
      gsap.set(rootNodeMat.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 0.8 });
      
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      // Illuminate path down to selected branch
      tl.to(pRoot.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1, duration: 0.3 });
      
      if (scenario === 1) {
        tl.to(pHL.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1, duration: 0.3 });
        tl.to(pDropL.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1, duration: 0.3 });
        tl.to(lNodeMat.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 0.8, duration: 0.4 });
      } 
      else if (scenario === 2) {
        tl.to(pDropC.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1, duration: 0.3 });
        tl.to(cNodeMat.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 0.8, duration: 0.4 });
      }
      else if (scenario === 3) {
        tl.to(pHR.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1, duration: 0.3 });
        tl.to(pDropR.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1, duration: 0.3 });
        tl.to(rNodeMat.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 0.8, duration: 0.4 });
      }
    }
    else if (step === 2) {
      // Step 2: Apply the logic (change text in branch node)
      // Restore previous step lights instantly
      gsap.set([rootNodeMat.current, pRoot.current], { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1 });
      if (scenario === 1) gsap.set([pHL.current, pDropL.current, lNodeMat.current], { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1 });
      if (scenario === 2) gsap.set([pDropC.current, cNodeMat.current], { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1 });
      if (scenario === 3) gsap.set([pHR.current, pDropR.current, rNodeMat.current], { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1 });
      
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      if (scenario === 1) {
        tl.to(lNodeMat.current, { emissiveIntensity: 2, duration: 0.3, yoyo: true, repeat: 1 });
        tl.call(() => {
          lNodeText1.current.text = "x - 1 = 3";
          lNodeText2.current.text = "Solving...";
        });
      } else if (scenario === 2) {
        tl.to(cNodeMat.current, { emissiveIntensity: 2, duration: 0.3, yoyo: true, repeat: 1 });
        tl.call(() => {
          cNodeText1.current.text = "(2^2)^x = 2^3";
          cNodeText2.current.text = "2x = 3";
        });
      } else if (scenario === 3) {
        tl.to(rNodeMat.current, { emissiveIntensity: 2, duration: 0.3, yoyo: true, repeat: 1 });
        tl.call(() => {
          rNodeText1.current.text = "x - 2 = 0";
          rNodeText2.current.text = "Solving...";
        });
      }
      tl.to({}, { duration: 0.5 }); // tiny buffer
    }
    else if (step === 3) {
      // Step 3: Flow to final solution
      gsap.set([rootNodeMat.current, pRoot.current], { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1 });
      
      const tl = gsap.timeline({ onComplete: onStepComplete });
      
      if (scenario === 1) {
        gsap.set([pHL.current, pDropL.current, lNodeMat.current], { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1 });
        tl.to(pBotDropL.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1, duration: 0.2 });
        tl.to(pBotHL.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1, duration: 0.3 });
      } else if (scenario === 2) {
        gsap.set([pDropC.current, cNodeMat.current], { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1 });
        tl.to(pBotDropC.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1, duration: 0.2 });
      } else if (scenario === 3) {
        gsap.set([pHR.current, pDropR.current, rNodeMat.current], { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1 });
        tl.to(pBotDropR.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1, duration: 0.2 });
        tl.to(pBotHR.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1, duration: 0.3 });
      }
      
      tl.to(pBotFinal.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1, duration: 0.3 });
      tl.to(finalNodeMat.current, { color: activeColor, emissive: activeEmissive, emissiveIntensity: 1, duration: 0.4 });
      tl.to(finalText.current.scale, { x: 1.2, y: 1.2, z: 1.2, duration: 0.2, yoyo: true, repeat: 1 });
    }

  }, [step, scenario]);

  return (
    <group position={[0, 0, 0]}>
      {/* Floor Grid */}
      <gridHelper args={[40, 40, '#1e293b', '#0f172a']} position={[0, -4, -2]} />

      {/* --- NODES --- */}
      {/* Root Node */}
      <group position={[0, 3, 0]}>
        <Box args={[3.5, 1, 0.5]}>
          <meshStandardMaterial ref={rootNodeMat} color="#334155" />
        </Box>
        <Text ref={rootText} position={[0, 0, 0.26]} fontSize={0.4} color="white" fontWeight="bold">
          Equation Node
        </Text>
      </group>

      {/* Branch 1: Left */}
      <group position={[-4, 0, 0]}>
        <Box args={[3.2, 1.2, 0.5]}>
          <meshStandardMaterial ref={lNodeMat} color="#334155" />
        </Box>
        <Text ref={lNodeText1} position={[0, 0.2, 0.26]} fontSize={0.3} color="white" fontWeight="bold">Equal Bases</Text>
        <Text ref={lNodeText2} position={[0, -0.2, 0.26]} fontSize={0.25} color="#94a3b8">f(x) = g(x)</Text>
      </group>

      {/* Branch 2: Center */}
      <group position={[0, 0, 0]}>
        <Box args={[3.2, 1.2, 0.5]}>
          <meshStandardMaterial ref={cNodeMat} color="#334155" />
        </Box>
        <Text ref={cNodeText1} position={[0, 0.2, 0.26]} fontSize={0.3} color="white" fontWeight="bold">Factorize</Text>
        <Text ref={cNodeText2} position={[0, -0.2, 0.26]} fontSize={0.25} color="#94a3b8">To Primes</Text>
      </group>

      {/* Branch 3: Right */}
      <group position={[4, 0, 0]}>
        <Box args={[3.2, 1.2, 0.5]}>
          <meshStandardMaterial ref={rNodeMat} color="#334155" />
        </Box>
        <Text ref={rNodeText1} position={[0, 0.2, 0.26]} fontSize={0.3} color="white" fontWeight="bold">Unequal/Coprime</Text>
        <Text ref={rNodeText2} position={[0, -0.2, 0.26]} fontSize={0.25} color="#94a3b8">f(x) = 0</Text>
      </group>

      {/* Final Solution Node */}
      <group position={[0, -3, 0]}>
        <Box args={[2.5, 1, 0.5]}>
          <meshStandardMaterial ref={finalNodeMat} color="#334155" />
        </Box>
        <Text ref={finalText} position={[0, 0, 0.26]} fontSize={0.5} color="white" fontWeight="bold">
          Solution
        </Text>
      </group>

      {/* --- PIPES (Edges) --- */}
      {/* Top section */}
      <Pipe matRef={pRoot} position={[0, 2, 0]} args={[0.1, 1, 0.1]} />
      <Pipe matRef={pHL} position={[-2, 1.5, 0]} args={[4, 0.1, 0.1]} />
      <Pipe matRef={pHR} position={[2, 1.5, 0]} args={[4, 0.1, 0.1]} />
      
      {/* Drops to nodes */}
      <Pipe matRef={pDropL} position={[-4, 1.05, 0]} args={[0.1, 0.9, 0.1]} />
      <Pipe matRef={pDropC} position={[0, 1.05, 0]} args={[0.1, 0.9, 0.1]} />
      <Pipe matRef={pDropR} position={[4, 1.05, 0]} args={[0.1, 0.9, 0.1]} />

      {/* Drops from nodes */}
      <Pipe matRef={pBotDropL} position={[-4, -1.05, 0]} args={[0.1, 0.9, 0.1]} />
      <Pipe matRef={pBotDropC} position={[0, -1.05, 0]} args={[0.1, 0.9, 0.1]} />
      <Pipe matRef={pBotDropR} position={[4, -1.05, 0]} args={[0.1, 0.9, 0.1]} />

      {/* Bottom horizontal collector */}
      <Pipe matRef={pBotHL} position={[-2, -1.5, 0]} args={[4, 0.1, 0.1]} />
      <Pipe matRef={pBotHR} position={[2, -1.5, 0]} args={[4, 0.1, 0.1]} />

      {/* Final drop to solution */}
      <Pipe matRef={pBotFinal} position={[0, -2, 0]} args={[0.1, 1, 0.1]} />

    </group>
  );
}

// --- Main Application UI ---

export default function App() {
  const [scenario, setScenario] = useState<number>(1);
  const [step, setStep] = useState<number>(0);

  const isComplete = step === 3;
  const isAnimating = false; // Add robust checking if needed

  const nextStep = () => {
    if (step < 3) setStep(step + 1);
  };
  
  const reset = () => {
    setStep(0);
  };

  const handleScenarioChange = (newScenario: number) => {
    setScenario(newScenario);
    setStep(0);
  };

  return (
    <div className="w-full h-full relative overflow-hidden bg-slate-950 text-slate-100 font-sans">
      
      {/* Header */}
      <header className="absolute top-0 inset-x-0 h-16 bg-slate-900/80 backdrop-blur-md border-b border-slate-800 z-20 flex items-center px-6">
        <h1 className="text-lg font-bold bg-gradient-to-r from-emerald-400 to-teal-500 bg-clip-text text-transparent flex items-center gap-3">
          <GitBranch className="w-5 h-5 text-emerald-400" />
          Exponential Equations Decision Tree
        </h1>
      </header>

      {/* 3D Canvas Layer */}
      <div className="absolute inset-0 z-0 touch-none">
        <Canvas camera={{ position: [0, 0, 10], fov: 55 }}>
          <ambientLight intensity={0.6} />
          <directionalLight castShadow position={[10, 15, 10]} intensity={1.5} />
          <spotLight position={[-10, 10, -5]} intensity={1} color="#38bdf8" />
          <OrbitControls makeDefault enableDamping dampingFactor={0.05} maxPolarAngle={Math.PI / 2 + 0.1} />
          <FlowchartScene 
            scenario={scenario} 
            step={step} 
            onStepComplete={() => {}} 
          />
        </Canvas>
      </div>

      {/* Live Math HUD Overlay */}
      <div className="absolute top-20 right-6 z-10 pointer-events-none w-full max-w-[340px]">
        <div className="bg-slate-900/80 backdrop-blur-md border border-slate-700/80 p-5 rounded-2xl shadow-2xl pointer-events-auto transition-all duration-300">
          <h3 className="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-2">
            <Calculator className="w-4 h-4" /> Trace Log
          </h3>
          <div className="bg-slate-950/80 rounded-xl p-4 border border-slate-800 flex flex-col gap-3 text-lg shadow-inner min-h-[140px]">
            
            {/* Step 0 - Initial Equation */}
            <div className={cn("transition-all duration-500", step >= 0 ? "opacity-100" : "opacity-0 h-0 overflow-hidden")}>
              <div className="text-[10px] text-slate-500 uppercase tracking-widest mb-1">Equation</div>
              {scenario === 1 && <BlockMath math={`2^{x-1} = 2^3`} />}
              {scenario === 2 && <BlockMath math={`4^x = 8`} />}
              {scenario === 3 && <BlockMath math={`3^{x-2} = 5^{x-2}`} />}
            </div>

            {/* Step 2 - Rule Applied */}
            <div className={cn("transition-all duration-500", step >= 2 ? "opacity-100" : "opacity-0 h-0 overflow-hidden hidden")}>
              <div className="text-[10px] text-blue-400 uppercase tracking-widest mb-1">Logic Derived</div>
              {scenario === 1 && <BlockMath math={`x - 1 = 3`} />}
              {scenario === 2 && <BlockMath math={`(2^2)^x = 2^3 \\rightarrow 2x = 3`} />}
              {scenario === 3 && <BlockMath math={`x - 2 = 0`} />}
            </div>

            {/* Step 3 - Solution */}
            <div className={cn("transition-all duration-500 text-emerald-400", step >= 3 ? "opacity-100" : "opacity-0 h-0 overflow-hidden hidden")}>
              <div className="text-[10px] text-emerald-500 uppercase tracking-widest mb-1">Solution</div>
              {scenario === 1 && <BlockMath math={`x = 4`} />}
              {scenario === 2 && <BlockMath math={`x = 1.5`} />}
              {scenario === 3 && <BlockMath math={`x = 2`} />}
            </div>

          </div>
        </div>
      </div>

      {/* Bottom Interface: Misconceptions & Controls */}
      <div className="absolute bottom-0 inset-x-0 z-20 pointer-events-none p-4 sm:p-6 flex flex-col gap-4 max-w-7xl mx-auto items-center">
        
        {/* Misconception Trap HUD - specifically for Coprime unequal bases (Scenario 3) */}
        <div className={cn(
          "backdrop-blur-xl p-5 rounded-2xl shadow-2xl pointer-events-auto w-full max-w-4xl transition-all duration-700 transform",
          (scenario === 3 && step >= 1) ? "translate-y-0 opacity-100 scale-100" : "translate-y-12 opacity-0 scale-95 pointer-events-none absolute bottom-0",
          "bg-slate-900/95 border border-slate-700"
        )}>
          <h4 className="text-sm font-semibold text-slate-200 flex items-center gap-2 mb-4 justify-center">
            <Info className="w-4 h-4 text-emerald-400" />
            Handling Unequal Coprime Bases
          </h4>
          
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="bg-red-500/10 border border-red-500/20 rounded-xl p-4 flex items-center gap-4 group">
              <div className="bg-red-500/20 p-3 rounded-full shrink-0">
                <Close className="w-5 h-5 text-red-400" />
              </div>
              <div className="w-full">
                <p className="text-[10px] text-red-400/80 font-bold uppercase tracking-wider mb-1">Fatal Mistake</p>
                <div className="text-sm text-slate-200">
                   <BlockMath math={`3^{x-2} = 5^{x-2} \\rightarrow 3 = 5`} />
                </div>
              </div>
            </div>

            <div className="bg-emerald-500/10 border border-emerald-500/20 rounded-xl p-4 flex items-center gap-4 group">
              <div className="bg-emerald-500/20 p-3 rounded-full shrink-0">
                <Check className="w-5 h-5 text-emerald-400" />
              </div>
              <div className="w-full">
                <p className="text-[10px] text-emerald-400/80 font-bold uppercase tracking-wider mb-1">Correct Rule (Zero Exponent)</p>
                <div className="text-sm text-slate-200">
                  <BlockMath math={`a^{f(x)} = b^{f(x)} \\Rightarrow f(x) = 0`} />
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Master Control Panel */}
        <div className="bg-slate-900/95 backdrop-blur-2xl border border-slate-700/80 p-6 rounded-3xl shadow-[0_10px_50px_-10px_rgba(0,0,0,0.7)] pointer-events-auto w-full transition-all">
          <div className="flex flex-col md:flex-row gap-6 items-center justify-between">
            
            {/* Scenario Selection */}
            <div className="flex flex-col sm:flex-row bg-slate-950 p-1.5 rounded-xl border border-slate-800 w-full md:w-auto shrink-0 shadow-inner gap-1">
              <button 
                onClick={() => handleScenarioChange(1)}
                className={cn("px-4 py-2.5 rounded-lg text-xs sm:text-sm font-semibold transition-all duration-300", scenario === 1 ? "bg-slate-700 text-white shadow-md" : "text-slate-400 hover:text-slate-200")}
              >
                Match Bases
              </button>
              <button 
                onClick={() => handleScenarioChange(2)}
                className={cn("px-4 py-2.5 rounded-lg text-xs sm:text-sm font-semibold transition-all duration-300", scenario === 2 ? "bg-slate-700 text-white shadow-md" : "text-slate-400 hover:text-slate-200")}
              >
                Composite Bases
              </button>
              <button 
                onClick={() => handleScenarioChange(3)}
                className={cn("px-4 py-2.5 rounded-lg text-xs sm:text-sm font-semibold transition-all duration-300", scenario === 3 ? "bg-slate-700 text-white shadow-md" : "text-slate-400 hover:text-slate-200")}
              >
                Unequal Bases
              </button>
            </div>

            {/* Execution Actions */}
            <div className="flex gap-3 w-full md:w-auto shrink-0">
              <button 
                onClick={reset}
                className="flex-1 md:flex-none flex items-center justify-center p-3 px-6 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-xl transition-colors border border-slate-600 shadow-md"
                aria-label="Reset Tree"
              >
                <RotateCcw className="w-5 h-5 md:mr-2" /> <span className="hidden md:inline">Reset</span>
              </button>
              
              <button 
                onClick={nextStep}
                disabled={isComplete}
                className={cn(
                  "flex-1 md:flex-none flex items-center justify-center gap-2 px-8 py-3 rounded-xl font-bold transition-all shadow-lg text-sm",
                  isComplete
                    ? "bg-slate-800 text-slate-500 cursor-not-allowed border border-slate-700" 
                    : "bg-emerald-600 hover:bg-emerald-500 text-white shadow-[0_0_20px_rgba(16,185,129,0.3)]"
                )}
              >
                {step === 0 && <><ArrowDown className="w-5 h-5" /> Trace Path</>}
                {step === 1 && <><Play className="w-5 h-5 fill-current" /> Apply Rule</>}
                {step === 2 && <><Check className="w-5 h-5" /> Derive Solution</>}
                {step === 3 && <><Check className="w-5 h-5" /> Solved</>}
              </button>
            </div>

          </div>
        </div>
      </div>
    </div>
  );
}
