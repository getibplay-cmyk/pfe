@props(['value'])
@php($tone = App\Support\Ui\UiLabel::tone($value))
<span @class([
    'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold',
    'bg-emerald-100 text-emerald-800' => $tone === 'success',
    'bg-amber-100 text-amber-900' => $tone === 'warning',
    'bg-red-100 text-red-800' => $tone === 'danger',
    'bg-blue-100 text-blue-800' => $tone === 'info',
    'bg-slate-100 text-slate-700' => $tone === 'muted',
])>{{ App\Support\Ui\UiLabel::get($value) }}</span>
