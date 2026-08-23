<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            title="Couleur du véhicule · modèle S7 v8"
            eyebrow="Intelligence consultative"
            description="Soumettez une photo privée, consultez la suggestion ONNX puis consignez une décision humaine. La couleur enregistrée du véhicule n’est jamais modifiée automatiquement."
        >
            <x-slot:actions>
                <a href="{{ route('intelligence.index') }}" class="rf-button-secondary">Retour à Intelligence</a>
            </x-slot:actions>
        </x-page-header>

        <x-section-card title="État du runtime" description="Activation explicite, artefacts gelés et exécution asynchrone sur la queue Intelligence.">
            <div class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">Feature flag</p>
                    <p class="mt-1 font-semibold {{ $runtime['enabled'] ? 'text-emerald-700' : 'text-amber-800' }}">{{ $runtime['enabled'] ? 'Activé' : 'Désactivé par défaut' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">Paire ONNX / métadonnées</p>
                    <p class="mt-1 font-semibold {{ $runtime['artifact_ready'] ? 'text-emerald-700' : 'text-amber-800' }}">{{ $runtime['artifact_ready'] ? 'SHA-256 vérifiés' : 'Installation requise' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">Calcul</p>
                    <p class="mt-1 font-semibold text-slate-900">{{ $runtime['provider'] === 'CUDAExecutionProvider' ? 'GPU CUDA' : 'CPU ONNX Runtime' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">Effet métier</p>
                    <p class="mt-1 font-semibold text-slate-900">Aucune action automatique</p>
                </div>
            </div>
        </x-section-card>

        <x-section-card title="Gate scientifique final indépendant" description="Mesures gelées sur l’évaluation externe finale, avec abstention au seuil 0,977.">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-slate-500">Macro-F1</dt><dd class="font-semibold">{{ number_format($contract['macro_f1'], 6, ',', ' ') }}</dd></div>
                <div><dt class="text-slate-500">Balanced accuracy</dt><dd class="font-semibold">{{ number_format($contract['balanced_accuracy'] * 100, 3, ',', ' ') }} %</dd></div>
                <div><dt class="text-slate-500">Rappel minimal</dt><dd class="font-semibold">{{ number_format($contract['minimum_recall'] * 100, 1, ',', ' ') }} %</dd></div>
                <div><dt class="text-slate-500">ECE</dt><dd class="font-semibold">{{ number_format($contract['ece'], 5, ',', ' ') }}</dd></div>
                <div><dt class="text-slate-500">Précision acceptée</dt><dd class="font-semibold">{{ number_format($contract['accepted_precision'] * 100, 0, ',', ' ') }} %</dd></div>
                <div><dt class="text-slate-500">Couverture</dt><dd class="font-semibold">{{ number_format($contract['coverage'] * 100, 3, ',', ' ') }} %</dd></div>
                <div><dt class="text-slate-500">Fausse acceptation rejet</dt><dd class="font-semibold">{{ number_format($contract['reject_false_acceptance'] * 100, 1, ',', ' ') }} %</dd></div>
                <div><dt class="text-slate-500">Version</dt><dd class="font-mono text-xs font-semibold">{{ $contract['model_version'] }}</dd></div>
            </dl>
            <p class="mt-4 text-xs leading-5 text-slate-500">Ces résultats qualifient l’artefact gelé sur son protocole externe ; ils ne garantissent pas une exactitude identique sur toutes les photos RentFleet. L’abstention et la validation humaine restent obligatoires.</p>
        </x-section-card>

        @if (auth()->user()->hasPermission('prediction.color.review'))
            <x-section-card title="Nouvelle analyse privée" description="JPEG, PNG ou WebP · 8 Mo maximum · 8 000 × 8 000 pixels maximum.">
                @if ($canRun)
                    <form method="GET" action="{{ route('intelligence.vehicle-colors.index') }}" class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-end">
                        <div class="min-w-0 flex-1">
                            <x-input-label for="color-vehicle-search" value="Rechercher un véhicule" />
                            <input id="color-vehicle-search" name="vehicle_search" value="{{ $vehicleSearch }}" maxlength="100" placeholder="Immatriculation, marque ou modèle" class="mt-1 block w-full rounded-lg border-slate-300">
                        </div>
                        <div class="flex gap-2">
                            <x-primary-button class="justify-center">Rechercher</x-primary-button>
                            @if ($vehicleSearch !== '')
                                <a href="{{ route('intelligence.vehicle-colors.index') }}" class="rf-button-secondary">Effacer</a>
                            @endif
                        </div>
                    </form>
                    <p class="mb-4 text-xs leading-5 text-slate-500">Le sélecteur affiche au maximum {{ $vehicleSelectorLimit }} véhicules. Affinez la recherche si le véhicule attendu n’apparaît pas.</p>
                    <form method="POST" action="{{ route('intelligence.vehicle-colors.store') }}" enctype="multipart/form-data" class="grid gap-4 md:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
                        @csrf
                        <div>
                            <x-input-label for="color-vehicle" value="Véhicule" required />
                            <select id="color-vehicle" name="vehicle_id" class="mt-1 block w-full rounded-lg border-slate-300" required>
                                <option value="">Sélectionner un véhicule</option>
                                @foreach ($vehicles as $vehicle)
                                    <option value="{{ $vehicle->id }}" @selected(old('vehicle_id') == $vehicle->id)>{{ $vehicle->registration_number }} · {{ $vehicle->brand }} {{ $vehicle->model }} · couleur actuelle : {{ $vehicle->color ?: 'non renseignée' }}</option>
                                @endforeach
                            </select>
                            <x-field-error :messages="$errors->get('vehicle_id')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="color-image" value="Photo du véhicule" required />
                            <input id="color-image" name="image" type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white text-sm" required>
                            <x-field-error :messages="$errors->get('image')" class="mt-2" />
                        </div>
                        <x-primary-button class="justify-center">Lancer l’analyse</x-primary-button>
                    </form>
                @else
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">Le lancement est fermé. Installez la paire authentique, vérifiez le runtime avec <code>rentfleet:doctor</code>, puis activez explicitement <code>RENTFLEET_COLOR_V8_ENABLED</code>.</div>
                @endif
            </x-section-card>
        @endif

        <x-section-card title="Registre des analyses" description="Entrées tenant/agence-scopées, résultats consultatifs et revues append-only.">
            <div class="space-y-4">
                @forelse ($runs as $run)
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-mono text-xs text-slate-500">{{ $run->run_id }}</p>
                                <h3 class="mt-1 text-lg font-semibold text-slate-950">{{ $run->vehicle->registration_number }} · {{ $run->vehicle->brand }} {{ $run->vehicle->model }}</h3>
                                <p class="text-sm text-slate-600">Couleur actuellement enregistrée : <span class="font-medium">{{ $run->vehicle->color ?: 'non renseignée' }}</span></p>
                            </div>
                            <x-status-badge :value="$run->status" />
                        </div>

                        <div class="mt-4 grid gap-4 lg:grid-cols-[12rem_minmax(0,1fr)]">
                            <a href="{{ route('intelligence.vehicle-colors.input', $run) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                                <img src="{{ route('intelligence.vehicle-colors.input', $run) }}" alt="Photo privée soumise pour {{ $run->vehicle->registration_number }}" class="h-40 w-full object-cover" loading="lazy">
                                <span class="block px-3 py-2 text-center text-xs font-medium text-indigo-700">Ouvrir la photo privée</span>
                            </a>
                            <div>
                                @if ($run->status->value === 'succeeded')
                                    <div class="grid gap-3 text-sm sm:grid-cols-3">
                                        <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Suggestion</p><p class="mt-1 font-semibold">{{ $run->outcomeLabel() }}</p></div>
                                        <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Confiance supportée</p><p class="mt-1 font-semibold">{{ number_format((float) $run->confidence * 100, 2, ',', ' ') }} %</p></div>
                                        <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Politique du modèle</p><p class="mt-1 font-semibold {{ $run->model_accepted ? 'text-emerald-700' : 'text-amber-800' }}">{{ $run->model_accepted ? 'Acceptable pour revue humaine' : 'Abstention obligatoire' }}</p></div>
                                    </div>

                                    @if ($run->review)
                                        <div class="mt-4 rounded-xl border border-slate-200 p-4 text-sm">
                                            <p class="font-semibold">Décision humaine : {{ App\Support\Ui\UiLabel::get($run->review->decision) }}</p>
                                            @if ($run->review->note)<p class="mt-2 whitespace-pre-line text-slate-600">{{ $run->review->note }}</p>@endif
                                            <p class="mt-2 text-xs text-slate-500">Consignée le {{ App\Support\Ui\UiLabel::dateTime($run->review->reviewed_at) }} · aucun changement automatique de la fiche véhicule.</p>
                                        </div>
                                    @elseif ($canReview)
                                        <form method="POST" action="{{ route('intelligence.vehicle-colors.reviews.store', $run) }}" class="mt-4 grid gap-3 md:grid-cols-[minmax(0,12rem)_minmax(0,1fr)_auto] md:items-end">
                                            @csrf
                                            <div>
                                                <x-input-label :for="'color-decision-'.$run->id" value="Décision humaine" required />
                                                <select id="color-decision-{{ $run->id }}" name="decision" class="mt-1 block w-full rounded-lg border-slate-300" required>
                                                    @if ($run->model_accepted)<option value="accepted">Accepter la suggestion</option>@endif
                                                    <option value="rejected">Rejeter la suggestion</option>
                                                    <option value="ignored">Ignorer cette analyse</option>
                                                </select>
                                            </div>
                                            <div>
                                                <x-input-label :for="'color-note-'.$run->id" value="Note facultative" />
                                                <x-text-input :id="'color-note-'.$run->id" name="note" maxlength="500" class="mt-1 block w-full" />
                                            </div>
                                            <x-primary-button class="justify-center">Consigner</x-primary-button>
                                        </form>
                                    @endif
                                @elseif ($run->status->value === 'failed')
                                    <p class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">{{ $run->failureLabel() }}</p>
                                @else
                                    <p class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">L’image attend son traitement par le worker <code>intelligence</code>. Aucun effet métier n’est en attente.</p>
                                @endif
                            </div>
                        </div>
                    </article>
                @empty
                    <x-empty-state title="Aucune analyse couleur" description="Le registre est vide pour votre périmètre autorisé." />
                @endforelse
            </div>
            <div class="mt-5">{{ $runs->links() }}</div>
        </x-section-card>
    </div>
</x-app-layout>
