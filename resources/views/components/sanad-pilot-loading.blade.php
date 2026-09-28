<div
    data-sanad-pilot-progress
    data-state="idle"
    hidden
    class="rf-page-progress"
    role="progressbar"
    aria-label="{{ __('Chargement de la page') }}"
    aria-valuetext="Chargement en cours"
>
    <span aria-hidden="true" class="rf-page-progress-bar"></span>
</div>

<div
    data-sanad-pilot-loading-overlay
    hidden
    class="rf-loading-overlay"
    role="status"
    aria-live="polite"
    aria-label="{{ __('Opération longue en cours') }}"
>
    <div class="rf-loading-overlay-card">
        <x-spinner :announce="false" size="lg" class="text-sanad-pilot-blue" />
        <p data-sanad-pilot-loading-message class="text-sm font-semibold text-sanad-pilot-text">{{ __('Opération en cours…') }}</p>
        <p class="text-xs text-sanad-pilot-muted">{{ __('Veuillez conserver cette page ouverte.') }}</p>
    </div>
</div>
