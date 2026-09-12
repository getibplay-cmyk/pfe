<x-app-layout>
    <div class="rf-page">
        <x-page-header title="Réentraînement des modèles" eyebrow="Administration de la plateforme" description="Préparez une session Colab, comparez les versions et conservez les décisions de qualification." />
        <x-form-errors />
        <x-section-card title="Préparer une campagne" description="Choisissez jusqu’à dix jeux du même modèle. Seules les contributions explicitement partagées par une entreprise active sont proposées.">
            <form method="POST" action="{{ route('platform.training.store') }}" class="space-y-4" data-loading-form>
                @csrf <div><x-input-label for="campaign-name" value="Nom de la campagne" /><input id="campaign-name" name="name" required maxlength="100" class="mt-1 w-full" placeholder="Exemple : demande, données regroupées de septembre"></div>
                <x-responsive-table label="Contributions autorisées"><table><thead><tr><th>Sélection</th><th>Jeu et entreprise</th><th>Modèle</th><th>Observations</th></tr></thead><tbody>
                    @forelse($datasets as $dataset)<tr><td><input id="dataset-{{ $dataset->public_id }}" type="checkbox" name="datasets[]" value="{{ $dataset->public_id }}"><label class="sr-only" for="dataset-{{ $dataset->public_id }}">Choisir {{ $dataset->name }}</label></td><td><strong>{{ $dataset->name }}</strong><p class="text-sm text-slate-600">{{ $dataset->tenant_name }}</p></td><td>{{ $catalog[$dataset->family]['label'] }}</td><td>{{ $dataset->row_count }}</td></tr>
                    @empty<tr><td colspan="4"><x-empty-state title="Aucune contribution autorisée" description="Le responsable d’entreprise prépare ses jeux dans Données d’apprentissage et choisit de les partager." /></td></tr>@endforelse
                </tbody></table><x-slot:footer>{{ $datasets->links() }}</x-slot:footer></x-responsive-table>
                <x-submit-button label="Préparer la session Colab" loading-label="Préparation…" :disabled="$datasets->isEmpty()" />
            </form>
        </x-section-card>
        <x-section-card title="Historique des campagnes" description="Chaque tentative possède ses propres données figées et son résultat. La décision de retenir un candidat prépare sa qualification technique.">
            <x-responsive-table label="Campagnes de réentraînement"><table><thead><tr><th>Campagne</th><th>Modèle</th><th>État</th><th>Création</th></tr></thead><tbody>
                @forelse($campaigns as $campaign)<tr><td><a class="font-semibold text-brand-700 underline" href="{{ route('platform.training.show', $campaign) }}">{{ $campaign->name }}</a></td><td>{{ $catalog[$campaign->family]['label'] }}</td><td>{{ $campaign->result?->review ? ($campaign->result->review->decision === 'qualified' ? 'Retenu pour qualification technique' : 'Candidat rejeté') : ($campaign->result ? 'Comparaison disponible' : 'Prête pour Colab') }}</td><td>{{ App\Support\Ui\UiLabel::dateTime($campaign->created_at) }}</td></tr>
                @empty<tr><td colspan="4"><x-empty-state title="Aucune campagne" description="Sélectionnez une contribution pour préparer votre première session." /></td></tr>@endforelse
            </tbody></table><x-slot:footer>{{ $campaigns->links() }}</x-slot:footer></x-responsive-table>
        </x-section-card>
        <x-section-card title="Quel entraînement pour chaque modèle ?"><div class="grid gap-4 md:grid-cols-2">@foreach($catalog as $item)<div><h2 class="font-semibold">{{ $item['label'] }}</h2><p class="text-sm text-slate-600">{{ $item['recipe'] }}</p></div>@endforeach</div><p class="mt-4 text-sm text-slate-600">L’optimisation de flotte OR-Tools se requalifie sur de nouveaux scénarios ; elle ne nécessite pas d’apprentissage statistique.</p></x-section-card>
    </div>
</x-app-layout>
