<x-guest-layout>
    <h1 class="text-2xl font-bold">{{ __('Double authentification') }}</h1>
    <p class="my-4 text-sm text-slate-600">{{ __('Saisissez le code de votre application ou un code de secours.') }}</p>
    <form method="POST" action="{{ route('security.verify') }}" class="space-y-4">
        @csrf
        <x-input-label for="code" :value="__('Code de vérification')" />
        <x-text-input id="code" name="code" dir="ltr" autocomplete="one-time-code" maxlength="40" required autofocus />
        <x-input-error :messages="$errors->get('code')" />
        <x-primary-button>{{ __('Vérifier') }}</x-primary-button>
    </form>
    <form method="POST" action="{{ route('logout') }}" class="mt-4">@csrf<button class="rf-button-link">{{ __('Déconnexion') }}</button></form>
</x-guest-layout>
