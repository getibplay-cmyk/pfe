<x-app-layout>
    <div class="rf-page">
        <x-page-header :title="__('Fonctionnalités intelligentes et accès')" :eyebrow="__('Administration de la plateforme')" :description="__('Contrôlez l’accès de chaque entreprise aux aides à la décision disponibles, sans lancer de traitement.')"></x-page-header>
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">{{ __('Une autorisation ne lance aucun traitement. L’utilisateur conserve ses permissions métier habituelles et toutes les suggestions restent consultatives.') }}</div>
        <x-form-errors />

        <x-section-card :title="__('Catalogue applicatif')" :description="__('État global assaini des six fonctionnalités réellement intégrées.')">
            <x-responsive-table :label="__('Catalogue des fonctionnalités intelligentes')"><table><thead><tr><th>{{ __('Fonctionnalité') }}</th><th>{{ __('Usage') }}</th><th>{{ __('Disponibilité') }}</th><th>{{ __('Service prêt') }}</th><th>{{ __('Entreprises autorisées') }}</th><th>{{ __('Dernière modification') }}</th></tr></thead><tbody>
                @foreach($capabilities as $item)<tr><td><strong>{{ $item['label'] }}</strong><br><span class="text-slate-500">{{ $item['description'] }}</span></td><td>{{ $item['usage'] }}</td><td><x-status-badge :value="$item['globally_enabled'] ? 'active' : 'inactive'" /><p class="mt-1 text-xs text-slate-500">{{ $item['message'] }}</p></td><td>{{ $item['runtime_ready'] ? __('Prêt') : __('Non prêt') }}</td><td>{{ $item['enabled_tenants'] }}</td><td>{{ $item['latest_change'] ? App\Support\Ui\UiLabel::dateTime($item['latest_change']) : '—' }}</td></tr>@endforeach
            </tbody></table></x-responsive-table>
        </x-section-card>

        @php($activeFilterCount = collect(['q', 'status'])->filter(fn (string $key): bool => request()->filled($key))->count())
        <x-filter-panel :title="__('Filtrer les entreprises')" :active-count="$activeFilterCount" :result-count="$tenants->total()">
            @if($activeFilterCount > 0)
                <x-slot:tags>
                    @if(request()->filled('q'))<a class="rf-filter-tag" href="{{ route('platform.intelligence.index', request()->except(['q', 'page'])) }}">{{ __('Recherche :') }} {{ request('q') }} <span aria-hidden="true">{{ __('×') }}</span><span class="sr-only">{{ __('Retirer la recherche') }}</span></a>@endif
                    @if(request()->filled('status'))<a class="rf-filter-tag" href="{{ route('platform.intelligence.index', request()->except(['status', 'page'])) }}">{{ __('État') }} <span aria-hidden="true">{{ __('×') }}</span><span class="sr-only">{{ __('Retirer le filtre état') }}</span></a>@endif
                </x-slot:tags>
            @endif
            <form class="rf-filter-grid" method="GET" data-loading-form><div><x-input-label for="intelligence-tenant-search" :value="__('Entreprise')" /><input id="intelligence-tenant-search" name="q" value="{{ request('q') }}" class="mt-1 w-full"></div><div><x-input-label for="intelligence-tenant-status" :value="__('État')" /><select id="intelligence-tenant-status" name="status" class="mt-1 w-full"><option value="">{{ __('Tous') }}</option><option value="active" @selected(request('status') === 'active')>{{ __('Actives') }}</option><option value="suspended" @selected(request('status') === 'suspended')>{{ __('Suspendues') }}</option><option value="archived" @selected(request('status') === 'archived')>{{ __('Archivées') }}</option></select></div><div class="flex items-end gap-2"><x-submit-button :label="__('Filtrer')" loading-label="{{ __('Filtrage…') }}" />@if($activeFilterCount > 0)<a href="{{ route('platform.intelligence.index') }}" class="rf-button-secondary"><x-icon name="reset" /> {{ __('Effacer') }}</a>@endif</div></form>
        </x-filter-panel>

        <x-responsive-table :label="__('Autorisations par entreprise')"><table><thead><tr><th>{{ __('Entreprise') }}</th>@foreach($capabilities as $item)<th>{{ $item['label'] }}</th>@endforeach</tr></thead><tbody>
            @forelse($tenants as $tenant)
                @php($tenantAccesses = ($accesses[$tenant->id] ?? collect())->keyBy('capability'))
                <tr><td><a href="{{ route('platform.tenants.show', $tenant) }}" class="font-semibold text-brand-700">{{ $tenant->name }}</a><br><x-status-badge :value="$tenant->status" /></td>
                    @foreach($capabilities as $item)
                        @php($enabled = (bool) ($tenantAccesses[$item['capability']->value]->enabled ?? false))
                        <td>
                            <form
                                method="POST"
                                action="{{ route('platform.intelligence.update', [$tenant, $item['capability']->value]) }}"
                                x-belkhir-space-confirm
                                data-confirm-title="{{ $enabled ? __('Désactiver cette fonctionnalité') : __('Autoriser cette fonctionnalité') }}"
                                data-confirm-resource="{{ $item['label'] }} · {{ $tenant->name }}"
                                data-confirm-consequence="{{ $enabled ? __('Les nouveaux traitements seront désactivés pour cette entreprise.') : __('Cette fonctionnalité deviendra accessible à cette entreprise selon ses permissions métier.') }}"
                                data-confirm-label="{{ $enabled ? __('Désactiver') : __('Autoriser') }}"
                                data-loading-form
                            >
                                @csrf @method('PATCH')
                                <input type="hidden" name="enabled" value="{{ $enabled ? '0' : '1' }}">
                                <x-submit-button
                                    :variant="$enabled ? 'secondary' : 'primary'"
                                    :label="$enabled ? __('Désactiver') : __('Autoriser')"
                                    loading-label="{{ __('Mise à jour…') }}"
                                    :aria-label="($enabled ? __('Désactiver ') : __('Autoriser ')).$item['label'].' pour '.$tenant->name"
                                    :disabled="! $enabled && $tenant->status->value !== 'active'"
                                />
                            </form>
                        </td>
                    @endforeach
                </tr>
            @empty<tr><td colspan="{{ $capabilities->count() + 1 }}"><x-empty-state :title="__('Aucune entreprise')" :description="__('Aucune entreprise ne correspond aux filtres.')" /></td></tr>@endforelse
        </tbody></table><x-slot:footer>{{ $tenants->links() }}</x-slot:footer></x-responsive-table>
    </div>
</x-app-layout>
