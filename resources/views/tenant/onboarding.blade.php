<x-app-layout>
    <div class="rf-page">
        <x-page-header title="Démarrage guidé" eyebrow="Configuration initiale" description="Préparez votre entreprise pour réaliser un premier parcours de location complet." />

        <x-section-card title="Progression de votre espace" :description="$completed === $steps->count() ? 'Votre configuration initiale est terminée.' : 'Chaque étape est validée à partir des données réellement enregistrées.'">
            <x-progress-bar label="Configuration initiale" :value="$completed" :max="$steps->count()" :value-text="$completed.' étapes sur '.$steps->count()" :tone="$completed === $steps->count() ? 'success' : 'blue'" />
            @if($subscription)
                <p class="mt-4 text-sm text-slate-600">Plan <strong>{{ $subscription->plan->name }}</strong>@if($subscription->status->value === 'trialing' && $subscription->trial_ends_at) · essai jusqu’au {{ App\Support\Ui\UiLabel::dateTime($subscription->trial_ends_at) }}@endif</p>
            @endif
        </x-section-card>

        <div class="grid gap-4 lg:grid-cols-2">
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
