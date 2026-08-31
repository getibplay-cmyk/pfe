@props(['id' => 'belkhir-space-confirm-dialog'])
<div
    x-data="belkhirSpaceConfirmDialog"
    x-on:belkhir-space-confirm-request.window="show($event)"
    x-on:keydown.escape.window="if (open) close()"
>
    <template x-teleport="body">
        <div
            x-cloak
            x-show="open"
            x-transition:enter="transition ease-out duration-200 motion-reduce:transition-none"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150 motion-reduce:transition-none"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[90] flex items-center justify-center bg-belkhir-space-ink/70 p-4"
            x-on:click.self="close()"
        >
            <section
                x-ref="dialog"
                id="{{ $id }}"
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="{{ $id }}-title"
                aria-describedby="{{ $id }}-description"
                x-on:keydown.tab="trap($event, $refs.dialog)"
                class="w-full max-w-md rounded-2xl border border-belkhir-space-border bg-white p-6 shadow-2xl"
            >
                <div class="flex items-start gap-4">
                    <span aria-hidden="true" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-700">
                        <x-icon name="warning" size="lg" />
                    </span>
                    <div class="min-w-0">
                        <h2 id="{{ $id }}-title" class="text-lg font-bold text-belkhir-space-text" x-text="title"></h2>
                        <p class="mt-1 break-words text-sm font-semibold text-belkhir-space-text" x-text="resource"></p>
                        <p id="{{ $id }}-description" class="mt-2 text-sm leading-6 text-belkhir-space-muted" x-text="consequence"></p>
                    </div>
                </div>
                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button x-ref="cancel" type="button" class="rf-button-secondary" x-on:click="close()">Annuler</button>
                    <button type="button" class="rf-button border-red-700 bg-red-700 text-white hover:border-red-800 hover:bg-red-800" x-on:click="confirm()" x-text="confirmLabel"></button>
                </div>
            </section>
        </div>
    </template>
</div>
