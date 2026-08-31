const DEFAULT_CONFIRMATION = Object.freeze({
    title: 'Confirmer cette action',
    resource: 'Élément sélectionné',
    consequence: 'Cette action peut modifier durablement cet élément.',
    confirmLabel: 'Confirmer',
});

function focusableElements(container) {
    if (! container?.querySelectorAll) {
        return [];
    }

    return [...container.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    )].filter((element) => ! element.hasAttribute?.('hidden'));
}

export function trapDialogFocus(event, container) {
    if (event.key !== 'Tab') {
        return;
    }

    const focusable = focusableElements(container);
    const first = focusable[0];
    const last = focusable.at(-1);

    if (! first || ! last) {
        event.preventDefault();

        return;
    }

    if (event.shiftKey && globalThis.document?.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (! event.shiftKey && globalThis.document?.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

export function createBelkhirSpaceConfirmDialog() {
    return {
        open: false,
        title: DEFAULT_CONFIRMATION.title,
        resource: DEFAULT_CONFIRMATION.resource,
        consequence: DEFAULT_CONFIRMATION.consequence,
        confirmLabel: DEFAULT_CONFIRMATION.confirmLabel,
        form: null,
        submitter: null,
        returnFocus: null,

        show(event) {
            const detail = event?.detail ?? {};

            if (! detail.form) {
                return;
            }

            this.form = detail.form;
            this.submitter = detail.submitter ?? null;
            this.returnFocus = detail.submitter ?? globalThis.document?.activeElement ?? null;
            this.title = detail.title || DEFAULT_CONFIRMATION.title;
            this.resource = detail.resource || DEFAULT_CONFIRMATION.resource;
            this.consequence = detail.consequence || DEFAULT_CONFIRMATION.consequence;
            this.confirmLabel = detail.confirmLabel || DEFAULT_CONFIRMATION.confirmLabel;
            this.open = true;
            this.$nextTick?.(() => this.$refs?.cancel?.focus());
        },

        close({ restoreFocus = true } = {}) {
            const returnFocus = this.returnFocus;

            this.open = false;
            this.form = null;
            this.submitter = null;
            this.returnFocus = null;

            if (restoreFocus) {
                this.$nextTick?.(() => returnFocus?.focus?.());
            }
        },

        confirm() {
            const form = this.form;
            const submitter = this.submitter;

            if (! form) {
                return;
            }

            form.dataset.belkhirSpaceConfirmed = 'true';
            this.close({ restoreFocus: false });

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter ?? undefined);
            } else {
                form.submit();
            }
        },

        trap(event, container) {
            trapDialogFocus(event, container);
        },
    };
}

export function readableFileSize(bytes) {
    const value = Number(bytes);

    if (! Number.isFinite(value) || value <= 0) {
        return '0 octet';
    }

    if (value < 1024) {
        return `${value} octets`;
    }

    if (value < 1024 * 1024) {
        return `${(value / 1024).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} Ko`;
    }

    return `${(value / (1024 * 1024)).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} Mo`;
}

export function createBelkhirSpaceFileInput({
    previewImages = false,
    multiple = false,
    disabled = false,
    urlApi = globalThis.URL,
} = {}) {
    return {
        dragging: false,
        fileName: '',
        fileSize: '',
        previewUrl: '',
        selection: 0,

        get hasFile() {
            return this.fileName !== '';
        },

        select(files) {
            const selectedFiles = Array.from(files ?? []);
            const first = selectedFiles[0];
            const selection = ++this.selection;

            this.releasePreview();

            if (! first) {
                this.fileName = '';
                this.fileSize = '';

                return;
            }

            this.fileName = multiple && selectedFiles.length > 1
                ? `${selectedFiles.length} fichiers sélectionnés`
                : first.name;
            this.fileSize = multiple && selectedFiles.length > 1
                ? readableFileSize(selectedFiles.reduce((total, file) => total + Number(file.size || 0), 0))
                : readableFileSize(first.size);

            if (previewImages && first.type?.startsWith('image/') && selection === this.selection) {
                this.previewUrl = urlApi?.createObjectURL?.(first) ?? '';
            }
        },

        changed(event) {
            this.select(event?.target?.files);
        },

        acceptDrop(files) {
            this.dragging = false;

            if (disabled || ! files?.length || ! this.$refs?.input) {
                return;
            }

            if (typeof globalThis.DataTransfer !== 'function') {
                return;
            }

            const transfer = new globalThis.DataTransfer();
            Array.from(files).slice(0, multiple ? undefined : 1).forEach((file) => transfer.items.add(file));
            this.$refs.input.files = transfer.files;
            this.$refs.input.dispatchEvent(new Event('change', { bubbles: true }));
        },

        clear() {
            ++this.selection;
            this.releasePreview();
            this.fileName = '';
            this.fileSize = '';

            if (this.$refs?.input) {
                this.$refs.input.value = '';
                this.$refs.input.dispatchEvent(new Event('change', { bubbles: true }));
                this.$refs.input.focus();
            }
        },

        releasePreview() {
            if (this.previewUrl) {
                urlApi?.revokeObjectURL?.(this.previewUrl);
                this.previewUrl = '';
            }
        },

        destroy() {
            this.releasePreview();
        },
    };
}

export function createBelkhirSpaceLightbox(images = []) {
    const safeImages = Array.from(images).map((image) => ({
        src: String(image?.src ?? ''),
        alt: String(image?.alt ?? 'Photo agrandie'),
    })).filter((image) => image.src !== '');

    return {
        images: safeImages,
        open: false,
        index: 0,
        returnFocus: null,

        get current() {
            return this.images[this.index] ?? null;
        },

        get hasSeveral() {
            return this.images.length > 1;
        },

        show(index, trigger) {
            if (! Number.isInteger(index) || ! this.images[index]) {
                return;
            }

            this.index = index;
            this.returnFocus = trigger ?? globalThis.document?.activeElement ?? null;
            this.open = true;
            this.$nextTick?.(() => this.$refs?.close?.focus());
        },

        close() {
            const returnFocus = this.returnFocus;

            this.open = false;
            this.returnFocus = null;
            this.$nextTick?.(() => returnFocus?.focus?.());
        },

        previous() {
            if (this.hasSeveral) {
                this.index = (this.index - 1 + this.images.length) % this.images.length;
            }
        },

        next() {
            if (this.hasSeveral) {
                this.index = (this.index + 1) % this.images.length;
            }
        },

        trap(event, container) {
            trapDialogFocus(event, container);
        },
    };
}

export function registerBelkhirSpaceUi(Alpine) {
    Alpine.data('belkhirSpaceConfirmDialog', createBelkhirSpaceConfirmDialog);
    Alpine.data('belkhirSpaceFileInput', (config) => createBelkhirSpaceFileInput(config));
    Alpine.data('belkhirSpaceLightbox', (images) => createBelkhirSpaceLightbox(images));

    Alpine.directive('belkhir-space-confirm', (form, {}, { cleanup }) => {
        const listener = (event) => {
            if (form.dataset.belkhirSpaceConfirmed === 'true') {
                delete form.dataset.belkhirSpaceConfirmed;

                return;
            }

            event.preventDefault();
            globalThis.window?.dispatchEvent(new CustomEvent('belkhir-space-confirm-request', {
                detail: {
                    form,
                    submitter: event.submitter ?? null,
                    title: form.dataset.confirmTitle,
                    resource: form.dataset.confirmResource,
                    consequence: form.dataset.confirmConsequence,
                    confirmLabel: form.dataset.confirmLabel,
                },
            }));
        };

        form.addEventListener('submit', listener);
        cleanup(() => form.removeEventListener('submit', listener));
    });
}
