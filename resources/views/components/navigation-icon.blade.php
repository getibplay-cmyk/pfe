@props(['name'])
<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" {{ $attributes->class('h-5 w-5 shrink-0') }}>
    @switch($name)
        @case('dashboard') @case('platform-dashboard') <path d="M4 13h6V4H4v9Zm0 7h6v-4H4v4Zm10 0h6v-9h-6v9Zm0-12h6V4h-6v4Z" /> @break
        @case('reservations') <path d="M6 3v3m12-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Zm3 8h3m3 0h2m-8 4h3" /> @break
        @case('availability') <circle cx="12" cy="12" r="8" /><path d="m8.5 12 2.2 2.2 4.8-5" /> @break
        @case('contracts') <path d="M7 3h8l4 4v14H7V3Zm8 0v5h4M10 12h6m-6 4h6" /> @break
        @case('pricing') @case('finance') <path d="M12 3v18m4-14.5H9.5a3 3 0 0 0 0 6h5a3 3 0 0 1 0 6H8" /> @break
        @case('customers') @case('users') <path d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-3A4.5 4.5 0 0 0 4 18.5V20m6-10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm7-1a3 3 0 0 1 3 3v1m-5-9a3 3 0 0 1 0 5" /> @break
        @case('vehicles') <path d="M4 15h16l-2-6H6l-2 6Zm2 0v3m12-3v3M7 12h.01M17 12h.01" /> @break
        @case('vehicle-categories') <path d="M4 6h7v5H4V6Zm9 0h7v5h-7V6ZM4 13h7v5H4v-5Zm9 0h7v5h-7v-5Z" /> @break
        @case('vehicle-blocks') <path d="M7 11V8a5 5 0 0 1 10 0v3m-11 0h12v10H6V11Z" /> @break
        @case('maintenance') <path d="m14 6 4-4 4 4-4 4m-2-2L7 17m-4 4 4-4m-2-2 4 4" /> @break
        @case('insurance') <path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6l-7-3Zm-3 9 2 2 4-4" /> @break
        @case('reports') <path d="M5 20V10m7 10V4m7 16v-7" /> @break
        @case('tenant') @case('agencies') @case('platform-tenants') <path d="M4 21V8l8-5 8 5v13M8 21v-5h8v5M8 10h.01M12 10h.01M16 10h.01" /> @break
        @case('audit') <path d="M4 5h16v14H4V5Zm4 4h8m-8 4h8m-8 4h5" /> @break
        @default <circle cx="12" cy="12" r="8" /><path d="M12 8v4l2 2" />
    @endswitch
</svg>
