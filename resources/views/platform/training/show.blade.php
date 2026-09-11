<x-app-layout>
    <div class="rf-page">
        <x-page-header :title="$campaign->name" eyebrow="Campagne de réentraînement" :description="$definition['label'].' · Référence : '.$campaign->baseline_version"><a class="rf-button-secondary" href="{{ route('platform.training.index') }}">Toutes les campagnes</a></x-page-header>
        <x-form-errors />
        @unless($available)<p role="alert" class="rounded-xl bg-amber-50 p-4 text-amber-950">Une contribution a été révoquée ou suspendue. Les téléchargements, relances et décisions favorables sont bloqués.</p>@endunless
        <div class="grid gap-4 sm:grid-cols-3">@foreach(['train' => 'Apprentissage', 'validation' => 'Validation', 'test' => 'Test séparé'] as $key => $label)<x-section-card :title="$label"><p class="text-3xl font-semibold">{{ $campaign->summary[$key] }}</p><p class="text-sm text-slate-500">observations</p></x-section-card>@endforeach</div>
        <x-section-card title="Lancer une session" description="Colab exécute le calcul dans votre session Google. Ses ressources dépendent de votre compte et de leur disponibilité.">
            <ol class="space-y-3 text-sm"><li><strong>1.</strong> Téléchargez le manifeste puis ouvrez le notebook. Il vérifie les données et conserve les partitions.</li><li><strong>2.</strong> Montez votre Drive privé et choisissez un dossier de travail. Pour la vision, ajoutez les images et les poids sources de confiance.</li><li><strong>3.</strong> Lancez la recette du modèle, puis comparez la référence et le candidat sur le même test. Les résultats restent dans Drive.</li><li><strong>4.</strong> Importez le rapport JSON ci-dessous. Une tentative suivante conserve l’historique de celle-ci.</li></ol>
            @if($available)<div class="mt-4 flex flex-wrap gap-3"><a class="rf-button-primary" href="{{ route('platform.training.download', $campaign) }}">Télécharger le manifeste</a><a class="rf-button-secondary" href="{{ $notebookUrl }}" target="_blank" rel="noopener noreferrer">Ouvrir Colab</a><form method="POST" action="{{ route('platform.training.retry', $campaign) }}" data-loading-form>@csrf <x-submit-button variant="secondary" label="Nouvelle tentative" loading-label="Préparation…" /></form></div>@endif
        </x-section-card>
        @if(!$campaign->result && $available)
            <x-section-card title="Importer la comparaison" description="Le SaaS recalcule les indicateurs à partir des prédictions fournies. La provenance des poids et le respect du protocole restent à vérifier par l’opérateur.">
                <form method="POST" action="{{ route('platform.training.result', $campaign) }}" enctype="multipart/form-data" class="space-y-4" data-loading-form>@csrf
                    <div><x-input-label for="training-report" value="Rapport Colab JSON (5 Mo maximum)" /><input id="training-report" name="report" type="file" accept=".json,application/json" required></div>
                    <label class="flex gap-2 text-sm"><input type="checkbox" name="protocol_confirmed" value="1" required><span>J’ai vérifié les poids de référence, les droits d’usage, la séparation des groupes et l’absence de réglage sur le test final.</span></label>
                    <x-submit-button label="Calculer la comparaison" loading-label="Vérification…" />
                </form>
            </x-section-card>
        @endif
        @if($campaign->result)
            @php($result = $campaign->result)
            <x-section-card title="Comparaison du candidat" :description="$result->candidate_version.' · '.$result->metrics['test_count'].' observations de test'">
                <x-responsive-table label="Comparaison des versions"><table><thead><tr><th>Indicateur</th><th>Référence</th><th>Candidat</th></tr></thead><tbody><tr><td>{{ $result->metrics['metric_label'] }}</td><td>{{ number_format($result->metrics['baseline'], 4, ',', ' ') }}</td><td>{{ number_format($result->metrics['candidate'], 4, ',', ' ') }}</td></tr>@foreach($result->metrics['segments'] as $label => $segment)<tr><td>{{ match ($campaign->family) { 'color' => App\Support\Intelligence\VehicleColor\VehicleColorContract::label((string) $label), 'anomaly' => (string) $label === '1' ? 'Anomalies confirmées' : 'Usages normaux', 'damage' => $label === 'precision' ? 'Précision' : 'Rappel', default => $label } }}</td><td>{{ number_format($segment['baseline'], 4, ',', ' ') }}</td><td>{{ number_format($segment['candidate'], 4, ',', ' ') }}</td></tr>@endforeach</tbody></table></x-responsive-table>
                @if($available)<a class="rf-button-secondary mt-3" href="{{ route('platform.training.report', $campaign) }}">Télécharger le rapport conservé</a>@endif
                <p class="mt-3 font-semibold">{{ $result->eligible ? 'Critères de comparaison franchis' : 'Critères de comparaison non franchis' }}</p>
                @if($result->metrics['reasons'])<ul class="mt-2 list-disc pl-5 text-sm">@foreach($result->metrics['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>@endif
                @if($result->review)<p class="mt-4 font-semibold">{{ $result->review->decision === 'qualified' ? 'Retenu pour qualification technique' : 'Candidat rejeté' }}</p><p class="text-sm">{{ $result->review->note }}</p>
                @else<form method="POST" action="{{ route('platform.training.review', $campaign) }}" class="mt-4 space-y-3" data-loading-form>@csrf
                    <div><x-input-label for="training-decision" value="Décision" /><select id="training-decision" name="decision" required><option value="rejected">Rejeter le candidat</option>@if($available && $result->eligible)<option value="qualified">Retenir pour qualification technique</option>@endif</select></div>
                    <div><x-input-label for="training-note" value="Motif de la décision" /><textarea id="training-note" name="note" required minlength="10" maxlength="500" rows="3" class="w-full"></textarea></div><x-submit-button label="Enregistrer la décision" loading-label="Enregistrement…" />
                </form>@endif
                <p class="mt-4 text-sm text-slate-600">La version en service reste gérée par le déploiement versionné. Un candidat retenu exige encore les contrôles de compatibilité, de calibration et de non-régression, avec possibilité de retour à la version précédente.</p>
            </x-section-card>
        @endif
    </div>
</x-app-layout>
