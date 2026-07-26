<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6">
        <x-page-header title="Délégations par agence" eyebrow="Gouvernance des accès" description="Un responsable d’agence ne peut attribuer que les rôles cochés ici et dont les permissions restent sous son propre plafond."><x-slot:actions><a href="{{ route('roles.index') }}" class="rf-button-secondary">Retour aux rôles</a></x-slot:actions></x-page-header>
        <x-form-errors />
        <div class="space-y-4">
            @forelse($agencies as $agency)
                <form method="POST" action="{{ route('roles.delegations.update', $agency) }}">
                    @csrf @method('PUT')
                    <x-section-card :title="$agency->name" description="Les rôles Propriétaire de l’entreprise et Administrateur plateforme sont toujours exclus.">
                        @php($selected = collect(old('role_ids', $delegations->get($agency->id, collect())))->map(fn($id) => (int) $id))
                        <fieldset>
                            <legend class="sr-only">Rôles délégués pour l’agence {{ $agency->name }}</legend>
                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach($roles as $role)<label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm"><input type="checkbox" name="role_ids[]" value="{{ $role->id }}" class="mt-1 rounded border-slate-300" @checked($selected->contains($role->id))><span><span class="font-medium">{{ $role->displayName() }}</span><span class="mt-0.5 block text-xs text-slate-500">{{ $role->permissions->count() }} permission(s)</span></span></label>@endforeach
                            </div>
                        </fieldset>
                        <div class="mt-4 flex justify-end"><x-confirmation-button type="submit" variant="secondary" message="Confirmer la nouvelle délégation pour cette agence ?">Enregistrer la délégation</x-confirmation-button></div>
                    </x-section-card>
                </form>
            @empty
                <x-empty-state title="Aucune agence active" description="Activez une agence avant de déléguer des rôles." />
            @endforelse
        </div>
    </div>
</x-app-layout>
