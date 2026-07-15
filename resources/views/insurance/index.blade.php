<x-app-layout>
    <div class="space-y-7">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><p class="text-sm text-slate-500">Suivi administratif</p><h1 class="text-3xl font-bold">Assurance</h1><p class="mt-2 text-sm text-slate-600">Les références sensibles restent chiffrées et masquées. Toute décision de sinistre est humaine.</p></div>
            <div class="flex gap-2">
                @if(auth()->user()->hasPermission('insurance.manage'))<a href="{{ route('insurance.policies.create') }}" class="rounded-lg border bg-white px-4 py-2 text-sm">Nouvelle police</a>@endif
                @if(auth()->user()->hasPermission('claim.manage'))<a href="{{ route('insurance.claims.create') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">Déclarer un sinistre</a>@endif
            </div>
        </div>

        @if(auth()->user()->hasPermission('insurance.manage'))
            <form method="POST" action="{{ route('insurance.companies.store') }}" class="grid gap-3 rounded-xl bg-white p-5 shadow-sm md:grid-cols-4">@csrf
                <label class="text-sm">Compagnie<input name="name" required value="{{ old('name') }}" class="mt-1 w-full rounded border-slate-300"><x-input-error :messages="$errors->get('name')" /></label>
                <label class="text-sm">Email<input name="email" type="email" value="{{ old('email') }}" class="mt-1 w-full rounded border-slate-300"></label>
                <label class="text-sm">Téléphone<input name="phone" value="{{ old('phone') }}" class="mt-1 w-full rounded border-slate-300"></label>
                <div class="self-end"><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Ajouter la compagnie</button></div>
            </form>
        @endif

        <section class="rounded-xl bg-white p-6 shadow-sm"><h2 class="font-semibold">Polices et garanties</h2><div class="mt-4 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="text-left text-slate-500"><th class="py-2">Police</th><th>Véhicule</th><th>Compagnie</th><th>Échéance</th><th>Statut</th><th></th></tr></thead><tbody>
            @forelse($policies as $policy)<tr class="border-t"><td class="py-3 font-medium">{{ $policy->maskedPolicyNumber() }}</td><td>{{ $policy->vehicle->registration_number }}</td><td>{{ $policy->company->name }}</td><td>{{ $policy->ends_at->format('d/m/Y') }}</td><td><span class="rounded-full bg-slate-100 px-2 py-1 text-xs">{{ $policy->status }}</span></td><td class="text-right"><a href="{{ route('insurance.policies.show', $policy) }}" class="text-indigo-700 underline">Ouvrir</a></td></tr>
            @empty<tr><td colspan="6" class="py-10 text-center text-slate-500">Aucune police.</td></tr>@endforelse
        </tbody></table></div><div class="mt-4">{{ $policies->links() }}</div></section>

        <section class="rounded-xl bg-white p-6 shadow-sm"><h2 class="font-semibold">Sinistres récents</h2><div class="mt-4 space-y-3 text-sm">
            @forelse($claims as $claim)<a href="{{ route('insurance.claims.show', $claim) }}" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-4 hover:bg-slate-50"><span><strong>{{ $claim->claim_number }}</strong> · {{ $claim->policy->maskedPolicyNumber() }}</span><span class="rounded-full bg-slate-100 px-2 py-1 text-xs">{{ $claim->status->label() }}</span></a>
            @empty<p class="text-slate-500">Aucun sinistre.</p>@endforelse
        </div></section>
    </div>
</x-app-layout>
