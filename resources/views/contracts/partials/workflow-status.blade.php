<x-section-card title="Prérequis du cycle" description="Ces contrôles expliquent les actions disponibles et les éventuels blocages.">
    <div class="grid gap-3 text-sm md:grid-cols-2 lg:grid-cols-4">
        @foreach(['identity' => 'Identité client', 'licence' => 'Permis conducteur', 'contract' => 'PDF de la version', 'valid' => 'Fichiers et empreintes'] as $key => $label)
            <div class="rounded-xl border border-slate-200 p-3">
                <p class="text-slate-500">{{ $label }}</p>
                <p class="mt-1 flex items-center gap-2 font-semibold {{ $documentStatus[$key] ? 'text-emerald-700' : 'text-amber-800' }}"><span aria-hidden="true">{{ $documentStatus[$key] ? '✓' : '!' }}</span>{{ $documentStatus[$key] ? 'Validé' : 'Manquant ou invalide' }}</p>
            </div>
        @endforeach
    </div>
    <x-flash-message class="mt-4" :type="$documentStatus['valid'] ? 'success' : 'warning'" :message="$documentStatus['message']" />
    <div class="mt-4 grid gap-3 text-sm md:grid-cols-3">
        <div class="rounded-lg bg-slate-50 p-3"><span class="text-slate-500">Inspection départ</span><p class="mt-1 font-semibold">{{ $departure ? 'Terminée' : 'Requise avant activation' }}</p></div>
        <div class="rounded-lg bg-slate-50 p-3"><span class="text-slate-500">Caution effective</span><p class="mt-1 font-semibold">{{ App\Support\Ui\UiLabel::money($depositTotals['balance'], $contract->currency) }} / {{ App\Support\Ui\UiLabel::money($contract->deposit_required, $contract->currency) }}</p></div>
        <div class="rounded-lg bg-slate-50 p-3"><span class="text-slate-500">Inspection retour</span><p class="mt-1 font-semibold">{{ $return ? 'Terminée' : 'À réaliser après activation' }}</p></div>
    </div>
</x-section-card>

<x-section-card title="Versions contractuelles" description="Chaque évolution crée une version traçable ; les documents restent privés.">
    <x-responsive-table label="Versions du contrat" class="shadow-none">
        <table><thead><tr><th>Version</th><th>Créée</th><th>Motif</th><th>État</th><th>Document</th></tr></thead><tbody>
            @foreach($contract->versions->sortByDesc('version_number') as $version)
                <tr><td class="font-semibold">v{{ $version->version_number }}</td><td class="whitespace-nowrap">{{ App\Support\Ui\UiLabel::dateTime($version->created_at) }}</td><td>{{ $version->change_reason ?? 'Version initiale' }}</td><td>{{ $version->locked_at ? 'Verrouillée' : 'Nouvelle version possible' }}</td><td>@if($version->document)@can('view', $version->document)<a href="{{ route('documents.show', $version->document) }}">Document privé</a>@else<span>Accès protégé</span>@endcan @else<span class="text-slate-500">Non rattaché</span>@endif</td></tr>
            @endforeach
        </tbody></table>
    </x-responsive-table>
</x-section-card>
