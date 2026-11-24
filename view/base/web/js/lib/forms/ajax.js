import { resolveSafeRedirectUrl } from '../security.js';

const formControllers = new WeakMap();

function getWindowLocation() {
    return typeof window !== 'undefined' && window.location ? window.location : null;
}

function parseJsonAttribute(value, fallback = {}) {
    if (typeof value !== 'string' || value.trim() === '') {
        return fallback;
    }

    try {
        return JSON.parse(value);
    } catch {
        return fallback;
    }
}

function createRequestHeaders(headers = {}) {
    const resolvedHeaders = new Headers(headers);

    if (!resolvedHeaders.has('Accept')) {
        resolvedHeaders.set('Accept', 'application/json');
    }

    if (!resolvedHeaders.has('X-Requested-With')) {
        resolvedHeaders.set('X-Requested-With', 'XMLHttpRequest');
    }

    return resolvedHeaders;
}

function looksLikeJsonResponse(text) {
    const normalizedText = text.trim();

    return normalizedText.startsWith('{') || normalizedText.startsWith('[');
}

async function parseResponsePayload(response) {
    const responseText = await response.text();
    if (responseText.trim() === '') {
        return null;
    }

    if (!looksLikeJsonResponse(responseText)) {
        return responseText;
    }

    try {
        return JSON.parse(responseText);
    } catch {
        return responseText;
    }
}

function normalizeRequestResult(result) {
    if (result && typeof result === 'object' && ('payload' in result || 'response' in result)) {
        return {
            payload: result.payload ?? null,
            response: result.response ?? null,
        };
    }

    return {
        payload: null,
        response: result ?? null,
    };
}

function getDefaultButtonText(button, fallback = 'Submit') {
    const cachedText = button.dataset.baFormDefaultText;
    if (typeof cachedText === 'string' && cachedText !== '') {
        return cachedText;
    }

    const text = button.querySelector('span')?.textContent?.trim()
        || button.textContent?.trim()
        || fallback;

    button.dataset.baFormDefaultText = text;

    return text;
}

function setButtonText(button, text, fallback = 'Submit') {
    const resolvedText = typeof text === 'string' && text.trim() !== ''
        ? text.trim()
        : getDefaultButtonText(button, fallback);
    const label = button.querySelector('span');

    if (label instanceof HTMLElement) {
        label.textContent = resolvedText;
    } else {
        button.textContent = resolvedText;
    }

    button.title = resolvedText;
}

function setFormBusyState(form, button, isBusy, options = {}) {
    form.dataset.baFormState = isBusy ? 'loading' : 'idle';

    if (isBusy) {
        form.setAttribute('aria-busy', 'true');
    } else {
        form.removeAttribute('aria-busy');
    }

    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    if (isBusy) {
        button.classList.add('disabled');
        button.disabled = true;
        button.setAttribute('aria-disabled', 'true');
        setButtonText(button, options.submittingLabel, options.defaultButtonText);
        return;
    }

    button.classList.remove('disabled');
    button.disabled = false;
    button.removeAttribute('aria-disabled');
    setButtonText(button, options.defaultButtonText, options.defaultButtonText);
}

function markFormSubmitted(form, button, options = {}) {
    form.dataset.baFormState = 'submitted';
    form.removeAttribute('aria-busy');

    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    button.disabled = false;
    button.removeAttribute('aria-disabled');
    setButtonText(button, options.submittedLabel, options.defaultButtonText);
}

export function resolveFormElement(root) {
    if (root instanceof HTMLFormElement) {
        return root;
    }

    if (!(root instanceof HTMLElement)) {
        return null;
    }

    const nestedForm = root.querySelector('form');
    if (nestedForm instanceof HTMLFormElement) {
        return nestedForm;
    }

    const parentForm = root.closest('form');

    return parentForm instanceof HTMLFormElement ? parentForm : null;
}

export function findSubmitButton(form, selector = '.action.primary') {
    const directMatch = form.querySelector(selector);
    if (directMatch instanceof HTMLButtonElement) {
        return directMatch;
    }

    const fallbackMatch = form.querySelector('button[type="submit"], button:not([type])');

    return fallbackMatch instanceof HTMLButtonElement ? fallbackMatch : null;
}

