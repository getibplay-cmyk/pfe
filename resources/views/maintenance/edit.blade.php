<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div><a href="{{ route('maintenance.show', $maintenance) }}" class="text-sm text-indigo-700">← {{ $maintenance->maintenance_number }}</a><h1 class="mt-2 text-3xl font-bold">Modifier la maintenance planifiée</h1><p class="mt-1 text-sm text-slate-600">L’agence et le statut ne peuvent pas être modifiés.</p></div>
        <form method="POST" action="{{ route('maintenance.update', $maintenance) }}" class="grid gap-5 rounded-xl bg-white p-6 shadow-sm md:grid-cols-2">
            @csrf @method('PUT')
            @include('maintenance._form')
        </form>
    </div>
</x-app-layout>
