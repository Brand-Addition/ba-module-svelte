import { createAccordionController } from './accordion.js';
import { createCollapsibleController } from './collapsible.js';
import { readElementStringAttribute } from './shared.js';
import { createModalController } from './modal.js';
import { createQuantitySwitchController } from './quantity-switch.js';

let customElementsRegistered = false;

function createDisclosureElementClass(createController) {
    return class extends HTMLElement {
        static observedAttributes = ['open'];

        attributeChangedCallback(name) {
            if (name !== 'open' || !this.controller) {
                return;
            }

            if (this.hasAttribute('open')) {
                this.controller.open('attribute');
            } else {
                this.controller.close('attribute');
            }
        }

        connectedCallback() {
            this.controller = createController(this);
        }

        disconnectedCallback() {
            this.controller?.destroy?.();
            this.controller = null;
        }

        close(reason = 'programmatic') {
            this.controller?.close(reason);
        }

        isOpen() {
            return this.controller?.isOpen?.() ?? this.hasAttribute('open');
        }

        open(reason = 'programmatic') {
            this.controller?.open(reason);
        }

        toggle(reason = 'programmatic') {
            this.controller?.toggle(reason);
        }
    };
}

class BaAccordionElement extends HTMLElement {
    connectedCallback() {
        this.controller = createAccordionController(this);
    }

    disconnectedCallback() {
        this.controller?.destroy?.();
        this.controller = null;
    }

    close(index) {
        this.controller?.close(index);
    }

    open(index) {
        this.controller?.open(index);
    }

    refresh() {
        this.controller?.refresh();
    }

    toggle(index) {
        this.controller?.toggle(index);
    }
}

class BaAccordianElement extends BaAccordionElement {}

class BaModalElement extends HTMLElement {
    static observedAttributes = ['open'];

    attributeChangedCallback(name) {
        if (name !== 'open' || !this.controller) {
            return;
        }

        if (this.hasAttribute('open')) {
            this.controller.open('attribute');
        } else {
            this.controller.close('attribute', 'attribute');
        }
    }

    connectedCallback() {
        this.controller = createModalController(this, {
            trigger: readElementStringAttribute(this, 'trigger'),
        });
    }

    disconnectedCallback() {
        this.controller?.destroy?.();
        this.controller = null;
    }

    close(returnValue = 'close', reason = 'programmatic') {
        this.controller?.close(returnValue, reason);
    }

    open(reason = 'programmatic') {
        this.controller?.open(reason);
    }

    requestClose(returnValue = 'close', reason = 'programmatic') {
        this.controller?.requestClose(returnValue, reason);
    }
}

// class BaAddToCartElement extends HTMLElement {
//     connectedCallback() {
//         if (this.style.display === '') {
//             this.style.display = 'contents';
//         }

//         this.controller = createAddToCartController(this, {
//             addToCartButtonSelector: readElementStringAttribute(this, 'button-selector') || '.action.tocart',
//             messagesSelector: readElementStringAttribute(this, 'messages-selector') || '[data-placeholder="messages"]',
//             minicartSelector: readElementStringAttribute(this, 'minicart-selector') || '[data-block="minicart"]',
//             productStatusSelector: readElementStringAttribute(this, 'product-status-selector') || '.stock.available',
//         });
//     }

//     disconnectedCallback() {
//         this.controller?.destroy?.();
//         this.controller = null;
//     }

//     submit() {
//         return this.controller?.submit?.() ?? false;
//     }
// }

class BaQuantitySwitchElement extends HTMLElement {
    connectedCallback() {
        if (this.style.display === '') {
            this.style.display = 'block';
        }

        this.controller = createQuantitySwitchController(this);
    }

    disconnectedCallback() {
        this.controller?.destroy?.();
        this.controller = null;
    }

    sync() {
        this.controller?.sync?.();
    }
}

export function registerCustomElements() {
    if (customElementsRegistered || typeof window === 'undefined' || !window.customElements) {
        return;
    }

    if (!window.customElements.get('ba-collapsible')) {
        window.customElements.define('ba-collapsible', createDisclosureElementClass(createCollapsibleController));
    }

    if (!window.customElements.get('ba-accordion')) {
        window.customElements.define('ba-accordion', BaAccordionElement);
    }

    if (!window.customElements.get('ba-accordian')) {
        window.customElements.define('ba-accordian', BaAccordianElement);
    }

    if (!window.customElements.get('ba-modal')) {
        window.customElements.define('ba-modal', BaModalElement);
    }

    // if (!window.customElements.get('ba-add-to-cart')) {
    //     window.customElements.define('ba-add-to-cart', BaAddToCartElement);
    // }

    if (!window.customElements.get('ba-quantity-switch')) {
        window.customElements.define('ba-quantity-switch', BaQuantitySwitchElement);
    }

    customElementsRegistered = true;
}
