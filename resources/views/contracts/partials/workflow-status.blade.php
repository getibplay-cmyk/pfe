<section class="rounded-xl bg-white p-5 shadow-sm">
    <h2 class="font-semibold">Prérequis du cycle</h2>
    <div class="mt-4 grid gap-3 text-sm md:grid-cols-2 lg:grid-cols-4">
        @foreach([
            'identity' => 'Identité client',
            'licence' => 'Permis conducteur',
            'contract' => 'PDF de la version',
            'valid' => 'Contrôle fichiers et empreintes',
        ] as $key => $label)
            <div class="rounded-lg border p-3">
                <p class="text-slate-500">{{ $label }}</p>
                <p class="mt-1 font-medium {{ $documentStatus[$key] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $documentStatus[$key] ? 'Validé' : 'Manquant ou invalide' }}</p>
            </div>
        @endforeach
    </div>
    <p class="mt-3 text-sm {{ $documentStatus['valid'] ? 'text-emerald-700' : 'text-amber-800' }}">{{ $documentStatus['message'] }}</p>
    <div class="mt-4 grid gap-3 text-sm md:grid-cols-3">
        <p class="rounded-lg bg-slate-50 p-3">Inspection départ : <strong>{{ $departure ? 'terminée' : 'requise avant activation' }}</strong></p>
        <p class="rounded-lg bg-slate-50 p-3">Caution effective : <strong>{{ $depositTotals['balance'] }} {{ $contract->currency }}</strong> / {{ $contract->deposit_required }} {{ $contract->currency }}</p>
        <p class="rounded-lg bg-slate-50 p-3">Inspection retour : <strong>{{ $return ? 'terminée' : 'à réaliser après activation' }}</strong></p>
    </div>
</section>

<section class="rounded-xl bg-white p-5 shadow-sm">
    <h2 class="font-semibold">Versions contractuelles</h2>
    <div class="mt-4 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="text-left text-slate-500"><th class="py-2">Version</th><th>Créée</th><th>Motif</th><th>État</th><th>Document</th></tr></thead><tbody>
        @foreach($contract->versions->sortByDesc('version_number') as $version)
            <tr class="border-t"><td class="py-3 font-medium">v{{ $version->version_number }}</td><td>{{ $version->created_at->format('d/m/Y H:i') }}</td><td>{{ $version->change_reason ?? 'Version initiale' }}</td><td>{{ $version->locked_at ? 'Verrouillée' : 'Courante modifiable par nouvelle version' }}</td><td>@if($version->document)@can('view', $version->document)<a class="underline" href="{{ route('documents.show', $version->document) }}">Document privé</a>@else<span>Protégé</span>@endcan @else<span class="text-slate-500">Non rattaché</span>@endif</td></tr>
        @endforeach
    </tbody></table></div>
</section>
