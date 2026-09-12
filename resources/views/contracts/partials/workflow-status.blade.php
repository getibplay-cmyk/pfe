<x-section-card :title="__('Prérequis du cycle')" :description="__('Ces contrôles expliquent les actions disponibles et les éventuels blocages.')">
    <div class="grid gap-3 text-sm md:grid-cols-2 lg:grid-cols-4">
        @foreach(['identity' => 'Identité client', 'licence' => 'Permis conducteur', 'contract' => 'PDF de la version', 'valid' => 'Fichiers et empreintes'] as $key => $label)
            <div class="rounded-xl border border-slate-200 p-3">
                <p class="text-slate-500">{{ __($label) }}</p>
                <p class="mt-1 flex items-center gap-2 font-semibold {{ $documentStatus[$key] ? 'text-emerald-700' : 'text-amber-800' }}"><span aria-hidden="true">{{ $documentStatus[$key] ? '✓' : '!' }}</span>{{ $documentStatus[$key] ? __('Validé') : __('Manquant ou invalide') }}</p>
            </div>
        @endforeach
    </div>
    <x-flash-message class="mt-4" :type="$documentStatus['valid'] ? 'success' : 'warning'" :message="$documentStatus['message']" />
    <div class="mt-4 grid gap-3 text-sm md:grid-cols-3">
        <div class="rounded-lg bg-slate-50 p-3"><span class="text-slate-500">{{ __('Inspection départ') }}</span><p class="mt-1 font-semibold">{{ $departure ? __('Terminée') : __('Requise avant activation') }}</p></div>
        <div class="rounded-lg bg-slate-50 p-3"><span class="text-slate-500">{{ __('Caution effective') }}</span><p class="mt-1 font-semibold">{{ App\Support\Ui\UiLabel::money($depositTotals['balance'], $contract->currency) }} / {{ App\Support\Ui\UiLabel::money($contract->deposit_required, $contract->currency) }}</p></div>
        <div class="rounded-lg bg-slate-50 p-3"><span class="text-slate-500">{{ __('Inspection retour') }}</span><p class="mt-1 font-semibold">{{ $return ? __('Terminée') : __('À réaliser après activation') }}</p></div>
    </div>
</x-section-card>

<x-section-card :title="__('Versions contractuelles')" :description="__('Chaque évolution crée une version traçable ; les documents restent privés.')">
    <x-responsive-table :label="__('Versions du contrat')" class="shadow-none">
        <table><thead><tr><th>{{ __('Version') }}</th><th>{{ __('Créée') }}</th><th>{{ __('Motif') }}</th><th>{{ __('État') }}</th><th>{{ __('Document') }}</th></tr></thead><tbody>
            @foreach($contract->versions->sortByDesc('version_number') as $version)
                <tr><td class="font-semibold">{{ __('v') }}{{ $version->version_number }}</td><td class="whitespace-nowrap">{{ App\Support\Ui\UiLabel::dateTime($version->created_at) }}</td><td>{{ $version->change_reason ?? __('Version initiale') }}</td><td>{{ $version->locked_at ? __('Verrouillée') : __('Nouvelle version possible') }}</td><td>@if($version->document)@can('view', $version->document)<a href="{{ route('documents.show', $version->document) }}">{{ __('Document privé') }}</a>@else<span>{{ __('Accès protégé') }}</span>@endcan @else<span class="text-slate-500">{{ __('Non rattaché') }}</span>@endif</td></tr>
            @endforeach
        </tbody></table>
    </x-responsive-table>
</x-section-card>
