<button {{ $attributes->merge(['type' => 'submit'])->class('rf-button-primary') }}>
    {{ $slot }}
</button>
