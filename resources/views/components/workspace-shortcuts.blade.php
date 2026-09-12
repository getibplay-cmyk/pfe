@php($screen = App\Support\Ui\WorkspacePreferences::SCREENS[request()->route()?->getName()] ?? null)
@if ($screen && auth()->user()->hasPermission($screen[0]))
    <details class="rf-panel mx-auto mt-6 max-w-7xl p-4 text-sm">
        <summary class="cursor-pointer font-semibold text-brand-700">{{ __('Enregistrer cette vue dans mes filtres') }}</summary>
        <form method="POST" action="{{ route('workspace.filters.store') }}" class="mt-4 flex flex-wrap items-end gap-3">
            @csrf
            <input type="hidden" name="screen" value="{{ request()->route()->getName() }}">
            @php($savedCriteria = array_intersect_key(request()->query(), array_flip($screen[1])))
            @if ($savedCriteria === [])<input type="hidden" name="filters[q]" value="">@endif
            @foreach ($savedCriteria as $key => $value)@if (is_scalar($value))<input type="hidden" name="filters[{{ $key }}]" value="{{ $value }}">@endif@endforeach
            <div><x-input-label for="filter-label" :value="__('Nom du filtre')" /><x-text-input id="filter-label" name="label" maxlength="60" required /></div>
            <x-primary-button>{{ __('Enregistrer') }}</x-primary-button>
            <a href="{{ route('workspace.index') }}" class="rf-button-link">{{ __('Mes filtres') }}</a>
        </form>
    </details>
@endif
