@props(['compact' => false, 'surface' => 'light'])
@php($dark = $surface === 'dark')

<span {{ $attributes->class(['inline-flex items-center gap-3']) }}>
    <x-application-logo @class(['h-10 w-10 shrink-0', 'text-brand-500' => $dark, 'text-brand-700' => ! $dark]) />
    @if (! $compact)
        <span class="min-w-0 leading-tight">
            <span @class(['block text-lg font-bold tracking-tight', 'text-white' => $dark, 'text-slate-950' => ! $dark])>RentFleet</span>
            <span @class(['block text-[0.68rem] font-medium uppercase tracking-[0.16em]', 'text-slate-400' => $dark, 'text-slate-500' => ! $dark])>Gestion de mobilité</span>
        </span>
    @else
        <span class="sr-only">RentFleet</span>
    @endif
</span>
