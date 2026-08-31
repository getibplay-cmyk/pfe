<button {{ $attributes->merge(['type' => 'button'])->class('rf-button-quiet') }}>
    {{ $slot }}
</button>
