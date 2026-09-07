<x-app-layout><div class="rf-page">
    <x-page-header title="Portail du locataire" :description="$customer->displayName()" />
    <a class="rf-button-link" href="{{ route('customers.show', $customer) }}">← Revenir au client</a>
    <x-section-card title="Accès personnel et révocable" description="Un lien valable 48 heures ouvre une seule session de 30 minutes. Un nouveau lien révoque les précédents accès et sessions.">
        @if(session('portal_url'))<div class="mb-5 rounded-xl border border-blue-200 bg-blue-50 p-4"><label for="portal-link" class="rf-field-label">Lien à transmettre personnellement au client</label><input id="portal-link" class="mt-1 w-full" readonly value="{{ session('portal_url') }}"><p class="mt-2 text-sm">Ce lien ne sera affiché qu’une fois. Toute personne qui le possède peut ouvrir cet espace.</p></div>@endif
        <div class="flex flex-wrap gap-3"><form method="POST" action="{{ route('customers.portal-access.store', $customer) }}" data-loading-form>@csrf<x-primary-button>Créer un lien personnel</x-primary-button></form><form method="POST" action="{{ route('customers.portal-access.revoke', $customer) }}" data-loading-form>@csrf @method('DELETE')<x-secondary-button type="submit">Révoquer tous les accès</x-secondary-button></form></div>
        <ul class="mt-5 divide-y">@forelse($accesses as $access)<li class="flex flex-wrap justify-between gap-3 py-3 text-sm"><span>Créé le {{ App\Support\Ui\UiLabel::dateTime($access->created_at) }}</span><span>{{ $access->revoked_at ? 'Révoqué' : ($access->expires_at->isPast() ? 'Expiré' : ($access->consumed_at ? 'Lien utilisé' : 'En attente')) }}</span></li>@empty<li class="text-sm text-slate-500">Aucun accès créé.</li>@endforelse</ul>
    </x-section-card>
</div></x-app-layout>
