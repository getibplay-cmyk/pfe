export function initializeCmiCheckout(root = document, host = window) {
    const form = root.querySelector?.('form[data-cmi-checkout-form]');
    if (! form || form.dataset.autoSubmit === 'false' || form.dataset.autoSubmitted === 'true') return false;

    form.dataset.autoSubmitted = 'true';
    host.setTimeout?.(() => {
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }, 450);

    return true;
}
