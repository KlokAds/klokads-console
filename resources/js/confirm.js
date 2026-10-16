import { reactive } from 'vue';

/** Shared state for the app's confirm dialog (replaces the browser's confirm()). */
export const confirmState = reactive({
    open: false,
    title: '',
    message: '',
    confirmLabel: 'Confirm',
    cancelLabel: 'Cancel',
    tone: 'default', // default | danger | money
    resolve: null,
});

/**
 * Ask the user to confirm an action. Resolves true / false.
 *
 * @param {{title: string, message?: string, confirmLabel?: string, cancelLabel?: string, tone?: 'default'|'danger'|'money'}} options
 * @returns {Promise<boolean>}
 */
export function confirmDialog(options) {
    confirmState.resolve?.(false);

    return new Promise((resolve) => {
        Object.assign(confirmState, {
            confirmLabel: 'Confirm',
            cancelLabel: 'Cancel',
            tone: 'default',
            message: '',
            ...options,
            open: true,
            resolve,
        });
    });
}

export function settleConfirm(value) {
    const resolve = confirmState.resolve;
    confirmState.open = false;
    confirmState.resolve = null;
    resolve?.(value);
}
