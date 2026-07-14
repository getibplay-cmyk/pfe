<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex items-center justify-between"><div><p class="text-sm text-slate-500">Tarification versionnée</p><h1 class="text-2xl font-bold">Règles tarifaires</h1></div>@can('create', App\Models\PricingRule::class)<a href="{{ route('pricing-rules.create') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">Nouvelle règle</a>@endcan</div>
        <form class="grid gap-3 rounded-xl bg-white p-4 shadow-sm md:grid-cols-3">
            <select name="agency_id" class="rounded-lg border-slate-300"><option value="">Toutes les agences</option>@foreach($agencies as $agency)<option value="{{ $agency->id }}" @selected(request('agency_id') == $agency->id)>{{ $agency->name }}</option>@endforeach</select>
            <select name="category_id" class="rounded-lg border-slate-300"><option value="">Toutes les catégories</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>@endforeach</select>
            <button class="rounded-lg bg-slate-800 px-4 py-2 text-white">Filtrer</button>
        </form>
        <div class="overflow-x-auto rounded-xl bg-white shadow-sm"><table class="min-w-full text-sm"><thead class="bg-slate-50 text-left"><tr><th class="p-3">Nom</th><th class="p-3">Portée</th><th class="p-3">Catégorie</th><th class="p-3">Journalier</th><th class="p-3">Validité</th><th class="p-3">État</th><th class="p-3"></th></tr></thead><tbody>
            @forelse($rules as $rule)<tr class="border-t"><td class="p-3 font-medium">{{ $rule->name }} <span class="text-xs text-slate-400">#{{ $rule->id }}</span></td><td class="p-3">{{ $rule->agency?->name ?? 'Tout le tenant' }}</td><td class="p-3">{{ $rule->vehicleCategory->name }}</td><td class="p-3">{{ $rule->daily_rate }} {{ $rule->currency }}</td><td class="p-3">{{ $rule->valid_from->format('d/m/Y') }} – {{ $rule->valid_to?->format('d/m/Y') ?? 'sans fin' }}</td><td class="p-3">{{ $rule->is_active ? 'Active' : 'Archivée' }}</td><td class="p-3">@can('update', $rule)<a class="text-indigo-700" href="{{ route('pricing-rules.edit', $rule) }}">Créer une version</a>@endcan</td></tr>@empty<tr><td colspan="7" class="p-8 text-center text-slate-500">Aucune règle tarifaire.</td></tr>@endforelse
        </tbody></table></div>{{ $rules->links() }}
    </div>
</x-app-layout>
