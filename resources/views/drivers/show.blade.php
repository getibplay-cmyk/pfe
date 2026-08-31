<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6">
        <x-page-header :title="$driver->first_name.' '.$driver->last_name" eyebrow="Conducteur" :description="'Client : '.$driver->customer->displayName()" :breadcrumbs="[['label' => 'Clients et conducteurs', 'url' => route('customers.index')], ['label' => $driver->customer->displayName(), 'url' => route('customers.show', $driver->customer)], ['label' => $driver->first_name.' '.$driver->last_name]]">
            <x-slot:actions>
                <a href="{{ route('customers.show', $driver->customer) }}" class="rf-button-secondary"><x-icon name="previous" size="xs" />Retour au client</a>
                @can('update', $driver)<a href="{{ route('drivers.edit', $driver) }}" class="rf-button-secondary">Modifier</a>@endcan
                @can('archive', $driver)<form method="POST" action="{{ route('drivers.destroy', $driver) }}" x-belkhir-space-confirm data-confirm-title="Archiver ce conducteur" data-confirm-resource="Fiche conducteur sélectionnée" data-confirm-consequence="Le conducteur sera archivé et restera présent dans l’historique." data-confirm-label="Archiver">@csrf @method('DELETE')<button class="rf-button-danger">Archiver</button></form>@endcan
            </x-slot:actions>
        </x-page-header>
        <x-form-errors />

        <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Vérification</dt><dd><x-status-badge :value="$driver->verification_status" /></dd></div>
                <div><dt class="text-slate-500">Conducteur principal</dt><dd class="font-medium">{{ $driver->is_primary ? 'Oui' : 'Non' }}</dd></div>
                <div><dt class="text-slate-500">Permis</dt><dd class="font-medium">{{ $protector->maskEncrypted($driver->licence_number_encrypted) }}</dd></div>
                <div><dt class="text-slate-500">Expiration</dt><dd class="font-medium">{{ App\Support\Ui\UiLabel::date($driver->licence_expires_at) }} · {{ $driver->isLicenceExpired() ? 'Expiré' : 'Valide' }}</dd></div>
            </dl>
            @can('viewIdentity', $driver)<a class="mt-4 inline-flex text-sm font-medium text-indigo-700" href="{{ route('drivers.licence', $driver) }}">Consulter le numéro complet (audité)</a>@endcan

            @can('verify', $driver)
                <div class="mt-5 flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('drivers.verify', $driver) }}">@csrf<button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm text-white">Vérifier le conducteur</button></form>
                    <form method="POST" action="{{ route('drivers.reject-verification', $driver) }}" class="min-w-64 space-y-2">@csrf<label for="driver-rejection-reason" class="text-sm font-medium">Motif du rejet</label><textarea id="driver-rejection-reason" name="reason" required maxlength="1000" rows="2" aria-describedby="driver-rejection-help driver-rejection-error" class="w-full">{{ old('reason') }}</textarea><p id="driver-rejection-help" class="text-xs text-slate-500">Expliquez la décision sans recopier le numéro de permis.</p><x-field-error id="driver-rejection-error" :messages="$errors->get('reason')" /><button class="rounded-lg border border-red-200 px-4 py-2 text-sm text-red-700">Rejeter la vérification</button></form>
                </div>
            @endcan
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-semibold">Documents privés du conducteur</h2>
            @can('upload', App\Models\Document::class)
                <form class="my-4 grid gap-3 sm:grid-cols-2" method="POST" enctype="multipart/form-data" action="{{ route('drivers.documents.store', $driver) }}" data-loading-form>@csrf
                    <input type="hidden" name="document_type" value="driving_licence"><input type="hidden" name="is_sensitive" value="1">
                    <label class="text-sm">Titre *<input name="title" value="{{ old('title', 'Permis de conduire') }}" required class="mt-1 w-full"></label>
                    <x-file-input id="driver-document-file" name="file" label="Fichier" required :errors="$errors->get('file')" />
                    <x-submit-button class="justify-self-start" label="Ajouter le permis privé" loading-label="Ajout en cours…" />
                </form>
            @endcan
            <div class="space-y-2">
                @forelse ($driver->documents as $document)
                    @can('view', $document)<a class="block rounded-lg border p-3 text-sm text-indigo-700" href="{{ route('documents.show', $document) }}">{{ $document->title }} · {{ App\Support\Ui\UiLabel::get($document->document_type) }}</a>@endcan
                @empty <x-empty-state title="Aucun document" description="Ajoutez un permis privé avant de vérifier le conducteur." /> @endforelse
            </div>
        </section>
    </div>
</x-app-layout>
