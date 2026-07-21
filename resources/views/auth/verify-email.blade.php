<x-guest-layout>
    <header>
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-700">Sécurité du compte</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Vérifier votre adresse e-mail</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">Utilisez le lien envoyé à votre adresse professionnelle. Vous pouvez demander un nouvel envoi si le message n’est pas arrivé.</p>
    </header>
    @if (session('status') === 'verification-link-sent')<x-flash-message class="mt-5" message="Un nouveau lien de vérification a été envoyé." />@endif
    <div class="mt-6 space-y-3">
        <form method="POST" action="{{ route('verification.send') }}">@csrf<x-primary-button class="w-full">Renvoyer le lien</x-primary-button></form>
        <form method="POST" action="{{ route('logout') }}">@csrf<x-secondary-button type="submit" class="w-full">Se déconnecter</x-secondary-button></form>
    </div>
</x-guest-layout>
