@props(['bag' => null])
@php($errorBag = $bag ? $errors->getBag($bag) : $errors)
@if ($errorBag->any())
    <div role="alert" aria-live="assertive" {{ $attributes->class('rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-950') }}>
        <p class="font-semibold">Le formulaire contient {{ $errorBag->count() }} erreur(s).</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach ($errorBag->all() as $error)<li><a href="#contenu" class="underline underline-offset-2">{{ $error }}</a></li>@endforeach
        </ul>
    </div>
@endif
