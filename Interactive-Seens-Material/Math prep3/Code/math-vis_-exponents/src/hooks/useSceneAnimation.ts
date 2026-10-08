import {useEffect, type DependencyList} from 'react';
import gsap from 'gsap';

// Cancel scene-owned animations on navigation and before a new step starts.
// Keep the final transforms so the following step can continue from them.
export function useSceneAnimation(setup: () => void, dependencies: DependencyList) {
  useEffect(() => {
    const context = gsap.context(setup);
    return () => context.kill(false);
  }, dependencies);
}