export function applyValidationRules(form) {
    Array.from(form.elements).forEach((field) => {
        if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement)) {
            return;
        }

        const rules = parseJsonAttribute(field.getAttribute('data-validate'), {});

        if (field.classList.contains('required-entry') || rules.required === true || rules['required-entry'] === true) {
            field.required = true;
        }

        if (typeof rules.minlength === 'number') {
            field.minLength = rules.minlength;
        }

        if (typeof rules.maxlength === 'number') {
            field.maxLength = rules.maxlength;
        }

        if (typeof rules.min === 'number') {
            field.min = String(rules.min);
        }

        if (typeof rules.max === 'number') {
            field.max = String(rules.max);
        }
    });
}

export function validateForm(form) {
    applyValidationRules(form);

    if (typeof form.reportValidity === 'function') {
        return form.reportValidity();
    }

    return typeof form.checkValidity === 'function' ? form.checkValidity() : true;
}

export function redirectTo(url) {
    const location = getWindowLocation();
    if (!location || typeof location.assign !== 'function') {
        return false;
    }

    const resolvedUrl = resolveSafeRedirectUrl(url, location);
    if (resolvedUrl === '') {
        return false;
    }

    const currentUrlWithoutHash = String(location.href ?? '').split('#')[0];
    const nextUrlWithoutHash = resolvedUrl.split('#')[0];

    location.assign(resolvedUrl);

    if (currentUrlWithoutHash === nextUrlWithoutHash && typeof location.reload === 'function') {
        location.reload();
    }

    return true;
}

