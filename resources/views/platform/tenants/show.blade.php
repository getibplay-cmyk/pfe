<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6">
        <x-page-header :title="$tenant->name" :eyebrow="'Entreprise cliente · '.$tenant->slug" description="Configuration structurelle et état de service de l’entreprise cliente.">
            <x-slot:actions><x-status-badge :value="$tenant->status" /><a href="{{ route('platform.tenants.edit', $tenant) }}" class="rf-button-primary">Modifier</a></x-slot:actions>
        </x-page-header>

        <x-form-errors />
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            @foreach($counts as $label => $value)
                <x-stat-card :label="$label" :value="$value" />
            @endforeach
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-section-card title="Informations">
                <x-metadata-list>
                    <x-metadata-item label="Raison sociale">{{ $tenant->legal_name ?? '—' }}</x-metadata-item>
                    <x-metadata-item label="E-mail">{{ $tenant->email ?? '—' }}</x-metadata-item>
                    <x-metadata-item label="Administrateur actif">{{ $owner?->name ?? 'Absent' }} @if($owner)<span class="block text-slate-500">{{ $owner->email }}</span>@endif</x-metadata-item>
                    <x-metadata-item label="Devise / fuseau">{{ $tenant->settings['currency'] ?? 'MAD' }} / {{ $tenant->settings['timezone'] ?? 'Africa/Casablanca' }}</x-metadata-item>
                </x-metadata-list>
            </x-section-card>
            <x-section-card title="Agences">
                <div class="divide-y">
                    @forelse($agencies as $agency)
                        <div class="flex justify-between py-3 text-sm"><span><strong>{{ $agency->name }}</strong><span class="block text-slate-500">{{ $agency->code }}</span></span><x-status-badge :value="$agency->is_active ? 'active' : 'inactive'" /></div>
                    @empty
                        <x-empty-state title="Aucune agence" />
                    @endforelse
                </div>
            </x-section-card>
        </div>

        <x-section-card title="État de service">
            @if($tenant->status->value === 'active')
                <form method="POST" action="{{ route('platform.tenants.suspend', $tenant) }}" class="space-y-3" onsubmit="return confirm('Suspendre cette entreprise cliente et révoquer ses sessions ?')">
                    @csrf
                    <div>
                        <x-input-label for="tenant-suspension-reason" value="Motif de suspension" required />
                        <textarea id="tenant-suspension-reason" name="reason" required maxlength="2000" rows="3" aria-describedby="tenant-suspension-help tenant-suspension-error" class="mt-1 w-full">{{ old('reason') }}</textarea>
                        <p id="tenant-suspension-help" class="mt-1 text-xs text-slate-500">Ce motif administratif ne doit contenir aucune donnée personnelle sensible.</p>
                        <x-field-error id="tenant-suspension-error" :messages="$errors->get('reason')" />
                    </div>
                    <x-confirmation-button message="Suspendre cette entreprise cliente et révoquer ses sessions ?">Suspendre l’entreprise cliente</x-confirmation-button>
                </form>
            @elseif($tenant->status->value === 'suspended')
                <div class="rounded bg-amber-50 p-4 text-sm"><p><strong>Motif :</strong> {{ $tenant->suspension_reason }}</p><p class="mt-1 text-slate-600">Suspendue le {{ App\Support\Ui\UiLabel::dateTime($tenant->suspended_at) }}</p></div>
                <form method="POST" action="{{ route('platform.tenants.reactivate', $tenant) }}" class="mt-4">@csrf<x-confirmation-button message="Réactiver cette entreprise cliente ?">Réactiver l’entreprise cliente</x-confirmation-button></form>
            @else
                <p class="text-sm text-slate-500">Cette entreprise cliente archivée ne peut pas être modifiée depuis ce parcours.</p>
            @endif
        </x-section-card>
    </div>
</x-app-layout>
