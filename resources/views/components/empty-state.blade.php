@props(['title' => 'Aucune donnée', 'description' => null])
<div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-center">
    <p class="font-medium text-slate-800">{{ $title }}</p>
    @if ($description)<p class="mt-1 text-sm text-slate-500">{{ $description }}</p>@endif
    @if (isset($action))<div class="mt-4">{{ $action }}</div>@endif
</div>
