import { mount, unmount } from 'svelte';
import SvelteRenderer from '@modules/BA_Svelte/svelte/renderer.svelte';
import { createSvelteMountRuntime } from './svelte-mounts-core.js';

const runtime = createSvelteMountRuntime({
    mountComponent: mount,
    renderer: SvelteRenderer,
    unmountComponent: unmount,
});

export const {
    destroyMountedComponentsInScope,
    initializeRuntime,
    mountSvelteComponent,
    mountSvelteComponents,
} = runtime;
