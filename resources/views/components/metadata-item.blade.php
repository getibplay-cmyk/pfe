@props(['label'])
<div class="min-w-0">
    <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</dt>
    <dd class="mt-1 break-words font-medium text-slate-900">{{ $slot }}</dd>
</div>
