import {forwardRef, type ComponentProps} from 'react';
import {Text as DreiText} from '@react-three/drei';
import {useThree} from '@react-three/fiber';

// Explicit font also covers Drei's preload, before Troika's default is applied.
export const Text = forwardRef<any, ComponentProps<typeof DreiText>>((props, ref) => {
  const canvas = useThree(state => state.gl.domElement);
  return <DreiText {...props} {...{gpuAccelerateSDF: false}} ref={ref} characters="" font={`${import.meta.env.BASE_URL}fonts/DejaVuSans.ttf`}
    onSync={mesh => { canvas.dataset.textReady = 'true'; props.onSync?.(mesh); }} />;
});
