<script>
    import ContainerRenderer from '@modules/BA_Svelte/svelte-src/src/ContainerRenderer.svelte';

    let { items = [] } = $props();
</script>

<ContainerRenderer {items} />
