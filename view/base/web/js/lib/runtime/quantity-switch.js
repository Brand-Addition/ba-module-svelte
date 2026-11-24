const quantitySwitchControllers = new WeakMap();

export function createQuantitySwitchController(element) {
    if (!(element instanceof HTMLElement)) {
        return null;
    }

    const existingController = quantitySwitchControllers.get(element);
    if (existingController) {
        return existingController;
    }

    const quantityInput = element.querySelector('input[name="qty"]');
    const quantitySelect = element.querySelector('select.qty-select, select[name="qty_as_select"]');

    if (!(quantityInput instanceof HTMLInputElement) || !(quantitySelect instanceof HTMLSelectElement)) {
        return null;
    }

    function syncFromSelect() {
        quantityInput.value = quantitySelect.value;

        if (quantitySelect.value === '10') {
            quantityInput.style.display = '';
            quantitySelect.style.display = 'none';
            quantityInput.focus();
            return;
        }

        quantityInput.style.display = 'none';
        quantitySelect.style.display = '';
    }

    function handleSelectChange() {
        syncFromSelect();
    }

    const controller = {
        destroy() {
            quantitySelect.removeEventListener('change', handleSelectChange);
            quantitySwitchControllers.delete(element);
        },
        root: element,
        sync: syncFromSelect,
    };

    quantitySelect.addEventListener('change', handleSelectChange);
    syncFromSelect();

    quantitySwitchControllers.set(element, controller);

    return controller;
}
