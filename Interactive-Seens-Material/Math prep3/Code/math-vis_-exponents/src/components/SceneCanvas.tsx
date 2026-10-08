import {Component, Suspense, useLayoutEffect, type ComponentProps, type ReactNode} from 'react';
import {Canvas as FiberCanvas, useThree} from '@react-three/fiber';
import {Html} from '@react-three/drei';

function ResponsiveCamera() {
  const {camera, size} = useThree();
  useLayoutEffect(() => {
    // Scenes were authored for a wide standalone window. Preserve their
    // horizontal field of view inside the narrower sidebar layout.
    camera.zoom = Math.min(1, (size.width / size.height) / (16 / 9));
    camera.updateProjectionMatrix();
  }, [camera, size.width, size.height]);
  return null;
}

class RenderBoundary extends Component<{children: ReactNode}, {failed: boolean}> {
  state = {failed: false};
  static getDerivedStateFromError() { return {failed: true}; }
  render() {
    return this.state.failed ? (
      <div role="alert" className="absolute inset-0 flex flex-col items-center justify-center gap-4 bg-slate-950 text-white">
        <p>The scene could not load. Reload to try again.</p>
        <button onClick={() => window.location.reload()}>Reload scene</button>
      </div>
    ) : this.props.children;
  }
}

export function Canvas({children, ...props}: ComponentProps<typeof FiberCanvas>) {
  return <RenderBoundary>
    <FiberCanvas dpr={[1, 2]} {...props} fallback={<p role="alert">3D rendering is unavailable. Enable browser graphics acceleration and reload.</p>}>
      <color attach="background" args={['#020617']} />
      <ResponsiveCamera />
      <Suspense fallback={<Html center><p style={{color: 'white', whiteSpace: 'nowrap'}}>Loading scene assets…</p></Html>}>
        {children}
      </Suspense>
    </FiberCanvas>
  </RenderBoundary>;
}
