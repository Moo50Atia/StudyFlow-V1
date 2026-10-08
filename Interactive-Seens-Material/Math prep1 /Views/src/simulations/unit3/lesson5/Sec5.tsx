import React, { useState, useRef, useEffect, Suspense, useMemo } from 'react';
import { Canvas } from '../../../components/common/SceneCanvas';
import { useFrame, useThree } from '@react-three/fiber';
import { OrbitControls, Environment, Text, Sphere } from '@react-three/drei';
import * as THREE from 'three';
import gsap from 'gsap';
import 'katex/dist/katex.min.css';
import { BlockMath } from 'react-katex';
import { ShieldAlert, SplitSquareHorizontal, Shapes, Layers } from 'lucide-react';

// ---------------------------------------------------------
// 3D GEOMETRY HELPERS
// ---------------------------------------------------------

const updateArcGeom = (geom: THREE.BufferGeometry, start: number, end: number, r: number, segments: number = 64) => {
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

const updateSquareGeom = (geom: THREE.BufferGeometry, v: THREE.Vector3, d1: THREE.Vector3, d2: THREE.Vector3, size: number) => {
    const pos = geom.attributes.position.array as Float32Array;
    const p1 = new THREE.Vector3().addVectors(v, d1.clone().multiplyScalar(size));
    const p2 = new THREE.Vector3().addVectors(p1, d2.clone().multiplyScalar(size));
    const p3 = new THREE.Vector3().addVectors(v, d2.clone().multiplyScalar(size));

    pos[0] = p1.x - v.x; pos[1] = p1.y - v.y; pos[2] = 0;
    pos[3] = p2.x - v.x; pos[4] = p2.y - v.y; pos[5] = 0;
    pos[6] = p3.x - v.x; pos[7] = p3.y - v.y; pos[8] = 0;
    
    geom.setDrawRange(0, 3);
    geom.attributes.position.needsUpdate = true;
};

const updateTick = (geom: THREE.BufferGeometry, p1: THREE.Vector3, p2: THREE.Vector3, count: number) => {
    const pos = geom.attributes.position.array as Float32Array;
    const dir = new THREE.Vector3().subVectors(p2, p1).normalize();
    const perp = new THREE.Vector3(-dir.y, dir.x, 0);
    const mid = new THREE.Vector3().addVectors(p1, p2).multiplyScalar(0.5);
    const size = 0.2;

    if (count === 1) {
        const top = mid.clone().add(perp.clone().multiplyScalar(size));
        const bot = mid.clone().sub(perp.clone().multiplyScalar(size));
        pos.set([top.x, top.y, 0, bot.x, bot.y, 0]);
        geom.setDrawRange(0, 2);
    } else {
        const m1 = mid.clone().add(dir.clone().multiplyScalar(0.15));
        const m2 = mid.clone().sub(dir.clone().multiplyScalar(0.15));
        const t1 = m1.clone().add(perp.clone().multiplyScalar(size));
        const b1 = m1.clone().sub(perp.clone().multiplyScalar(size));
        const t2 = m2.clone().add(perp.clone().multiplyScalar(size));
        const b2 = m2.clone().sub(perp.clone().multiplyScalar(size));
        pos.set([t1.x, t1.y, 0, b1.x, b1.y, 0, t2.x, t2.y, 0, b2.x, b2.y, 0]);
        geom.setDrawRange(0, 4);
    }
    geom.attributes.position.needsUpdate = true;
};

const updateChevron = (geom: THREE.BufferGeometry, p1: THREE.Vector3, p2: THREE.Vector3, count: number) => {
    const pos = geom.attributes.position.array as Float32Array;
    const dir = new THREE.Vector3().subVectors(p2, p1).normalize();
    const perp = new THREE.Vector3(-dir.y, dir.x, 0);
    const mid = new THREE.Vector3().addVectors(p1, p2).multiplyScalar(0.5);
    const size = 0.3;
    const w = 0.25; 

    if (count === 1) {
        const tip = mid.clone();
        const left = mid.clone().sub(dir.clone().multiplyScalar(size)).add(perp.clone().multiplyScalar(w));
        const right = mid.clone().sub(dir.clone().multiplyScalar(size)).sub(perp.clone().multiplyScalar(w));
        pos.set([left.x, left.y, 0, tip.x, tip.y, 0, tip.x, tip.y, 0, right.x, right.y, 0]);
        geom.setDrawRange(0, 4);
    }
    geom.attributes.position.needsUpdate = true;
};

const updateLine = (mesh: THREE.Mesh, p1: THREE.Vector3, p2: THREE.Vector3) => {
    const dist = p1.distanceTo(p2);
    mesh.scale.y = dist;
    mesh.position.copy(p1).lerp(p2, 0.5);
    mesh.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), p2.clone().sub(p1).normalize());
};

