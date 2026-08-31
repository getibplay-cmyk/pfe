@props(['surface' => 'light'])
@php($dark = $surface === 'dark')

<svg
    viewBox="0 0 48 48"
    role="img"
    aria-label="Monogramme {{ config('brand.name') }}"
    xmlns="http://www.w3.org/2000/svg"
    data-brand-mark="belkhir-space-monogram"
    {{ $attributes->class(['text-white' => $dark, 'text-belkhir-space-blue' => ! $dark]) }}
>
    <rect x="1" y="1" width="46" height="46" rx="14" fill="currentColor" @class(['stroke-white/20' => ! $dark, 'stroke-slate-200' => $dark]) stroke-width="1.5" />
    <path
        d="M16 12.5h11.2a6.1 6.1 0 0 1 0 12.2H16Zm0 12.2h12.1a6.4 6.4 0 0 1 0 12.8H16Z"
        fill="none"
        @class(['stroke-white' => ! $dark, 'stroke-belkhir-space-ink' => $dark])
        stroke-width="3.4"
        stroke-linecap="round"
        stroke-linejoin="round"
    />
    <path d="M31 11.5h5v5" fill="none" class="stroke-belkhir-space-orange" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" />
</svg>
