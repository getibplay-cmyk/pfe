<x-app-layout>
    <div class="rf-page">
        <x-page-header title="Démarrage guidé" eyebrow="Configuration initiale" description="Préparez votre entreprise pour réaliser un premier parcours de location complet." />

        <x-section-card title="Progression de votre espace" :description="$completed === $steps->count() ? 'Votre configuration initiale est terminée.' : 'Chaque étape est validée à partir des données réellement enregistrées.'">
            <x-progress-bar label="Configuration initiale" :value="$completed" :max="$steps->count()" :value-text="$completed.' étapes sur '.$steps->count()" :tone="$completed === $steps->count() ? 'success' : 'blue'" />
            @if($subscription)
                <p class="mt-4 text-sm text-slate-600">Plan <strong>{{ $subscription->plan->name }}</strong>@if($subscription->status->value === 'trialing' && $subscription->trial_ends_at) · essai jusqu’au {{ App\Support\Ui\UiLabel::dateTime($subscription->trial_ends_at) }}@endif</p>
            @endif
        </x-section-card>

        @if($nextStep)<x-section-card title="Votre prochaine étape" :description="$nextStep['description']"><a class="rf-button-primary" href="{{ $nextStep['url'] }}">{{ $nextStep['action'] }}</a></x-section-card>@endif
        @if($recentImports->isNotEmpty())<x-section-card title="Mes derniers imports"><ul class="divide-y">@foreach($recentImports as $import)<li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"><span>{{ $import->kind === 'vehicles' ? 'Véhicules' : 'Clients' }} · {{ $import->row_count }} lignes · {{ App\Support\Ui\UiLabel::dateTime($import->created_at) }}</span>@if($import->completed_at)<span>Import confirmé</span>@elseif($import->expires_at->isFuture() && $import->payload !== null)<a class="rf-button-link" href="{{ route('onboarding.import.show', $import) }}">Reprendre la vérification</a>@else<span>Aperçu expiré ou abandonné</span>@endif</li>@endforeach</ul></x-section-card>@endif
        <div class="grid gap-4 lg:grid-cols-2">
            @if(auth()->user()->hasPermission('vehicle.create') && auth()->user()->hasPermission('customer.create'))
                <section class="rf-panel p-5 lg:col-span-2"><h2 class="font-semibold">Vous avez déjà vos données ?</h2><p class="mt-2 text-sm text-slate-600">Importez un CSV de véhicules ou de clients. Vérifiez l’aperçu, corrigez les doublons, puis confirmez l’ensemble.</p><a href="{{ route('onboarding.import.index') }}" class="rf-button-primary mt-3">Importer mes données</a></section>
            @endif
            @foreach($steps as $index => $step)
                <article class="rf-panel flex gap-4 p-5">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $step['complete'] ? 'bg-emerald-100 text-emerald-800' : 'bg-blue-100 text-blue-800' }} font-bold" aria-hidden="true">{{ $step['complete'] ? '✓' : $index + 1 }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-start justify-between gap-2"><h2 class="font-semibold text-slate-900">{{ $step['label'] }}</h2><x-status-badge :value="$step['complete'] ? 'completed' : 'pending'" :label="$step['complete'] ? 'Terminée' : 'À faire'" /></div>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $step['description'] }}</p>
                        <a href="{{ $step['url'] }}" class="rf-button-link mt-3 inline-flex">{{ $step['action'] }}<span aria-hidden="true">→</span></a>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</x-app-layout>
