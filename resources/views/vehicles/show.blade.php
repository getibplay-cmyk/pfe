<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page-header :title="$vehicle->registration_number.' · '.$vehicle->brand.' '.$vehicle->model" :eyebrow="$vehicle->category->name" :description="__('Fiche opérationnelle du véhicule, de ses indisponibilités et de ses documents privés.')" :breadcrumbs="[['label' => __('Véhicules'), 'url' => route('vehicles.index')], ['label' => $vehicle->registration_number]]">
            <x-slot:actions>
                <a href="{{ route('vehicles.index') }}" class="rf-button-secondary"><x-icon name="previous" size="xs" />{{ __('Retour aux véhicules') }}</a>
                @can('viewAny', App\Models\VehicleBlock::class)<a href="{{ route('vehicle-blocks.index', ['vehicle_id' => $vehicle->id]) }}" class="rf-button-secondary">{{ __('Voir les blocs') }}</a>@endcan
                @can('create', App\Models\VehicleBlock::class)<a href="{{ route('vehicle-blocks.create', ['vehicle_id' => $vehicle->id]) }}" class="rf-button-secondary"><x-icon name="add" size="xs" />{{ __('Créer un bloc') }}</a>@endcan
                @can('update', $vehicle)<a href="{{ route('vehicles.edit', $vehicle) }}" class="rf-button-primary"><x-icon name="edit" size="xs" />{{ __('Modifier') }}</a>@endcan
            </x-slot:actions>
        </x-page-header>
        <x-form-errors />
        <x-section-card :title="__('Identification et exploitation')">
            <x-metadata-list class="sm:grid-cols-2 lg:grid-cols-4">
                <x-metadata-item :label="__('État')"><x-status-badge :value="$vehicle->operational_status" /></x-metadata-item>
                <x-metadata-item :label="__('Agence')">{{ $vehicle->agency->name }}</x-metadata-item>
                <x-metadata-item :label="__('Catégorie')">{{ $vehicle->category->name }}</x-metadata-item>
                <x-metadata-item :label="__('Kilométrage')">{{ App\Support\Ui\BusinessNumber::distance($vehicle->current_mileage, 0) }}</x-metadata-item>
                <x-metadata-item :label="__('Année')">{{ $vehicle->production_year }}</x-metadata-item>
                <x-metadata-item :label="__('Énergie')">{{ App\Support\Ui\UiLabel::get($vehicle->fuel_type) }}</x-metadata-item>
                <x-metadata-item :label="__('Transmission')">{{ App\Support\Ui\UiLabel::get($vehicle->transmission) }}</x-metadata-item>
                <x-metadata-item :label="__('Immatriculation')"><span class="font-mono">{{ $vehicle->registration_number }}</span></x-metadata-item>
            </x-metadata-list>
        </x-section-card>
        <div class="grid gap-6 lg:grid-cols-2">
            <x-section-card :title="__('État opérationnel')" :description="__('Toute modification est historisée.')">
                @can('update', $vehicle)
                    <form method="POST" action="{{ route('vehicles.status', $vehicle) }}" class="grid gap-4 sm:grid-cols-2">@csrf
                        <div><x-input-label for="vehicle-status" :value="__('Nouvel état')" required /><select id="vehicle-status" name="operational_status" class="mt-1 w-full">@foreach(App\Enums\VehicleOperationalStatus::cases() as $status)<option value="{{ $status->value }}">{{ App\Support\Ui\UiLabel::get($status) }}</option>@endforeach</select><x-field-error :messages="$errors->get('operational_status')" class="mt-2" /></div>
                        <div><x-input-label for="vehicle-status-reason" :value="__('Motif')" /><input id="vehicle-status-reason" name="reason" value="{{ old('reason') }}" class="mt-1 w-full"><x-field-error :messages="$errors->get('reason')" class="mt-2" /></div>
                        <div class="sm:col-span-2"><x-primary-button>{{ __('Changer le statut') }}</x-primary-button></div>
                    </form>
                @endcan
                <x-timeline class="mt-6" :label="__('Historique des états du véhicule')">@foreach($vehicle->statusHistories as $history)<x-timeline-item :title="($history->from_status ? App\Support\Ui\UiLabel::get($history->from_status) : __('Création')).' → '.App\Support\Ui\UiLabel::get($history->to_status)" :meta="App\Support\Ui\UiLabel::dateTime($history->created_at)" :active="$loop->first"></x-timeline-item>@endforeach</x-timeline>
            </x-section-card>
            <x-section-card :title="__('Documents privés')" :description="__('Les téléchargements restent contrôlés par les autorisations et sont auditables.')">
                @can('upload', App\Models\Document::class)
                    <form class="mb-5 space-y-4" method="POST" enctype="multipart/form-data" action="{{ route('vehicles.documents.store', $vehicle) }}" data-loading-form>@csrf
                        <div><x-input-label for="vehicle-document-title" :value="__('Titre')" required /><input id="vehicle-document-title" name="title" value="{{ old('title') }}" required class="mt-1 w-full"><x-field-error :messages="$errors->get('title')" class="mt-2" /></div>
                        <div><x-input-label for="vehicle-document-type" :value="__('Type')" required /><select id="vehicle-document-type" name="document_type" class="mt-1 w-full"><option value="vehicle_registration">{{ __('Carte grise') }}</option><option value="vehicle_insurance">{{ __('Assurance') }}</option><option value="vehicle_photo">{{ __('Photo') }}</option><option value="other">{{ __('Autre') }}</option></select><x-field-error :messages="$errors->get('document_type')" class="mt-2" /></div>
                        <input type="hidden" name="is_sensitive" value="0">
                        <x-file-input id="vehicle-document-file" name="file" :label="__('Fichier')" required :errors="$errors->get('file')" hint="Le fichier reste privé et sera contrôlé côté serveur." />
                        <x-submit-button :label="__('Ajouter le document')" loading-label="{{ __('Ajout en cours…') }}" />
                    </form>
                @endcan
                <div class="space-y-2">@forelse($vehicle->documents as $document)@can('view', $document)<a class="flex items-center justify-between rounded-lg border border-slate-200 p-3 text-sm font-medium text-brand-700 hover:bg-brand-50" href="{{ route('documents.show', $document) }}"><span>{{ $document->title }}</span><span aria-hidden="true">→</span></a>@endcan @empty<x-empty-state :title="__('Aucun document')" :description="__('Aucun document privé n’est encore associé à ce véhicule.')" />@endforelse</div>
            </x-section-card>
        </div>
        <x-section-card :title="__('Derniers blocs de disponibilité')" :description="__('Réservations, contrats, maintenances et blocs manuels qui affectent la disponibilité.')">
            <x-responsive-table :label="__('Blocs du véhicule')" class="shadow-none">
                <table><thead><tr><th>{{ __('Type') }}</th><th>{{ __('Période') }}</th><th>{{ __('Statut') }}</th><th>{{ __('Créateur') }}</th></tr></thead><tbody>@forelse($vehicle->blocks as $block)<tr><td>{{ $block->block_type->label() }}</td><td class="whitespace-nowrap">{{ App\Support\Ui\UiLabel::dateTime($block->starts_at) }} → {{ App\Support\Ui\UiLabel::dateTime($block->ends_at) }}</td><td><x-status-badge :value="$block->status" /></td><td>{{ $block->creator?->name ?? __('Système') }}</td></tr>@empty<tr><td colspan="4"><x-empty-state :title="__('Aucun bloc enregistré')" /></td></tr>@endforelse</tbody></table>
            </x-responsive-table>
        </x-section-card>
    </div>
</x-app-layout>
