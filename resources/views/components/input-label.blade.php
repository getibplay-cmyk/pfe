@props(['value', 'required' => false])
<label {{ $attributes->class('rf-field-label') }}>
    {{ $value ?? $slot }}
    @if ($required)<span class="text-red-700" aria-hidden="true">*</span><span class="sr-only"> (obligatoire)</span>@endif
</label>
