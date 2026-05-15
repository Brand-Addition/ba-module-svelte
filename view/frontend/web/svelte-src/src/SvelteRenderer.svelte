<script>
    import ContainerRenderer from './ContainerRenderer.svelte';

    const componentModules = import.meta.glob('/**/*.svelte', { eager: true });

    let { config = null } = $props();

    function normalizeComponentPath(componentPath) {
        if (typeof componentPath !== 'string' || componentPath.trim() === '') {
            return '';
        }

        if (componentPath.includes('::')) {
            const [moduleName, templatePath] = componentPath.split('::');
            const normalizedTemplatePath = String(templatePath || '').replace(/^\/+/, '');

            return `/${moduleName}/svelte/${normalizedTemplatePath}`;
        }

        return componentPath.startsWith('/') ? componentPath : `/${componentPath}`;
    }

    const normalizedComponentPath = $derived.by(() => normalizeComponentPath(config?.component));
    const containerMap = $derived.by(() => (
        config?.containers && typeof config.containers === 'object' ? config.containers : {}
    ));
    const propBag = $derived.by(() => (
        config?.props && typeof config.props === 'object' ? config.props : {}
    ));
    const LoadedComponent = $derived.by(() => {
        const moduleDefinition = componentModules[normalizedComponentPath];

        return moduleDefinition?.default ?? null;
    });
    const errorMessage = $derived.by(() => {
        if (!normalizedComponentPath) {
            return 'Missing Svelte component path.';
        }

        if (LoadedComponent) {
            return '';
        }

        return `Unable to resolve component: ${normalizedComponentPath}`;
    });

    function getContainer(name = 'default') {
        if (typeof name !== 'string' || name.trim() === '') {
            return [];
        }

        const items = containerMap[name];

        return Array.isArray(items) ? items : [];
    }

    function hasContainer(name = 'default') {
        return getContainer(name).length > 0;
    }
</script>

{#if LoadedComponent}
    <LoadedComponent
        {...propBag}
        default_container={getContainer('default')}
        get_container={getContainer}
        has_container={hasContainer}
        containers={containerMap}
        ContainerRenderer={ContainerRenderer}
    />
{:else}
    <div class="svelte-renderer__error">{errorMessage}</div>
{/if}

<style>
    .svelte-renderer__error {
        background: #fff3f1;
        border: 1px solid #f2b6ad;
        border-radius: 12px;
        color: #8a1c0f;
        padding: 1rem;
    }
</style>
