<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            title="J14-B · import et revue des lots de résultats"
            eyebrow="Intelligence contrôlée"
            description="Validez un résultat synthétique lié à un snapshot J14-A, puis consignez une décision humaine append-only. Aucun modèle ni aucune action métier ne sont exécutés."
        >
            <x-slot:actions>
                <a href="{{ route('intelligence.index') }}" class="rf-button-secondary">Retour aux analyses</a>
            </x-slot:actions>
        </x-page-header>

        <x-section-card title="Fallback explicite" description="Seule une preuve synthétique acceptée et dont le fichier privé reste intègre peut être rappelée.">
            @if ($fallback->available())
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950">
                    <p class="font-semibold">Dernière preuve acceptée disponible</p>
                    <p class="mt-1">Lot <code>{{ $fallback->batch->batch_id }}</code>, accepté le {{ App\Support\Ui\UiLabel::dateTime($fallback->batch->decision->created_at) }}.</p>
                    <p class="mt-2 font-medium">Effet constant : aucune action opérationnelle.</p>
                </div>
            @else
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                    <p class="font-semibold">Aucune preuve acceptée et intègre disponible</p>
                    <p class="mt-1">Fallback : aucune recommandation. Le fonctionnement métier reste inchangé.</p>
                </div>
            @endif
        </x-section-card>

        @if (auth()->user()->hasPermission('prediction.demo.review'))
            <x-section-card
                title="Importer un lot JSON fermé"
                description="Le tenant et l’agence viennent de la session. Taille maximale 1 Mio ; extension et type JSON vérifiés côté serveur."
            >
                <div class="space-y-4">
                    @forelse ($exportRuns as $run)
                        @can('importResultBatch', $run)
                            <article class="rounded-xl border border-slate-200 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="font-mono text-xs text-slate-700">{{ $run->run_id }}</p>
                                        <p class="mt-1 text-sm text-slate-600">
                                            {{ number_format($run->row_count, 0, ',', ' ') }} ligne(s) · exporté le {{ App\Support\Ui\UiLabel::dateTime($run->created_at) }}
                                        </p>
                                    </div>
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{{ $run->scope_kind === 'agency' ? 'Agence' : 'Entreprise' }}</span>
                                </div>
                                <form method="POST" enctype="multipart/form-data" action="{{ route('intelligence.result-batches.store', $run) }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                                    @csrf
                                    <div class="min-w-0 flex-1">
                                        <x-input-label :for="'result-batch-'.$run->id" value="Lot de résultats JSON" />
                                        <input id="result-batch-{{ $run->id }}" type="file" name="result_batch" accept=".json,application/json" required class="mt-1 block w-full text-sm text-slate-700">
                                    </div>
                                    <x-confirmation-button type="submit" variant="secondary" message="Importer ce lot synthétique après validation complète du contrat J14-B ?">Valider et importer</x-confirmation-button>
                                </form>
                            </article>
                        @endcan
                    @empty
                        <x-empty-state title="Aucun snapshot J14-A disponible" description="Créez d’abord un export autorisé depuis l’écran Intelligence." />
                    @endforelse
                </div>
                <x-field-error :messages="$errors->get('result_batch')" class="mt-4" />
            </x-section-card>
        @endif

        <x-section-card
            title="Registre append-only"
            description="Les identifiants métiers, chemins privés, empreintes et valeurs quantitatives ne sont pas affichés."
        >
            <div class="space-y-4">
                @forelse ($batches as $batch)
                    <article class="rounded-xl border border-slate-200 bg-white p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-mono text-xs text-slate-700">{{ $batch->batch_id }}</p>
                                <p class="mt-1 text-sm text-slate-600">
                                    {{ number_format($batch->rows_count, 0, ',', ' ') }} résultat(s) qualitatif(s) · importé le {{ App\Support\Ui\UiLabel::dateTime($batch->imported_at) }}
                                </p>
                            </div>
                            <x-status-badge :value="$batch->reviewStatus()" />
                        </div>

                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                            <div><dt class="text-slate-500">Source</dt><dd class="mt-1 font-medium">Fixture synthétique, aucun calcul</dd></div>
                            <div><dt class="text-slate-500">Validation</dt><dd class="mt-1"><x-status-badge :value="$batch->validation_status" /></dd></div>
                            <div><dt class="text-slate-500">Effet</dt><dd class="mt-1 font-medium">Aucune action opérationnelle</dd></div>
                        </dl>

                        <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4">
                            <a href="{{ route('intelligence.result-batches.download', $batch) }}" class="rf-button-secondary">Télécharger la preuve JSON</a>
                            @if ($batch->decision)
                                <p class="text-sm text-slate-600">
                                    Décision par {{ $batch->decision->actor->name }} · motif <code>{{ $batch->decision->reason_code }}</code>
                                </p>
                            @endif
                        </div>

                        @can('review', $batch)
                            @if (! $batch->decision)
                                <div class="mt-4 grid gap-4 border-t border-slate-200 pt-4 lg:grid-cols-2">
                                    <form method="POST" action="{{ route('intelligence.result-batches.decisions.store', $batch) }}" class="space-y-3 rounded-xl bg-emerald-50 p-4">
                                        @csrf
                                        <input type="hidden" name="decision" value="accepted_for_demo_review">
                                        <label class="block text-sm font-medium text-emerald-950" for="accept-reason-{{ $batch->id }}">Accepter pour revue de démonstration</label>
                                        <select id="accept-reason-{{ $batch->id }}" name="reason_code" required class="block w-full rounded-lg border-emerald-300 text-sm">
                                            @foreach ($acceptReasonCodes as $reasonCode)<option value="{{ $reasonCode }}">{{ $reasonCode }}</option>@endforeach
                                        </select>
                                        <x-confirmation-button type="submit" variant="secondary" message="Consigner cette acceptation humaine sans effet métier ?">Consigner l’acceptation</x-confirmation-button>
                                    </form>
                                    <form method="POST" action="{{ route('intelligence.result-batches.decisions.store', $batch) }}" class="space-y-3 rounded-xl bg-rose-50 p-4">
                                        @csrf
                                        <input type="hidden" name="decision" value="rejected">
                                        <label class="block text-sm font-medium text-rose-950" for="reject-reason-{{ $batch->id }}">Rejeter la preuve</label>
                                        <select id="reject-reason-{{ $batch->id }}" name="reason_code" required class="block w-full rounded-lg border-rose-300 text-sm">
                                            @foreach ($rejectReasonCodes as $reasonCode)<option value="{{ $reasonCode }}">{{ $reasonCode }}</option>@endforeach
                                        </select>
                                        <x-confirmation-button type="submit" variant="danger" message="Consigner ce rejet append-only ?">Consigner le rejet</x-confirmation-button>
                                    </form>
                                </div>
                            @endif
                        @endcan
                    </article>
                @empty
                    <x-empty-state title="Aucun lot J14-B importé" description="L’absence de lot n’altère aucune fonctionnalité opérationnelle." />
                @endforelse
            </div>
            <div class="mt-5">{{ $batches->links() }}</div>
        </x-section-card>
    </div>
</x-app-layout>
