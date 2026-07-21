<form id="send-verification" method="post" action="{{ route('verification.send') }}">@csrf</form>
<form method="post" action="{{ route('profile.update') }}" class="space-y-5">
    @csrf
    @method('patch')
    <x-form-errors />
    <div>
        <x-input-label for="name" value="Nom complet" required />
        <x-text-input id="name" name="name" type="text" class="mt-1" :value="old('name', $user->name)" :invalid="$errors->has('name')" required autofocus autocomplete="name" aria-describedby="name-error" />
        <x-field-error id="name-error" class="mt-2" :messages="$errors->get('name')" />
    </div>
    <div>
        <x-input-label for="email" value="Adresse e-mail" required />
        <x-text-input id="email" name="email" type="email" class="mt-1" :value="old('email', $user->email)" :invalid="$errors->has('email')" required autocomplete="username" aria-describedby="profile-email-error" />
        <x-field-error id="profile-email-error" class="mt-2" :messages="$errors->get('email')" />
    </div>
    <x-primary-button type="submit">Enregistrer le profil</x-primary-button>
</form>
