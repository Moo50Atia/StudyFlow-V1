import { Suspense } from 'react';
import { Canvas as FiberCanvas, type CanvasProps } from '@react-three/fiber';
import { Html } from '@react-three/drei';

/** Keep the measured canvas mounted while scene assets suspend. */
export function Canvas({ children, ...props }: CanvasProps) {
  return (
    <div style={{ position: 'absolute', inset: 0 }}>
      <FiberCanvas {...props}>
        <Suspense fallback={
          <Html center>
            <div role="status" className="whitespace-nowrap text-sm text-white">
              Loading 3D scene…
            </div>
          </Html>
        }>
          {children}
        </Suspense>
      </FiberCanvas>
    </div>
  );
}
