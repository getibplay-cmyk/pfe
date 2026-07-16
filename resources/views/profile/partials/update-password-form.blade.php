<section>
    <header>
        <h2 class="text-lg font-semibold text-slate-950">Sécurité du compte</h2>
        <p class="mt-1 text-sm text-slate-600">Le changement du mot de passe ferme vos autres sessions actives.</p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-6 grid gap-5 md:grid-cols-2">
        @csrf
        @method('put')

        <div class="md:col-span-2">
            <x-input-label for="update_password_current_password" value="Mot de passe actuel *" />
            <x-text-input id="update_password_current_password" name="current_password" type="password" class="mt-1 block w-full" required autocomplete="current-password" />
            <x-input-error :messages="$errors->updatePassword->get('current_password')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="update_password_password" value="Nouveau mot de passe *" />
            <x-text-input id="update_password_password" name="password" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
            <x-input-error :messages="$errors->updatePassword->get('password')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="update_password_password_confirmation" value="Confirmation *" />
            <x-text-input id="update_password_password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
            <x-input-error :messages="$errors->updatePassword->get('password_confirmation')" class="mt-2" />
        </div>
        <div class="md:col-span-2"><x-primary-button type="submit">Changer le mot de passe</x-primary-button></div>
    </form>
</section>
