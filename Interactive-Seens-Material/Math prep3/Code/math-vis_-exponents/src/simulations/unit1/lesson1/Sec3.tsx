import React, { useState, useRef } from 'react';
import { useSceneAnimation } from '../../../hooks/useSceneAnimation';
import { Canvas } from '../../../components/SceneCanvas';
import { OrbitControls, RoundedBox, } from '@react-three/drei';
import { Text } from '../../../components/SceneText';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { RotateCcw, Info, Check, X as Close, Play } from 'lucide-react';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

const MathBlock = React.forwardRef(({ position, base, exponent, color }: any, ref: any) => {
  return (
    <group ref={ref} position={position}>
      <RoundedBox args={[1.5, 1.5, 1.5]} radius={0.2}>
        <meshStandardMaterial color={color} />
      </RoundedBox>
      <Text position={[0, 0, 0.76]} fontSize={0.8} color="white" fontWeight="bold">
        {base}
      </Text>
      <Text position={[0.5, 0.5, 0.76]} fontSize={0.4} color="#fcd34d" fontWeight="bold">
        {exponent}
      </Text>
    </group>
  );
});

function Scene({ mode, m, n, k, step, onComplete }: any) {
  const b1 = useRef<THREE.Group>(null!);
  const b2 = useRef<THREE.Group>(null!);
  const bracketRef = useRef<THREE.Group>(null!);
  const outerTextRef = useRef<THREE.Group>(null!);
  
  const c1 = useRef<THREE.Mesh>(null!);
  const c2 = useRef<THREE.Mesh>(null!);

  useSceneAnimation(() => {
    const isProd = mode === 'product';
    if (step === 0) {
      b1.current.position.set(isProd ? -1.2 : 0, isProd ? 0 : 1.2, 0);
      b2.current.position.set(isProd ? 1.2 : 0, isProd ? 0 : -1.2, 0);
      bracketRef.current.visible = true;
      outerTextRef.current.visible = true;
      c1.current.visible = false;
      c2.current.visible = false;
    } else if (step === 1) { 
      c1.current.visible = true;
      c2.current.visible = true;
      c1.current.position.set(isProd ? 2 : 1.5, isProd ? 2 : 2.5, 0);
      c2.current.position.set(isProd ? 2 : 1.5, isProd ? 2 : 2.5, 0);
      
      gsap.to(c1.current.position, {
        x: isProd ? -0.7 : 0.5,
        y: isProd ? 0.5 : 1.7,
        duration: 1,
        ease: 'power2.inOut'
      });
      gsap.to(c2.current.position, {
        x: isProd ? 1.7 : 0.5,
        y: isProd ? 0.5 : -0.7,
        duration: 1,
        ease: 'power2.inOut',
        onComplete: () => {
          c1.current.visible = false;
          c2.current.visible = false;
          onComplete();
        }
      });
    } else if (step === 2) { 
      bracketRef.current.visible = false;
      outerTextRef.current.visible = false;
      gsap.to(b1.current.position, { x: isProd ? -1.8 : 0, y: isProd ? 0 : 1.8, duration: 0.5 });
      gsap.to(b2.current.position, { x: isProd ? 1.8 : 0, y: isProd ? 0 : -1.8, duration: 0.5 });
      onComplete();
    }
  }, [step, mode, m, n, k]);

  const b1Exp = step >= 2 ? m * k : m;
  const b2Exp = step >= 2 ? n * k : n;

  return (
    <group>
      <group ref={bracketRef}>
        <Text position={mode === 'product' ? [-2.5, 0, 0] : [-1.5, 0, 0]} fontSize={3.5} color="white">[</Text>
        <Text position={mode === 'product' ? [2.5, 0, 0] : [1.5, 0, 0]} fontSize={3.5} color="white">]</Text>
        {mode === 'quotient' && (
          <mesh position={[0, 0, 0]}>
            <boxGeometry args={[2.5, 0.1, 0.1]} />
            <meshStandardMaterial color="white" />
          </mesh>
        )}
      </group>
      
      <group ref={outerTextRef} position={mode === 'product' ? [3.2, 1.8, 0] : [2.2, 2.2, 0]}>
        <Text fontSize={0.8} color="#fcd34d" fontWeight="bold">{k}</Text>
      </group>
      
      <group ref={b1}><MathBlock base="a" exponent={b1Exp} color="#3b82f6" /></group>
      <group ref={b2}><MathBlock base="b" exponent={b2Exp} color="#ec4899" /></group>
      
      <mesh ref={c1}><sphereGeometry args={[0.15]} /><meshBasicMaterial color="#fcd34d" /></mesh>
      <mesh ref={c2}><sphereGeometry args={[0.15]} /><meshBasicMaterial color="#fcd34d" /></mesh>
    </group>
  );
}

