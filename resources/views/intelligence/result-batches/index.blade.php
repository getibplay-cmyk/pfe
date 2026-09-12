<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            :title="__('Résultats de démonstration')"
            :eyebrow="__('Démonstration isolée')"
            :description="__('Vérifiez des résultats d’exemple et consignez une décision humaine. Aucune donnée métier n’est modifiée.')"
        >
            <x-slot:actions>
                <a href="{{ route('intelligence.index') }}" class="rf-button-secondary"><x-icon name="previous" size="xs" />{{ __('Retour aux analyses') }}</a>
            </x-slot:actions>
        </x-page-header>

        <x-section-card :title="__('Dernier résultat accepté')" :description="__('Seul un exemple accepté et dont le fichier privé est toujours disponible peut être affiché.')">
            @if ($fallback->available())
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950">
                    <p class="font-semibold">{{ __('Dernier résultat accepté disponible') }}</p>
                    <p class="mt-1">{{ __('Accepté le') }} {{ App\Support\Ui\UiLabel::dateTime($fallback->batch->decision->created_at) }}.</p>
                    <p class="mt-2 font-medium">{{ __('Aucune action n’est appliquée automatiquement.') }}</p>
                </div>
            @else
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                    <p class="font-semibold">{{ __('Aucun résultat accepté disponible') }}</p>
                    <p class="mt-1">{{ __('Aucune suggestion n’est affichée. Le fonctionnement métier reste inchangé.') }}</p>
                </div>
            @endif
        </x-section-card>

        @if (auth()->user()->hasPermission('prediction.demo.review'))
            <x-section-card
                :title="__('Importer des résultats de démonstration')"
                :description="__('Votre entreprise et votre agence sont déterminées automatiquement. Taille maximale : 1 Mio.')"
            >
                <div class="space-y-4">
                    @forelse ($exportRuns as $run)
                        @can('importResultBatch', $run)
                            <article class="rounded-xl border border-slate-200 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="mt-1 text-sm text-slate-600">
                                            {{ App\Support\Ui\BusinessNumber::count($run->row_count, 'ligne') }} {{ __('· exporté le') }} {{ App\Support\Ui\UiLabel::dateTime($run->created_at) }}
                                        </p>
                                    </div>
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{{ $run->scope_kind === 'agency' ? __('Agence') : __('Entreprise') }}</span>
                                </div>
                                <form method="POST" enctype="multipart/form-data" action="{{ route('intelligence.result-batches.store', $run) }}" class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end" data-loading-form>
                                    @csrf
                                    <x-file-input :id="'result-batch-'.$run->id" name="result_batch" :label="__('Fichier de résultats')" accept=".json,application/json" formats="JSON" max-size="1 Mio" required :errors="$errors->get('result_batch')" />
                                    <x-confirmation-button type="submit" variant="secondary" :message="__('Importer ces résultats de démonstration après vérification ?')" loading-label="{{ __('Validation…') }}">{{ __('Valider et importer') }}</x-confirmation-button>
                                </form>
                            </article>
                        @endcan
                    @empty
                        <x-empty-state :title="__('Aucun export disponible')" :description="__('Créez d’abord un export autorisé depuis l’écran des analyses.')" />
                    @endforelse
                </div>
                <x-field-error :messages="$errors->get('result_batch')" class="mt-4" />
            </x-section-card>
        @endif

        <x-section-card
            :title="__('Historique des résultats')"
            :description="__('Les données sensibles et les emplacements privés ne sont jamais affichés.')"
        >
            <div class="space-y-4">
                @forelse ($batches as $batch)
                    <article class="rounded-xl border border-slate-200 bg-white p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="mt-1 text-sm text-slate-600">
                                    {{ App\Support\Ui\BusinessNumber::count($batch->rows_count, 'résultat') }} {{ $batch->rows_count > 1 ? __('qualitatifs') : __('qualitatif') }} {{ __('· importé le') }} {{ App\Support\Ui\UiLabel::dateTime($batch->imported_at) }}
                                </p>
                            </div>
                            <x-status-badge :value="$batch->reviewStatus()" />
                        </div>

                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                            <div><dt class="text-slate-500">{{ __('Source') }}</dt><dd class="mt-1 font-medium">{{ __('Démonstration isolée') }}</dd></div>
                            <div><dt class="text-slate-500">{{ __('Validation') }}</dt><dd class="mt-1"><x-status-badge :value="$batch->validation_status" /></dd></div>
                            <div><dt class="text-slate-500">{{ __('Effet') }}</dt><dd class="mt-1 font-medium">{{ __('Aucune action opérationnelle') }}</dd></div>
                        </dl>

                        <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4">
                            <a href="{{ route('intelligence.result-batches.download', $batch) }}" class="rf-button-secondary" data-no-global-loading="true"><x-icon name="download" size="xs" />{{ __('Télécharger les résultats') }}</a>
                            @if ($batch->decision)
                                <p class="text-sm text-slate-600">
                                    {{ __('Décision par') }} {{ $batch->decision->actor->name }} {{ __('· motif :') }} {{ App\Support\Ui\UiLabel::get($batch->decision->reason_code) }}
                                </p>
                            @endif
                        </div>

                        @can('review', $batch)
                            @if (! $batch->decision)
                                <div class="mt-4 grid gap-4 border-t border-slate-200 pt-4 lg:grid-cols-2">
                                    <form method="POST" action="{{ route('intelligence.result-batches.decisions.store', $batch) }}" class="space-y-3 rounded-xl bg-emerald-50 p-4">
                                        @csrf
                                        <input type="hidden" name="decision" value="accepted_for_demo_review">
                                        <label class="block text-sm font-medium text-emerald-950" for="accept-reason-{{ $batch->id }}">{{ __('Accepter pour revue de démonstration') }}</label>
                                        <select id="accept-reason-{{ $batch->id }}" name="reason_code" required class="block w-full rounded-lg border-emerald-300 text-sm">
                                            @foreach ($acceptReasonCodes as $reasonCode)<option value="{{ $reasonCode }}">{{ App\Support\Ui\UiLabel::get($reasonCode) }}</option>@endforeach
                                        </select>
                                        <x-confirmation-button type="submit" variant="secondary" :message="__('Consigner cette acceptation humaine sans effet métier ?')">{{ __('Consigner l’acceptation') }}</x-confirmation-button>
                                    </form>
                                    <form method="POST" action="{{ route('intelligence.result-batches.decisions.store', $batch) }}" class="space-y-3 rounded-xl bg-rose-50 p-4">
                                        @csrf
                                        <input type="hidden" name="decision" value="rejected">
                                        <label class="block text-sm font-medium text-rose-950" for="reject-reason-{{ $batch->id }}">{{ __('Rejeter la preuve') }}</label>
                                        <select id="reject-reason-{{ $batch->id }}" name="reason_code" required class="block w-full rounded-lg border-rose-300 text-sm">
                                            @foreach ($rejectReasonCodes as $reasonCode)<option value="{{ $reasonCode }}">{{ App\Support\Ui\UiLabel::get($reasonCode) }}</option>@endforeach
                                        </select>
                                        <x-confirmation-button type="submit" variant="danger" :message="__('Consigner ce rejet dans l’historique ?')">{{ __('Consigner le rejet') }}</x-confirmation-button>
                                    </form>
                                </div>
                            @endif
                        @endcan
                    </article>
                @empty
                    <x-empty-state :title="__('Aucun résultat importé')" :description="__('L’absence de résultat n’altère aucune fonctionnalité opérationnelle.')" />
                @endforelse
            </div>
            <div class="mt-5">{{ $batches->links() }}</div>
        </x-section-card>
    </div>
</x-app-layout>
