<x-app-layout>
    <div class="rf-page max-w-6xl">
        <x-page-header
            title="Catégories de véhicules"
            eyebrow="Parc automobile"
            description="Organisez le parc par capacité et par usage sans modifier les véhicules existants."
        >
            <x-slot:actions>
                @can('create', App\Models\VehicleCategory::class)
                    <x-link-button variant="primary" href="{{ route('vehicle-categories.create') }}">
                        <x-icon name="add" size="sm" />
                        Nouvelle catégorie
                    </x-link-button>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <x-result-count :paginator="$categories" />

        <x-responsive-table label="Catégories de véhicules">
            <table>
                <thead><tr><th>Code</th><th>Nom</th><th>Capacité</th><th class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($categories as $category)
                        <tr>
                            <td><span class="rounded-lg bg-belkhir-space-canvas px-2.5 py-1 font-mono text-xs font-semibold text-belkhir-space-text">{{ $category->code }}</span></td>
                            <td><div class="flex items-center gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-belkhir-space-blue" aria-hidden="true"><x-icon name="vehicle" size="sm" /></span>
                                <span class="font-semibold text-belkhir-space-text">{{ $category->name }}</span>
                            </div></td>
                            <td>{{ $category->seats ?? '—' }} places</td>
                            <td><div class="flex justify-end">
                                @can('update', $category)
                                    <x-icon-button icon="edit" :label="'Modifier la catégorie '.$category->name" :href="route('vehicle-categories.edit', $category)" />
                                @endcan
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-4"><x-empty-state title="Aucune catégorie" description="Créez une catégorie pour structurer les véhicules du parc." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-responsive-table>

        {{ $categories->links() }}
    </div>
</x-app-layout>
