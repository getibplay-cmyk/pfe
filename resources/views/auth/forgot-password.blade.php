<x-guest-layout>
    <header>
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-700">Récupération du compte</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Mot de passe oublié</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">Saisissez votre adresse e-mail professionnelle. Si un compte est associé, vous recevrez un lien de réinitialisation.</p>
    </header>
    <x-auth-session-status class="mt-5" :status="session('status')" />
    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-5">
        @csrf
        <x-form-errors />
        <div>
            <x-input-label for="email" value="Adresse e-mail" required />
            <x-text-input id="email" class="mt-1" type="email" name="email" :value="old('email')" :invalid="$errors->has('email')" required autofocus autocomplete="username" aria-describedby="email-error" />
            <x-field-error id="email-error" :messages="$errors->get('email')" class="mt-2" />
        </div>
        <x-primary-button class="w-full">Envoyer le lien de réinitialisation</x-primary-button>
    </form>
    <a class="rf-button-link mt-4 w-full justify-center" href="{{ route('login') }}">Retour à la connexion</a>
</x-guest-layout>