export default function Sec3() {
  const [m, setM] = useState(2);
  const [n, setN] = useState(3);
  const [k, setK] = useState(4);
  const [mode, setMode] = useState<'product' | 'quotient'>('product');
  const [step, setStep] = useState(0);
  const [isAnimating, setIsAnimating] = useState(false);

  const executeStep = () => {
    if (step < 2 && !isAnimating) {
      setIsAnimating(true);
      setStep(step + 1);
    }
  };

  const reset = () => {
    if (!isAnimating) {
      setStep(0);
    }
  };

  return (
    <div className="w-full h-full relative overflow-hidden bg-slate-950 text-slate-100 font-sans">
      <div className="absolute inset-0 z-0 touch-none">
        <Canvas camera={{ position: [0, 0, 10], fov: 50 }}>
          <ambientLight intensity={0.5} />
          <directionalLight castShadow position={[5, 10, 5]} intensity={1.5} />
          <OrbitControls makeDefault enableDamping dampingFactor={0.05} />
          <Scene mode={mode} m={m} n={n} k={k} step={step} onComplete={() => setIsAnimating(false)} />
        </Canvas>
      </div>

      {/* Misconception Alert */}
      <div className={cn(
        "absolute top-20 left-6 z-10 w-full max-w-[340px] transition-all duration-700",
        step === 2 && !isAnimating ? "translate-x-0 opacity-100" : "-translate-x-12 opacity-0 pointer-events-none"
      )}>
        <div className="bg-slate-900/95 backdrop-blur-xl border border-slate-700 rounded-2xl shadow-2xl p-5">
          <h4 className="text-sm font-semibold text-slate-200 flex items-center gap-2 mb-4">
            <Info className="w-4 h-4 text-amber-400" />
            Misconception Trap | تنبيه
          </h4>
          <div className="flex flex-col gap-3">
            <div className="bg-red-500/10 border border-red-500/20 rounded-xl p-3 flex items-center gap-4">
              <Close className="w-6 h-6 text-red-400 shrink-0" />
              <div>
                <p className="text-xs text-slate-400">Incorrect</p>
                <div className="text-red-300 font-medium">
                  {mode === 'product' ? <BlockMath math={`(a+b)^{${k}} = a^{${k}} + b^{${k}}`} /> : <BlockMath math={`(a-b)^{${k}} = a^{${k}} - b^{${k}}`} />}
                </div>
              </div>
            </div>
            <div className="bg-emerald-500/10 border border-emerald-500/20 rounded-xl p-3 flex items-center gap-4">
              <Check className="w-6 h-6 text-emerald-400 shrink-0" />
              <div>
                <p className="text-xs text-slate-400">Correct Rule</p>
                <div className="text-emerald-300 font-medium">
                  {mode === 'product' ? <BlockMath math={`(ab)^{${k}} = a^{${k}} b^{${k}}`} /> : <BlockMath math={`(\\frac{a}{b})^{${k}} = \\frac{a^{${k}}}{b^{${k}}}`} />}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Control Panel */}
      <div className="absolute bottom-6 inset-x-6 z-10 pointer-events-none">
        <div className="max-w-5xl mx-auto bg-slate-900/95 backdrop-blur-xl border border-slate-700/50 p-6 rounded-2xl shadow-2xl pointer-events-auto flex flex-col md:flex-row gap-8 items-center">
          
          <div className="flex-1 w-full space-y-4">
            <div className="flex items-center justify-between mb-2">
              <label className="text-sm font-semibold text-slate-300">Mode | الوضع</label>
              <div className="flex bg-slate-800 rounded-lg p-1">
                <button 
                  onClick={() => { if (!isAnimating) { setMode('product'); setStep(0); } }}
                  className={cn("px-4 py-1.5 rounded-md text-sm font-medium transition-colors", mode === 'product' ? "bg-blue-600 text-white" : "text-slate-400 hover:text-white")}
                >
                  Product
                </button>
                <button 
                  onClick={() => { if (!isAnimating) { setMode('quotient'); setStep(0); } }}
                  className={cn("px-4 py-1.5 rounded-md text-sm font-medium transition-colors", mode === 'quotient' ? "bg-blue-600 text-white" : "text-slate-400 hover:text-white")}
                >
                  Quotient
                </button>
              </div>
            </div>
            
            <div className="grid grid-cols-3 gap-4">
              <div className="space-y-2">
                <div className="flex justify-between">
                  <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider">m = {m}</label>
                </div>
                <input type="range" min="1" max="5" value={m} onChange={(e) => { setM(parseInt(e.target.value)); setStep(0); }} disabled={isAnimating} className="w-full accent-blue-500" />
              </div>
              <div className="space-y-2">
                <div className="flex justify-between">
                  <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider">n = {n}</label>
                </div>
                <input type="range" min="1" max="5" value={n} onChange={(e) => { setN(parseInt(e.target.value)); setStep(0); }} disabled={isAnimating} className="w-full accent-pink-500" />
              </div>
              <div className="space-y-2">
                <div className="flex justify-between">
                  <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider">k = {k}</label>
                </div>
                <input type="range" min="2" max="5" value={k} onChange={(e) => { setK(parseInt(e.target.value)); setStep(0); }} disabled={isAnimating} className="w-full accent-amber-500" />
              </div>
            </div>
          </div>

          <div className="flex gap-3 shrink-0">
            <button 
              onClick={reset}
              disabled={isAnimating || step === 0}
              className="flex items-center justify-center p-3 px-6 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-xl transition-colors border border-slate-600 disabled:opacity-50"
            >
              <RotateCcw className="w-5 h-5 mr-2" /> Reset
            </button>
            
            <button 
              onClick={executeStep}
              disabled={isAnimating || step === 2}
              className={cn(
                "flex items-center justify-center gap-2 px-8 py-3 rounded-xl font-bold transition-all shadow-lg",
                isAnimating || step === 2
                  ? "bg-slate-800 text-slate-500 cursor-not-allowed border border-slate-700" 
                  : "bg-emerald-600 hover:bg-emerald-500 text-white shadow-[0_0_20px_rgba(16,185,129,0.3)]"
              )}
            >
              <Play className="w-5 h-5" /> {step === 0 ? 'Distribute' : 'Resolve'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
