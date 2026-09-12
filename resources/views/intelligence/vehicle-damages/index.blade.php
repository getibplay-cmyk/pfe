<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            :title="__('Analyse des dommages')"
            :eyebrow="__('Aide à la décision')"
            :description="__('Analysez une photo privée liée à une inspection de retour, visualisez des zones candidates puis consignez une vérification humaine. Aucun dommage, frais ou responsabilité n’est créé automatiquement.')"
        >
            <x-slot:actions>
                <a href="{{ route('intelligence.index') }}" class="rf-button-secondary"><x-icon name="previous" size="xs" />{{ __('Retour aux analyses') }}</a>
            </x-slot:actions>
        </x-page-header>

        <x-section-card :title="__('Disponibilité du service')" :description="__('Les photos restent privées et chaque résultat doit être validé pendant l’inspection.')">
            <div class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">{{ __('Accès au service') }}</p>
                    <p class="mt-1 font-semibold {{ $runtime['enabled'] ? 'text-emerald-700' : 'text-amber-800' }}">{{ $runtime['enabled'] ? __('Disponible') : __('Désactivé par défaut') }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">{{ __('Installation') }}</p>
                    <p class="mt-1 font-semibold {{ $runtime['artifact_ready'] ? 'text-emerald-700' : 'text-amber-800' }}">{{ $runtime['artifact_ready'] ? __('Prête') : __('À finaliser') }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">{{ __('Confidentialité') }}</p>
                    <p class="mt-1 font-semibold text-slate-900">{{ __('Traitement privé') }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">{{ __('Effet métier') }}</p>
                    <p class="mt-1 font-semibold text-slate-900">{{ __('Aucune action automatique') }}</p>
                </div>
            </div>
        </x-section-card>

        <x-section-card :title="__('Règles d’utilisation')" :description="__('L’analyse assiste l’inspection de retour sans remplacer la vérification du véhicule.')">
            <div class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">{{ __('Zones proposées') }}</p><p class="mt-1 font-semibold">{{ __('À vérifier sur la photo') }}</p></div>
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">{{ __('Validation humaine') }}</p><p class="mt-1 font-semibold">{{ __('Obligatoire') }}</p></div>
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">{{ __('Frais et responsabilité') }}</p><p class="mt-1 font-semibold">{{ __('Décision humaine uniquement') }}</p></div>
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">{{ __('Photos') }}</p><p class="mt-1 font-semibold">{{ __('Conservées en stockage privé') }}</p></div>
            </div>
            <p class="mt-4 text-xs leading-5 text-slate-500">{{ __('Une zone proposée n’est ni une preuve de responsabilité ni une décision de facturation. Elle doit être comparée à l’état réel du véhicule pendant l’inspection.') }}</p>
        </x-section-card>

        @if (auth()->user()->hasPermission('prediction.damage.review'))
            <x-section-card :title="__('Nouvelle analyse de retour')" :description="__('Seules les inspections de retour terminées et autorisées sont proposées.')">
                @if ($canRun)
                    <form method="GET" action="{{ route('intelligence.vehicle-damages.index') }}" class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-end">
                        <div class="min-w-0 flex-1">
                            <x-input-label for="damage-inspection-search" :value="__('Rechercher une plaque, un véhicule ou un contrat')" />
                            <x-text-input id="damage-inspection-search" name="inspection_search" class="mt-1 block w-full" :value="$inspectionSearch" maxlength="100" />
                        </div>
                        <div class="flex gap-2">
                            <x-primary-button class="justify-center">{{ __('Rechercher') }}</x-primary-button>
                            @if ($inspectionSearch !== '')
                                <a href="{{ route('intelligence.vehicle-damages.index') }}" class="rf-button-secondary"><x-icon name="reset" size="xs" />{{ __('Effacer') }}</a>
                            @endif
                        </div>
                    </form>
                    <p class="mb-4 text-xs leading-5 text-slate-500">{{ __('Le sélecteur affiche au maximum') }} {{ $inspectionSelectorLimit }} {{ __('inspections. Affinez la recherche si le retour attendu n’apparaît pas.') }}</p>
                    <form method="POST" action="{{ route('intelligence.vehicle-damages.store') }}" enctype="multipart/form-data" data-loading-form class="grid gap-4 md:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
                        @csrf
                        <div>
                            <x-input-label for="damage-inspection" :value="__('Inspection de retour')" required />
                            <select id="damage-inspection" name="vehicle_inspection_id" class="mt-1 block w-full rounded-lg border-slate-300" required>
                                <option value="">{{ __('Sélectionner une inspection') }}</option>
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
                            :label="__('Photo extérieure du retour')"
                            accept="image/jpeg,image/png,image/webp"
                            formats="JPEG, PNG, WebP"
                            max-size="8 Mo"
                            preview="image"
                            fit="contain"
                            :errors="$errors->get('image')"
                            required
                        />
                        <x-submit-button :label="__('Lancer l’analyse')" loading-label="{{ __('Envoi de la photo…') }}" class="justify-center" />
                    </form>
                @else
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">{{ __('Ce service n’est pas encore disponible. Contactez l’administrateur de la plateforme.') }}</div>
                @endif
            </x-section-card>
        @endif

        <x-section-card :title="__('Historique des analyses')" :description="__('Consultez les photos, les zones proposées et les décisions enregistrées pour votre périmètre autorisé.')">
            <div class="space-y-4">
                @forelse ($runs as $run)
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="mt-1 text-lg font-semibold text-slate-950">{{ $run->vehicle->registration_number }} · {{ $run->vehicle->brand }} {{ $run->vehicle->model }}</h3>
                                <p class="text-sm text-slate-600">{{ __('Inspection de retour · contrat') }} {{ $run->inspection->rentalContract->contract_number }}</p>
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
                                                            aria-label="{{ __('Zone candidate ') }}{{ $index + 1 }}{{ __(', probabilité ') }}{{ App\Support\Ui\BusinessNumber::confidence($region['probability']) }}"
                                                        ><span class="bg-red-600 px-1">{{ $index + 1 }}</span></span>
                                                    @endforeach
                                                @endif
                                            </x-slot:overlay>
                                        </x-photo-frame>
                                    </div>
                                    <span class="block bg-white px-3 py-2 text-center text-xs font-medium text-indigo-700">{{ __('Ouvrir la photo privée') }}</span>
                                </a>
                            </div>

                            <div>
                                @if ($run->status->value === 'succeeded')
                                    <a class="rf-button-secondary mb-4" href="{{ route('annotations.edit', ['family' => 'damage', 'run' => $run->run_id]) }}">{{ __('Vérifier les annotations pour l’apprentissage') }}</a>
                                @endif
                                @if ($run->status->value === 'succeeded')
                                    <div class="grid gap-3 text-sm sm:grid-cols-3">
                                        <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">{{ __('Analyse proposée') }}</p><p class="mt-1 font-semibold">{{ $run->outcomeLabel() }}</p></div>
                                        <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">{{ __('Niveau de confiance maximal') }}</p><p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::confidence($run->max_probability_damage) }}</p></div>
                                        <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">{{ __('Zones affichées') }}</p><p class="mt-1 font-semibold">{{ count($run->candidate_regions ?? []) }}</p></div>
                                    </div>

                                    @if ($run->quality_status === 'abstained')
                                        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                                            <p class="font-semibold">{{ __('Abstention qualité — ne pas interpréter comme une absence de dommage.') }}</p>
                                            <ul class="mt-2 list-disc space-y-1 ps-5">
                                                @foreach ($run->qualityReasonLabels() as $reason)<li>{{ $reason }}</li>@endforeach
                                            </ul>
                                        </div>
                                    @elseif ($run->suggested_damage)
                                        <p class="mt-4 text-sm leading-6 text-amber-900">{{ __('Les cadres rouges indiquent des zones candidates. Ils ne délimitent pas précisément un dommage.') }}</p>
                                    @else
                                        <p class="mt-4 text-sm leading-6 text-slate-600">{{ __('Aucune zone de dommage suffisamment fiable n’a été proposée. Vérifiez néanmoins toute la photo et le véhicule.') }}</p>
                                    @endif

                                    @if ($run->review)
                                        <div class="mt-4 rounded-xl border border-slate-200 p-4 text-sm">
                                            <p class="font-semibold">{{ __('Décision humaine :') }} {{ App\Support\Ui\UiLabel::get($run->review->decision) }}</p>
                                            @if ($run->review->note)<p class="mt-2 whitespace-pre-line text-slate-600">{{ $run->review->note }}</p>@endif
                                            <p class="mt-2 text-xs text-slate-500">{{ __('Consignée le') }} {{ App\Support\Ui\UiLabel::dateTime($run->review->reviewed_at) }} {{ __('· aucune création automatique de dommage, frais ou responsabilité.') }}</p>
                                        </div>
                                    @elseif ($canReview)
                                        <form method="POST" action="{{ route('intelligence.vehicle-damages.reviews.store', $run) }}" class="mt-4 grid gap-3 md:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_auto] md:items-end">
                                            @csrf
                                            <div>
                                                <x-input-label :for="'damage-decision-'.$run->id" :value="__('Vérification humaine')" required />
                                                <select id="damage-decision-{{ $run->id }}" name="decision" class="mt-1 block w-full rounded-lg border-slate-300" required>
                                                    @if ($run->quality_status === 'usable' && $run->suggested_damage)<option value="confirmed">{{ __('Confirmer une zone visible') }}</option>@endif
                                                    <option value="rejected">{{ __('Rejeter la proposition') }}</option>
                                                    <option value="new_photo_required">{{ __('Demander une nouvelle photo') }}</option>
                                                </select>
                                            </div>
                                            <div>
                                                <x-input-label :for="'damage-note-'.$run->id" :value="__('Note facultative')" />
                                                <x-text-input :id="'damage-note-'.$run->id" name="note" maxlength="500" class="mt-1 block w-full" />
                                            </div>
                                            <x-primary-button class="justify-center">{{ __('Consigner') }}</x-primary-button>
                                        </form>
                                    @endif
                                @elseif ($run->status->value === 'failed')
                                    <p class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">{{ $run->failureLabel() }}</p>
                                @else
                                    <p class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">{{ __('Analyse en cours. Aucune décision ni aucun frais ne sont appliqués automatiquement.') }}</p>
                                @endif
                            </div>
                        </div>
                    </article>
                @empty
                    <x-empty-state :title="__('Aucune analyse de dommages')" :description="__('Le registre est vide pour votre périmètre autorisé.')" />
                @endforelse
            </div>
            <div class="mt-5">{{ $runs->links() }}</div>
        </x-section-card>
    </div>
</x-app-layout>
