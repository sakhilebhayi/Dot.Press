// Shared render context for the element components. SlideView provides it, elements inject it.
import { inject } from 'vue';

export const RENDER_CTX = Symbol('press-render-ctx');

export function useRenderCtx() {
    const ctx = inject(RENDER_CTX, null);
    if (!ctx) throw new Error('Press element rendered outside a SlideView');
    return ctx;
}
