import React, { useState, useRef } from 'react';
import { useSceneAnimation } from '../../../hooks/useSceneAnimation';
import { Canvas } from '../../../components/SceneCanvas';
import { OrbitControls, RoundedBox, } from '@react-three/drei';
import { Text } from '../../../components/SceneText';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { RotateCcw, Info, Check, X as Close, Play, Layers } from 'lucide-react';
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

function Scene({ mode, m, n, step, onComplete }: any) {
  const b1 = useRef<THREE.Group>(null!);
  const b2 = useRef<THREE.Group>(null!);
  const res = useRef<THREE.Group>(null!);
  const opRef = useRef<THREE.Group>(null!);

  useSceneAnimation(() => {
    if (step === 0) {
      b1.current.visible = true;
      b2.current.visible = true;
      opRef.current.visible = true;
      opRef.current.scale.set(1, 1, 1);
      res.current.visible = false;
      
      if (mode === 'multiply') {
        gsap.to(b1.current.position, { x: -2, y: 0, duration: 0.5 });
        gsap.to(b2.current.position, { x: 2, y: 0, duration: 0.5 });
        gsap.to(opRef.current.position, { x: 0, y: 0, duration: 0.5 });
      } else {
        gsap.to(b1.current.position, { x: 0, y: 2, duration: 0.5 });
        gsap.to(b2.current.position, { x: 0, y: -2, duration: 0.5 });
        gsap.to(opRef.current.position, { x: 0, y: 0, duration: 0.5 });
      }
    } else if (step === 1) {
      gsap.to(opRef.current.scale, { x: 0, y: 0, duration: 0.3 });
      
      if (mode === 'multiply') {
        gsap.to(b1.current.position, { x: 0, duration: 1, ease: 'power2.inOut' });
        gsap.to(b2.current.position, { x: 0, duration: 1, ease: 'power2.inOut', onComplete: () => {
          b1.current.visible = false;
          b2.current.visible = false;
          res.current.visible = true;
          res.current.scale.set(0.5, 0.5, 0.5);
          gsap.to(res.current.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: 'back.out(1.5)' });
          onComplete();
        }});
      } else {
        gsap.to(b1.current.position, { y: 0, duration: 1, ease: 'power2.inOut' });
        gsap.to(b2.current.position, { y: 0, duration: 1, ease: 'power2.inOut', onComplete: () => {
          b1.current.visible = false;
          b2.current.visible = false;
          res.current.visible = true;
          res.current.scale.set(0.5, 0.5, 0.5);
          gsap.to(res.current.scale, { x: 1, y: 1, z: 1, duration: 0.5, ease: 'back.out(1.5)' });
          onComplete();
        }});
      }
    }
  }, [step, mode]);

  return (
    <group>
      <group ref={b1}><MathBlock base="a" exponent={m} color="#3b82f6" /></group>
      <group ref={b2}><MathBlock base="a" exponent={n} color="#ec4899" /></group>
      <group ref={opRef}>
        {mode === 'multiply' ? (
          <Text fontSize={1.5} color="white">×</Text>
        ) : (
          <mesh><boxGeometry args={[3, 0.1, 0.1]} /><meshStandardMaterial color="white" /></mesh>
        )}
      </group>
      <group ref={res} visible={false}>
        <MathBlock base="a" exponent={mode === 'multiply' ? m + n : m - n} color="#10b981" />
      </group>
    </group>
  );
}

export default function Sec1() {
  const [m, setM] = useState(3);
  const [n, setN] = useState(2);
  const [mode, setMode] = useState<'multiply' | 'divide'>('multiply');
  const [step, setStep] = useState(0);
  const [isAnimating, setIsAnimating] = useState(false);

  const executeStep = () => {
    if (step === 0 && !isAnimating) {
      setIsAnimating(true);
      setStep(1);
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
          <Scene mode={mode} m={m} n={n} step={step} onComplete={() => setIsAnimating(false)} />
        </Canvas>
      </div>

      {/* Misconception Alert */}
      <div className={cn(
        "absolute top-20 left-6 z-10 w-full max-w-[340px] transition-all duration-700",
        step === 1 && !isAnimating ? "translate-x-0 opacity-100" : "-translate-x-12 opacity-0 pointer-events-none"
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
                  {mode === 'multiply' ? <BlockMath math={`a^{${m}} \\times a^{${n}} = a^{${m * n}}`} /> : <BlockMath math={`\\frac{a^{${m}}}{a^{${n}}} = a^{${m / n}}`} />}
                </div>
              </div>
            </div>
            <div className="bg-emerald-500/10 border border-emerald-500/20 rounded-xl p-3 flex items-center gap-4">
              <Check className="w-6 h-6 text-emerald-400 shrink-0" />
              <div>
                <p className="text-xs text-slate-400">Correct Rule</p>
                <div className="text-emerald-300 font-medium">
                  {mode === 'multiply' ? <BlockMath math={`a^{${m}} \\times a^{${n}} = a^{${m}+${n}}`} /> : <BlockMath math={`\\frac{a^{${m}}}{a^{${n}}} = a^{${m}-${n}}`} />}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Control Panel */}
      <div className="absolute bottom-6 inset-x-6 z-10 pointer-events-none">
        <div className="max-w-4xl mx-auto bg-slate-900/95 backdrop-blur-xl border border-slate-700/50 p-6 rounded-2xl shadow-2xl pointer-events-auto flex flex-col md:flex-row gap-8 items-center">
          
          <div className="flex-1 w-full space-y-4">
            <div className="flex items-center justify-between mb-2">
              <label className="text-sm font-semibold text-slate-300">Mode | الوضع</label>
              <div className="flex bg-slate-800 rounded-lg p-1">
                <button 
                  onClick={() => { if (!isAnimating) { setMode('multiply'); setStep(0); } }}
                  className={cn("px-4 py-1.5 rounded-md text-sm font-medium transition-colors", mode === 'multiply' ? "bg-blue-600 text-white" : "text-slate-400 hover:text-white")}
                >
                  Multiply
                </button>
                <button 
                  onClick={() => { if (!isAnimating) { setMode('divide'); setStep(0); } }}
                  className={cn("px-4 py-1.5 rounded-md text-sm font-medium transition-colors", mode === 'divide' ? "bg-blue-600 text-white" : "text-slate-400 hover:text-white")}
                >
                  Divide
                </button>
              </div>
            </div>
            
            <div className="grid grid-cols-2 gap-6">
              <div className="space-y-2">
                <div className="flex justify-between">
                  <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider">m = {m}</label>
                </div>
                <input 
                  type="range" min="1" max="8" value={m}
                  onChange={(e) => { setM(parseInt(e.target.value)); setStep(0); }}
                  disabled={isAnimating}
                  className="w-full accent-blue-500"
                />
              </div>
              <div className="space-y-2">
                <div className="flex justify-between">
                  <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider">n = {n}</label>
                </div>
                <input 
                  type="range" min="1" max="8" value={n}
                  onChange={(e) => { setN(parseInt(e.target.value)); setStep(0); }}
                  disabled={isAnimating}
                  className="w-full accent-pink-500"
                />
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
              disabled={isAnimating || step === 1}
              className={cn(
                "flex items-center justify-center gap-2 px-8 py-3 rounded-xl font-bold transition-all shadow-lg",
                isAnimating || step === 1
                  ? "bg-slate-800 text-slate-500 cursor-not-allowed border border-slate-700" 
                  : "bg-emerald-600 hover:bg-emerald-500 text-white shadow-[0_0_20px_rgba(16,185,129,0.3)]"
              )}
            >
              <Play className="w-5 h-5" /> Combine
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
