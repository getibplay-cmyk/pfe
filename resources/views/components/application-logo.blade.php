@props(['surface' => 'light'])
@php($dark = $surface === 'dark')

<svg
    viewBox="0 0 48 48"
    role="img"
    aria-label="{{ __('Monogramme ') }}{{ config('brand.name') }}"
    xmlns="http://www.w3.org/2000/svg"
    data-brand-mark="sanad-pilot-monogram"
    {{ $attributes->class(['text-white' => $dark, 'text-sanad-pilot-blue' => ! $dark]) }}
>
    <rect x="1" y="1" width="46" height="46" rx="14" fill="currentColor" @class(['stroke-white/20' => ! $dark, 'stroke-slate-200' => $dark]) stroke-width="1.5" />
    <path
        d="M30.5 15.5H21a6 6 0 0 0 0 12h6a5 5 0 0 1 0 10H16"
        fill="none"
        @class(['stroke-white' => ! $dark, 'stroke-sanad-pilot-ink' => $dark])
        stroke-width="3.4"
        stroke-linecap="round"
        stroke-linejoin="round"
    />
    <path d="M30 11.5h6v6m0-6-7 7" fill="none" class="stroke-sanad-pilot-orange" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" />
</svg>
