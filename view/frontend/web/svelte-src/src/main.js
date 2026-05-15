import { mount } from 'svelte';
import SvelteRenderer from './SvelteRenderer.svelte';

document.querySelectorAll('.svelte-root').forEach((target) => {
    if (target.dataset.svelteMounted === 'true') {
        return;
    }

    const config = target.dataset.config ? JSON.parse(target.dataset.config) : null;

    if (!config) {
        return;
    }

    target.dataset.svelteMounted = 'true';

    mount(SvelteRenderer, {
        props: { config },
        target,
    });
});
