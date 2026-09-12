<form method="POST" action="{{ route('locale.update') }}" class="inline-flex items-center gap-1">
    @csrf
    <label for="locale-choice" class="sr-only">{{ __('Langue') }}</label>
    <select id="locale-choice" name="locale" class="min-h-10 rounded-lg border-slate-300 bg-white py-1 text-sm text-slate-900" aria-label="{{ __('Langue') }}">
        <option value="fr" @selected(app()->getLocale() === 'fr')>{{ __('Français') }}</option>
        <option value="ar" @selected(app()->getLocale() === 'ar')>العربية</option>
    </select>
    <button type="submit" class="rf-button-secondary px-2" aria-label="{{ __('Changer de langue') }}">✓</button>
</form>
