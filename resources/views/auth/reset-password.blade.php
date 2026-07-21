<x-guest-layout>
    <header>
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-700">Sécurité du compte</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Réinitialiser le mot de passe</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">Choisissez un mot de passe unique d’au moins 12 caractères, avec majuscules, minuscules et chiffres.</p>
    </header>
    <form method="POST" action="{{ route('password.store') }}" class="mt-6 space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-form-errors />
        <div>
            <x-input-label for="email" value="Adresse e-mail" required />
            <x-text-input id="email" class="mt-1" type="email" name="email" :value="old('email', $request->email)" :invalid="$errors->has('email')" required autofocus autocomplete="username" aria-describedby="email-error" />
            <x-field-error id="email-error" :messages="$errors->get('email')" class="mt-2" />
        </div>
        <x-password-field id="password" name="password" label="Nouveau mot de passe" :messages="$errors->get('password')" autocomplete="new-password" />
        <x-password-field id="password_confirmation" name="password_confirmation" label="Confirmation du mot de passe" :messages="$errors->get('password_confirmation')" autocomplete="new-password" />
        <x-primary-button class="w-full">Enregistrer le nouveau mot de passe</x-primary-button>
    </form>
</x-guest-layout>
