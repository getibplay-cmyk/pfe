@props(['groups', 'billingState' => null])
@if($billingState !== null && !$billingState['legacy'])
    @php($subscription = $billingState['subscription'])
    @php($deadline = $subscription?->status->value === 'trialing' ? $subscription->trial_ends_at : $subscription?->ends_at)
    @if(!$billingState['available'] || ($deadline && $deadline->lte(now()->addDays(7))))
        <x-section-card :title="__('Votre abonnement demande votre attention')">
            <p class="text-sm text-slate-700">{{ $billingState['reason'] }} @if($deadline) {{ __('Échéance :') }} {{ App\Support\Ui\UiLabel::dateTime($deadline) }}.@endif</p>
            <a href="{{ route('tenant-saas-account.show') }}" class="rf-button-primary mt-3">{{ __('Gérer l’abonnement et les factures') }}</a>
        </x-section-card>
    @endif
@endif
@if(count($groups))
    <section aria-labelledby="actions-today" data-dashboard-actions>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3"><div><h2 id="actions-today" class="text-xl font-bold text-slate-900">{{ __('À traiter aujourd’hui') }}</h2><p class="text-sm text-slate-500">{{ __('Les cinq premières priorités de chaque catégorie, dans votre périmètre.') }}</p></div>@if(auth()->user()->hasPermission('vehicle.view'))<a class="rf-button-secondary" href="{{ route('fleet.planning.index') }}">{{ __('Ouvrir le planning') }}</a>@endif</div>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach($groups as $group)
                <article class="rf-panel p-4" data-action-group="{{ $group['key'] }}"><div class="flex items-center justify-between gap-2"><h3 class="font-semibold">{{ $group['title'] }}</h3><span class="rounded-full bg-blue-50 px-3 py-1 text-sm font-bold text-blue-900">{{ $group['count'] }}</span></div>
                    <ul class="mt-3 divide-y">@forelse($group['items'] as $item)<li class="py-3"><p class="font-medium">{{ $item['label'] }}</p><p class="mt-1 text-sm text-slate-500">{{ $item['detail'] }}</p><a class="rf-button-link mt-2 inline-flex" href="{{ $item['url'] }}">{{ $item['action'] }} →</a></li>@empty<li class="py-3 text-sm text-slate-500">{{ __('Aucune action à traiter.') }}</li>@endforelse</ul>
                    <a class="rf-button-link mt-3 inline-flex" href="{{ $group['url'] }}">{{ __('Voir tous les dossiers (') }}{{ $group['count'] }}) →</a>
                </article>
            @endforeach
        </div>
    </section>
@endif
