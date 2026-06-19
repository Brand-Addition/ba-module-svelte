import test from 'node:test';
import assert from 'node:assert/strict';

class FakeClassList {
    constructor() {
        this.values = new Set();
    }

    add(...tokens) {
        tokens.forEach((token) => this.values.add(token));
    }

    contains(token) {
        return this.values.has(token);
    }

    remove(...tokens) {
        tokens.forEach((token) => this.values.delete(token));
    }
}

class FakeEventTarget {
    constructor() {
        this.listeners = new Map();
    }

    addEventListener(type, listener) {
        if (!this.listeners.has(type)) {
            this.listeners.set(type, new Set());
        }

        this.listeners.get(type).add(listener);
    }

    dispatchEvent(event) {
        const listeners = this.listeners.get(event.type) ?? new Set();

        listeners.forEach((listener) => {
            listener.call(this, event);
        });

        return true;
    }

    removeEventListener(type, listener) {
        this.listeners.get(type)?.delete(listener);
    }
}

class FakeElement extends FakeEventTarget {
    constructor(tagName = 'div') {
        super();
        this.tagName = tagName.toUpperCase();
        this.attributes = new Map();
        this.classList = new FakeClassList();
        this.closestMap = new Map();
        this.dataset = {};
        this.innerHTML = '';
        this.isConnected = true;
        this.outerHTML = '';
        this.queryAllMap = new Map();
        this.queryMap = new Map();
        this.style = {};
        this.textContent = '';
        this.title = '';
    }

    closest(selector) {
        return this.closestMap.get(selector) ?? null;
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }

    hasAttribute(name) {
        return this.attributes.has(name);
    }

    querySelector(selector) {
        return this.queryMap.get(selector) ?? null;
    }

    querySelectorAll(selector) {
        return this.queryAllMap.get(selector) ?? [];
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }
}

class FakeButtonElement extends FakeElement {
    constructor() {
        super('button');
        this.disabled = false;
    }
}

class FakeInputElement extends FakeElement {
    constructor() {
        super('input');
        this.max = '';
        this.maxLength = -1;
        this.min = '';
        this.minLength = -1;
        this.required = false;
        this.value = '';
    }

    focus() {
        this.focused = true;
    }
}

class FakeSelectElement extends FakeElement {
    constructor() {
        super('select');
        this.value = '';
    }
}

class FakeTextareaElement extends FakeElement {
    constructor() {
        super('textarea');
    }
}

class FakeFormElement extends FakeElement {
    constructor() {
        super('form');
        this.action = '/submit';
        this.elements = [];
        this.method = 'post';
        this.reportValidityResult = true;
    }

    reportValidity() {
        return this.reportValidityResult;
    }
}

class FakeCustomEvent {
    constructor(type, options = {}) {
        this.bubbles = options.bubbles ?? false;
        this.detail = options.detail ?? null;
        this.type = type;
    }
}

function createLocalStorage() {
    const values = new Map();

    return {
        getItem(key) {
            return values.has(key) ? values.get(key) : null;
        },
        setItem(key, value) {
            values.set(key, String(value));
        },
    };
}

function createDocument() {
    const documentElement = new FakeElement('html');
    const body = new FakeElement('body');
    const selectors = new Map();

    return {
        baseURI: 'https://example.test/',
        body,
        documentElement,
        querySelector(selector) {
            return selectors.get(selector) ?? null;
        },
        querySelectorAll() {
            return [];
        },
        registerSelector(selector, element) {
            selectors.set(selector, element);
        },
    };
}

function installDom() {
    const document = createDocument();
    const window = new FakeEventTarget();

    window.document = document;
    window.localStorage = createLocalStorage();
    window.location = {
        assign(url) {
            window.location.assignedUrl = url;
        },
        href: 'https://example.test/current',
        reload() {
            window.location.reloadCount = (window.location.reloadCount ?? 0) + 1;
        },
    };
    window.setTimeout = () => 1;
    window.clearTimeout = () => {};

    globalThis.CustomEvent = FakeCustomEvent;
    globalThis.document = document;
    globalThis.FormData = class FakeFormData {
        constructor(form) {
            this.values = new Map(form.formDataEntries ?? []);
        }

        get(key) {
            return this.values.get(key) ?? null;
        }
    };
    globalThis.HTMLElement = FakeElement;
    globalThis.HTMLButtonElement = FakeButtonElement;
    globalThis.HTMLFormElement = FakeFormElement;
    globalThis.HTMLInputElement = FakeInputElement;
    globalThis.HTMLSelectElement = FakeSelectElement;
    globalThis.HTMLTextAreaElement = FakeTextareaElement;
    globalThis.window = window;

    return { document, window };
}

test('messages facade sanitizes unsafe server fragments', async () => {
    const { document } = installDom();
    const messages = await import('../../js/lib/messages.js');
    const target = new FakeElement();

    document.registerSelector('[data-placeholder="messages"]', target);

    messages.applyMessagePayload({
        messages: '<div onclick="alert(1)"><script>alert(1)</script><a href="javascript:alert(1)">Unsafe</a><span>Safe</span></div>',
    });

    assert.equal(target.innerHTML, '<div><a>Unsafe</a><span>Safe</span></div>');
});

