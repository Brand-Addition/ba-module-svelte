import test from 'node:test';
import assert from 'node:assert/strict';

class FakeNode {
    constructor() {
        this.ownerDocument = null;
        this.parentNode = null;
    }

    remove() {
        this.parentNode?.removeChild(this);
    }
}

class FakeClassList {
    constructor() {
        this.values = new Set();
    }

    add(...tokens) {
        tokens.forEach((token) => this.values.add(token));
    }

    contains(token) {
        return this.values.has(token);
    }
}

class FakeElement extends FakeNode {
    constructor(tagName = 'div') {
        super();
        this.attributes = new Map();
        this.children = [];
        this.classList = new FakeClassList();
        this.dataset = {};
        this.style = {};
        this.tagName = tagName.toUpperCase();
    }

    appendChild(child) {
        child.parentNode = this;
        child.ownerDocument = this.ownerDocument;
        this.children.push(child);

        return child;
    }

    contains(node) {
        return this.children.some((child) => child === node || child.contains(node));
    }

    findAll(predicate) {
        const matches = [];

        this.children.forEach((child) => {
            if (predicate(child)) {
                matches.push(child);
            }

            matches.push(...child.findAll(predicate));
        });

        return matches;
    }

    hasAttribute(name) {
        return this.attributes.has(name);
    }

    matches(selector) {
        if (selector === '.svelte-root, [data-ba-svelte-root]') {
            return this.classList.contains('svelte-root') || this.hasAttribute('data-ba-svelte-root');
        }

        return false;
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] ?? null;
    }

    querySelectorAll(selector) {
        if (selector === '[data-ba-svelte-fallback]') {
            return this.findAll((child) => child.hasAttribute('data-ba-svelte-fallback'));
        }

        if (selector === '[data-ba-svelte-host]') {
            return this.findAll((child) => child.hasAttribute('data-ba-svelte-host'));
        }

        if (selector === '.svelte-root, [data-ba-svelte-root]') {
            return this.findAll((child) => child.matches(selector));
        }

        return [];
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    removeChild(child) {
        this.children = this.children.filter((candidate) => candidate !== child);
        child.parentNode = null;

        return child;
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }
}

class FakeDocument extends FakeNode {
    constructor() {
        super();
        this.ownerDocument = this;
    }

    createElement(tagName) {
        const element = new FakeElement(tagName);
        element.ownerDocument = this;

        return element;
    }
}

class FakeDocumentFragment extends FakeNode {}

function installDom() {
    const document = new FakeDocument();

    globalThis.document = document;
    globalThis.Document = FakeDocument;
    globalThis.DocumentFragment = FakeDocumentFragment;
    globalThis.Element = FakeElement;
    globalThis.HTMLElement = FakeElement;
    globalThis.Node = FakeNode;

    return document;
}

function createAnimationFrameController() {
    let nextHandle = 1;
    const queue = new Map();

    return {
        cancel(handle) {
            queue.delete(handle);
        },
        flush() {
            const callbacks = Array.from(queue.values());
            queue.clear();
            callbacks.forEach((callback) => callback());
        },
        request(callback) {
            const handle = nextHandle;
            nextHandle += 1;
            queue.set(handle, callback);

            return handle;
        },
    };
}

function createRoot(document, config = { component: 'BA_Svelte::example.svelte' }) {
    const root = document.createElement('div');
    root.classList.add('svelte-root');
    root.dataset.config = JSON.stringify(config);

    return root;
}

function appendFallback(document, root, label = 'Home / Category') {
    const fallback = document.createElement('div');
    fallback.setAttribute('data-ba-svelte-fallback', 'true');
    fallback.dataset.label = label;
    root.appendChild(fallback);

    return fallback;
}

test('root mount without fallback still mounts directly into the wrapper', async () => {
    const document = installDom();
    const animationFrame = createAnimationFrameController();
    const { createSvelteMountRuntime } = await import('../../js/lib/runtime/svelte-mounts-core.js');
    const mountCalls = [];
    const unmountCalls = [];
    const renderer = {};

    const runtime = createSvelteMountRuntime({
        cancelAnimationFrame: animationFrame.cancel,
        mountComponent(component, options) {
            mountCalls.push({ component, options });
            return { mounted: true };
        },
        renderer,
        requestAnimationFrame: animationFrame.request,
        unmountComponent(app) {
            unmountCalls.push(app);
        },
    });

    const root = createRoot(document);
    const controller = runtime.mountSvelteComponent(root);

    assert.ok(controller);
    assert.equal(controller.mountTarget, root);
    assert.equal(mountCalls[0].component, renderer);
    assert.equal(mountCalls[0].options.target, root);
    assert.equal(root.dataset.svelteMounted, 'true');
    assert.equal(root.dataset.baSvelteEnhanced, undefined);

    controller.destroy();

    assert.equal(root.dataset.svelteMounted, 'false');
    assert.deepEqual(unmountCalls, [{ mounted: true }]);
});

