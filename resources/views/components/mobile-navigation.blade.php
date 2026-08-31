@props(['sections', 'user'])
<aside x-ref="mobilePanel" role="dialog" aria-modal="true" aria-label="Menu principal" class="relative flex h-full w-[min(88vw,22rem)] flex-col overflow-y-auto bg-atlas-ink p-5 text-white shadow-2xl" @keydown="trapMenu($event)">
    <div class="flex items-center justify-between gap-4">
        <x-brand-logo surface="dark" />
        <button type="button" @click="closeMenu()" class="rounded-lg p-2 text-slate-300 hover:bg-white/10 hover:text-white" aria-label="Fermer le menu">
            <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-width="2" d="m6 6 12 12M18 6 6 18" /></svg>
        </button>
    </div>
    <nav aria-label="Navigation mobile" class="mt-7 flex-1 space-y-6">
        @foreach ($sections as $section)
            <section>
                <h2 class="px-3 text-[0.68rem] font-bold uppercase tracking-[0.14em] text-slate-400">{{ $section['label'] }}</h2>
                <div class="mt-2 space-y-1">
                    @foreach ($section['items'] as $item)
                        <x-navigation-item :item="$item" surface="mobile" />
                    @endforeach
                </div>
            </section>
        @endforeach
    </nav>
    <div class="mt-6 border-t border-white/10 pt-4">
        <p class="truncate text-sm font-semibold text-white">{{ $user->name }}</p>
        <p class="truncate text-xs text-slate-400">{{ App\Support\Ui\UiLabel::get($user->is_platform_admin ? 'platform-admin' : $user->role?->slug) }}</p>
        <a href="{{ route('profile.edit') }}" class="mt-2 inline-flex min-h-10 w-full items-center rounded-lg px-3 py-2 text-sm font-semibold text-brand-300 hover:bg-white/10 hover:text-white">Mon profil</a>
    </div>
</aside>
