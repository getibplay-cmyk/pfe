<x-app-layout>
    <div class="mx-auto max-w-4xl space-y-6">
        <div><a href="{{ route('maintenance.index') }}" class="text-sm text-indigo-700">← Maintenance</a><h1 class="mt-2 text-3xl font-bold">Planifier une maintenance</h1></div>
        <form method="POST" action="{{ route('maintenance.store') }}" class="grid gap-5 rounded-xl bg-white p-6 shadow-sm md:grid-cols-2">
            @csrf
            @include('maintenance._form', ['maintenance' => new App\Models\MaintenanceOrder])
        </form>
    </div>
</x-app-layout>
