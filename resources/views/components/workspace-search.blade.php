<a href="{{ route('workspace.index') }}" class="rf-button-secondary" data-workspace-open><x-icon name="search" size="xs" /><span class="hidden sm:inline">{{ __('Rechercher') }}</span><span class="sr-only sm:not-sr-only"><kbd class="rf-kbd">{{ __('Ctrl K') }}</kbd></span></a>
<dialog data-workspace-dialog data-search-url="{{ route('workspace.index') }}" data-empty="{{ __('Aucun résultat dans votre périmètre.') }}" data-error="{{ __('La recherche est indisponible. Réessayez.') }}" data-loading="{{ __('Recherche en cours…') }}" class="w-[min(40rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 p-0 shadow-2xl backdrop:bg-slate-950/60">
    <div class="p-5">
        <div class="mb-4 flex items-center justify-between gap-3"><h2 class="font-bold" id="workspace-dialog-title">{{ __('Rechercher dans mon espace') }}</h2><button type="button" data-workspace-close class="rf-button-link">{{ __('Fermer') }}</button></div>
        <label for="workspace-quick-query" class="sr-only">{{ __('Rechercher') }}</label><x-text-input id="workspace-quick-query" data-workspace-query maxlength="80" autocomplete="off" :placeholder="__('Au moins deux caractères')" />
        <p data-workspace-status role="status" class="mt-3 text-sm text-slate-500"></p>
        <ul data-workspace-results class="mt-3 max-h-[50vh] divide-y divide-slate-100 overflow-y-auto"></ul>
        <a href="{{ route('workspace.index') }}" class="rf-button-link mt-4">{{ __('Mes favoris et filtres') }}</a>
    </div>
</dialog>
