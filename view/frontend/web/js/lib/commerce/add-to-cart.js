import { _ } from '../i18n.js';
import {
    AJAX_ADD_TO_CART_ERROR_EVENT,
    AJAX_ADD_TO_CART_EVENT,
    dispatchCompatEvent,
} from '../events/storefront.js';
import {
    createAjaxFormController,
    redirectTo,
} from '../forms/ajax.js';
import {
    DEFAULT_MESSAGES_SELECTOR,
    applyMessagePayload,
} from '../messages/storefront.js';
import {
    resolveAddToCartRedirectUrl,
    updateMagentoHtmlFragment,
    updateMagentoProductStatus,
} from './magento-compat.js';

const addToCartControllers = new WeakMap();

function collectProductIds(formData) {
    const productId = formData.get('product');

    return productId === null || productId === ''
        ? []
        : [String(productId)];
}

function collectProductInfo(formData) {
    return collectProductIds(formData).map((id) => ({ id }));
}

function buildEventDetail(form, formData, response = null) {
    return {
        form,
        productIds: collectProductIds(formData),
        productInfo: collectProductInfo(formData),
        response,
        sku: form.dataset.productSku || '',
    };
}

export function createAddToCartController(root, options = {}) {
    if (!(root instanceof HTMLElement) && !(root instanceof HTMLFormElement)) {
        return null;
    }

    const existingController = addToCartControllers.get(root);
    if (existingController) {
        return existingController;
    }

    const resolvedOptions = {
        addToCartButtonSelector: options.addToCartButtonSelector || root.dataset.baAddToCartButtonSelector || '.action.tocart',
        addedLabel: options.addedLabel || root.dataset.baAddToCartAddedLabel || _('Added'),
        addingLabel: options.addingLabel || root.dataset.baAddToCartAddingLabel || _('Adding...'),
        messagesSelector: options.messagesSelector || root.dataset.baAddToCartMessagesSelector || DEFAULT_MESSAGES_SELECTOR,
        minicartSelector: options.minicartSelector || root.dataset.baAddToCartMinicartSelector || '[data-block="minicart"]',
        productStatusSelector: options.productStatusSelector || root.dataset.baAddToCartProductStatusSelector || '.stock.available',
    };
    const formController = createAjaxFormController(root, {
        buttonTextFallback: _('Add to Cart'),
        buildRequest: typeof options.buildRequest === 'function' ? options.buildRequest : undefined,
        errorMessage: _('Unable to add this item to the cart.'),
        resetDelay: 1000,
        sendRequest: typeof options.sendRequest === 'function' ? options.sendRequest : undefined,
        submitButtonSelector: resolvedOptions.addToCartButtonSelector,
        submittedLabel: resolvedOptions.addedLabel,
        submittingLabel: resolvedOptions.addingLabel,
        async onBeforeSubmit({ form }) {
            const minicart = document.querySelector(resolvedOptions.minicartSelector);
            if (minicart instanceof HTMLElement) {
                dispatchCompatEvent(minicart, 'contentLoading');
            }

            form.dataset.baCommerceState = 'loading';
        },
        async onError({ error, form, formData, payload, response }) {
            dispatchCompatEvent(
                typeof document !== 'undefined' ? document : null,
                AJAX_ADD_TO_CART_ERROR_EVENT,
                buildEventDetail(form, formData, payload ?? response ?? error)
            );

            if (response?.redirected && response.url && redirectTo(response.url)) {
                return false;
            }

            window.location.reload();

            return false;
        },
        async onFinally({ form }) {
            form.dataset.baCommerceState = 'idle';
        },
        async onSuccess({ form, formData, payload, response }) {
            dispatchCompatEvent(
                typeof document !== 'undefined' ? document : null,
                AJAX_ADD_TO_CART_EVENT,
                buildEventDetail(form, formData, payload)
            );

            if (response.redirected && response.url && typeof payload === 'string' && redirectTo(response.url)) {
                return false;
            }

            if (payload && typeof payload === 'object' && typeof payload.backUrl === 'string' && payload.backUrl !== '') {
                if (redirectTo(resolveAddToCartRedirectUrl(payload.backUrl, { form }))) {
                    return false;
                }
            }

            // if (payload && typeof payload === 'object') {
            //     applyMessagePayload(payload, {
            //         selector: resolvedOptions.messagesSelector,
            //     });
            // }

            if (payload && typeof payload === 'object' && typeof payload.minicart === 'string') {
                updateMagentoHtmlFragment(resolvedOptions.minicartSelector, payload.minicart);
            }

            if (payload && typeof payload === 'object' && payload.product?.statusText) {
                updateMagentoProductStatus(form, resolvedOptions.productStatusSelector, payload.product.statusText);
            }

            return true;
        },
    });

    if (!formController) {
        return null;
    }

    const controller = {
        destroy() {
            formController.destroy();
            addToCartControllers.delete(root);
        },
        form: formController.form,
        root,
        submit(optionsOverride = {}) {
            return formController.submit(optionsOverride);
        },
    };

    addToCartControllers.set(root, controller);

    return controller;
}
