@props(['id' => 'sanad-pilot-confirm-dialog'])
<div
    x-data="sanadPilotConfirmDialog"
    x-on:sanad-pilot-confirm-request.window="show($event)"
>
    <dialog
        x-ref="modal"
        id="{{ $id }}"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="{{ $id }}-title"
        aria-describedby="{{ $id }}-description"
        x-on:cancel.prevent="close()"
        x-on:click.self="close()"
        class="rf-confirm-dialog"
    >
        <div class="flex items-start gap-4">
            <span aria-hidden="true" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-700">
                <x-icon name="warning" size="lg" />
            </span>
            <div class="min-w-0">
                <h2 id="{{ $id }}-title" class="text-lg font-bold text-sanad-pilot-text" x-text="title">{{ __('Confirmer cette action') }}</h2>
                <p class="mt-1 break-words text-sm font-semibold text-sanad-pilot-text" x-text="resource">{{ __('Élément sélectionné') }}</p>
                <p id="{{ $id }}-description" class="mt-2 text-sm leading-6 text-sanad-pilot-muted" x-text="consequence">{{ __('Cette action peut modifier durablement cet élément.') }}</p>
            </div>
        </div>
        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <button x-ref="cancel" type="button" autofocus class="rf-button-secondary" x-on:click="close()">{{ __('Annuler') }}</button>
            <button type="button" class="rf-button-danger" x-on:click="confirm()" x-text="confirmLabel">{{ __('Confirmer') }}</button>
        </div>
    </dialog>
</div>
