<script>
    import SvelteRenderer from './SvelteRenderer.svelte';

    let { items = [] } = $props();

    const containerItems = $derived.by(() => Array.isArray(items) ? items : []);
</script>

{#each containerItems as item, index (item?.name ?? `${item?.component}-${index}`)}
    <SvelteRenderer config={item} />
{/each}
