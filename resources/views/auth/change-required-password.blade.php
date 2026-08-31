<x-guest-layout>
    <x-auth-heading
        icon="key"
        eyebrow="Sécurité du compte"
        title="Choisissez votre mot de passe"
        :description="'Le mot de passe temporaire doit être remplacé avant d’accéder aux fonctions '.config('brand.name').'.'"
    />

    <div class="mt-5 rounded-xl border border-belkhir-space-border bg-belkhir-space-canvas px-4 py-3 text-xs leading-5 text-belkhir-space-muted">
        Utilisez au moins 12 caractères avec majuscules, minuscules et chiffres.
    </div>

    <form method="POST" action="{{ route('password.change-required.update') }}" class="mt-7 space-y-5" data-loading-form>
        @csrf
        @method('PUT')
        <x-form-errors />
        <x-password-field id="current_password" name="current_password" label="Mot de passe temporaire" :messages="$errors->get('current_password')" autocomplete="current-password" autofocus />
        <x-password-field id="password" name="password" label="Nouveau mot de passe" :messages="$errors->get('password')" autocomplete="new-password" />
        <x-password-field id="password_confirmation" name="password_confirmation" label="Confirmation du mot de passe" :messages="$errors->get('password_confirmation')" autocomplete="new-password" />
        <x-submit-button label="Enregistrer et continuer" loading-label="Enregistrement en cours…" class="w-full" />
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-3" data-loading-form>
        @csrf
        <x-submit-button label="Se déconnecter" loading-label="Déconnexion…" variant="secondary" class="w-full" />
    </form>
</x-guest-layout>
