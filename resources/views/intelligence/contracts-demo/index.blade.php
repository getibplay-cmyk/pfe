<x-app-layout>
    <div class="rf-page">
        <x-page-header
            title="Contrats Intelligence synthétiques"
            eyebrow="Démonstration isolée J12"
            description="Validez l’adaptateur Laravel sans modèle, sans donnée réelle et sans action sur la flotte."
        >
            <x-slot:actions><a href="{{ route('intelligence.index') }}" class="rf-button-secondary"><x-icon name="previous" size="xs" />Retour aux analyses</a></x-slot:actions>
        </x-page-header>

        <x-form-errors />

        <x-section-card title="Frontière de sécurité" description="Ce workflow est volontairement non opérationnel.">
            <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-slate-700">
                <li>Fixtures `SYNTH-*` scellées uniquement ; aucun payload libre n’est accepté.</li>
                <li>Les quatre feature flags de modèle restent à `false` et `ready_for_saas=false`.</li>
                <li>Toute décision humaine a l’effet constant `NO_OPERATIONAL_ACTION`.</li>
                <li>Aucune écriture n’est faite dans les véhicules, réservations, contrats, maintenances, blocs ou registres financiers.</li>
            </ul>
        </x-section-card>

        @if ($canReview)
            <x-section-card title="Ajouter une fixture scellée" description="Le tenant et l’agence proviennent exclusivement du contexte serveur.">
                <form method="POST" action="{{ route('intelligence.contract-demo.fixtures.store') }}" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                    @csrf
                    <div>
                        <x-input-label for="j11-module" value="Contrat synthétique" required />
                        <select id="j11-module" name="module_id" class="mt-1 w-full" required>
                            @foreach ($modules as $module)
                                <option value="{{ $module->value }}">{{ $module->label() }} · {{ $module->auditScore() }}</option>
                            @endforeach
                        </select>
                        <x-field-error :messages="$errors->get('module_id')" class="mt-2" />
                    </div>
                    <x-primary-button>Valider et ajouter</x-primary-button>
                </form>
            </x-section-card>
        @endif

        <x-result-count :paginator="$records" />
        <x-responsive-table label="Registre append-only des fixtures J12">
            <table>
                <thead><tr><th>Module</th><th>Périmètre</th><th>Validation</th><th>Dernière décision</th><th>Action</th></tr></thead>
                <tbody>
                    @forelse ($records as $record)
                        @php($latestDecision = $record->decisions->first())
                        <tr>
                            <td><p class="font-semibold">{{ $record->module_id->label() }}</p><p class="text-xs text-slate-500">Contrat {{ $record->contract_version }}</p></td>
                            <td>{{ $record->agency?->name ?? 'Tenant entier' }}</td>
                            <td><x-status-badge :value="$record->validation_status" /><p class="mt-1 text-xs text-slate-500">Fixture synthétique</p></td>
                            <td>
                                @if ($latestDecision)
                                    <p class="font-medium">{{ $latestDecision->decision->label() }}</p>
                                    <p class="text-xs text-slate-500">{{ $latestDecision->reason_code }} · aucune action métier</p>
                                @else
                                    <span class="text-slate-500">En attente de revue locale</span>
                                @endif
                            </td>
                            <td>
                                @can('review', $record)
                                    <form method="POST" action="{{ route('intelligence.contract-demo.decisions.store', $record) }}" class="min-w-64 space-y-2">
                                        @csrf
                                        <select name="decision" class="w-full" aria-label="Décision humaine de démonstration">
                                            @foreach (App\Enums\J11DemoDecision::cases() as $decision)
                                                <option value="{{ $decision->value }}">{{ $decision->label() }}</option>
                                            @endforeach
                                        </select>
                                        <select name="reason_code" class="w-full" aria-label="Motif de la décision">
                                            @foreach ($reasonCodes as $reasonCode)<option value="{{ $reasonCode }}">{{ $reasonCode }}</option>@endforeach
                                        </select>
                                        <x-secondary-button type="submit">Enregistrer sans effet métier</x-secondary-button>
                                    </form>
                                @else
                                    <span class="text-sm text-slate-500">Lecture seule</span>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-empty-state title="Aucune fixture locale" description="Le workflow reste fermé tant qu’aucune fixture scellée n’est ajoutée." /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer>{{ $records->links() }}</x-slot:footer>
        </x-responsive-table>
    </div>
</x-app-layout>
