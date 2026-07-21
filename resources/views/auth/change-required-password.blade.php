<x-app-layout>
    <div class="mx-auto max-w-xl space-y-6">
        <x-page-header title="Choisissez votre mot de passe" eyebrow="Sécurité du compte" description="Le mot de passe temporaire doit être remplacé avant d’accéder aux fonctions RentFleet." />
        <x-section-card title="Créer un mot de passe personnel" description="Utilisez au moins 12 caractères avec majuscules, minuscules et chiffres.">
            <form method="POST" action="{{ route('password.change-required.update') }}" class="space-y-5">
                @csrf @method('PUT')
                <x-form-errors />
                <x-password-field id="current_password" name="current_password" label="Mot de passe temporaire" :messages="$errors->get('current_password')" autocomplete="current-password" />
                <x-password-field id="password" name="password" label="Nouveau mot de passe" :messages="$errors->get('password')" autocomplete="new-password" />
                <x-password-field id="password_confirmation" name="password_confirmation" label="Confirmation du mot de passe" :messages="$errors->get('password_confirmation')" autocomplete="new-password" />
                <x-primary-button>Enregistrer et continuer</x-primary-button>
            </form>
        </x-section-card>
    </div>
</x-app-layout>
