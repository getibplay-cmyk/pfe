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
                <article class="rf-panel rf-action-card" data-action-group="{{ $group['key'] }}">
                    <div class="rf-action-card-header">
                        <span @class(['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', 'bg-orange-50 text-orange-800' => $group['count'] > 0, 'bg-emerald-50 text-emerald-800' => $group['count'] === 0])><x-icon :name="$group['count'] > 0 ? 'calendar' : 'check'" /></span>
                        <h3 class="min-w-0 flex-1 font-semibold text-slate-900">{{ $group['title'] }}</h3>
                        <span class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-sm font-bold tabular-nums text-slate-900">{{ $group['count'] }}</span>
                    </div>
                    <ul class="divide-y divide-slate-100">
                        @forelse($group['items'] as $item)
                            <li class="py-4"><p class="font-semibold text-slate-900">{{ $item['label'] }}</p><p class="mt-1 text-sm leading-5 text-slate-600">{{ $item['detail'] }}</p><a class="rf-button-link mt-2 gap-2" href="{{ $item['url'] }}">{{ $item['action'] }}<x-icon name="next" size="xs" /></a></li>
                        @empty<li class="flex items-center gap-2 py-6 text-sm text-slate-600"><x-icon name="check" class="text-emerald-700" />{{ __('Aucune action à traiter.') }}</li>@endforelse
                    </ul>
                    <div class="border-t border-slate-100 px-4 py-2"><a class="rf-button-link gap-2" href="{{ $group['url'] }}">{{ __('Voir tous les dossiers (') }}{{ $group['count'] }})<x-icon name="next" size="xs" /></a></div>
                </article>
                </article>
            @endforeach
        </div>
    </section>
@endif
