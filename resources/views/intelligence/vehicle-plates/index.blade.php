<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            title="Lecture de plaques marocaines · ANPR hybride"
            eyebrow="Intelligence consultative"
            description="Soumettez une photo du véhicule : le détecteur privé recadre la plaque, l’OCR local propose une lecture, puis un humain confirme ou corrige. La fiche véhicule n’est jamais modifiée automatiquement."
        >
            <x-slot:actions>
                <a href="{{ route('intelligence.index') }}" class="rf-button-secondary">Retour à Intelligence</a>
            </x-slot:actions>
        </x-page-header>

        <x-section-card title="État du pipeline local" description="Détecteur Faster R-CNN privé et OCR arabe PP-OCRv5 dans deux environnements Python isolés.">
            <div class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">Feature flag</p>
                    <p class="mt-1 font-semibold {{ $runtime['enabled'] ? 'text-emerald-700' : 'text-amber-800' }}">{{ $runtime['enabled'] ? 'Activé explicitement' : 'Désactivé par défaut' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">Détection de plaque</p>
                    <p class="mt-1 font-semibold {{ $runtime['detector_ready'] ? 'text-emerald-700' : 'text-amber-800' }}">{{ $runtime['detector_ready'] ? 'Checkpoint vérifié · prêt' : 'Checkpoint privé non configuré' }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $runtime['detector_device'] === 'gpu:0' ? 'GPU local' : 'CPU local' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">Reconnaissance OCR</p>
                    <p class="mt-1 font-semibold {{ $runtime['ocr_ready'] ? 'text-emerald-700' : 'text-amber-800' }}">{{ $runtime['ocr_ready'] ? 'PP-OCRv5 prêt' : 'Runtime OCR non prêt' }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $runtime['ocr_device'] === 'gpu:0' ? 'GPU local' : 'CPU local' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">Effet métier</p>
                    <p class="mt-1 font-semibold text-slate-900">Aucune action automatique</p>
                </div>
            </div>
        </x-section-card>

        <x-section-card title="Pilote privé du 28 août 2026" description="Preuve de fonctionnement et de couverture, pas encore preuve d’exactitude locale.">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">Crops traités</dt><dd class="mt-1 font-semibold">{{ number_format($pilot['total'], 0, ',', ' ') }}</dd></div>
                <div class="rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">Suggestions complètes</dt><dd class="mt-1 font-semibold">{{ number_format($pilot['complete'], 0, ',', ' ') }} · {{ number_format($pilot['complete'] / $pilot['total'] * 100, 2, ',', ' ') }} %</dd></div>
                <div class="rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">Fallback exécuté</dt><dd class="mt-1 font-semibold">{{ number_format($pilot['fallback'], 0, ',', ' ') }} · {{ number_format($pilot['fallback'] / $pilot['total'] * 100, 2, ',', ' ') }} %</dd></div>
                <div class="rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">Corrections historiques</dt><dd class="mt-1 font-semibold">{{ $pilot['reviewed'] }} / {{ number_format($pilot['total'], 0, ',', ' ') }}</dd></div>
            </dl>
            <p class="mt-4 text-xs leading-5 text-amber-800">Les 1 783 lignes restantes doivent encore être vérifiées. Aucune accuracy, exact-match ou aptitude production n’est revendiquée avant cette revue et un jeu de test indépendant.</p>
        </x-section-card>

        @if (auth()->user()->hasPermission('prediction.plate.review'))
            <x-section-card title="Nouvelle analyse privée" description="Photo complète recommandée, ou crop manuel de secours · JPEG, PNG ou WebP · 8 Mo maximum.">
                @if ($canRun)
                    <form method="GET" action="{{ route('intelligence.vehicle-plates.index') }}" class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-end">
                        <div class="min-w-0 flex-1">
                            <x-input-label for="plate-vehicle-search" value="Rechercher un véhicule" />
                            <input id="plate-vehicle-search" name="vehicle_search" value="{{ $vehicleSearch }}" maxlength="100" placeholder="Immatriculation, marque ou modèle" class="mt-1 block w-full rounded-lg border-slate-300">
                        </div>
                        <div class="flex gap-2">
                            <x-primary-button class="justify-center">Rechercher</x-primary-button>
                            @if ($vehicleSearch !== '')
                                <a href="{{ route('intelligence.vehicle-plates.index') }}" class="rf-button-secondary">Effacer</a>
                            @endif
                        </div>
                    </form>
                    <p class="mb-4 text-xs leading-5 text-slate-500">L’image est réencodée sans métadonnées et conservée sur le stockage privé. L’OCR ne reçoit jamais la photo complète : uniquement le crop produit par le détecteur ou fourni manuellement.</p>
                    @unless ($canRunFullImage)
                        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">Le checkpoint privé du détecteur n’est pas encore vérifié sur cette machine. Le mode « crop manuel » reste testable.</div>
                    @endunless
                    <form method="POST" action="{{ route('intelligence.vehicle-plates.store') }}" enctype="multipart/form-data" class="grid gap-4 md:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto] xl:items-end">
                        @csrf
                        <div>
                            <x-input-label for="plate-vehicle" value="Véhicule" required />
                            <select id="plate-vehicle" name="vehicle_id" class="mt-1 block w-full rounded-lg border-slate-300" required>
                                <option value="">Sélectionner un véhicule</option>
                                @foreach ($vehicles as $vehicle)
                                    <option value="{{ $vehicle->id }}" @selected(old('vehicle_id') == $vehicle->id)>{{ $vehicle->registration_number }} · {{ $vehicle->brand }} {{ $vehicle->model }}</option>
                                @endforeach
                            </select>
                            <x-field-error :messages="$errors->get('vehicle_id')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="plate-input-kind" value="Mode d’entrée" required />
                            <select id="plate-input-kind" name="input_kind" class="mt-1 block w-full rounded-lg border-slate-300" required>
                                <option value="full_vehicle_image" @disabled(! $canRunFullImage) @selected(old('input_kind', $canRunFullImage ? 'full_vehicle_image' : 'plate_crop') === 'full_vehicle_image')>Photo complète · détection automatique</option>
                                <option value="plate_crop" @selected(old('input_kind', $canRunFullImage ? 'full_vehicle_image' : 'plate_crop') === 'plate_crop')>Crop manuel · OCR direct</option>
                            </select>
                            <x-field-error :messages="$errors->get('input_kind')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="plate-image" value="Photo ou crop" required />
                            <input id="plate-image" name="image" type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white text-sm" required>
                            <x-field-error :messages="$errors->get('image')" class="mt-2" />
                        </div>
                        <x-primary-button class="justify-center">Lancer l’ANPR</x-primary-button>
                    </form>
                @else
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">Le lancement est fermé. Préchargez les runtimes locaux, testez leurs workers, puis activez explicitement <code>RENTFLEET_PLATE_HYBRID_REVIEW_ENABLED</code>.</div>
                @endif
            </x-section-card>
        @endif

        <x-section-card title="Registre OCR et corrections" description="Runs tenant/agence-scopés, crops privés et corrections append-only réutilisables pour un futur réentraînement contrôlé.">
            <div class="space-y-4">
                @forelse ($runs as $run)
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-mono text-xs text-slate-500">{{ $run->run_id }}</p>
                                @if ($run->vehicle)
                                    <h3 class="mt-1 text-lg font-semibold text-slate-950">{{ $run->vehicle->registration_number }} · {{ $run->vehicle->brand }} {{ $run->vehicle->model }}</h3>
                                @else
                                    <h3 class="mt-1 text-lg font-semibold text-slate-950">Préparation d’un nouveau véhicule</h3>
                                @endif
                            </div>
                            <x-status-badge :value="$run->status" />
                        </div>

                        <div class="mt-4 grid gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
                            <div class="space-y-3">
                                <a href="{{ route('intelligence.vehicle-plates.input', $run) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                                    <img src="{{ route('intelligence.vehicle-plates.input', $run) }}" alt="Image source privée" class="h-32 w-full object-contain" loading="lazy">
                                    <span class="block px-3 py-2 text-center text-xs font-medium text-indigo-700">{{ $run->usesDetector() ? 'Ouvrir la photo source' : 'Ouvrir le crop manuel' }}</span>
                                </a>
                                @if ($run->hasDetectedCrop())
                                    <a href="{{ route('intelligence.vehicle-plates.crop', $run) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-xl border border-emerald-200 bg-emerald-50">
                                        <img src="{{ route('intelligence.vehicle-plates.crop', $run) }}" alt="Crop privé détecté à vérifier" class="h-28 w-full object-contain" loading="lazy">
                                        <span class="block px-3 py-2 text-center text-xs font-medium text-emerald-800">Ouvrir le crop détecté</span>
                                    </a>
                                @endif
                                <p class="text-center text-xs text-slate-500">{{ $run->inputKindLabel() }}</p>
                            </div>
                            <div>
                                @if ($run->status->value === 'succeeded')
                                    <div class="grid gap-3 text-sm sm:grid-cols-3">
                                        <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Suggestion</p><p dir="ltr" class="mt-1 font-mono text-base font-semibold">{{ $run->display_text }}</p></div>
                                        <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Confiance non calibrée</p><p class="mt-1 font-semibold">{{ number_format((float) $run->confidence * 100, 2, ',', ' ') }} %</p></div>
                                        <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Chemin</p><p class="mt-1 font-semibold">{{ $run->suggestionLabel() }}</p></div>
                                    </div>
                                    @if ($run->usesDetector())
                                        <p class="mt-3 text-xs leading-5 text-slate-500">Détection : {{ number_format((float) $run->detector_confidence * 100, 2, ',', ' ') }} % de score non calibré · {{ $run->detector_candidate_count }} candidat(s). Le crop affiché est la seule image transmise à l’OCR.</p>
                                    @endif
                                    <p class="mt-3 text-xs leading-5 text-slate-500">Fallback segmenté : {{ $run->fallback_executed ? 'oui' : 'non' }}. La confiance aide à prioriser la revue ; elle n’est ni une probabilité calibrée ni une autorisation de mise à jour.</p>

                                    @if ($run->review)
                                        <div class="mt-4 rounded-xl border border-slate-200 p-4 text-sm">
                                            <p class="font-semibold">Décision humaine : {{ App\Support\Ui\UiLabel::get($run->review->decision) }}</p>
                                            @if ($run->review->verified_canonical)<p dir="ltr" class="mt-2 font-mono text-base">{{ $run->review->verified_canonical }}</p>@endif
                                            @if ($run->review->note)<p class="mt-2 whitespace-pre-line text-slate-600">{{ $run->review->note }}</p>@endif
                                            <p class="mt-2 text-xs text-slate-500">Consignée le {{ App\Support\Ui\UiLabel::dateTime($run->review->reviewed_at) }} · disponible comme feedback, sans changement de la fiche véhicule.</p>
                                        </div>
                                    @elseif ($canReview && $run->vehicle_id !== null)
                                        <form method="POST" action="{{ route('intelligence.vehicle-plates.reviews.store', $run) }}" class="mt-4 grid gap-3 lg:grid-cols-[minmax(0,11rem)_minmax(0,14rem)_minmax(0,1fr)_auto] lg:items-end">
                                            @csrf
                                            <div>
                                                <x-input-label :for="'plate-decision-'.$run->id" value="Décision" required />
                                                <select id="plate-decision-{{ $run->id }}" name="decision" class="mt-1 block w-full rounded-lg border-slate-300" required>
                                                    @if ($run->hasCompleteSuggestion())<option value="confirmed">Confirmée</option>@endif
                                                    <option value="corrected" @selected(! $run->hasCompleteSuggestion())>Corrigée</option>
                                                    <option value="ignored">Ignorée</option>
                                                </select>
                                            </div>
                                            <div>
                                                <x-input-label :for="'plate-canonical-'.$run->id" value="Plaque vérifiée" />
                                                <x-text-input :id="'plate-canonical-'.$run->id" name="verified_canonical" maxlength="16" dir="ltr" :value="$run->suggested_canonical" placeholder="12345|أ|7" class="mt-1 block w-full font-mono" />
                                            </div>
                                            <div>
                                                <x-input-label :for="'plate-note-'.$run->id" value="Note facultative" />
                                                <x-text-input :id="'plate-note-'.$run->id" name="note" maxlength="500" class="mt-1 block w-full" />
                                            </div>
                                            <x-primary-button class="justify-center">Consigner</x-primary-button>
                                        </form>
                                        <p class="mt-2 text-xs text-slate-500">Si la suggestion complète est juste, laissez-la et choisissez « Confirmée ». Sinon, saisissez la plaque complète et choisissez « Corrigée ».</p>
                                    @endif
                                @elseif ($run->status->value === 'failed')
                                    <p class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">{{ $run->failureLabel() }}</p>
                                @else
                                    <p class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">L’image attend le pipeline détecteur/OCR sur le worker <code>intelligence</code>. Aucun effet métier n’est en attente.</p>
                                @endif
                            </div>
                        </div>
                    </article>
                @empty
                    <x-empty-state title="Aucune analyse de plaque" description="Le registre est vide pour votre périmètre autorisé." />
                @endforelse
            </div>
            <div class="mt-5">{{ $runs->links() }}</div>
        </x-section-card>
    </div>
</x-app-layout>
