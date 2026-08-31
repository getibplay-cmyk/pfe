<div
    data-belkhir-space-progress
    data-state="idle"
    hidden
    class="rf-page-progress"
    role="progressbar"
    aria-label="Chargement de la page"
    aria-valuetext="Chargement en cours"
>
    <span aria-hidden="true" class="rf-page-progress-bar"></span>
</div>

<div
    data-belkhir-space-loading-overlay
    hidden
    class="rf-loading-overlay"
    role="status"
    aria-live="polite"
    aria-label="Opération longue en cours"
>
    <div class="rf-loading-overlay-card">
        <x-spinner :announce="false" size="lg" class="text-belkhir-space-blue" />
        <p data-belkhir-space-loading-message class="text-sm font-semibold text-belkhir-space-text">Opération en cours…</p>
        <p class="text-xs text-belkhir-space-muted">Veuillez conserver cette page ouverte.</p>
    </div>
</div>
