@props(['id', 'name', 'label', 'messages' => [], 'autocomplete' => 'current-password', 'required' => true, 'autofocus' => false])
<div x-data="{ visible: false }">
    <x-input-label :for="$id" :value="$label" :required="$required" />
    <div class="relative mt-1.5">
        <span class="pointer-events-none absolute inset-y-0 start-0 flex w-11 items-center justify-center text-belkhir-space-muted" aria-hidden="true">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect width="16" height="11" x="4" y="10" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3" /></svg>
        </span>
        <input type="password" x-bind:type="visible ? 'text' : 'password'" id="{{ $id }}" name="{{ $name }}" @required($required) @if($autofocus) autofocus @endif autocomplete="{{ $autocomplete }}" @if($messages) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif class="block w-full rounded-lg border-belkhir-space-border ps-11 pe-14 shadow-sm focus:border-belkhir-space-blue focus:ring-belkhir-space-blue">
        <button
            type="button"
            x-on:click="visible = ! visible"
            aria-label="Afficher le mot de passe"
            x-bind:aria-label="visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
            aria-pressed="false"
            x-bind:aria-pressed="visible.toString()"
            title="Afficher le mot de passe"
            x-bind:title="visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
            class="absolute inset-y-0 end-0 flex min-h-11 min-w-11 items-center justify-center rounded-e-lg text-belkhir-space-muted transition hover:bg-brand-50 hover:text-belkhir-space-blue focus-visible:z-10"
        >
            <svg x-show="! visible" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2.1 12S5.6 5 12 5s9.9 7 9.9 7-3.5 7-9.9 7-9.9-7-9.9-7Z" /><circle cx="12" cy="12" r="3" /></svg>
            <svg x-cloak x-show="visible" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 3 18 18" /><path d="M10.6 10.7a2 2 0 0 0 2.7 2.7" /><path d="M9.9 4.2A10.5 10.5 0 0 1 12 4c6.4 0 10 8 10 8a16.5 16.5 0 0 1-2.1 3.1M6.2 6.2C3.5 8 2 12 2 12s3.6 8 10 8a10.5 10.5 0 0 0 4.1-.8" /></svg>
        </button>
    </div>
    <x-field-error :id="$id.'-error'" :messages="$messages" class="mt-2" />
</div>
