@if ($errors->any())
    <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
        <p class="font-semibold">Le formulaire contient {{ $errors->count() }} erreur(s).</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif
