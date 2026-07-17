<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6">
        <x-page-header :title="$driver->first_name.' '.$driver->last_name" eyebrow="Conducteur" :description="'Client : '.$driver->customer->displayName()">
            <x-slot:actions>
                @can('update', $driver)<a href="{{ route('drivers.edit', $driver) }}" class="rounded-lg border bg-white px-4 py-2 text-sm font-medium">Modifier</a>@endcan
                @can('archive', $driver)<form method="POST" action="{{ route('drivers.destroy', $driver) }}" onsubmit="return confirm('Archiver ce conducteur ?')">@csrf @method('DELETE')<button class="rounded-lg border border-red-200 bg-white px-4 py-2 text-sm text-red-700">Archiver</button></form>@endcan
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
                    <form method="POST" action="{{ route('drivers.reject-verification', $driver) }}" class="flex gap-2">@csrf<input name="reason" required maxlength="1000" placeholder="Motif obligatoire" class="min-w-48"><button class="rounded-lg border border-red-200 px-4 py-2 text-sm text-red-700">Rejeter</button></form>
                </div>
            @endcan
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-semibold">Documents privés du conducteur</h2>
            @can('upload', App\Models\Document::class)
                <form class="my-4 grid gap-3 sm:grid-cols-2" method="POST" enctype="multipart/form-data" action="{{ route('drivers.documents.store', $driver) }}">@csrf
                    <input type="hidden" name="document_type" value="driving_licence"><input type="hidden" name="is_sensitive" value="1">
                    <label class="text-sm">Titre *<input name="title" value="{{ old('title', 'Permis de conduire') }}" required class="mt-1 w-full"></label>
                    <label class="text-sm">Fichier *<input type="file" name="file" required class="mt-1 block w-full text-sm"><x-input-error :messages="$errors->get('file')" /></label>
                    <button class="justify-self-start rounded-lg bg-slate-950 px-4 py-2 text-sm text-white">Ajouter le permis privé</button>
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
