@props(['value', 'label' => null])
@php($tone = App\Support\Ui\UiLabel::tone($value))
<span {{ $attributes->class([
    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset',
    'bg-emerald-50 text-emerald-800 ring-emerald-600/20' => $tone === 'success',
    'bg-amber-50 text-amber-900 ring-amber-600/20' => $tone === 'warning',
    'bg-red-50 text-red-800 ring-red-600/20' => $tone === 'danger',
    'bg-blue-50 text-blue-800 ring-blue-600/20' => $tone === 'info',
    'bg-slate-100 text-slate-700 ring-slate-500/20' => $tone === 'muted',
]) }}><span aria-hidden="true" class="h-1.5 w-1.5 rounded-full bg-current"></span>{{ $label ?? App\Support\Ui\UiLabel::get($value) }}</span>
