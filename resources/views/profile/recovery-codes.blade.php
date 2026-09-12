<x-app-layout>
    <div class="rf-page max-w-3xl">
        <x-page-header :title="__('Conservez vos codes de secours')" :description="__('Ils ne seront affichés qu’une seule fois. Chaque code ne peut servir qu’une fois. Conservez-les dans un endroit privé.')" />
        <div class="rf-panel rf-panel-body"><ul dir="ltr" class="grid grid-cols-1 gap-3 font-mono sm:grid-cols-2">@foreach ($codes as $code)<li class="rounded bg-slate-100 p-3 select-all">{{ $code }}</li>@endforeach</ul></div>
        <a href="{{ route('security.index') }}" class="rf-button-primary">{{ __('J’ai conservé mes codes') }}</a>
    </div>
</x-app-layout>
