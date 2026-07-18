<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-6">
        <div><a href="{{ route('maintenance.show', $maintenance) }}" class="text-sm text-indigo-700">← {{ $maintenance->maintenance_number }}</a><h1 class="mt-2 text-3xl font-bold">Replanifier la maintenance</h1><p class="mt-1 text-sm text-slate-600">Pour une maintenance approuvée, le bloc véhicule est déplacé atomiquement avec l’ordre.</p></div>
        <form method="POST" action="{{ route('maintenance.reschedule', $maintenance) }}" class="grid gap-5 rounded-xl bg-white p-6 shadow-sm sm:grid-cols-2" onsubmit="return confirm('Confirmer cette nouvelle période ?')">
            @csrf @method('PATCH')
            <label class="text-sm">Nouveau début<input type="datetime-local" name="scheduled_start_at" required value="{{ old('scheduled_start_at', $maintenance->scheduled_start_at?->format('Y-m-d\TH:i')) }}" class="mt-1 w-full rounded-lg border-slate-300"><x-input-error :messages="$errors->get('scheduled_start_at')" class="mt-1" /></label>
            <label class="text-sm">Nouvelle fin<input type="datetime-local" name="scheduled_end_at" required value="{{ old('scheduled_end_at', $maintenance->scheduled_end_at?->format('Y-m-d\TH:i')) }}" class="mt-1 w-full rounded-lg border-slate-300"><x-input-error :messages="$errors->get('scheduled_end_at')" class="mt-1" /></label>
            <label class="text-sm sm:col-span-2">Motif obligatoire<textarea name="reason" required rows="3" class="mt-1 w-full rounded-lg border-slate-300">{{ old('reason') }}</textarea><x-input-error :messages="$errors->get('reason')" class="mt-1" /></label>
            <div class="sm:col-span-2"><button class="rounded-lg bg-indigo-700 px-4 py-2 text-white">Replanifier</button></div>
        </form>
    </div>
</x-app-layout>
