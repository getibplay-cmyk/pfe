@props(['compact' => false, 'surface' => 'light'])
@php($dark = $surface === 'dark')

<span {{ $attributes->class(['inline-flex items-center gap-3']) }} data-brand="belkhir-space">
    <x-application-logo :surface="$surface" class="h-10 w-10 shrink-0" />
    @if (! $compact)
        <span class="min-w-0 leading-tight">
            <span @class(['block text-lg font-extrabold tracking-[0.12em]', 'text-white' => $dark, 'text-belkhir-space-ink' => ! $dark])>{{ config('brand.name') }}</span>
            <span @class(['block max-w-56 text-[0.68rem] font-medium leading-4', 'text-slate-300' => $dark, 'text-slate-600' => ! $dark])>{{ config('brand.description') }}</span>
        </span>
    @else
        <span class="sr-only">{{ config('brand.name') }} — {{ config('brand.description') }}</span>
    @endif
</span>
