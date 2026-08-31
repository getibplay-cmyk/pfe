<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            title="Analyse des dommages"
            eyebrow="Aide à la décision"
            description="Analysez une photo privée liée à une inspection de retour, visualisez des zones candidates puis consignez une vérification humaine. Aucun dommage, frais ou responsabilité n’est créé automatiquement."
        >
            <x-slot:actions>
                <a href="{{ route('intelligence.index') }}" class="rf-button-secondary"><x-icon name="previous" size="xs" />Retour aux analyses</a>
            </x-slot:actions>
        </x-page-header>

        <x-section-card title="Disponibilité du service" description="Les photos restent privées et chaque résultat doit être validé pendant l’inspection.">
            <div class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">Accès au service</p>
                    <p class="mt-1 font-semibold {{ $runtime['enabled'] ? 'text-emerald-700' : 'text-amber-800' }}">{{ $runtime['enabled'] ? 'Disponible' : 'Désactivé par défaut' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">Fichiers nécessaires</p>
                    <p class="mt-1 font-semibold {{ $runtime['artifact_ready'] ? 'text-emerald-700' : 'text-amber-800' }}">{{ $runtime['artifact_ready'] ? 'Vérifiés' : 'Installation requise' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">Traitement</p>
                    <p class="mt-1 font-semibold text-slate-900">{{ $runtime['provider'] === 'CUDAExecutionProvider' ? 'Accéléré' : 'Local' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">Effet métier</p>
                    <p class="mt-1 font-semibold text-slate-900">Aucune action automatique</p>
                </div>
            </div>
        </x-section-card>

        @if ($contract['backend'] === 'rtdetrv2_s')
            <x-section-card title="Pilote RT-DETRv2-S" description="Checkpoint soup 19/24/29 optimisé sur la validation de développement, sans calibration ni consultation du test final.">
                <div class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">AP validation</p><p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::confidence($contract['validation_ap']) }}</p></div>
                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">AP50 validation</p><p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::confidence($contract['validation_ap50']) }}</p></div>
                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">AP75 validation</p><p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::confidence($contract['validation_ap75']) }}</p></div>
                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Seuil précision 90</p><p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::confidence($contract['decision_threshold']) }}</p></div>
                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Précision IoU50 au seuil</p><p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::confidence($contract['validation_precision_iou50']) }}</p></div>
                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Rappel IoU50 au seuil</p><p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::confidence($contract['validation_recall_iou50']) }}</p></div>
                </div>
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                    Le profil retenu privilégie fortement la précision, au prix d’un rappel de 22,59 %. Le gate scientifique AP ≥ 40 % et AP50 ≥ 65 % n’est pas atteint. Ce backend est donc limité à un pilote consultatif avec revue humaine obligatoire ; ses boîtes ne constituent ni une segmentation précise ni une preuve de responsabilité.
                </div>
            </x-section-card>
        @else
            <x-section-card title="Qualification scientifique v1.1" :description="'Résultats du test final gelé sur la source publique HITL; la validation locale '.config('brand.name').' reste à réaliser.'">
                <div class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-5">
                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Balanced accuracy</p><p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::confidence($contract['balanced_accuracy']) }}</p></div>
                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Macro-F1</p><p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::confidence($contract['macro_f1']) }}</p></div>
                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Rappel dommage</p><p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::confidence($contract['damage_recall']) }}</p></div>
                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">ECE</p><p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::scientificDecimal($contract['ece'], 4, 4) }}</p></div>
                    <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Seuil de patch calibré</p><p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::confidence($contract['decision_threshold']) }}</p></div>
                </div>
                <p class="mt-4 text-xs leading-5 text-slate-500">Le plancher de qualification de 75 % concerne les métriques du modèle, pas le seuil d’inférence. Le seuil de patch 49,5 % a été choisi sur le split de calibration. Une région affichée reste une proposition à contrôler, jamais une segmentation précise ni une preuve de responsabilité.</p>
            </x-section-card>
        @endif

        @if (auth()->user()->hasPermission('prediction.damage.review'))
            <x-section-card title="Nouvelle analyse de retour" description="Seules les inspections de retour terminées et autorisées sont proposées.">
                @if ($canRun)
                    <form method="GET" action="{{ route('intelligence.vehicle-damages.index') }}" class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-end">
                        <div class="min-w-0 flex-1">
                            <x-input-label for="damage-inspection-search" value="Rechercher une plaque, un véhicule ou un contrat" />
                            <x-text-input id="damage-inspection-search" name="inspection_search" class="mt-1 block w-full" :value="$inspectionSearch" maxlength="100" />
                        </div>
                        <div class="flex gap-2">
                            <x-primary-button class="justify-center">Rechercher</x-primary-button>
                            @if ($inspectionSearch !== '')
                                <a href="{{ route('intelligence.vehicle-damages.index') }}" class="rf-button-secondary"><x-icon name="reset" size="xs" />Effacer</a>
                            @endif
                        </div>
                    </form>
                    <p class="mb-4 text-xs leading-5 text-slate-500">Le sélecteur affiche au maximum {{ $inspectionSelectorLimit }} inspections. Affinez la recherche si le retour attendu n’apparaît pas.</p>
                    <form method="POST" action="{{ route('intelligence.vehicle-damages.store') }}" enctype="multipart/form-data" data-loading-form class="grid gap-4 md:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
                        @csrf
                        <div>
                            <x-input-label for="damage-inspection" value="Inspection de retour" required />
                            <select id="damage-inspection" name="vehicle_inspection_id" class="mt-1 block w-full rounded-lg border-slate-300" required>
                                <option value="">Sélectionner une inspection</option>
                                @foreach ($inspections as $inspection)
                                    <option value="{{ $inspection->id }}" @selected(old('vehicle_inspection_id') == $inspection->id)>
                                        {{ $inspection->vehicle->registration_number }} · {{ $inspection->vehicle->brand }} {{ $inspection->vehicle->model }} · {{ $inspection->rentalContract->contract_number }} · {{ App\Support\Ui\UiLabel::dateTime($inspection->completed_at) }}
                                    </option>
                                @endforeach
                            </select>
                            <x-field-error :messages="$errors->get('vehicle_inspection_id')" class="mt-2" />
                        </div>
                        <x-file-input
                            id="damage-image"
                            name="image"
                            label="Photo extérieure du retour"
                            accept="image/jpeg,image/png,image/webp"
                            formats="JPEG, PNG, WebP"
                            max-size="8 Mo"
                            preview="image"
                            fit="contain"
                            :errors="$errors->get('image')"
                            required
                        />
                        <x-submit-button label="Lancer l’analyse" loading-label="Envoi de la photo…" class="justify-center" />
                    </form>
                @else
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">Le lancement est fermé. Installez l’ONNX et sa carte depuis le Drive privé, vérifiez le runtime avec <code>rentfleet:doctor</code>, puis activez explicitement <code>RENTFLEET_DAMAGE_V1_ENABLED</code>.</div>
                @endif
            </x-section-card>
        @endif

        <x-section-card title="Registre des analyses" description="Résultats tenant/agence-scopés, photos privées et revues humaines append-only.">
            <div class="space-y-4">
                @forelse ($runs as $run)
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-mono text-xs text-slate-500">{{ $run->run_id }}</p>
                                <h3 class="mt-1 text-lg font-semibold text-slate-950">{{ $run->vehicle->registration_number }} · {{ $run->vehicle->brand }} {{ $run->vehicle->model }}</h3>
                                <p class="text-sm text-slate-600">Inspection de retour · contrat {{ $run->inspection->rentalContract->contract_number }}</p>
                            </div>
                            <x-status-badge :value="$run->status" />
                        </div>

                        <div class="mt-4 grid gap-5 lg:grid-cols-[minmax(16rem,28rem)_minmax(0,1fr)]">
                            <div>
                                <a href="{{ route('intelligence.vehicle-damages.input', $run) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-xl border border-slate-200 bg-slate-950">
                                    @php
                                        $overlayWidthRem = 32 * $run->input_width / $run->input_height;
                                    @endphp
                                    <div class="flex justify-center bg-slate-950 p-2">
                                        <x-photo-frame
                                            :src="route('intelligence.vehicle-damages.input', $run)"
                                            alt="Photo privée de l’inspection de retour"
                                            kind="evidence"
                                            fit="contain"
                                            class="border-slate-700 bg-slate-950"
                                            data-damage-overlay-frame
                                            style="aspect-ratio: {{ $run->input_width }} / {{ $run->input_height }}; width: min(100%, {{ number_format($overlayWidthRem, 4, '.', '') }}rem);"
                                        >
                                            <x-slot:overlay>
                                                @if ($run->status->value === 'succeeded' && $run->quality_status === 'usable')
                                                    @foreach ($run->candidate_regions ?? [] as $index => $region)
                                                        @php
                                                            $left = 100 * $region['x'] / $run->input_width;
                                                            $top = 100 * $region['y'] / $run->input_height;
                                                            $width = 100 * $region['width'] / $run->input_width;
                                                            $height = 100 * $region['height'] / $run->input_height;
                                                        @endphp
                                                        <span
                                                            class="absolute border-2 border-red-500 bg-red-500/10 text-[10px] font-bold text-white shadow"
                                                            style="left: {{ number_format($left, 4, '.', '') }}%; top: {{ number_format($top, 4, '.', '') }}%; width: {{ number_format($width, 4, '.', '') }}%; height: {{ number_format($height, 4, '.', '') }}%;"
                                                            aria-label="Zone candidate {{ $index + 1 }}, probabilité {{ App\Support\Ui\BusinessNumber::confidence($region['probability']) }}"
                                                        ><span class="bg-red-600 px-1">{{ $index + 1 }}</span></span>
                                                    @endforeach
                                                @endif
                                            </x-slot:overlay>
                                        </x-photo-frame>
                                    </div>
                                    <span class="block bg-white px-3 py-2 text-center text-xs font-medium text-indigo-700">Ouvrir la photo privée</span>
                                </a>
                            </div>

                            <div>
                                @if ($run->status->value === 'succeeded')
                                    <div class="grid gap-3 text-sm sm:grid-cols-3">
                                        <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Résultat consultatif</p><p class="mt-1 font-semibold">{{ $run->outcomeLabel() }}</p></div>
                                        <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Score maximal</p><p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::confidence($run->max_probability_damage) }}</p></div>
                                        <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Zones affichées</p><p class="mt-1 font-semibold">{{ count($run->candidate_regions ?? []) }}</p></div>
                                    </div>

                                    @if ($run->quality_status === 'abstained')
                                        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                                            <p class="font-semibold">Abstention qualité — ne pas interpréter comme une absence de dommage.</p>
                                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                                @foreach ($run->qualityReasonLabels() as $reason)<li>{{ $reason }}</li>@endforeach
                                            </ul>
                                        </div>
                                    @elseif ($run->suggested_damage)
                                        <p class="mt-4 text-sm leading-6 text-amber-900">Les cadres rouges indiquent des zones candidates. Ils ne délimitent pas précisément un dommage.</p>
                                    @else
                                        <p class="mt-4 text-sm leading-6 text-slate-600">Aucune détection ne franchit le seuil sélectionné. Ce résultat n’exclut pas un dommage hors champ, minuscule ou différent du domaine d’entraînement.</p>
                                    @endif

                                    @if ($run->review)
                                        <div class="mt-4 rounded-xl border border-slate-200 p-4 text-sm">
                                            <p class="font-semibold">Décision humaine : {{ App\Support\Ui\UiLabel::get($run->review->decision) }}</p>
                                            @if ($run->review->note)<p class="mt-2 whitespace-pre-line text-slate-600">{{ $run->review->note }}</p>@endif
                                            <p class="mt-2 text-xs text-slate-500">Consignée le {{ App\Support\Ui\UiLabel::dateTime($run->review->reviewed_at) }} · aucune création automatique de dommage, frais ou responsabilité.</p>
                                        </div>
                                    @elseif ($canReview)
                                        <form method="POST" action="{{ route('intelligence.vehicle-damages.reviews.store', $run) }}" class="mt-4 grid gap-3 md:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_auto] md:items-end">
                                            @csrf
                                            <div>
                                                <x-input-label :for="'damage-decision-'.$run->id" value="Vérification humaine" required />
                                                <select id="damage-decision-{{ $run->id }}" name="decision" class="mt-1 block w-full rounded-lg border-slate-300" required>
                                                    @if ($run->quality_status === 'usable' && $run->suggested_damage)<option value="confirmed">Confirmer une zone visible</option>@endif
                                                    <option value="rejected">Rejeter la proposition</option>
                                                    <option value="new_photo_required">Demander une nouvelle photo</option>
                                                </select>
                                            </div>
                                            <div>
                                                <x-input-label :for="'damage-note-'.$run->id" value="Note facultative" />
                                                <x-text-input :id="'damage-note-'.$run->id" name="note" maxlength="500" class="mt-1 block w-full" />
                                            </div>
                                            <x-primary-button class="justify-center">Consigner</x-primary-button>
                                        </form>
                                    @endif
                                @elseif ($run->status->value === 'failed')
                                    <p class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">{{ $run->failureLabel() }}</p>
                                @else
                                    <p class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">La photo attend son traitement par le worker <code>intelligence</code>. Aucun effet métier n’est en attente.</p>
                                @endif
                            </div>
                        </div>
                    </article>
                @empty
                    <x-empty-state title="Aucune analyse dommages" description="Le registre est vide pour votre périmètre autorisé." />
                @endforelse
            </div>
            <div class="mt-5">{{ $runs->links() }}</div>
        </x-section-card>
    </div>
</x-app-layout>
