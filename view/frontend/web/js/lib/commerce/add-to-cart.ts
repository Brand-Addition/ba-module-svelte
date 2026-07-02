import { buildRestUrl, requestMagentoJson } from '../magento.js';
import { dispatchStorefrontMessage } from '../messages.js';
import { _ } from "../i18n.js";

// Plan:
// 1. js lib files making it easy to make custom UI for adding to cart
// 2. .svelte component for add to cart rendering that uses the add to cart js lib files
// I plan to have 2. component be passed a typescript class that has the type and everything else it needs to make the minimal add to cart

export class AddToCartService {
    constructor(
        public productId: string | number,
        public qty = 1
    ) {}


    async add(): Promise<void> {
        const url = buildRestUrl('checkout/cart/add');

        await requestMagentoJson(url, {
            method: 'POST',
            body: {
                form_key: getFormKey(),
                product: this.productId,
                qty: this.qty,
            },
        });

        dispatchStorefrontMessage('success', _('Added to cart.'));
    }
}

/**
 * todo: move to actual helper like $.mage.cookie
 */
function getFormKey(): string | undefined {
    return document.cookie
        .split('; ')
        .find((cookie: string) => cookie.startsWith('form_key='))
        ?.split('=')[1];
}
