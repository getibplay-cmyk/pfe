@props(['type' => 'success', 'message' => null])
@php($isError = $type === 'error')
@if ($message || $slot->isNotEmpty())
    <div role="{{ $isError ? 'alert' : 'status' }}" aria-live="{{ $isError ? 'assertive' : 'polite' }}" {{ $attributes->class([
        'flex items-start gap-3 rounded-xl border p-4 text-sm shadow-sm',
        'border-emerald-200 bg-emerald-50 text-emerald-950' => $type === 'success',
        'border-red-200 bg-red-50 text-red-950' => $isError,
        'border-amber-200 bg-amber-50 text-amber-950' => $type === 'warning',
        'border-blue-200 bg-blue-50 text-blue-950' => $type === 'info',
    ]) }}>
        <x-icon :name="match ($type) { 'error' => 'error', 'warning' => 'warning', 'info' => 'info', default => 'check' }" class="mt-0.5 shrink-0" />
        @if ($message)<p class="min-w-0 break-words leading-6">{{ $message }}</p>@else<div class="min-w-0 break-words leading-6">{{ $slot }}</div>@endif
    </div>
@endif
