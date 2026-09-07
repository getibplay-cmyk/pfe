<x-portal-layout title="Accéder à mon espace locataire">
    <x-section-card title="Votre accès personnel">
        <p class="text-slate-600">Consultez vos locations, retrouvez vos documents et transmettez vos justificatifs. Ce lien ouvre une seule session de 30 minutes.</p>
        <form method="POST" action="{{ $entryUrl }}" class="mt-5" data-loading-form>@csrf<x-primary-button>Ouvrir mon espace</x-primary-button></form>
    </x-section-card>
</x-portal-layout>
