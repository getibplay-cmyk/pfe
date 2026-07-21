@props(['disabled' => false, 'invalid' => false])
<input @disabled($disabled) @if($invalid) aria-invalid="true" @endif {{ $attributes->class('w-full rounded-lg border-slate-300 shadow-sm focus:border-brand-600 focus:ring-brand-600') }}>
