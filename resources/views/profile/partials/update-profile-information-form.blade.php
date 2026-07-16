<section>
    <header>
        <h2 class="text-lg font-semibold text-slate-950">Informations personnelles</h2>
        <p class="mt-1 text-sm text-slate-600">Votre nom et votre adresse e-mail sont les seules informations modifiables ici.</p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">@csrf</form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-5">
        @csrf
        @method('patch')
        <x-form-errors />

        <div>
            <x-input-label for="name" value="Nom complet *" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" value="Adresse e-mail *" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button type="submit">Enregistrer le profil</x-primary-button>
            @if (session('status') === 'profile-updated')<p role="status" class="text-sm text-emerald-700">Profil enregistré.</p>@endif
        </div>
    </form>
</section>
