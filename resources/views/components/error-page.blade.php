@props(['code', 'title', 'message', 'href' => '/'])
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — RentFleet</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-50 p-6 text-slate-900">
    <main class="mx-auto flex min-h-[calc(100vh-3rem)] max-w-xl items-center">
        <section class="rf-panel w-full p-8 text-center sm:p-10">
            <x-brand-logo class="justify-center" />
            <p class="mt-8 text-xs font-bold uppercase tracking-[0.18em] text-brand-700">Erreur {{ $code }}</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight">{{ $title }}</h1>
            <p class="mt-3 leading-7 text-slate-600">{{ $message }}</p>
            @if(request()->attributes->get('correlation_id'))<p class="mt-5 break-all rounded-lg bg-slate-50 p-3 font-mono text-xs text-slate-500">Référence : {{ request()->attributes->get('correlation_id') }}</p>@endif
            <a class="rf-button-primary mt-7" href="{{ $href }}">Continuer</a>
        </section>
    </main>
</body>
</html>
