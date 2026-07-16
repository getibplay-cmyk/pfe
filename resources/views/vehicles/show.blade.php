<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page-header :title="$vehicle->registration_number.' · '.$vehicle->brand.' '.$vehicle->model" :eyebrow="$vehicle->category->name">
            <x-slot:actions>@can('update', $vehicle)<a href="{{ route('vehicles.edit', $vehicle) }}" class="rounded-lg border bg-white px-4 py-2 text-sm">Modifier</a>@endcan</x-slot:actions>
        </x-page-header>
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="font-semibold">Statut opérationnel</h2><p class="my-3"><x-status-badge :value="$vehicle->operational_status" /></p>
                @can('update', $vehicle)<form method="POST" action="{{ route('vehicles.status', $vehicle) }}" class="grid gap-3 sm:grid-cols-2">@csrf<label class="text-sm">Nouvel état<select name="operational_status" class="mt-1 w-full">@foreach(App\Enums\VehicleOperationalStatus::cases() as $status)<option value="{{ $status->value }}">{{ App\Support\Ui\UiLabel::get($status) }}</option>@endforeach</select></label><label class="text-sm">Motif<input name="reason" value="{{ old('reason') }}" class="mt-1 w-full"></label><div class="sm:col-span-2"><button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-sm text-white">Changer le statut</button></div></form>@endcan
                <ol class="mt-5 space-y-2 text-sm">@foreach($vehicle->statusHistories as $history)<li class="border-l-2 border-slate-200 pl-3">{{ App\Support\Ui\UiLabel::dateTime($history->created_at) }} · {{ $history->from_status ? App\Support\Ui\UiLabel::get($history->from_status) : 'Création' }} → {{ App\Support\Ui\UiLabel::get($history->to_status) }}</li>@endforeach</ol>
            </section>
            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="font-semibold">Documents privés</h2>
                @can('upload', App\Models\Document::class)<form class="my-4 space-y-3" method="POST" enctype="multipart/form-data" action="{{ route('vehicles.documents.store', $vehicle) }}">@csrf<label class="block text-sm">Titre *<input name="title" value="{{ old('title') }}" required class="mt-1 w-full"></label><label class="block text-sm">Type<select name="document_type" class="mt-1 w-full"><option value="vehicle_registration">Carte grise</option><option value="vehicle_insurance">Assurance</option><option value="vehicle_photo">Photo</option><option value="other">Autre</option></select></label><input type="hidden" name="is_sensitive" value="0"><input type="file" name="file" required class="text-sm"><button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-sm text-white">Ajouter</button></form>@endcan
                <div class="space-y-2">@forelse($vehicle->documents as $document)@can('view', $document)<a class="block rounded-lg border p-3 text-sm text-indigo-700" href="{{ route('documents.show', $document) }}">{{ $document->title }}</a>@endcan @empty <x-empty-state title="Aucun document" /> @endforelse</div>
            </section>
        </div>
    </div>
</x-app-layout>
