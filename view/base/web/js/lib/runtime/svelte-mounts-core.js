import {
    COMPONENT_SELECTOR,
    parseJsonAttribute,
    queryAll,
} from './shared.js';

const FALLBACK_SELECTOR = '[data-ba-svelte-fallback]';
const MOUNT_HOST_ATTRIBUTE = 'data-ba-svelte-host';

function resolveRequestAnimationFrame() {
    if (typeof requestAnimationFrame === 'function') {
        return requestAnimationFrame;
    }

    return (callback) => {
        callback();

        return 0;
    };
}

function resolveCancelAnimationFrame() {
    if (typeof cancelAnimationFrame === 'function') {
        return cancelAnimationFrame;
    }

    return () => {};
}

function parseComponentConfig(target) {
    const rawConfig = target.dataset.baSvelteConfig ?? target.dataset.config ?? '';
    const config = parseJsonAttribute(rawConfig, null);

    return config && typeof config === 'object' ? config : null;
}

function createMountTargetState(
    target,
    requestFrame = resolveRequestAnimationFrame(),
    cancelFrame = resolveCancelAnimationFrame()
) {
    const fallback = target.querySelector(FALLBACK_SELECTOR);
    if (!(fallback instanceof HTMLElement)) {
        return {
            cleanupFailedMount() {},
            cleanupMountedDom() {
                delete target.dataset.baSvelteEnhanced;
            },
            finalizeSuccessfulMount() {},
            mountTarget: target,
        };
    }

    const documentRef = target.ownerDocument ?? document;
    const mountHost = documentRef.createElement('div');
    mountHost.setAttribute(MOUNT_HOST_ATTRIBUTE, 'true');
    mountHost.setAttribute('hidden', 'hidden');
    mountHost.setAttribute('aria-hidden', 'true');
    target.appendChild(mountHost);

    let revealFrame = null;

    function clearRevealFrame() {
        if (revealFrame === null) {
            return;
        }

        cancelFrame(revealFrame);
        revealFrame = null;
    }

    function removeMountHost() {
        if (mountHost.parentNode) {
            mountHost.parentNode.removeChild(mountHost);
        }
    }

    return {
        cleanupFailedMount() {
            clearRevealFrame();
            removeMountHost();
            delete target.dataset.baSvelteEnhanced;
        },
        cleanupMountedDom() {
            clearRevealFrame();
            removeMountHost();
            delete target.dataset.baSvelteEnhanced;
        },
        finalizeSuccessfulMount() {
            clearRevealFrame();
            revealFrame = requestFrame(() => {
                revealFrame = null;
                mountHost.removeAttribute('hidden');
                mountHost.removeAttribute('aria-hidden');
                fallback.remove();
                target.dataset.baSvelteEnhanced = 'true';
            });
        },
        mountTarget: mountHost,
    };
}

export function createSvelteMountRuntime({
    mountComponent,
    renderer,
    requestAnimationFrame: requestFrame = resolveRequestAnimationFrame(),
    cancelAnimationFrame: cancelFrame = resolveCancelAnimationFrame(),
    unmountComponent,
}) {
    const componentMounts = new WeakMap();
    const activeComponentTargets = new Set();

    function mountSvelteComponent(target, config = null) {
        if (!(target instanceof HTMLElement)) {
            throw new Error('mountSvelteComponent() requires an HTMLElement target.');
        }

        const existingMount = componentMounts.get(target);
        if (existingMount) {
            return existingMount;
        }

        const resolvedConfig = config ?? parseComponentConfig(target);
        if (!resolvedConfig) {
            return null;
        }

        const mountTargetState = createMountTargetState(target, requestFrame, cancelFrame);

        let app;
        try {
            app = mountComponent(renderer, {
                props: { config: resolvedConfig },
                target: mountTargetState.mountTarget,
            });
        } catch (error) {
            mountTargetState.cleanupFailedMount();
            throw error;
        }

        const controller = {
            app,
            config: resolvedConfig,
            destroy() {
                target.dataset.svelteMounted = 'false';
                delete target.dataset.baSvelteEnhanced;
                componentMounts.delete(target);
                activeComponentTargets.delete(target);
                mountTargetState.cleanupMountedDom();
                unmountComponent(app);
            },
            mountTarget: mountTargetState.mountTarget,
            target,
        };

        target.dataset.svelteMounted = 'true';
        componentMounts.set(target, controller);
        activeComponentTargets.add(target);
        mountTargetState.finalizeSuccessfulMount();

        return controller;
    }

    function mountSvelteComponents(root = document) {
        return queryAll(root, COMPONENT_SELECTOR)
            .map((target) => mountSvelteComponent(target))
            .filter(Boolean);
    }

    function initializeRuntime(root = document) {
        mountSvelteComponents(root);
    }

    function destroyMountedComponentsInScope(scope) {
        activeComponentTargets.forEach((element) => {
            if (!(element instanceof HTMLElement)) {
                return;
            }

            if (scope === element || (scope instanceof Node && scope.contains(element))) {
                componentMounts.get(element)?.destroy();
            }
        });
    }

    return {
        destroyMountedComponentsInScope,
        initializeRuntime,
        mountSvelteComponent,
        mountSvelteComponents,
    };
}
