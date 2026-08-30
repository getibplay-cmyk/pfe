<x-app-layout>
    <div class="rf-page">
        <x-page-header title="Fonctionnalités intelligentes" eyebrow="Administration de la plateforme" description="Contrôlez l’accès de chaque entreprise aux assistants intégrés, sans modifier leurs modèles ni leurs paramètres scientifiques."></x-page-header>
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">Une autorisation ne lance aucun traitement. L’utilisateur conserve ses permissions métier habituelles et toutes les suggestions restent consultatives.</div>
        <x-form-errors />

        <x-section-card title="Catalogue applicatif" description="État global assaini des six fonctionnalités réellement intégrées.">
            <x-responsive-table label="Catalogue des fonctionnalités intelligentes"><table><thead><tr><th>Fonctionnalité</th><th>Usage</th><th>Disponibilité</th><th>Environnement prêt</th><th>Entreprises autorisées</th><th>Dernière modification</th></tr></thead><tbody>
                @foreach($capabilities as $item)<tr><td><strong>{{ $item['label'] }}</strong><br><span class="text-slate-500">{{ $item['description'] }}</span></td><td>{{ $item['usage'] }}</td><td><x-status-badge :value="$item['globally_enabled'] ? 'active' : 'inactive'" /><p class="mt-1 text-xs text-slate-500">{{ $item['message'] }}</p></td><td>{{ $item['runtime_ready'] ? 'Prêt' : 'Non prêt' }}</td><td>{{ $item['enabled_tenants'] }}</td><td>{{ $item['latest_change'] ? App\Support\Ui\UiLabel::dateTime($item['latest_change']) : '—' }}</td></tr>@endforeach
            </tbody></table></x-responsive-table>
        </x-section-card>

        <x-filter-panel title="Filtrer les entreprises"><form class="rf-filter-grid" method="GET"><div><x-input-label for="intelligence-tenant-search" value="Entreprise" /><input id="intelligence-tenant-search" name="q" value="{{ request('q') }}" class="mt-1 w-full"></div><div><x-input-label for="intelligence-tenant-status" value="État" /><select id="intelligence-tenant-status" name="status" class="mt-1 w-full"><option value="">Tous</option><option value="active" @selected(request('status') === 'active')>Actives</option><option value="suspended" @selected(request('status') === 'suspended')>Suspendues</option><option value="archived" @selected(request('status') === 'archived')>Archivées</option></select></div><div class="flex items-end gap-2"><x-primary-button>Filtrer</x-primary-button><a href="{{ route('platform.intelligence.index') }}" class="rf-button-secondary">Effacer</a></div></form></x-filter-panel>

        <x-responsive-table label="Autorisations par entreprise"><table><thead><tr><th>Entreprise</th>@foreach($capabilities as $item)<th>{{ $item['label'] }}</th>@endforeach</tr></thead><tbody>
            @forelse($tenants as $tenant)
                @php($tenantAccesses = ($accesses[$tenant->id] ?? collect())->keyBy('capability'))
                <tr><td><a href="{{ route('platform.tenants.show', $tenant) }}" class="font-semibold text-brand-700">{{ $tenant->name }}</a><br><x-status-badge :value="$tenant->status" /></td>
                    @foreach($capabilities as $item)
                        @php($enabled = (bool) ($tenantAccesses[$item['capability']->value]->enabled ?? false))
                        <td><form method="POST" action="{{ route('platform.intelligence.update', [$tenant, $item['capability']->value]) }}" onsubmit="return confirm('{{ $enabled ? 'Désactiver les nouveaux traitements pour cette entreprise ?' : 'Autoriser cette fonctionnalité pour cette entreprise ?' }}')">@csrf @method('PATCH')<input type="hidden" name="enabled" value="{{ $enabled ? '0' : '1' }}"><button class="{{ $enabled ? 'rf-button-secondary' : 'rf-button-primary' }}" @disabled(! $enabled && $tenant->status->value !== 'active')>{{ $enabled ? 'Désactiver' : 'Autoriser' }}</button><span class="sr-only">{{ $item['label'] }} pour {{ $tenant->name }}</span></form></td>
                    @endforeach
                </tr>
            @empty<tr><td colspan="{{ $capabilities->count() + 1 }}"><x-empty-state title="Aucune entreprise" description="Aucune entreprise ne correspond aux filtres." /></td></tr>@endforelse
        </tbody></table><x-slot:footer>{{ $tenants->links() }}</x-slot:footer></x-responsive-table>
    </div>
</x-app-layout>
