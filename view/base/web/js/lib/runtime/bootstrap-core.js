export function handleRuntimeMutationRecords(
    records,
    {
        destroyMountedComponentsInScope,
        mountSvelteComponents,
    }
) {
    records.forEach((record) => {
        record.addedNodes.forEach((node) => {
            if (node instanceof HTMLElement) {
                mountSvelteComponents(node);
            }
        });

        record.removedNodes.forEach((node) => {
            if (node instanceof HTMLElement) {
                destroyMountedComponentsInScope(node);
            }
        });
    });
}