test('forms facade applies validation and manages one AJAX submit lifecycle', async () => {
    installDom();
    const forms = await import('../../js/lib/forms.js');
    const form = new FakeFormElement();
    const button = new FakeButtonElement();
    const input = new FakeInputElement();

    input.setAttribute('data-validate', '{"required-entry":true,"minlength":2}');
    form.elements = [input];
    form.formDataEntries = [['product', '42']];
    form.queryMap.set('.action.primary', button);

    forms.applyValidationRules(form);

    assert.equal(input.required, true);
    assert.equal(input.minLength, 2);

    const controller = forms.createAjaxFormController(form, {
        sendRequest() {
            return {
                payload: { ok: true },
                response: {
                    ok: true,
                    redirected: false,
                    url: '',
                },
            };
        },
        submitButtonSelector: '.action.primary',
        submittedLabel: 'Saved',
        submittingLabel: 'Saving',
    });

    const result = await controller.submit();

    assert.equal(result, true);
    assert.equal(form.dataset.baFormState, 'submitted');
    assert.equal(button.textContent, 'Saved');
});

test('forms facade only redirects to same-origin http urls', async () => {
    const { window } = installDom();
    const forms = await import('../../js/lib/forms.js');

    assert.equal(forms.redirectTo('/customer/account'), true);
    assert.equal(window.location.assignedUrl, 'https://example.test/customer/account');

    delete window.location.assignedUrl;

    assert.equal(forms.redirectTo('https://evil.test/phish'), false);
    assert.equal(window.location.assignedUrl, undefined);

    assert.equal(forms.redirectTo('javascript:alert(1)'), false);
    assert.equal(window.location.assignedUrl, undefined);
});

test('commerce facade creates add-to-cart controllers through one official API', async () => {
    const { document } = installDom();
    const commerce = await import('../../js/lib/commerce.js');
    const form = new FakeFormElement();
    const button = new FakeButtonElement();
    const messagesTarget = new FakeElement();
    const minicartTarget = new FakeElement();
    const productStatus = new FakeElement();
    const productStatusLabel = new FakeElement('span');
    const scope = new FakeElement();

    form.queryMap.set('.action.primary', button);
    form.formDataEntries = [['product', '42']];
    document.body = new FakeElement('body');
    document.registerSelector('[data-placeholder="messages"]', messagesTarget);
    document.registerSelector('[data-block="minicart"]', minicartTarget);
    form.closestMap.set('.product-item-info, .product-info-main, .product-add-form, .product-item', scope);
    scope.queryAllMap.set('.stock.available', [productStatus]);
    productStatus.queryMap.set('span', productStatusLabel);

    const controller = commerce.createAddToCartController(form, {
        sendRequest() {
            return {
                payload: {
                    backUrl: 'https://evil.test/blocked',
                    messages: '<div class="message-success" onclick="alert(1)">Added<script>alert(1)</script></div>',
                    minicart: '<div><img src="x" onerror="alert(1)"></div>',
                    product: {
                        statusText: '<img src=x onerror=alert(1)>Sold out',
                    },
                },
                response: {
                    ok: true,
                    redirected: false,
                    url: '',
                },
            };
        },
    });

    assert.ok(controller);
    assert.equal(await controller.submit(), true);
    assert.equal(messagesTarget.innerHTML, '<div class="message-success">Added</div>');
    assert.equal(minicartTarget.outerHTML, '<div><img src="x"></div>');
    assert.equal(productStatusLabel.textContent, '<img src=x onerror=alert(1)>Sold out');
    assert.equal(productStatusLabel.innerHTML, '');
});

test('magento utility builds storefront URLs, REST URLs, and JSON requests', async () => {
    const { window } = installDom();
    const magento = await import('../../js/lib/magento.js');
    let request = null;

    window.BASE_URL = 'https://example.test/store/';
    window.checkoutConfig = {
        storeCode: 'en_gb',
    };
    globalThis.fetch = async (url, options) => {
        request = { options, url };

        return {
            ok: true,
            status: 200,
            async text() {
                return '{"ok":true}';
            },
        };
    };

    assert.equal(
        magento.buildStorefrontUrl('customer/address'),
        'https://example.test/store/customer/address'
    );
    assert.equal(
        magento.buildRestUrl('/customers/me'),
        'https://example.test/store/rest/en_gb/V1/customers/me'
    );

    const payload = await magento.requestMagentoJson(magento.buildRestUrl('/customers/me'), {
        body: {
            scope: 'full',
        },
        method: 'POST',
        query: {
            include: 'addresses',
        },
    });

    assert.deepEqual(payload, { ok: true });
    assert.equal(
        request.url,
        'https://example.test/store/rest/en_gb/V1/customers/me?include=addresses'
    );
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.body, '{"scope":"full"}');
    assert.equal(request.options.headers.get('Accept'), 'application/json');
    assert.equal(request.options.headers.get('X-Requested-With'), 'XMLHttpRequest');
});

test('i18n helper translates through baTranslate and preserves placeholders', async () => {
    const { window } = installDom();
    const [i18n, translation] = await Promise.all([
        import('../../js/lib/i18n.js'),
        import('../../js/lib/translation.js'),
    ]);

    assert.equal(i18n._('Open size guide'), 'Open size guide');

    translation.registerSvelteTranslations({
        '%s days': 'translated %s days',
        'Quote name': 'Translated quote name',
        View: 'Translated view',
    }, window);
    translation.ensureBaTranslate(window);

    assert.equal(i18n._('View'), 'Translated view');
    assert.equal(i18n._('Quote name'), 'Translated quote name');
    assert.equal(i18n._('%s days', 14), 'translated 14 days');
    assert.equal(i18n._('Unregistered phrase'), 'Unregistered phrase');
});
