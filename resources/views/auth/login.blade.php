<x-guest-layout>
    <x-auth-heading
        eyebrow="Espace sécurisé"
        :title="'Connexion à '.config('brand.name')"
        description="Accédez à l’espace de travail de votre organisation avec le compte fourni par votre administrateur."
    />

    <x-auth-session-status class="mt-5" :status="session('status')" />
    <form method="POST" action="{{ route('login') }}" class="mt-7 space-y-5" data-loading-form>
        @csrf
        <x-form-errors />
        <x-auth-email-field :value="old('email')" :messages="$errors->get('email')" autofocus />
        <x-password-field id="password" name="password" label="Mot de passe" :messages="$errors->get('password')" autocomplete="current-password" />
        <div class="flex flex-wrap items-center justify-between gap-3">
            <label for="remember_me" class="inline-flex min-h-11 cursor-pointer items-center gap-2.5 rounded-lg pe-2 text-sm text-belkhir-space-muted">
                <input id="remember_me" type="checkbox" class="h-4 w-4 rounded" name="remember" @checked(old('remember'))>
                Se souvenir de moi
            </label>
            @if (Route::has('password.request'))
                <a class="rf-button-link -me-3" href="{{ route('password.request') }}">Mot de passe oublié ?</a>
            @endif
        </div>
        <x-submit-button label="Se connecter" loading-label="Connexion en cours…" class="w-full" />
    </form>
    <div class="mt-6 flex gap-3 rounded-xl border border-belkhir-space-border bg-belkhir-space-canvas p-4 text-xs leading-5 text-belkhir-space-muted">
        <svg viewBox="0 0 24 24" class="mt-0.5 h-4 w-4 shrink-0 text-belkhir-space-blue" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" /><path d="M12 11v5m0-8h.01" /></svg>
        <p><strong class="text-belkhir-space-text">Accès professionnel :</strong> les comptes sont créés par un administrateur autorisé. Aucun compte ne peut être ouvert publiquement.</p>
    </div>
</x-guest-layout>
