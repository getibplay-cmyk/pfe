<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header
            title="Couleur suggérée"
            eyebrow="Aide à la décision"
            description="Soumettez une photo privée, consultez la couleur la plus probable puis consignez une décision humaine. La couleur enregistrée du véhicule n’est jamais modifiée automatiquement."
        >
            <x-slot:actions>
                <a href="{{ route('intelligence.index') }}" class="rf-button-secondary"><x-icon name="previous" size="xs" />Retour aux analyses</a>
            </x-slot:actions>
        </x-page-header>

        <x-section-card title="Disponibilité du service" description="Les photos restent privées et chaque suggestion doit être confirmée par un utilisateur.">
            <div class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">Accès au service</p>
                    <p class="mt-1 font-semibold {{ $runtime['enabled'] ? 'text-emerald-700' : 'text-amber-800' }}">{{ $runtime['enabled'] ? 'Disponible' : 'Désactivé par défaut' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">Installation</p>
                    <p class="mt-1 font-semibold {{ $runtime['artifact_ready'] ? 'text-emerald-700' : 'text-amber-800' }}">{{ $runtime['artifact_ready'] ? 'Prête' : 'À finaliser' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">Confidentialité</p>
                    <p class="mt-1 font-semibold text-slate-900">Traitement privé</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-slate-500">Effet métier</p>
                    <p class="mt-1 font-semibold text-slate-900">Aucune action automatique</p>
                </div>
            </div>
        </x-section-card>

        <x-section-card title="Règles d’utilisation" description="La suggestion accélère la saisie sans remplacer votre vérification.">
            <div class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Suggestion</p><p class="mt-1 font-semibold">À vérifier sur la photo</p></div>
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Confirmation humaine</p><p class="mt-1 font-semibold">Obligatoire</p></div>
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Fiche véhicule</p><p class="mt-1 font-semibold">Jamais modifiée automatiquement</p></div>
                <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Photo</p><p class="mt-1 font-semibold">Conservée en stockage privé</p></div>
            </div>
            <p class="mt-4 text-xs leading-5 text-slate-500">Vous pouvez accepter, corriger ou ignorer la couleur proposée. Comparez toujours la suggestion avec la photo et le véhicule.</p>
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
                                <a href="{{ route('intelligence.vehicle-colors.index') }}" class="rf-button-secondary"><x-icon name="reset" size="xs" />Effacer</a>
                            @endif
                        </div>
                    </form>
                    <p class="mb-4 text-xs leading-5 text-slate-500">Le sélecteur affiche au maximum {{ $vehicleSelectorLimit }} véhicules. Affinez la recherche si le véhicule attendu n’apparaît pas.</p>
                    <form method="POST" action="{{ route('intelligence.vehicle-colors.store') }}" enctype="multipart/form-data" data-loading-form class="grid gap-4 md:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
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
                        <x-file-input
                            id="color-image"
                            name="image"
                            label="Photo du véhicule"
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
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">Ce service n’est pas encore disponible. Contactez l’administrateur de la plateforme.</div>
                @endif
            </x-section-card>
        @endif

        <x-section-card title="Historique des analyses" description="Consultez les suggestions et les décisions enregistrées pour votre périmètre autorisé.">
            <div class="space-y-4">
                @forelse ($runs as $run)
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                @if ($run->vehicle)
                                    <h3 class="mt-1 text-lg font-semibold text-slate-950">{{ $run->vehicle->registration_number }} · {{ $run->vehicle->brand }} {{ $run->vehicle->model }}</h3>
                                    <p class="text-sm text-slate-600">Couleur actuellement enregistrée : <span class="font-medium">{{ $run->vehicle->color ?: 'non renseignée' }}</span></p>
                                @else
                                    <h3 class="mt-1 text-lg font-semibold text-slate-950">Préparation d’un nouveau véhicule</h3>
                                    <p class="text-sm text-slate-600">La fiche véhicule n’a pas encore été enregistrée.</p>
                                @endif
                            </div>
                            <x-status-badge :value="$run->status" />
                        </div>

                        <div class="mt-4 grid gap-4 lg:grid-cols-[12rem_minmax(0,1fr)]">
                            <a href="{{ route('intelligence.vehicle-colors.input', $run) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-2xl border border-belkhir-space-border bg-belkhir-space-canvas transition duration-150 hover:-translate-y-0.5 hover:shadow-lg motion-reduce:transform-none">
                                <x-photo-frame :src="route('intelligence.vehicle-colors.input', $run)" alt="Photo privée soumise pour l’analyse de couleur" kind="evidence" fit="contain" class="rounded-none border-0" />
                                <span class="block px-3 py-2 text-center text-xs font-medium text-indigo-700">Ouvrir la photo privée</span>
                            </a>
                            <div>
                                @if ($run->status->value === 'succeeded')
                                    @if ($run->hasDisplayableCandidate())
                                        <div class="grid gap-3 text-sm sm:grid-cols-3">
                                            <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Couleur la plus probable</p><p class="mt-1 font-semibold">{{ $run->outcomeLabel() }}</p></div>
                                            <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">Niveau de confiance</p><p class="mt-1 font-semibold">{{ App\Support\Ui\BusinessNumber::confidence($run->confidence) }}</p></div>
                                            <div class="rounded-xl bg-slate-50 p-3"><p class="text-slate-500">État de la suggestion</p><p class="mt-1"><x-status-badge :value="$run->consultativeStatus()" /></p></div>
                                        </div>
                                        @if ($run->hasLowConfidenceCandidate())
                                            <div class="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm leading-6 text-red-900">
                                                <p class="font-semibold">Suggestion à faible confiance — vérification visuelle obligatoire.</p>
                                                <p>Comparez directement la photo au véhicule avant toute décision humaine.</p>
                                            </div>
                                        @endif
                                        <p class="mt-3 text-xs leading-5 text-amber-800">Vérifiez toujours la photo avant d’accepter la suggestion. Aucune modification de la fiche véhicule n’est automatique.</p>
                                    @else
                                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                                            <p class="font-semibold">{{ $run->outcomeLabel() }}</p>
                                            <p class="mt-1">Aucune couleur valide n’est disponible pour cette analyse.</p>
                                        </div>
                                    @endif

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
                                                    @if ($run->hasDisplayableCandidate() && $run->model_accepted)<option value="accepted">Accepter la suggestion</option>@endif
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
                                    <p class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">Analyse en cours. Vous pouvez continuer à utiliser le formulaire : aucune modification n’est appliquée automatiquement.</p>
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
