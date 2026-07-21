<form method="post" action="{{ route('password.update') }}" class="grid gap-5 md:grid-cols-2">
    @csrf
    @method('put')
    <x-form-errors bag="updatePassword" class="md:col-span-2" />
    <div class="md:col-span-2">
        <x-password-field id="update_password_current_password" name="current_password" label="Mot de passe actuel" :messages="$errors->updatePassword->get('current_password')" autocomplete="current-password" />
    </div>
    <x-password-field id="update_password_password" name="password" label="Nouveau mot de passe" :messages="$errors->updatePassword->get('password')" autocomplete="new-password" />
    <x-password-field id="update_password_password_confirmation" name="password_confirmation" label="Confirmation" :messages="$errors->updatePassword->get('password_confirmation')" autocomplete="new-password" />
    <div class="md:col-span-2"><x-primary-button type="submit">Changer le mot de passe</x-primary-button></div>
</form>
