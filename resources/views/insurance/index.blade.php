<x-app-layout>
    <div class="space-y-8">
        <div><p class="text-sm text-slate-500">Suivi administratif</p><h1 class="text-3xl font-bold">Assurance</h1><p class="mt-2 text-sm text-slate-600">Les garanties sont configurables. RentFleet ne décide pas juridiquement de la responsabilité.</p></div>
        @if(auth()->user()->hasPermission('insurance.manage'))<form method="POST" action="{{ route('insurance.companies.store') }}" class="flex flex-wrap gap-3 rounded-xl bg-white p-6 shadow-sm">@csrf<input name="name" required placeholder="Compagnie" class="rounded-lg border-slate-300"><input name="email" type="email" placeholder="Email" class="rounded-lg border-slate-300"><input name="phone" placeholder="Téléphone" class="rounded-lg border-slate-300"><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Ajouter</button></form>@endif
        <section class="rounded-xl bg-white p-6 shadow-sm"><h2 class="font-semibold">Polices et garanties</h2><div class="mt-4 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="text-left text-slate-500"><th class="py-2">Police</th><th>Véhicule</th><th>Compagnie</th><th>Échéance</th><th>Statut</th><th>Garanties</th></tr></thead><tbody>@forelse($policies as $policy)<tr class="border-t"><td class="py-3">{{ $policy->maskedPolicyNumber() }}</td><td>{{ $policy->vehicle->registration_number }}</td><td>{{ $policy->company->name }}</td><td>{{ $policy->ends_at->format('d/m/Y') }}</td><td>{{ $policy->status }}</td><td>{{ $policy->coverages->pluck('label')->join(', ') ?: 'Aucune' }}</td></tr>@empty<tr><td colspan="6" class="py-8 text-slate-500">Aucune police.</td></tr>@endforelse</tbody></table></div>{{ $policies->links() }}</section>
        <section class="rounded-xl bg-white p-6 shadow-sm">
            <h2 class="font-semibold">Sinistres</h2>
            <div class="mt-4 space-y-5 text-sm">
                @forelse($claims as $claim)
                    <article class="border-b pb-5">
                        <p class="font-medium">{{ $claim->claim_number }} · {{ $claim->status->label() }}</p>
                        <p class="text-slate-500">Demandé {{ $claim->claimed_amount }} {{ $claim->policy->currency }} · approuvé manuellement {{ $claim->approved_amount ?? '—' }}</p>
                        @if(auth()->user()->hasPermission('claim.manage'))
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if($claim->status->value === 'reported')
                                    <form method="POST" action="{{ route('insurance.claims.submit', $claim) }}">@csrf<button class="rounded border px-3 py-2">Soumettre</button></form>
                                    <form method="POST" action="{{ route('insurance.claims.review', $claim) }}">@csrf<button class="rounded border px-3 py-2">Démarrer la revue</button></form>
                                @elseif($claim->status->value === 'submitted')
                                    <form method="POST" action="{{ route('insurance.claims.review', $claim) }}">@csrf<button class="rounded border px-3 py-2">Démarrer la revue</button></form>
                                @elseif($claim->status->value === 'under_review')
                                    <form method="POST" action="{{ route('insurance.claims.approve', $claim) }}" class="flex gap-2">@csrf<input name="approved_amount" required inputmode="decimal" placeholder="Montant approuvé" class="rounded border-slate-300"><button class="rounded bg-emerald-700 px-3 py-2 text-white">Approuver</button></form>
                                    <form method="POST" action="{{ route('insurance.claims.reject', $claim) }}">@csrf<button class="rounded bg-red-700 px-3 py-2 text-white">Rejeter</button></form>
                                @elseif($claim->status->value === 'approved')
                                    <form method="POST" action="{{ route('insurance.claims.settle', $claim) }}" class="flex gap-2">@csrf<input name="settled_amount" required inputmode="decimal" placeholder="Montant réglé" class="rounded border-slate-300"><button class="rounded bg-emerald-700 px-3 py-2 text-white">Enregistrer le règlement</button></form>
                                @elseif($claim->status->value === 'settled')
                                    <form method="POST" action="{{ route('insurance.claims.close', $claim) }}">@csrf<button class="rounded bg-slate-900 px-3 py-2 text-white">Clôturer</button></form>
                                @endif
                            </div>
                        @endif
                        <ol class="mt-3 space-y-1 text-xs text-slate-500">
                            @foreach($claim->statusHistories->sortByDesc('changed_at')->take(3) as $history)
                                <li>{{ $history->from_status?->label() ?? 'Création' }} → {{ $history->to_status->label() }} · {{ $history->changed_at->format('d/m/Y H:i') }}</li>
                            @endforeach
                        </ol>
                    </article>
                @empty
                    <p class="text-slate-500">Aucun sinistre.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-app-layout>
