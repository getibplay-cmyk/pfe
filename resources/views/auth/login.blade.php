<x-guest-layout>
    <header>
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-700">Espace sécurisé</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Connexion à RentFleet</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">Accédez à l’espace de travail de votre organisation avec le compte fourni par votre administrateur.</p>
    </header>

    <x-auth-session-status class="mt-5" :status="session('status')" />
    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5">
        @csrf
        <x-form-errors />
        <div>
            <x-input-label for="email" value="Adresse e-mail" required />
            <x-text-input id="email" class="mt-1" type="email" name="email" :value="old('email')" :invalid="$errors->has('email')" required autofocus autocomplete="username" aria-describedby="email-error" />
            <x-field-error id="email-error" :messages="$errors->get('email')" class="mt-2" />
        </div>
        <x-password-field id="password" name="password" label="Mot de passe" :messages="$errors->get('password')" autocomplete="current-password" />
        <div class="flex flex-wrap items-center justify-between gap-3">
            <label for="remember_me" class="inline-flex items-center gap-2 text-sm text-slate-600">
                <input id="remember_me" type="checkbox" class="rounded" name="remember" @checked(old('remember'))>
                Se souvenir de moi
            </label>
            @if (Route::has('password.request'))<a class="rf-button-link -me-3" href="{{ route('password.request') }}">Mot de passe oublié ?</a>@endif
        </div>
        <x-primary-button class="w-full">Se connecter</x-primary-button>
    </form>
    <p class="mt-6 rounded-xl bg-slate-50 p-4 text-xs leading-5 text-slate-600"><strong class="text-slate-800">Accès B2B :</strong> les comptes sont créés par l’administrateur plateforme ou le propriétaire de votre organisation. Aucun compte ne peut être ouvert publiquement.</p>
</x-guest-layout>
