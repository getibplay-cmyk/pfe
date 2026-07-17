<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page-header :title="$customer->displayName()" eyebrow="Client">
            <x-slot:actions>
                @can('update', $customer)<a href="{{ route('customers.edit', $customer) }}" class="rounded-lg border bg-white px-4 py-2 text-sm font-medium">Modifier</a>@endcan
                @can('archive', $customer)<form method="POST" action="{{ route('customers.destroy', $customer) }}" onsubmit="return confirm('Archiver ce client sans supprimer son historique ?')">@csrf @method('DELETE')<button class="rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-medium text-red-700">Archiver</button></form>@endcan
            </x-slot:actions>
        </x-page-header>
        <x-form-errors />

        <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-2"><p>Vérification : <x-status-badge :value="$customer->verification_status" /></p><p>Identité : {{ $protector->maskEncrypted($customer->identity_number_encrypted) ?? 'Non renseignée' }}</p></div>
                @can('viewIdentity', $customer)<a class="text-sm font-medium text-indigo-700" href="{{ route('customers.identity', $customer) }}">Consulter la valeur complète (audité)</a>@endcan
            </div>
            @can('verify', $customer)
                <div class="mt-5 flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('customers.verify', $customer) }}">@csrf<button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm text-white">Vérifier le client</button></form>
                    <form method="POST" action="{{ route('customers.reject-verification', $customer) }}" class="flex gap-2">@csrf<input name="reason" required maxlength="1000" placeholder="Motif obligatoire" class="min-w-48"><button class="rounded-lg border border-red-200 px-4 py-2 text-sm text-red-700">Rejeter</button></form>
                </div>
            @endcan
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="font-semibold">Conducteurs</h2>
                <div class="mt-3 space-y-4">
                    @forelse ($customer->drivers as $driver)
                        <article class="rounded-lg border p-4 text-sm {{ $driver->trashed() ? 'bg-slate-50 opacity-75' : '' }}">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div><strong>{{ $driver->first_name }} {{ $driver->last_name }}</strong>@if($driver->is_primary)<span class="ml-2 text-xs text-indigo-700">Principal</span>@endif</div>
                                @if ($driver->trashed())
                                    @can('restore', $driver)<form method="POST" action="{{ route('drivers.restore', $driver->id) }}">@csrf<button class="text-indigo-700">Restaurer</button></form>@endcan
                                @else
                                    <a href="{{ route('drivers.show', $driver) }}" class="text-indigo-700">Ouvrir la fiche</a>
                                @endif
                            </div>
                            <p class="mt-2">Vérification : <x-status-badge :value="$driver->verification_status" /></p>
                            <p class="mt-1">Permis : <x-status-badge :value="$driver->isLicenceExpired() ? 'expired' : ($driver->isLicenceExpiringSoon() ? 'pending' : 'active')" /> · échéance {{ App\Support\Ui\UiLabel::date($driver->licence_expires_at) }}</p>
                            @unless ($driver->trashed())
                                <div class="mt-3 space-y-1">
                                    @forelse ($driver->documents as $document)
                                        @can('view', $document)<a class="block text-indigo-700" href="{{ route('documents.show', $document) }}">{{ $document->title }} · {{ App\Support\Ui\UiLabel::get($document->document_type) }}</a>@endcan
                                    @empty <p class="text-slate-500">Aucun permis privé.</p> @endforelse
                                </div>
                                @can('upload', App\Models\Document::class)
                                    <form class="mt-3 grid gap-2" method="POST" enctype="multipart/form-data" action="{{ route('drivers.documents.store', $driver) }}">@csrf
                                        <input type="hidden" name="document_type" value="driving_licence"><input type="hidden" name="is_sensitive" value="1">
                                        <input name="title" value="Permis de conduire" required class="w-full"><input type="file" name="file" required class="text-sm">
                                        <button class="justify-self-start rounded-lg border px-3 py-1.5 text-xs">Ajouter un permis privé</button>
                                    </form>
                                @endcan
                            @endunless
                        </article>
                    @empty
                        <x-empty-state title="Aucun conducteur" />
                    @endforelse
                </div>

                @can('create', App\Models\Driver::class)
                    <form class="mt-6 grid gap-3 sm:grid-cols-2" method="POST" action="{{ route('customers.drivers.store', $customer) }}">@csrf
                        <label class="text-sm">Prénom *<input name="first_name" value="{{ old('first_name') }}" required class="mt-1 w-full"><x-input-error :messages="$errors->get('first_name')" /></label>
                        <label class="text-sm">Nom *<input name="last_name" value="{{ old('last_name') }}" required class="mt-1 w-full"><x-input-error :messages="$errors->get('last_name')" /></label>
                        <label class="text-sm">Numéro de permis *<input name="licence_number" required class="mt-1 w-full"><x-input-error :messages="$errors->get('licence_number')" /></label>
                        <label class="text-sm">Catégorie<input name="licence_category" value="{{ old('licence_category') }}" class="mt-1 w-full"></label>
                        <label class="text-sm">Délivré le<input type="date" name="licence_issued_at" value="{{ old('licence_issued_at') }}" class="mt-1 w-full"><x-input-error :messages="$errors->get('licence_issued_at')" /></label>
                        <label class="text-sm">Expiration *<input type="date" name="licence_expires_at" value="{{ old('licence_expires_at') }}" required class="mt-1 w-full"><x-input-error :messages="$errors->get('licence_expires_at')" /></label>
                        <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" name="is_primary" value="1" @checked(old('is_primary'))> Conducteur principal</label>
                        <div class="sm:col-span-2"><button type="submit" class="rounded-lg bg-slate-950 px-4 py-2 text-sm text-white">Ajouter le conducteur</button></div>
                    </form>
                @endcan
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="font-semibold">Documents privés du client</h2>
                @can('upload', App\Models\Document::class)
                    <form class="my-4 grid gap-3" method="POST" enctype="multipart/form-data" action="{{ route('customers.documents.store', $customer) }}">@csrf
                        <label class="text-sm">Titre *<input name="title" value="{{ old('title') }}" required class="mt-1 w-full"><x-input-error :messages="$errors->get('title')" /></label>
                        <label class="text-sm">Type<select name="document_type" class="mt-1 w-full"><option value="customer_identity">Pièce d’identité</option><option value="other">Autre</option></select></label>
                        <input type="hidden" name="is_sensitive" value="1"><input type="file" name="file" required class="text-sm"><x-input-error :messages="$errors->get('file')" />
                        <button type="submit" class="rounded-lg bg-slate-950 px-4 py-2 text-sm text-white">Ajouter le document</button>
                    </form>
                @endcan
                <div class="space-y-2">@forelse($customer->documents as $document)@can('view', $document)<a class="block rounded-lg border p-3 text-sm text-indigo-700" href="{{ route('documents.show', $document) }}">{{ $document->title }}</a>@endcan @empty <x-empty-state title="Aucun document" /> @endforelse</div>
            </section>
        </div>
    </div>
</x-app-layout>
