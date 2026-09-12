<x-app-layout>
    @php($activeFilterCount = collect(['q', 'status'])->filter(fn (string $key): bool => request()->filled($key))->count())
    <div class="rf-page">
        <x-page-header :title="__('Abonnements SaaS')" :eyebrow="__('Administration de la plateforme')" :description="__('Suivez les abonnements des entreprises sans bloquer les comptes historiques dépourvus d’offre.')">
            <x-slot:actions><a href="{{ route('platform.plans.index') }}" class="rf-button-secondary"><x-icon name="chart" size="xs" />{{ __('Gérer les plans') }}</a></x-slot:actions>
        </x-page-header>
        <x-form-errors />
        <x-filter-panel :title="__('Filtrer les abonnements')" :active-count="$activeFilterCount" :result-count="$subscriptions->total()">
            <form class="rf-filter-grid" method="GET" data-loading-form>
                <div><x-input-label for="subscription-search" :value="__('Entreprise')" /><input id="subscription-search" name="q" value="{{ request('q') }}" class="mt-1 w-full"></div>
                <div><x-input-label for="subscription-status" :value="__('État')" /><select id="subscription-status" name="status" class="mt-1 w-full"><option value="">{{ __('Tous') }}</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ App\Support\Ui\UiLabel::get($status->value) }}</option>@endforeach</select></div>
                <div class="flex items-end gap-2"><x-submit-button :label="__('Filtrer')" loading-label="{{ __('Filtrage…') }}" />@if($activeFilterCount > 0)<a href="{{ route('platform.subscriptions.index') }}" class="rf-button-secondary"><x-icon name="reset" /> {{ __('Effacer') }}</a>@endif</div>
            </form>
        </x-filter-panel>
        <x-responsive-table :label="__('Historique des abonnements SaaS')">
            <table><thead><tr><th>{{ __('Entreprise') }}</th><th>{{ __('Plan') }}</th><th>{{ __('État') }}</th><th>{{ __('Période') }}</th><th>{{ __('Prix figé') }}</th><th class="text-end">{{ __('Actions') }}</th></tr></thead><tbody>
                @forelse($subscriptions as $subscription)
                    @php($targets = match($subscription->status->value) { 'trialing' => ['active','suspended','cancelled','expired'], 'active' => ['past_due','suspended','cancelled','expired'], 'past_due' => ['active','suspended','cancelled','expired'], 'suspended' => ['active','past_due','cancelled','expired'], default => [] })
                    <tr>
                        <td><a href="{{ route('platform.tenants.show', $subscription->tenant) }}" class="font-semibold text-brand-700">{{ $subscription->tenant->name }}</a></td>
                        <td>{{ $subscription->plan->name }}<br><span class="text-slate-500">{{ $subscription->plan->code }}</span></td>
                        <td><x-status-badge :value="$subscription->status" /></td>
                        <td>{{ App\Support\Ui\UiLabel::dateTime($subscription->starts_at) }}<br><span class="text-slate-500">{{ $subscription->ends_at ? 'au '.App\Support\Ui\UiLabel::dateTime($subscription->ends_at) : __('sans fin définie') }}</span></td>
                        <td>{{ App\Support\Ui\UiLabel::money($subscription->price_amount, $subscription->currency) }}</td>
                        <td class="text-end">
                            @if($targets !== [])
                                <form method="POST" action="{{ route('platform.subscriptions.transition', $subscription) }}" class="inline-flex items-center gap-2" x-belkhir-space-confirm data-confirm-title="{{ __('Modifier l’abonnement') }}" data-confirm-resource="{{ __('Abonnement sélectionné') }}" data-confirm-consequence="{{ __('Le nouvel état sera enregistré dans l’historique administratif de l’abonnement.') }}" data-confirm-label="{{ __('Appliquer') }}" data-loading-form>@csrf @method('PATCH')<label class="sr-only" for="subscription-status-{{ $subscription->id }}">{{ __('Nouvel état') }}</label><select id="subscription-status-{{ $subscription->id }}" name="status" class="text-sm">@foreach($targets as $target)<option value="{{ $target }}">{{ App\Support\Ui\UiLabel::get($target) }}</option>@endforeach</select><x-submit-button variant="secondary" :label="__('Appliquer')" loading-label="{{ __('Mise à jour…') }}" /></form>
                            @else<span class="text-sm text-slate-500">{{ __('Historique final') }}</span>@endif
                        </td>
                    </tr>
                @empty<tr><td colspan="6"><x-empty-state :title="__('Aucun abonnement SaaS')" :description="__('L’absence d’abonnement ne bloque pas les entreprises historiques.')" /></td></tr>@endforelse
            </tbody></table><x-slot:footer>{{ $subscriptions->links() }}</x-slot:footer>
        </x-responsive-table>
    </div>
</x-app-layout>
