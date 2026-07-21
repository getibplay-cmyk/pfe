<button {{ $attributes->merge(['type' => 'submit'])->class('rf-button-danger') }}>
    {{ $slot }}
</button>
