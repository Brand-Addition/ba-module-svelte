<script>
    import SvelteRenderer from '@modules/BA_Svelte/svelte-src/src/SvelteRenderer.svelte';

    let { config = null } = $props();
</script>

<SvelteRenderer {config} />
