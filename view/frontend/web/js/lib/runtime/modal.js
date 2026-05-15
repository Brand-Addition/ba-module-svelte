import {
    DEFAULT_OPEN_CLASS,
    emitEvent,
    getDocument,
    readModalOptions,
} from './shared.js';

const CLOSE_SELECTOR = '[data-ba-modal-close], [data-action="close-modal"]';

function ensureModalDialog(element) {
    if (!(element instanceof HTMLElement)) {
        return null;
    }

    const documentRef = getDocument(element);
    const existingDialog = Array.from(element.children).find((child) => child instanceof HTMLDialogElement) ?? null;

    if (existingDialog instanceof HTMLDialogElement) {
        existingDialog.dataset.baModalDialog = 'true';
        Array.from(existingDialog.children)
            .filter((child) => child instanceof HTMLElement)
            .forEach((child) => {
                child.dataset.baModalContent = 'true';
            });
        return existingDialog;
    }

    const dialog = documentRef.createElement('dialog');
    const content = documentRef.createElement('div');
    const transferableNodes = Array.from(element.childNodes).filter((node) => {
        if (node === dialog) {
            return false;
        }

        if (node.nodeType === Node.TEXT_NODE) {
            return node.textContent?.trim() !== '';
        }

        return true;
    });

    dialog.dataset.baModalDialog = 'true';
    content.dataset.baModalContent = 'true';

    transferableNodes.forEach((node) => content.appendChild(node));
    dialog.appendChild(content);
    element.appendChild(dialog);

    return dialog;
}

function bindTriggerListener(documentRef, triggerSelector, openModal) {
    if (typeof triggerSelector !== 'string' || triggerSelector.trim() === '') {
        return () => {};
    }

    const handleTriggerClick = (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const trigger = event.target.closest(triggerSelector);

        if (!(trigger instanceof HTMLElement) || !documentRef.contains(trigger)) {
            return;
        }

        event.preventDefault();
        openModal('trigger');
    };

    documentRef.addEventListener('click', handleTriggerClick);

    return () => {
        documentRef.removeEventListener('click', handleTriggerClick);
    };
}

export function createModalController(element, defaults = {}) {
    if (!(element instanceof HTMLElement)) {
        return null;
    }

    const existingController = element.__baModalController;
    if (existingController) {
        return existingController;
    }

    const dialog = ensureModalDialog(element);
    if (!(dialog instanceof HTMLDialogElement)) {
        throw new Error('ba-modal requires a <dialog> element.');
    }

    const options = readModalOptions(element, defaults);
    const documentRef = getDocument(element);
    const closeOnBackdrop = options.closeOnBackdrop ?? true;
    const openClass = options.openClass ?? DEFAULT_OPEN_CLASS;
    let lastCloseReason = 'close';

    function syncState(reason = 'programmatic') {
        element.dataset.state = dialog.open ? 'open' : 'closed';
        element.dataset.open = String(dialog.open);
        element.classList.toggle(openClass, dialog.open);
        dialog.classList.toggle(openClass, dialog.open);

        if (dialog.open) {
            element.setAttribute('open', '');
        } else {
            element.removeAttribute('open');
        }

        emitEvent(element, `ba:svelte:modal-${dialog.open ? 'open' : 'close'}`, {
            controller,
            dialog,
            open: dialog.open,
            reason,
            returnValue: dialog.returnValue ?? '',
        });
    }

    function openModal(reason = 'programmatic') {
        if (dialog.open) {
            return controller;
        }

        dialog.showModal();
        syncState(reason);

        return controller;
    }

    function requestClose(returnValue = 'close', reason = 'programmatic') {
        if (!dialog.open) {
            return controller;
        }

        lastCloseReason = reason;

        if (typeof dialog.requestClose === 'function') {
            dialog.requestClose(returnValue);
        } else {
            dialog.close(returnValue);
        }

        return controller;
    }

    function handleDialogClick(event) {
        if (closeOnBackdrop && event.target === dialog) {
            requestClose('backdrop', 'backdrop');
            return;
        }

        if (!(event.target instanceof Element) || !event.target.closest(CLOSE_SELECTOR)) {
            return;
        }

        event.preventDefault();
        requestClose('close-button', 'close-button');
    }

    function handleDialogClose() {
        syncState(lastCloseReason);
        lastCloseReason = 'close';
    }

    const controller = {
        close(returnValue = 'close', reason = 'programmatic') {
            return requestClose(returnValue, reason);
        },
        destroy() {
            dialog.removeEventListener('click', handleDialogClick);
            dialog.removeEventListener('close', handleDialogClose);
            removeTriggerListener();

            if (dialog.open) {
                dialog.close('destroy');
            }

            element.dataset.state = 'closed';
            element.dataset.open = 'false';
            element.classList.remove(openClass);
            element.removeAttribute('open');
            dialog.classList.remove(openClass);

            delete element.__baModalController;
        },
        dialog,
        open(reason = 'programmatic') {
            return openModal(reason);
        },
        requestClose,
    };

    const removeTriggerListener = bindTriggerListener(documentRef, options.trigger, openModal);

    dialog.addEventListener('click', handleDialogClick);
    dialog.addEventListener('close', handleDialogClose);

    if (options.initialOpen === true && !dialog.open) {
        dialog.showModal();
    }

    element.__baModalController = controller;
    syncState('init');

    return controller;
}
