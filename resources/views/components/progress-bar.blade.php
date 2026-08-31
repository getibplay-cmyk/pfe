@props([
    'label',
    'value',
    'max',
    'valueText' => null,
    'tone' => 'blue',
])
@php
    $progress = App\Support\Ui\BusinessNumber::progress($value, $max);
    $barClass = match ($tone) {
        'success' => 'bg-belkhir-space-success',
        'warning' => 'bg-belkhir-space-warning',
        'danger' => 'bg-belkhir-space-danger',
        'orange' => 'bg-belkhir-space-orange',
        default => 'bg-belkhir-space-blue',
    };
    $fraction = $valueText ?? (
        App\Support\Ui\BusinessNumber::integer($value).' sur '.App\Support\Ui\BusinessNumber::integer($max)
    );
    $displayValue = $progress === null
        ? App\Support\Ui\BusinessNumber::UNAVAILABLE
        : $fraction.' · '.$progress['percentage'];
@endphp
<div {{ $attributes->class('min-w-0') }}>
    <div class="mb-2 flex items-center justify-between gap-3 text-sm">
        <span class="font-semibold text-belkhir-space-text">{{ $label }}</span>
        <span class="shrink-0 text-belkhir-space-muted">{{ $displayValue }}</span>
    </div>
    <div
        role="progressbar"
        aria-label="{{ $label }}"
        aria-valuemin="0"
        @if($progress !== null) aria-valuemax="{{ $progress['max'] }}" aria-valuenow="{{ $progress['value'] }}" @endif
        aria-valuetext="{{ $displayValue }}"
        class="h-2.5 overflow-hidden rounded-full bg-slate-200"
    >
        @if($progress !== null)<span class="block h-full rounded-full {{ $barClass }} transition-[width] duration-500 ease-out motion-reduce:transition-none" style="width: {{ $progress['width'] }}%"></span>@endif
    </div>
</div>
