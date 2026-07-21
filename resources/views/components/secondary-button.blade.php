<button {{ $attributes->merge(['type' => 'button'])->class('rf-button-secondary') }}>
    {{ $slot }}
</button>
