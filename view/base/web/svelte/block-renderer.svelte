<script lang="ts">
    import Renderer from '@modules/BA_Svelte/svelte/renderer.svelte';

    type ComponentConfig = {
        component?: string;
        containers?: Record<string, unknown[]>;
        name?: string;
        props?: Record<string, unknown>;
    };

    let {
        block = null,
        blocks = [],
        ...overrideProps
    }: {
        block?: unknown;
        blocks?: unknown[];
        [key: string]: unknown;
    } = $props();

    const resolvedBlock = $derived.by<ComponentConfig | null>(() => {
        if (typeof block === 'string' && block.trim() !== '') {
            const namedBlock = Array.isArray(blocks)
                ? blocks.find((candidate) => (
                    candidate
                    && typeof candidate === 'object'
                    && 'name' in candidate
                    && candidate.name === block
                ))
                : null;

            return namedBlock && typeof namedBlock === 'object' ? namedBlock as ComponentConfig : null;
        }

        const candidate = Array.isArray(block) ? block[0] : block;

        return candidate && typeof candidate === 'object' ? candidate as ComponentConfig : null;
    });

    const mergedConfig = $derived.by<ComponentConfig | null>(() => {
        if (!resolvedBlock?.component) {
            return null;
        }

        return {
            ...resolvedBlock,
            props: {
                ...(resolvedBlock.props ?? {}),
                ...overrideProps,
            },
        };
    });
</script>

{#if mergedConfig}
    <Renderer config={mergedConfig} />
{/if}
