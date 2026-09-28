<x-app-layout>
    <div class="space-y-6">
        <x-page-header :title="__('Confirmer la nouvelle adresse')" :description="__('Votre adresse actuelle reste valable jusqu’à cette confirmation.')" />
        <x-section-card>
            <x-form-errors />
            <p class="mb-5">{{ $email }}</p>
            <form method="post" action="{{ $confirmationUrl }}">
                @csrf
                <x-primary-button type="submit">{{ __('Confirmer la nouvelle adresse') }}</x-primary-button>
            </form>
        </x-section-card>
    </div>
</x-app-layout>