export function createAjaxFormController(root, options = {}) {
    if (!(root instanceof HTMLElement) && !(root instanceof HTMLFormElement)) {
        return null;
    }

    const existingController = formControllers.get(root);
    if (existingController) {
        return existingController;
    }

    const form = resolveFormElement(root);
    if (!(form instanceof HTMLFormElement)) {
        return null;
    }

    const resolvedOptions = {
        buttonTextFallback: options.buttonTextFallback || 'Submit',
        buildRequest: typeof options.buildRequest === 'function' ? options.buildRequest : null,
        errorMessage: options.errorMessage || 'Form submission failed.',
        headers: options.headers ?? {},
        isSuccess: typeof options.isSuccess === 'function' ? options.isSuccess : null,
        onBeforeSubmit: typeof options.onBeforeSubmit === 'function' ? options.onBeforeSubmit : null,
        onError: typeof options.onError === 'function' ? options.onError : null,
        onFinally: typeof options.onFinally === 'function' ? options.onFinally : null,
        onSuccess: typeof options.onSuccess === 'function' ? options.onSuccess : null,
        resetDelay: Number(options.resetDelay ?? 1000),
        sendRequest: typeof options.sendRequest === 'function' ? options.sendRequest : null,
        submitButtonSelector: options.submitButtonSelector || '.action.primary',
        submittedLabel: options.submittedLabel || '',
        submittingLabel: options.submittingLabel || '',
        throwOnError: options.throwOnError === true,
        validate: typeof options.validate === 'function' ? options.validate : validateForm,
    };

    let isSubmitting = false;
    let resetTimer = 0;

    function resetButtonState(button) {
        window.clearTimeout(resetTimer);
        setFormBusyState(form, button, false, {
            defaultButtonText: getDefaultButtonText(button, resolvedOptions.buttonTextFallback),
            submittingLabel: resolvedOptions.submittingLabel,
        });
    }

    async function submit(overrideOptions = {}) {
        const runtimeOptions = {
            ...resolvedOptions,
            ...overrideOptions,
        };

        if (isSubmitting || runtimeOptions.validate(form) === false) {
            return false;
        }

        const formData = overrideOptions.formData instanceof FormData
            ? overrideOptions.formData
            : new FormData(form);
        const submitButton = findSubmitButton(form, runtimeOptions.submitButtonSelector);
        const defaultButtonText = submitButton
            ? getDefaultButtonText(submitButton, runtimeOptions.buttonTextFallback)
            : runtimeOptions.buttonTextFallback;
        const context = {
            form,
            formData,
            payload: null,
            redirectTo,
            response: null,
            root,
            submitButton,
        };
        let isSubmitted = false;

        isSubmitting = true;

        if (submitButton) {
            setFormBusyState(form, submitButton, true, {
                defaultButtonText,
                submittingLabel: runtimeOptions.submittingLabel,
            });
        } else {
            form.dataset.baFormState = 'loading';
            form.setAttribute('aria-busy', 'true');
        }

        try {
            if (typeof runtimeOptions.onBeforeSubmit === 'function') {
                await runtimeOptions.onBeforeSubmit(context);
            }

            const request = typeof runtimeOptions.buildRequest === 'function'
                ? await runtimeOptions.buildRequest(context)
                : {};
            const requestOptions = {
                body: request.body ?? formData,
                credentials: request.credentials ?? 'same-origin',
                headers: request.headers ?? createRequestHeaders(runtimeOptions.headers ?? {}),
                method: request.method ?? String(form.method || 'POST').toUpperCase(),
                url: request.url ?? form.action,
            };
            let response = null;
            let payload = null;

            if (typeof runtimeOptions.sendRequest === 'function') {
                const result = normalizeRequestResult(await runtimeOptions.sendRequest(context, requestOptions));

                response = result.response;
                payload = result.payload;
            } else {
                response = await fetch(requestOptions.url, {
                    body: requestOptions.body,
                    credentials: requestOptions.credentials,
                    headers: requestOptions.headers,
                    method: requestOptions.method,
                });
                payload = await parseResponsePayload(response);
            }

            context.response = response;
            context.payload = payload;

            const isSuccess = typeof runtimeOptions.isSuccess === 'function'
                ? runtimeOptions.isSuccess(context)
                : response?.ok !== false;

            if (!isSuccess) {
                const error = payload instanceof Error
                    ? payload
                    : new Error(runtimeOptions.errorMessage);
                const handled = typeof runtimeOptions.onError === 'function'
                    ? await runtimeOptions.onError({
                        ...context,
                        error,
                    })
                    : undefined;

                if (handled !== undefined) {
                    return handled;
                }

                if (runtimeOptions.throwOnError) {
                    throw error;
                }

                return false;
            }

            const result = typeof runtimeOptions.onSuccess === 'function'
                ? await runtimeOptions.onSuccess(context)
                : true;

            isSubmitted = true;

            if (submitButton) {
                markFormSubmitted(form, submitButton, {
                    defaultButtonText,
                    submittedLabel: runtimeOptions.submittedLabel,
                });
            }

            return result ?? true;
        } catch (error) {
            const handled = typeof runtimeOptions.onError === 'function'
                ? await runtimeOptions.onError({
                    ...context,
                    error,
                })
                : undefined;

            if (handled !== undefined) {
                return handled;
            }

            if (runtimeOptions.throwOnError) {
                throw error;
            }

            return false;
        } finally {
            if (submitButton && submitButton.isConnected) {
                if (isSubmitted && runtimeOptions.submittedLabel) {
                    resetTimer = window.setTimeout(() => {
                        resetButtonState(submitButton);
                    }, runtimeOptions.resetDelay);
                } else {
                    resetButtonState(submitButton);
                }
            } else {
                form.dataset.baFormState = 'idle';
                form.removeAttribute('aria-busy');
            }

            if (typeof runtimeOptions.onFinally === 'function') {
                await runtimeOptions.onFinally(context);
            }

            isSubmitting = false;
        }
    }

    function handleSubmit(event) {
        event.preventDefault();
        submit();
    }

    const controller = {
        destroy() {
            window.clearTimeout(resetTimer);
            form.removeEventListener('submit', handleSubmit);
            formControllers.delete(root);
        },
        form,
        isSubmitting() {
            return isSubmitting;
        },
        root,
        submit,
    };

    form.addEventListener('submit', handleSubmit);
    formControllers.set(root, controller);

    return controller;
}
