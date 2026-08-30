<x-app-layout>
    <div class="rf-page">
        <x-page-header title="Abonnements SaaS" eyebrow="Administration de la plateforme" description="Suivez les abonnements des entreprises sans bloquer les comptes historiques dépourvus d’offre.">
            <x-slot:actions><a href="{{ route('platform.plans.index') }}" class="rf-button-secondary">Gérer les plans</a></x-slot:actions>
        </x-page-header>
        <x-form-errors />
        <x-filter-panel>
            <form class="rf-filter-grid" method="GET">
                <div><x-input-label for="subscription-search" value="Entreprise" /><input id="subscription-search" name="q" value="{{ request('q') }}" class="mt-1 w-full"></div>
                <div><x-input-label for="subscription-status" value="État" /><select id="subscription-status" name="status" class="mt-1 w-full"><option value="">Tous</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ App\Support\Ui\UiLabel::get($status->value) }}</option>@endforeach</select></div>
                <div class="flex items-end gap-2"><x-primary-button>Filtrer</x-primary-button><a href="{{ route('platform.subscriptions.index') }}" class="rf-button-secondary">Effacer</a></div>
            </form>
        </x-filter-panel>
        <x-responsive-table label="Historique des abonnements SaaS">
            <table><thead><tr><th>Entreprise</th><th>Plan</th><th>État</th><th>Période</th><th>Prix figé</th><th class="text-right">Actions</th></tr></thead><tbody>
                @forelse($subscriptions as $subscription)
                    @php($targets = match($subscription->status->value) { 'trialing' => ['active','suspended','cancelled','expired'], 'active' => ['past_due','suspended','cancelled','expired'], 'past_due' => ['active','suspended','cancelled','expired'], 'suspended' => ['active','past_due','cancelled','expired'], default => [] })
                    <tr>
                        <td><a href="{{ route('platform.tenants.show', $subscription->tenant) }}" class="font-semibold text-brand-700">{{ $subscription->tenant->name }}</a></td>
                        <td>{{ $subscription->plan->name }}<br><span class="text-slate-500">{{ $subscription->plan->code }}</span></td>
                        <td><x-status-badge :value="$subscription->status" /></td>
                        <td>{{ App\Support\Ui\UiLabel::dateTime($subscription->starts_at) }}<br><span class="text-slate-500">{{ $subscription->ends_at ? 'au '.App\Support\Ui\UiLabel::dateTime($subscription->ends_at) : 'sans fin définie' }}</span></td>
                        <td>{{ App\Support\Ui\UiLabel::money($subscription->price_amount, $subscription->currency) }}</td>
                        <td class="text-right">
                            @if($targets !== [])
                                <form method="POST" action="{{ route('platform.subscriptions.transition', $subscription) }}" class="inline-flex items-center gap-2" onsubmit="return confirm('Confirmer ce changement d’état ?')">@csrf @method('PATCH')<label class="sr-only" for="subscription-status-{{ $subscription->id }}">Nouvel état</label><select id="subscription-status-{{ $subscription->id }}" name="status" class="text-sm">@foreach($targets as $target)<option value="{{ $target }}">{{ App\Support\Ui\UiLabel::get($target) }}</option>@endforeach</select><button class="rf-button-secondary">Appliquer</button></form>
                            @else<span class="text-sm text-slate-500">Historique final</span>@endif
                        </td>
                    </tr>
                @empty<tr><td colspan="6"><x-empty-state title="Aucun abonnement SaaS" description="L’absence d’abonnement ne bloque pas les entreprises historiques." /></td></tr>@endforelse
            </tbody></table><x-slot:footer>{{ $subscriptions->links() }}</x-slot:footer>
        </x-responsive-table>
    </div>
</x-app-layout>
