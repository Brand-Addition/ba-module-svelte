import { registerCustomElements } from './custom-elements.js';
import { handleRuntimeMutationRecords } from './bootstrap-core.js';
import {
    destroyMountedComponentsInScope,
    initializeRuntime,
    mountSvelteComponents,
} from './svelte-mounts.js';

let runtimeObserver = null;
let runtimeStarted = false;

export function observeRuntime(root = document.body ?? document.documentElement) {
    if (!(root instanceof HTMLElement) && !(root instanceof Document)) {
        return null;
    }

    if (runtimeObserver) {
        return runtimeObserver;
    }

    runtimeObserver = new MutationObserver((records) => {
        handleRuntimeMutationRecords(records, {
            destroyMountedComponentsInScope,
            mountSvelteComponents,
        });
    });

    runtimeObserver.observe(root, {
        childList: true,
        subtree: true,
    });

    return runtimeObserver;
}

export function disconnectRuntimeObserver() {
    runtimeObserver?.disconnect();
    runtimeObserver = null;
}

function startWhenReady() {
    registerCustomElements();
    initializeRuntime(document);
    observeRuntime(document.body ?? document.documentElement);
}

export function startRuntime() {
    if (runtimeStarted) {
        return runtimeObserver;
    }

    runtimeStarted = true;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startWhenReady, { once: true });
        return null;
    }

    startWhenReady();

    return runtimeObserver;
}
