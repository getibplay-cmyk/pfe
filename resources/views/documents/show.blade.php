<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-6">
        <x-page-header :title="$document->title" eyebrow="Document privé" description="Les fichiers sont accessibles uniquement par un téléchargement contrôlé et audité." />
        <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Type</dt><dd class="font-medium">{{ App\Support\Ui\UiLabel::get($document->document_type) }}</dd></div>
                <div><dt class="text-slate-500">Échéance de conservation</dt><dd class="font-medium">{{ App\Support\Ui\UiLabel::date($document->retention_until) }}</dd></div>
            </dl>
            @can('download', $document)<a class="mt-5 inline-flex rounded-lg bg-slate-950 px-4 py-2 text-sm font-medium text-white" href="{{ route('documents.download', $document) }}">Télécharger la version courante</a>@endcan
            <h2 class="mt-8 font-semibold">Versions</h2>
            <div class="mt-3 divide-y rounded-lg border">
                @forelse ($document->versions as $version)<div class="p-3 text-sm"><strong>Version {{ $version->version_number }}</strong><p class="text-slate-500">{{ $version->original_name }} · {{ App\Support\Ui\UiLabel::dateTime($version->created_at) }}</p></div>@empty <x-empty-state title="Aucune version disponible" /> @endforelse
            </div>
            @can('upload', $document)
                <form class="mt-6 space-y-3" method="POST" enctype="multipart/form-data" action="{{ route('documents.versions.store', $document) }}">@csrf
                    <x-input-label for="document_version" value="Nouvelle version *" /><input id="document_version" type="file" name="file" required class="block w-full text-sm"><x-input-error :messages="$errors->get('file')" />
                    <button type="submit" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-medium text-white">Ajouter la version</button>
                </form>
            @endcan
        </section>
    </div>
</x-app-layout>
