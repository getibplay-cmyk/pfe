<x-app-layout>
    <div class="rf-page">
        <x-page-header :title="__('Invitations d’accueil')" :eyebrow="__('Administration SaaS')" :description="__('Invitez un administrateur à créer lui-même son entreprise, son agence initiale et son mot de passe.')">
            <x-slot:actions><a href="#nouvelle-invitation" class="rf-button-primary"><x-icon name="add" size="xs" />{{ __('Nouvelle invitation') }}</a></x-slot:actions>
        </x-page-header>

        <x-section-card id="nouvelle-invitation" :title="__('Inviter une entreprise')" :description="__('Le lien est personnel, signé, utilisable une seule fois et envoyé uniquement par e-mail.')">
            @if($plans->isEmpty())
                <x-empty-state :title="__('Aucun plan actif')" :description="__('Activez d’abord un plan disposant d’au moins une agence et un utilisateur.')" />
            @else
                <form method="POST" action="{{ route('platform.onboarding-invitations.store') }}" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4 xl:items-end" data-loading-form>
                    @csrf
                    <label class="text-sm xl:col-span-2">{{ __('E-mail du futur administrateur') }}<input type="email" name="email" required autocomplete="off" value="{{ old('email') }}" class="mt-1 w-full rounded border-slate-300"></label>
                    <label class="text-sm">{{ __('Plan') }}<select name="saas_plan_id" required class="mt-1 w-full rounded border-slate-300"><option value="">{{ __('Sélectionner') }}</option>@foreach($plans as $plan)<option value="{{ $plan->id }}" @selected((string) old('saas_plan_id') === (string) $plan->id)>{{ $plan->name }} · {{ App\Support\Ui\UiLabel::money($plan->price_amount, $plan->currency) }}</option>@endforeach</select></label>
                    <label class="text-sm">{{ __('Essai (jours)') }}<input type="number" name="trial_days" min="1" max="90" required value="{{ old('trial_days', config('platform_billing.onboarding.default_trial_days')) }}" class="mt-1 w-full rounded border-slate-300"></label>
                    <label class="text-sm">{{ __('Validité du lien (heures)') }}<input type="number" name="expires_in_hours" min="1" max="168" required value="{{ old('expires_in_hours', config('platform_billing.onboarding.invitation_ttl_hours')) }}" class="mt-1 w-full rounded border-slate-300"></label>
                    <div class="md:col-span-2 xl:col-span-3"><x-form-errors /></div>
                    <div class="flex justify-end"><x-submit-button :label="__('Envoyer l’invitation')" loading-label="{{ __('Envoi en cours…') }}" icon="add" /></div>
                </form>
            @endif
        </x-section-card>

        @php($activeFilterCount = collect(['q', 'status'])->filter(fn (string $key): bool => request()->filled($key))->count())
        <x-filter-panel :title="__('Rechercher et filtrer')" :active-count="$activeFilterCount" :result-count="$invitations->total()">
            <form method="GET" class="grid gap-3 md:grid-cols-3 md:items-end" data-loading-form>
                <label class="text-sm">{{ __('Recherche') }}<input name="q" value="{{ request('q') }}" placeholder="{{ __('Adresse e-mail') }}" class="mt-1 w-full"></label>
                <label class="text-sm">{{ __('État') }}<select name="status" class="mt-1 w-full"><option value="">{{ __('Tous les états') }}</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ App\Support\Ui\UiLabel::get($status) }}</option>@endforeach</select></label>
                <div class="flex gap-2"><x-submit-button :label="__('Appliquer')" loading-label="{{ __('Recherche…') }}" />@if($activeFilterCount)<a href="{{ route('platform.onboarding-invitations.index') }}" class="rf-button-secondary">{{ __('Réinitialiser') }}</a>@endif</div>
            </form>
        </x-filter-panel>

        <x-responsive-table :label="__('Invitations d’accueil')">
            <table class="rf-table">
                <thead><tr><th>{{ __('Destinataire') }}</th><th>{{ __('Plan') }}</th><th>{{ __('État') }}</th><th>{{ __('Essai') }}</th><th>{{ __('Expiration') }}</th><th>{{ __('Création') }}</th><th>{{ __('Entreprise créée') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></tr></thead>
                <tbody>
                    @forelse($invitations as $invitation)
                        @php($visuallyExpired = $invitation->status->value === 'pending' && $invitation->expires_at->isPast())
                        <tr>
                            <td><strong>{{ $invitation->email }}</strong><span class="block text-slate-500">{{ __('par') }} {{ $invitation->creator?->name ?? __('Administration') }}</span></td>
                            <td>{{ $invitation->plan->name }}</td>
                            <td><x-status-badge :value="$visuallyExpired ? 'expired' : $invitation->status" /></td>
                            <td>{{ $invitation->trial_days }} {{ __('jours') }}</td>
                            <td>{{ App\Support\Ui\UiLabel::dateTime($invitation->expires_at) }}</td>
                            <td>{{ App\Support\Ui\UiLabel::dateTime($invitation->created_at) }}</td>
                            <td>@if($invitation->acceptedTenant)<a class="rf-button-link" href="{{ route('platform.tenants.show', $invitation->acceptedTenant) }}">{{ $invitation->acceptedTenant->name }}</a>@else<span class="text-slate-500">—</span>@endif</td>
                            <td class="text-end">@if($invitation->status->value === 'pending' && ! $visuallyExpired)<form method="POST" action="{{ route('platform.onboarding-invitations.revoke', $invitation) }}" data-loading-form>@csrf @method('PATCH')<x-confirmation-button :message="__('Le destinataire ne pourra plus utiliser ce lien.')" confirm-label="{{ __('Révoquer') }}" loading-label="{{ __('Révocation…') }}">{{ __('Révoquer') }}</x-confirmation-button></form>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><x-empty-state :title="__('Aucune invitation')" :description="__('Envoyez la première invitation depuis le formulaire ci-dessus.')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer>{{ $invitations->links() }}</x-slot:footer>
        </x-responsive-table>
    </div>
</x-app-layout>
