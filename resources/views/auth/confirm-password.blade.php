<x-guest-layout>
    <header>
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-700">Action sensible</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Confirmer votre mot de passe</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">Cette vérification protège l’accès à une opération sensible de RentFleet.</p>
    </header>
    <form method="POST" action="{{ route('password.confirm') }}" class="mt-6 space-y-5">
        @csrf
        <x-form-errors />
        <x-password-field id="password" name="password" label="Mot de passe actuel" :messages="$errors->get('password')" autocomplete="current-password" />
        <x-primary-button class="w-full">Confirmer et continuer</x-primary-button>
    </form>
</x-guest-layout>
