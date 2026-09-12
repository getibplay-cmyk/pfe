<x-app-layout>
    <div class="rf-page">
        <x-page-header
            :title="__('Démonstrations isolées')"
            :eyebrow="__('Fonctionnalités intelligentes')"
            :description="__('Découvrez des exemples sans donnée réelle et sans action sur la flotte.')"
        >
            <x-slot:actions><a href="{{ route('intelligence.index') }}" class="rf-button-secondary"><x-icon name="previous" size="xs" />{{ __('Retour aux analyses') }}</a></x-slot:actions>
        </x-page-header>

        <x-form-errors />

        <x-section-card :title="__('Protection de la démonstration')" :description="__('Ces exemples restent entièrement séparés de l’exploitation.')">
            <ul class="list-disc space-y-2 ps-5 text-sm leading-6 text-slate-700">
                <li>{{ __('Seuls les exemples prédéfinis sont acceptés.') }}</li>
                <li>{{ __('Aucune fonctionnalité indisponible n’est activée par cette démonstration.') }}</li>
                <li>{{ __('Toute décision reste limitée à l’exemple consulté.') }}</li>
                <li>{{ __('Aucune écriture n’est faite dans les véhicules, réservations, contrats, maintenances, blocs ou registres financiers.') }}</li>
            </ul>
        </x-section-card>

        @if ($canReview)
            <x-section-card :title="__('Ajouter un exemple')" :description="__('Votre entreprise et votre agence sont déterminées automatiquement à partir de votre session.')">
                <form method="POST" action="{{ route('intelligence.contract-demo.fixtures.store') }}" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                    @csrf
                    <div>
                        <x-input-label for="j11-module" :value="__('Exemple de démonstration')" required />
                        <select id="j11-module" name="module_id" class="mt-1 w-full" required>
                            @foreach ($modules as $module)
                                <option value="{{ $module->value }}">{{ $module->label() }}</option>
                            @endforeach
                        </select>
                        <x-field-error :messages="$errors->get('module_id')" class="mt-2" />
                    </div>
                    <x-primary-button>{{ __('Valider et ajouter') }}</x-primary-button>
                </form>
            </x-section-card>
        @endif

        <x-result-count :paginator="$records" />
        <x-responsive-table :label="__('Historique des démonstrations')">
            <table>
                <thead><tr><th>{{ __('Module') }}</th><th>{{ __('Périmètre') }}</th><th>{{ __('Validation') }}</th><th>{{ __('Dernière décision') }}</th><th>{{ __('Action') }}</th></tr></thead>
                <tbody>
                    @forelse ($records as $record)
                        @php($latestDecision = $record->decisions->first())
                        <tr>
                            <td><p class="font-semibold">{{ $record->module_id->label() }}</p></td>
                            <td>{{ $record->agency?->name ?? __('Entreprise entière') }}</td>
                            <td><x-status-badge :value="$record->validation_status" /><p class="mt-1 text-xs text-slate-500">{{ __('Démonstration isolée') }}</p></td>
                            <td>
                                @if ($latestDecision)
                                    <p class="font-medium">{{ $latestDecision->decision->label() }}</p>
                                    <p class="text-xs text-slate-500">{{ App\Support\Ui\UiLabel::get($latestDecision->reason_code) }} {{ __('· aucune action métier') }}</p>
                                @else
                                    <span class="text-slate-500">{{ __('En attente de revue locale') }}</span>
                                @endif
                            </td>
                            <td>
                                @can('review', $record)
                                    <form method="POST" action="{{ route('intelligence.contract-demo.decisions.store', $record) }}" class="min-w-64 space-y-2">
                                        @csrf
                                        <select name="decision" class="w-full" aria-label="{{ __('Décision humaine de démonstration') }}">
                                            @foreach (App\Enums\J11DemoDecision::cases() as $decision)
                                                <option value="{{ $decision->value }}">{{ $decision->label() }}</option>
                                            @endforeach
                                        </select>
                                        <select name="reason_code" class="w-full" aria-label="{{ __('Motif de la décision') }}">
                                            @foreach ($reasonCodes as $reasonCode)<option value="{{ $reasonCode }}">{{ App\Support\Ui\UiLabel::get($reasonCode) }}</option>@endforeach
                                        </select>
                                        <x-secondary-button type="submit">{{ __('Enregistrer sans effet métier') }}</x-secondary-button>
                                    </form>
                                @else
                                    <span class="text-sm text-slate-500">{{ __('Lecture seule') }}</span>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-empty-state :title="__('Aucune démonstration')" :description="__('Ajoutez un exemple pour commencer.')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer>{{ $records->links() }}</x-slot:footer>
        </x-responsive-table>
    </div>
</x-app-layout>
