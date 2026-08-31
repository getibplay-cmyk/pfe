@props(['name', 'size' => 'md'])
@php
    $sizeClass = match ($size) {
        'xs' => 'h-4 w-4',
        'sm' => 'h-[18px] w-[18px]',
        'lg' => 'h-6 w-6',
        default => 'h-5 w-5',
    };
@endphp
<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" data-icon="{{ $name }}" {{ $attributes->class([$sizeClass, 'shrink-0']) }}>
    @switch($name)
        @case('view') @case('eye') <path d="M2.8 12s3.4-6 9.2-6 9.2 6 9.2 6-3.4 6-9.2 6-9.2-6-9.2-6Z" /><circle cx="12" cy="12" r="2.5" /> @break
        @case('edit') @case('pencil') <path d="m14 5 5 5M4 20l4.2-1 10.3-10.3a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z" /> @break
        @case('delete') @case('trash') <path d="M4 7h16M9 7V4h6v3m3 0-1 14H7L6 7m4 4v6m4-6v6" /> @break
        @case('download') <path d="M12 3v12m-4-4 4 4 4-4M5 20h14" /> @break
        @case('upload') <path d="M12 16V4m-4 4 4-4 4 4M5 20h14" /> @break
        @case('save') <path d="M5 4h12l2 2v14H5V4Z" /><path d="M8 4v6h8V4M8 20v-6h8v6" /> @break
        @case('login') <path d="M14 4h5v16h-5M4 12h11m-4-4 4 4-4 4" /> @break
        @case('logout') <path d="M10 4H5v16h5m4-4 4-4-4-4m4 4H9" /> @break
        @case('launch') <path d="m7 17 10-10M9 7h8v8" /> @break
        @case('analysis') <circle cx="10.5" cy="10.5" r="5.5" /><path d="m14.5 14.5 4 4M8 11l1.7-1.8 1.6 1.4 2.2-2.5" /> @break
        @case('disable') <circle cx="12" cy="12" r="9" /><path d="m6 6 12 12" /> @break
        @case('print') <path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2m-10-4h10v8H7v-8Z" /><path d="M17 12h.01" /> @break
        @case('add') @case('plus') <path d="M12 5v14M5 12h14" /> @break
        @case('search') <circle cx="11" cy="11" r="6.5" /><path d="m16 16 4 4" /> @break
        @case('filter') <path d="M4 5h16l-6 7v6l-4 2v-8L4 5Z" /> @break
        @case('reset') <path d="M4 7v5h5M5.5 16a8 8 0 1 0 .5-9l-2 5" /> @break
        @case('close') <path d="m6 6 12 12M18 6 6 18" /> @break
        @case('menu') <path d="M4 7h16M4 12h16M4 17h16" /> @break
        @case('calendar') <path d="M6 3v3m12-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z" /> @break
        @case('chart') <path d="M5 20V10m7 10V4m7 16v-7" /> @break
        @case('building') @case('tenant') <path d="M4 21V8l8-5 8 5v13M8 21v-5h8v5M8 10h.01M12 10h.01M16 10h.01" /> @break
        @case('users') <path d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-3A4.5 4.5 0 0 0 4 18.5V20m6-10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm7-1a3 3 0 0 1 3 3v1m-5-9a3 3 0 0 1 0 5" /> @break
        @case('vehicle') <path d="M4 15h16l-2-6H6l-2 6Zm2 0v3m12-3v3M7 12h.01M17 12h.01" /> @break
        @case('payment') <path d="M3 7h18v11H3V7Zm0 4h18M7 15h3" /> @break
        @case('image') <rect x="3" y="4" width="18" height="16" rx="2" /><circle cx="9" cy="9" r="1.5" /><path d="m4 17 5-5 4 4 2-2 5 4" /> @break
        @case('file') <path d="M7 3h8l4 4v14H7V3Zm8 0v5h4M10 13h6m-6 4h6" /> @break
        @case('refresh') <path d="M20 7v5h-5M4 17v-5h5M6 8a7 7 0 0 1 12-2l2 6M18 16a7 7 0 0 1-12 2l-2-6" /> @break
        @case('previous') @case('chevron-left') <path d="m15 18-6-6 6-6" /> @break
        @case('next') @case('chevron-right') <path d="m9 18 6-6-6-6" /> @break
        @case('success') @case('check') <circle cx="12" cy="12" r="9" /><path d="m8 12 2.5 2.5L16 9" /> @break
        @case('warning') <path d="M12 3 2.8 20h18.4L12 3Z" /><path d="M12 9v4m0 3h.01" /> @break
        @case('error') <circle cx="12" cy="12" r="9" /><path d="m9 9 6 6m0-6-6 6" /> @break
        @case('lock') <path d="M7 11V8a5 5 0 0 1 10 0v3m-11 0h12v10H6V11Z" /> @break
        @case('mail') <rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" /> @break
        @default <circle cx="12" cy="12" r="9" /><path d="M12 8v4m0 4h.01" />
    @endswitch
</svg>