test('root mount with fallback keeps fallback visible until the swap frame then reveals the mounted host', async () => {
    const document = installDom();
    const animationFrame = createAnimationFrameController();
    const { createSvelteMountRuntime } = await import('../../js/lib/runtime/svelte-mounts-core.js');
    const mountCalls = [];

    const runtime = createSvelteMountRuntime({
        cancelAnimationFrame: animationFrame.cancel,
        mountComponent(component, options) {
            mountCalls.push({ component, options });
            return { mounted: true };
        },
        renderer: {},
        requestAnimationFrame: animationFrame.request,
        unmountComponent() {},
    });

    const root = createRoot(document);
    const fallback = appendFallback(document, root);
    const controller = runtime.mountSvelteComponent(root);
    const mountHost = mountCalls[0].options.target;

    assert.ok(controller);
    assert.notEqual(mountHost, root);
    assert.equal(root.children.includes(fallback), true);
    assert.equal(root.children.includes(mountHost), true);
    assert.equal(mountHost.hasAttribute('hidden'), true);
    assert.equal(root.dataset.baSvelteEnhanced, undefined);

    animationFrame.flush();

    assert.equal(root.children.includes(fallback), false);
    assert.equal(fallback.parentNode, null);
    assert.equal(mountHost.hasAttribute('hidden'), false);
    assert.equal(root.dataset.baSvelteEnhanced, 'true');

    controller.destroy();

    assert.equal(root.children.includes(mountHost), false);
});

test('invalid root config leaves fallback visible and does not mount', async () => {
    const document = installDom();
    const animationFrame = createAnimationFrameController();
    const { createSvelteMountRuntime } = await import('../../js/lib/runtime/svelte-mounts-core.js');
    const mountCalls = [];
    const consoleError = console.error;

    console.error = () => {};

    try {
        const runtime = createSvelteMountRuntime({
            cancelAnimationFrame: animationFrame.cancel,
            mountComponent(component, options) {
                mountCalls.push({ component, options });
                return { mounted: true };
            },
            renderer: {},
            requestAnimationFrame: animationFrame.request,
            unmountComponent() {},
        });

        const root = createRoot(document);
        const fallback = appendFallback(document, root);
        root.dataset.config = '{invalid-json';

        assert.equal(runtime.mountSvelteComponent(root), null);
        assert.deepEqual(mountCalls, []);
        assert.equal(root.children.includes(fallback), true);
        assert.equal(root.children.length, 1);
    } finally {
        console.error = consoleError;
    }
});

test('mount failure keeps fallback visible and cleans up the temporary host', async () => {
    const document = installDom();
    const animationFrame = createAnimationFrameController();
    const { createSvelteMountRuntime } = await import('../../js/lib/runtime/svelte-mounts-core.js');

    const runtime = createSvelteMountRuntime({
        cancelAnimationFrame: animationFrame.cancel,
        mountComponent() {
            throw new Error('mount failed');
        },
        renderer: {},
        requestAnimationFrame: animationFrame.request,
        unmountComponent() {},
    });

    const root = createRoot(document);
    const fallback = appendFallback(document, root);

    assert.throws(() => runtime.mountSvelteComponent(root), /mount failed/);
    assert.equal(root.children.includes(fallback), true);
    assert.equal(root.querySelector('[data-ba-svelte-host]'), null);
    assert.equal(root.dataset.svelteMounted, undefined);
});

test('late-added svelte roots observed by the runtime receive the same fallback swap behavior', async () => {
    const document = installDom();
    const animationFrame = createAnimationFrameController();
    const [
        { handleRuntimeMutationRecords },
        { createSvelteMountRuntime },
    ] = await Promise.all([
        import('../../js/lib/runtime/bootstrap-core.js'),
        import('../../js/lib/runtime/svelte-mounts-core.js'),
    ]);
    const mountCalls = [];

    const runtime = createSvelteMountRuntime({
        cancelAnimationFrame: animationFrame.cancel,
        mountComponent(component, options) {
            mountCalls.push({ component, options });
            return { mounted: true };
        },
        renderer: {},
        requestAnimationFrame: animationFrame.request,
        unmountComponent() {},
    });

    const root = createRoot(document);
    const fallback = appendFallback(document, root);

    handleRuntimeMutationRecords([{
        addedNodes: [root],
        removedNodes: [],
    }], runtime);

    assert.equal(mountCalls.length, 1);
    assert.equal(root.children.includes(fallback), true);

    animationFrame.flush();

    assert.equal(root.children.includes(fallback), false);
    assert.equal(root.dataset.baSvelteEnhanced, 'true');
});
