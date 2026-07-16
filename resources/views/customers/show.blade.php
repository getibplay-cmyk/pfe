<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page-header :title="$customer->displayName()" eyebrow="Client">
            <x-slot:actions>@can('update', $customer)<a href="{{ route('customers.edit', $customer) }}" class="rounded-lg border bg-white px-4 py-2 text-sm font-medium">Modifier</a>@endcan</x-slot:actions>
        </x-page-header>
        <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><div class="flex flex-wrap items-center justify-between gap-3"><p>Identité : {{ $protector->maskEncrypted($customer->identity_number_encrypted) ?? 'Non renseignée' }}</p>@can('viewIdentity', $customer)<a class="text-sm font-medium text-indigo-700" href="{{ route('customers.identity', $customer) }}">Consulter la valeur complète (audité)</a>@endcan</div></section>
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="font-semibold">Conducteurs</h2><div class="mt-3 space-y-2">@forelse($customer->drivers as $driver)<div class="rounded-lg border p-3 text-sm"><strong>{{ $driver->first_name }} {{ $driver->last_name }}</strong><p class="mt-1"><x-status-badge :value="$driver->isLicenceExpired() ? 'expired' : ($driver->isLicenceExpiringSoon() ? 'pending' : 'verified')" /> · échéance {{ App\Support\Ui\UiLabel::date($driver->licence_expires_at) }}</p></div>@empty <x-empty-state title="Aucun conducteur" /> @endforelse</div>
                @can('create', App\Models\Driver::class)<form class="mt-5 grid gap-3 sm:grid-cols-2" method="POST" action="{{ route('customers.drivers.store', $customer) }}">@csrf<label class="text-sm">Prénom *<input name="first_name" value="{{ old('first_name') }}" required class="mt-1 w-full"></label><label class="text-sm">Nom *<input name="last_name" value="{{ old('last_name') }}" required class="mt-1 w-full"></label><label class="text-sm">Numéro de permis *<input name="licence_number" required class="mt-1 w-full"></label><label class="text-sm">Expiration *<input type="date" name="licence_expires_at" required class="mt-1 w-full"></label><input type="hidden" name="verification_status" value="pending"><input type="hidden" name="is_primary" value="0"><div class="sm:col-span-2"><button type="submit" class="rounded-lg bg-slate-950 px-4 py-2 text-sm text-white">Ajouter le conducteur</button></div></form>@endcan
            </section>
            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="font-semibold">Documents privés</h2>
                @can('upload', App\Models\Document::class)<form class="my-4 grid gap-3" method="POST" enctype="multipart/form-data" action="{{ route('customers.documents.store', $customer) }}">@csrf<label class="text-sm">Titre *<input name="title" value="{{ old('title') }}" required class="mt-1 w-full"></label><label class="text-sm">Type<select name="document_type" class="mt-1 w-full"><option value="customer_identity">Pièce d’identité</option><option value="other">Autre</option></select></label><input type="hidden" name="is_sensitive" value="1"><input type="file" name="file" required class="text-sm"><button type="submit" class="rounded-lg bg-slate-950 px-4 py-2 text-sm text-white">Ajouter le document</button></form>@endcan
                <div class="space-y-2">@forelse($customer->documents as $document)@can('view', $document)<a class="block rounded-lg border p-3 text-sm text-indigo-700" href="{{ route('documents.show', $document) }}">{{ $document->title }}</a>@endcan @empty <x-empty-state title="Aucun document" /> @endforelse</div>
            </section>
        </div>
    </div>
</x-app-layout>
