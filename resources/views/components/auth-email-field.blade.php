@props([
    'id' => 'email',
    'name' => 'email',
    'label' => 'Adresse e-mail',
    'value' => null,
    'messages' => [],
    'autocomplete' => 'username',
    'autofocus' => false,
])

<div>
    <x-input-label :for="$id" :value="$label" required />
    <div class="relative mt-1.5">
        <span class="pointer-events-none absolute inset-y-0 start-0 flex w-11 items-center justify-center text-belkhir-space-muted" aria-hidden="true">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect width="18" height="14" x="3" y="5" rx="2" /><path d="m3 7 9 6 9-6" /></svg>
        </span>
        <x-text-input :id="$id" class="ps-11" type="email" :name="$name" :value="$value" :invalid="(bool) $messages" required :autofocus="$autofocus" :autocomplete="$autocomplete" :aria-describedby="$id.'-error'" />
    </div>
    <x-field-error :id="$id.'-error'" :messages="$messages" class="mt-2" />
</div>
