<script>
    import { createEventDispatcher } from 'svelte';
    import { _ } from '@modules/BA_Svelte/js/lib/i18n.js';

    let {
        children,
        class: className = '',
        closeLabel = 'Close',
        closeOnBackdrop = true,
        title = '',
    } = $props();

    const dispatch = createEventDispatcher();

    let dialog = null;

    export function close(returnValue = 'close') {
        if (!dialog?.open) {
            return;
        }

        if (typeof dialog.close === 'function') {
            dialog.close(returnValue);
        }
    }

    export function open() {
        if (!dialog || dialog.open) {
            return;
        }

        dialog.returnValue = 'close';
        dialog.showModal();
        dispatch('open');
    }

    export function requestClose(returnValue = 'close') {
        if (!dialog?.open) {
            return;
        }

        if (typeof dialog.requestClose === 'function') {
            dialog.requestClose(returnValue);
            return;
        }

        close(returnValue);
    }

    function handleBackdropClick(event) {
        if (!closeOnBackdrop || event.target !== dialog) {
            return;
        }

        requestClose('backdrop');
    }

    function handleClose() {
        dispatch('close', {
            returnValue: dialog?.returnValue ?? '',
        });
    }
</script>

<dialog bind:this={dialog} class={`ba-svelte-popup ${className}`.trim()} onclick={handleBackdropClick} onclose={handleClose}>
    <div class="ba-svelte-popup__inner">
        {#if title}
            <header class="ba-svelte-popup__header">
                <h2>{title}</h2>
                <button class="ba-svelte-popup__close" type="button" onclick={() => requestClose('close-button')}>
                    {_(closeLabel)}
                </button>
            </header>
        {/if}

        <div class="ba-svelte-popup__content">
            {@render children?.()}
        </div>
    </div>
</dialog>

<style>
    .ba-svelte-popup {
        background: #fff;
        border: 0;
        border-radius: 1rem;
        box-shadow: 0 1.5rem 4rem rgba(15, 39, 64, 0.22);
        color: #0f2740;
        max-width: min(42rem, calc(100vw - 2rem));
        padding: 0;
        width: 100%;
    }

    .ba-svelte-popup::backdrop {
        background: rgba(8, 18, 31, 0.48);
    }

    .ba-svelte-popup__inner {
        padding: 1.5rem;
    }

    .ba-svelte-popup__header {
        align-items: center;
        display: flex;
        gap: 1rem;
        justify-content: space-between;
        margin-bottom: 1rem;
    }

    .ba-svelte-popup__header h2 {
        font-size: 1.4rem;
        line-height: 1.1;
        margin: 0;
    }

    .ba-svelte-popup__close {
        background: transparent;
        border: 0;
        color: inherit;
        cursor: pointer;
        font: inherit;
        font-weight: 700;
        padding: 0;
    }

    .ba-svelte-popup__content {
        min-width: 0;
    }
</style>