// ---------------------------------------------------------
// 3D SCENE
// ---------------------------------------------------------

function Scene({ tiltDeg, isoTrap }: { tiltDeg: number, isoTrap: boolean }) {
    const { camera } = useThree();
    const proxy = useRef({ tiltRad: tiltDeg * Math.PI / 180, trapState: isoTrap ? 1 : 0 }).current;
    
    // Group 1: Rhombus / Square
    const baseLen = 8.0; 
    const pAL = useMemo(() => new THREE.Vector3(-14, -4, 0), []);
    const pBL = useMemo(() => new THREE.Vector3(-6, -4, 0), []);

    // Group 2: Trapezium
    const pDR = useMemo(() => new THREE.Vector3(2, -4, 0), []);
    const pCR = useMemo(() => new THREE.Vector3(12, -4, 0), []);

    // Refs L
    const lABL = useRef<THREE.Mesh>(null);
    const lBCL = useRef<THREE.Mesh>(null);
    const lCDL = useRef<THREE.Mesh>(null);
    const lDAL = useRef<THREE.Mesh>(null);
    const dACL = useRef<THREE.Mesh>(null);
    const dBDL = useRef<THREE.Mesh>(null);
    const ptCL = useRef<THREE.Group>(null);
    const ptDL = useRef<THREE.Group>(null);
    
    // Refs R
    const lABR = useRef<THREE.Mesh>(null);
    const lBCR = useRef<THREE.Mesh>(null);
    const lCDR = useRef<THREE.Mesh>(null);
    const lDAR = useRef<THREE.Mesh>(null);
    const dACR = useRef<THREE.Mesh>(null);
    const dBDR = useRef<THREE.Mesh>(null);
    const ptAR = useRef<THREE.Group>(null);
    const ptBR = useRef<THREE.Group>(null);

    // Text Refs
    const txtACL = useRef<any>(null);
    const txtBDL = useRef<any>(null);
    const txtACR = useRef<any>(null);
    const txtBDR = useRef<any>(null);
    
    const txtAngAL1 = useRef<any>(null);
    const txtAngAL2 = useRef<any>(null);
    
    const txtAngDR = useRef<any>(null);
    const txtAngCR = useRef<any>(null);

    // Geometries
    const makeBuffer = (size: number) => {
        const g = new THREE.BufferGeometry();
        g.setAttribute('position', new THREE.BufferAttribute(new Float32Array(size * 3), 3));
        return g;
    };

    // L Marks
    const tickABL = useMemo(() => makeBuffer(4), []);
    const tickBCL = useMemo(() => makeBuffer(4), []);
    const tickCDL = useMemo(() => makeBuffer(4), []);
    const tickDAL = useMemo(() => makeBuffer(4), []);
    const sqML = useMemo(() => makeBuffer(3), []);
    const sqAL = useMemo(() => makeBuffer(3), []);
    const sqBL = useMemo(() => makeBuffer(3), []);
    const sqCL = useMemo(() => makeBuffer(3), []);
    const sqDL = useMemo(() => makeBuffer(3), []);
    const arcAL1 = useMemo(() => makeBuffer(65), []);
    const arcAL2 = useMemo(() => makeBuffer(65), []);
    
    // R Marks
    const chevTopR = useMemo(() => makeBuffer(4), []);
    const chevBotR = useMemo(() => makeBuffer(4), []);
    const tickLeg1R = useMemo(() => makeBuffer(4), []);
    const tickLeg2R = useMemo(() => makeBuffer(4), []);
    const arcDR = useMemo(() => makeBuffer(65), []);
    const arcCR = useMemo(() => makeBuffer(65), []);

    // Mats
    const matSqRef = useRef<THREE.LineBasicMaterial>(null);
    const matLegTickRef = useRef<THREE.LineBasicMaterial>(null);

    useEffect(() => {
        gsap.to(camera.position, { 
            x: -1, y: 0, z: 24, duration: 1.5, ease: "power2.inOut" 
        });
    }, [camera]);

    useFrame(() => {
        proxy.tiltRad = THREE.MathUtils.lerp(proxy.tiltRad, tiltDeg * Math.PI / 180, 0.15);
        proxy.trapState = THREE.MathUtils.lerp(proxy.trapState, isoTrap ? 1 : 0, 0.15);
        
        // --- LEFT GROUP: RHOMBUS / SQUARE ---
        const T = proxy.tiltRad;
        const pDL = new THREE.Vector3(pAL.x + baseLen * Math.cos(T), pAL.y + baseLen * Math.sin(T), 0);
        const pCL = new THREE.Vector3(pDL.x + baseLen, pDL.y, 0);
        const pML = new THREE.Vector3().addVectors(pAL, pCL).multiplyScalar(0.5);

        if (ptDL.current) ptDL.current.position.copy(pDL);
        if (ptCL.current) ptCL.current.position.copy(pCL);

        if (lABL.current) updateLine(lABL.current, pAL, pBL);
        if (lBCL.current) updateLine(lBCL.current, pBL, pCL);
        if (lCDL.current) updateLine(lCDL.current, pCL, pDL);
        if (lDAL.current) updateLine(lDAL.current, pDL, pAL);
        if (dACL.current) updateLine(dACL.current, pAL, pCL);
        if (dBDL.current) updateLine(dBDL.current, pBL, pDL);

        updateTick(tickABL, pAL, pBL, 1);
        updateTick(tickBCL, pBL, pCL, 1);
        updateTick(tickCDL, pCL, pDL, 1);
        updateTick(tickDAL, pDL, pAL, 1);

        const lenACL = pAL.distanceTo(pCL);
        const lenBDL = pBL.distanceTo(pDL);
        
        if (txtACL.current) {
            const dirAC = new THREE.Vector3().subVectors(pCL, pAL).normalize();
            txtACL.current.position.copy(pAL.clone().add(dirAC.multiplyScalar(2.5)).add(new THREE.Vector3(-1.2, 0.4, 0)));
            txtACL.current.text = `AC = ${lenACL.toFixed(1)}`;
        }
        if (txtBDL.current) {
            const dirBD = new THREE.Vector3().subVectors(pDL, pBL).normalize();
            txtBDL.current.position.copy(pBL.clone().add(dirBD.multiplyScalar(2.5)).add(new THREE.Vector3(1.2, 0.4, 0)));
            txtBDL.current.text = `BD = ${lenBDL.toFixed(1)}`;
        }

        const isSquare = Math.abs(T - Math.PI/2) < 0.05;
        if (matSqRef.current) matSqRef.current.opacity = isSquare ? 1 : 0;
        
        if (isSquare) {
            updateSquareGeom(sqAL, pAL, new THREE.Vector3(1,0,0), new THREE.Vector3(0,1,0), 0.8);
            updateSquareGeom(sqBL, pBL, new THREE.Vector3(-1,0,0), new THREE.Vector3(0,1,0), 0.8);
            updateSquareGeom(sqCL, pCL, new THREE.Vector3(-1,0,0), new THREE.Vector3(0,-1,0), 0.8);
            updateSquareGeom(sqDL, pDL, new THREE.Vector3(1,0,0), new THREE.Vector3(0,-1,0), 0.8);
        }

        // Rhombus always has perp diagonals
        const vMCL = new THREE.Vector3().subVectors(pCL, pML).normalize();
        const vMDL = new THREE.Vector3().subVectors(pDL, pML).normalize();
        updateSquareGeom(sqML, pML, vMCL, vMDL, 0.8);

        // Bisected Angle at A
        const vACL = new THREE.Vector3().subVectors(pCL, pAL).normalize();
        const angAC = Math.atan2(vACL.y, vACL.x);
        updateArcGeom(arcAL1, 0, angAC, 1.2);
        updateArcGeom(arcAL2, angAC, T, 1.4);

        if (txtAngAL1.current) {
            const mid = angAC/2;
            txtAngAL1.current.position.set(pAL.x + Math.cos(mid)*2.0, pAL.y + Math.sin(mid)*2.0, 0);
            txtAngAL1.current.text = `${Math.round(angAC*180/Math.PI)}°`;
        }
        if (txtAngAL2.current) {
            const mid = (angAC + T)/2;
            txtAngAL2.current.position.set(pAL.x + Math.cos(mid)*2.2, pAL.y + Math.sin(mid)*2.2, 0);
            txtAngAL2.current.text = `${Math.round((T-angAC)*180/Math.PI)}°`;
        }

        // --- RIGHT GROUP: TRAPEZIUM ---
        const S = proxy.trapState; 
        const pAR = new THREE.Vector3(THREE.MathUtils.lerp(2, 4.5, S), 4, 0);
        const pBR = new THREE.Vector3(THREE.MathUtils.lerp(8, 9.5, S), 4, 0);

        if (ptAR.current) ptAR.current.position.copy(pAR);
        if (ptBR.current) ptBR.current.position.copy(pBR);

        if (lABR.current) updateLine(lABR.current, pAR, pBR);
        if (lBCR.current) updateLine(lBCR.current, pBR, pCR);
        if (lCDR.current) updateLine(lCDR.current, pCR, pDR);
        if (lDAR.current) updateLine(lDAR.current, pDR, pAR);
        if (dACR.current) updateLine(dACR.current, pAR, pCR);
        if (dBDR.current) updateLine(dBDR.current, pBR, pDR);

        updateChevron(chevTopR, pAR, pBR, 1);
        updateChevron(chevBotR, pDR, pCR, 1);

        if (matLegTickRef.current) matLegTickRef.current.opacity = S;
        updateTick(tickLeg1R, pDR, pAR, 2);
        updateTick(tickLeg2R, pCR, pBR, 2);

        const vDR_AR = new THREE.Vector3().subVectors(pAR, pDR).normalize();
        const angDR = Math.atan2(vDR_AR.y, vDR_AR.x);
        updateArcGeom(arcDR, 0, angDR, 1.2);

        const vCR_BR = new THREE.Vector3().subVectors(pBR, pCR).normalize();
        const angCR = Math.atan2(vCR_BR.y, vCR_BR.x);
        let angCR_start = angCR; if (angCR_start < 0) angCR_start += 2*Math.PI;
        updateArcGeom(arcCR, angCR_start, Math.PI, 1.2);

        if (txtAngDR.current) {
            const mid = angDR/2;
            txtAngDR.current.position.set(pDR.x + Math.cos(mid)*1.8, pDR.y + Math.sin(mid)*1.8, 0);
            txtAngDR.current.text = `${Math.round(angDR*180/Math.PI)}°`;
            txtAngDR.current.fillOpacity = S > 0.8 ? 1 : 0.2;
        }
        if (txtAngCR.current) {
            const mid = (angCR_start + Math.PI)/2;
            txtAngCR.current.position.set(pCR.x + Math.cos(mid)*1.8, pCR.y + Math.sin(mid)*1.8, 0);
            txtAngCR.current.text = `${Math.round((Math.PI - angCR_start)*180/Math.PI)}°`;
            txtAngCR.current.fillOpacity = S > 0.8 ? 1 : 0.2;
        }

        const lenACR = pAR.distanceTo(pCR);
        const lenBDR = pBR.distanceTo(pDR);

        if (txtACR.current) {
            const dirAC = new THREE.Vector3().subVectors(pCR, pAR).normalize();
            txtACR.current.position.copy(pAR.clone().add(dirAC.multiplyScalar(3.0)).add(new THREE.Vector3(1.2, 0.4, 0)));
            txtACR.current.text = `EG = ${lenACR.toFixed(1)}`;
        }
        if (txtBDR.current) {
            const dirBD = new THREE.Vector3().subVectors(pDR, pBR).normalize();
            txtBDR.current.position.copy(pBR.clone().add(dirBD.multiplyScalar(3.0)).add(new THREE.Vector3(-1.2, 0.4, 0)));
            txtBDR.current.text = `FH = ${lenBDR.toFixed(1)}`;
        }
    });

    return (
        <group position={[0, -0.5, 0]}>
            <ambientLight intensity={0.6} />
            <directionalLight position={[0, 20, 15]} intensity={1.5} castShadow />
            <Environment preset="city" opacity={0.3} />
            
            <mesh position={[0, 0, -2]}>
                <planeGeometry args={[100, 100]} />
                <meshStandardMaterial color="#020617" />
            </mesh>
            <gridHelper args={[100, 100, '#1e293b', '#0f172a']} position={[0, 0, -1.9]} rotation={[Math.PI/2, 0, 0]} />

            {/* ---------------- LEFT GROUP: RHOMBUS/SQUARE ---------------- */}
            <mesh ref={lABL} castShadow><cylinderGeometry args={[0.06, 0.06, 1]} /><meshStandardMaterial color="#3b82f6" roughness={0.3} /></mesh>
            <mesh ref={lBCL} castShadow><cylinderGeometry args={[0.06, 0.06, 1]} /><meshStandardMaterial color="#3b82f6" roughness={0.3} /></mesh>
            <mesh ref={lCDL} castShadow><cylinderGeometry args={[0.06, 0.06, 1]} /><meshStandardMaterial color="#3b82f6" roughness={0.3} /></mesh>
            <mesh ref={lDAL} castShadow><cylinderGeometry args={[0.06, 0.06, 1]} /><meshStandardMaterial color="#3b82f6" roughness={0.3} /></mesh>
            
            <mesh ref={dACL}><cylinderGeometry args={[0.03, 0.03, 1]} /><meshStandardMaterial color="#0ea5e9" roughness={0.3} /></mesh>
            <mesh ref={dBDL}><cylinderGeometry args={[0.03, 0.03, 1]} /><meshStandardMaterial color="#94a3b8" roughness={0.3} /></mesh>

            <lineSegments geometry={tickABL} position={[0,0,0.05]}><lineBasicMaterial color="#facc15" linewidth={4} /></lineSegments>
            <lineSegments geometry={tickBCL} position={[0,0,0.05]}><lineBasicMaterial color="#facc15" linewidth={4} /></lineSegments>
            <lineSegments geometry={tickCDL} position={[0,0,0.05]}><lineBasicMaterial color="#facc15" linewidth={4} /></lineSegments>
            <lineSegments geometry={tickDAL} position={[0,0,0.05]}><lineBasicMaterial color="#facc15" linewidth={4} /></lineSegments>

            <line geometry={arcAL1} position={[0,0,0.05]}><lineBasicMaterial color="#3b82f6" linewidth={4} /></line>
            <line geometry={arcAL2} position={[0,0,0.05]}><lineBasicMaterial color="#a855f7" linewidth={4} /></line>

            <lineBasicMaterial ref={matSqRef} color="#22c55e" linewidth={5} transparent />
            <line geometry={sqAL} position={[-14,-4,0.05]} material={matSqRef.current || undefined} />
            <line geometry={sqBL} position={[-6,-4,0.05]} material={matSqRef.current || undefined} />
            <group ref={ptCL}><line geometry={sqCL} position={[0,0,0.05]} material={matSqRef.current || undefined} /></group>
            <group ref={ptDL}><line geometry={sqDL} position={[0,0,0.05]} material={matSqRef.current || undefined} /></group>

            <line geometry={sqML} position={[0,0,0.05]}><lineBasicMaterial color="#22c55e" linewidth={4} /></line>

            <group position={[-14, -4, 0]}>
                <Sphere args={[0.2, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.6, -0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">A</Text>
            </group>
            <group position={[-6, -4, 0]}>
                <Sphere args={[0.2, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0.6, -0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">B</Text>
            </group>
            <group ref={ptCL}>
                <Sphere args={[0.2, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0.6, 0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">C</Text>
            </group>
            <group ref={ptDL}>
                <Sphere args={[0.2, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.6, 0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">D</Text>
            </group>

            <Text ref={txtACL} fontSize={0.5} color="#0ea5e9" fontWeight="bold" />
            <Text ref={txtBDL} fontSize={0.5} color="#94a3b8" fontWeight="bold" />
            <Text ref={txtAngAL1} fontSize={0.4} color="#3b82f6" fontWeight="bold" />
            <Text ref={txtAngAL2} fontSize={0.4} color="#a855f7" fontWeight="bold" />

            <Text position={[-10, 6.5, 0]} fontSize={0.8} color="#94a3b8" fontWeight="bold" anchorX="center">
                {tiltDeg === 90 ? "Square" : "Rhombus"}
            </Text>

            {/* ---------------- RIGHT GROUP: TRAPEZIUM ---------------- */}
            <mesh ref={lABR} castShadow><cylinderGeometry args={[0.06, 0.06, 1]} /><meshStandardMaterial color="#facc15" roughness={0.3} /></mesh>
            <mesh ref={lBCR} castShadow><cylinderGeometry args={[0.06, 0.06, 1]} /><meshStandardMaterial color="#a855f7" roughness={0.3} /></mesh>
            <mesh ref={lCDR} castShadow><cylinderGeometry args={[0.06, 0.06, 1]} /><meshStandardMaterial color="#facc15" roughness={0.3} /></mesh>
            <mesh ref={lDAR} castShadow><cylinderGeometry args={[0.06, 0.06, 1]} /><meshStandardMaterial color="#a855f7" roughness={0.3} /></mesh>
            
            <mesh ref={dACR}><cylinderGeometry args={[0.03, 0.03, 1]} /><meshStandardMaterial color="#f97316" roughness={0.3} /></mesh>
            <mesh ref={dBDR}><cylinderGeometry args={[0.03, 0.03, 1]} /><meshStandardMaterial color="#f97316" roughness={0.3} /></mesh>

            <lineSegments geometry={chevTopR} position={[0,0,0.05]}><lineBasicMaterial color="#facc15" linewidth={5} /></lineSegments>
            <lineSegments geometry={chevBotR} position={[0,0,0.05]}><lineBasicMaterial color="#facc15" linewidth={5} /></lineSegments>

            <lineBasicMaterial ref={matLegTickRef} color="#22c55e" linewidth={4} transparent />
            <lineSegments geometry={tickLeg1R} position={[0,0,0.05]} material={matLegTickRef.current || undefined} />
            <lineSegments geometry={tickLeg2R} position={[0,0,0.05]} material={matLegTickRef.current || undefined} />

            <line geometry={arcDR} position={[0,0,0.05]}><lineBasicMaterial color="#22c55e" linewidth={4} /></line>
            <line geometry={arcCR} position={[0,0,0.05]}><lineBasicMaterial color="#22c55e" linewidth={4} /></line>

            <group ref={ptAR}>
                <Sphere args={[0.2, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.6, 0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">E</Text>
            </group>
            <group ref={ptBR}>
                <Sphere args={[0.2, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0.6, 0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">F</Text>
            </group>
            <group position={[12, -4, 0]}>
                <Sphere args={[0.2, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[0.6, -0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">G</Text>
            </group>
            <group position={[2, -4, 0]}>
                <Sphere args={[0.2, 32, 32]}><meshStandardMaterial color="#ffffff" /></Sphere>
                <Text position={[-0.6, -0.6, 0]} fontSize={0.6} color="#ffffff" fontWeight="bold">H</Text>
            </group>

            <Text ref={txtACR} fontSize={0.5} color="#f97316" fontWeight="bold" />
            <Text ref={txtBDR} fontSize={0.5} color="#f97316" fontWeight="bold" />
            <Text ref={txtAngDR} fontSize={0.5} color="#22c55e" fontWeight="bold" />
            <Text ref={txtAngCR} fontSize={0.5} color="#22c55e" fontWeight="bold" />

            <Text position={[7, 6.5, 0]} fontSize={0.8} color="#94a3b8" fontWeight="bold" anchorX="center">
                {isoTrap ? "Isosceles Trapezium" : "Trapezium"}
            </Text>
        </group>
    );
}

// ---------------------------------------------------------
// UI COMPONENT
// ---------------------------------------------------------
export default function AdvancedQuadrilaterals() {
    const [tiltDeg, setTiltDeg] = useState(55);
    const [isoTrap, setIsoTrap] = useState(false);

    const isSquare = tiltDeg === 90;

    const renderRhombusMath = () => {
        if (isSquare) {
            return <BlockMath math={`\\text{Rhombus} + 90^\\circ \\angle = \\textbf{Square}`} />;
        }
        return <BlockMath math={`\\text{Rhombus}: \\text{4 Equal Sides}`} />;
    };

    const renderTrapMath = () => {
        if (isoTrap) {
            return <BlockMath math={`EH = FG \\implies EG = FH`} />;
        }
        return <BlockMath math={`EF \\parallel GH \\; \\text{only}`} />;
    };

    return (
        <div className="w-full h-full relative overflow-hidden bg-slate-950 flex flex-col font-sans">
            
            {/* Header Overlay */}
            <div className="absolute top-0 inset-x-0 z-10 p-4 md:p-6 pointer-events-none">
                <div className="bg-slate-900/80 backdrop-blur-md border border-slate-800 p-4 rounded-2xl shadow-xl flex justify-between items-start md:items-center flex-col lg:flex-row gap-4 max-w-7xl mx-auto">
                    <div>
                        <h1 className="text-xl md:text-2xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            Advanced Quadrilateral Classification
                        </h1>
                        <p className="text-slate-400 text-sm mt-1">
                            Comparing the highly symmetric Square with the minimal Trapezium.
                        </p>
                    </div>
                    <div className="flex gap-4">
                        <div className={`bg-slate-950/80 border p-3 rounded-xl min-w-[200px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-all duration-500 ${isSquare ? 'border-blue-500/50' : 'border-slate-800'}`}>
                            <div className={`text-sm px-4 flex items-center justify-center h-full min-h-[3rem] transition-colors ${isSquare ? 'text-blue-400' : 'text-slate-300'}`}>
                                {renderRhombusMath()}
                            </div>
                        </div>
                        <div className={`bg-slate-950/80 border p-3 rounded-xl min-w-[200px] flex justify-center shadow-inner overflow-x-auto pointer-events-auto transition-all duration-500 ${isoTrap ? 'border-green-500/50' : 'border-slate-800'}`}>
                            <div className={`text-sm px-4 flex items-center justify-center h-full min-h-[3rem] transition-colors ${isoTrap ? 'text-green-400' : 'text-slate-300'}`}>
                                {renderTrapMath()}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            {/* R3F Canvas */}
            <div className="flex-1 w-full h-full relative z-0 touch-none">
                <Suspense fallback={<div className="w-full h-full flex items-center justify-center text-white">Loading 3D Engine...</div>}>
                    <Canvas shadows>
                        <OrbitControls enablePan={true} enableZoom={true} maxPolarAngle={Math.PI / 2} minPolarAngle={Math.PI / 4} maxAzimuthAngle={0.2} minAzimuthAngle={-0.2} />
                        <Scene tiltDeg={tiltDeg} isoTrap={isoTrap} />
                    </Canvas>
                </Suspense>
            </div>

            {/* Controls Overlay */}
            <div className="absolute bottom-4 inset-x-4 z-10 flex flex-col lg:flex-row gap-4 items-end justify-center pointer-events-none max-w-7xl mx-auto">
                
                <div className={`flex-1 pointer-events-auto max-w-md w-full transition-all duration-500`}>
                    <div className={`border-l-4 border-t border-r border-b border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md bg-slate-900/90 border-l-rose-500 text-rose-300 bg-rose-900/40`}>
                        <h3 className="font-bold mb-2 flex items-center gap-2 text-sm uppercase tracking-wide">
                            <ShieldAlert size={18}/> Is a Trapezium a Parallelogram?
                        </h3>
                        <p className="text-sm leading-relaxed drop-shadow-md text-slate-300">
                            <b>CRITICAL MISCONCEPTION:</b> No! Look closely at the Trapezium on the right. It only has <b>one</b> pair of parallel sides (marked with yellow arrows). The legs (EH and FG) are distinctly NOT parallel. Because a parallelogram strictly requires <i>two</i> pairs of parallel sides, a trapezium can <b>never</b> be a parallelogram.
                        </p>
                    </div>
                </div>

                <div className="bg-slate-900/90 border border-slate-700 p-5 rounded-2xl shadow-2xl backdrop-blur-md flex flex-col pointer-events-auto shrink-0 w-full lg:w-[450px]">
                    
                    <div className="mb-6">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-2">
                            <Shapes size={14} /> Rhombus Shape Morph
                        </label>
                        <div className="flex justify-between text-xs font-bold mb-1">
                            <span className={isSquare ? "text-blue-400" : "text-slate-400"}>Angle = {tiltDeg}°</span>
                        </div>
                        <input 
                            type="range" 
                            min="50" max="90" step="1" 
                            value={tiltDeg} onChange={(e) => setTiltDeg(parseFloat(e.target.value))} 
                            className={`w-full h-1.5 rounded-lg appearance-none cursor-pointer mt-2 bg-slate-800 ${isSquare ? 'accent-blue-500' : 'accent-slate-500'}`}
                        />
                    </div>

                    <div className="border-t border-slate-800 pt-4 mb-2">
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-3 flex items-center gap-2">
                            <Layers size={14} /> Trapezium Properties
                        </label>
                        <button 
                            onClick={() => setIsoTrap(!isoTrap)} 
                            className={`w-full font-bold py-3 px-4 rounded-xl flex items-center justify-center gap-2 transition-all border text-sm ${
                                isoTrap ? 'bg-green-600/20 border-green-600/50 text-green-400' : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700'
                            }`}
                        >
                            {isoTrap ? "Isosceles Mode Active" : "Enable Isosceles Mode"}
                        </button>
                    </div>
                    
                </div>
            </div>
        </div>
    );
}
