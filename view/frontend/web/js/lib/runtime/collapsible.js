import { createDisclosureController } from './disclosure-base.js';

export function createCollapsibleController(element) {
    return createDisclosureController(element, 'collapsible');
}
