<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page-header title="Notifications" eyebrow="Centre d’alertes" description="Alertes internes limitées à votre entreprise, vos permissions et votre agence.">
            <x-slot:actions><form method="POST" action="{{ route('notifications.read-all') }}">@csrf<x-confirmation-button type="submit" variant="secondary" message="Marquer toutes les notifications accessibles comme lues ?">Tout marquer comme lu</x-confirmation-button></form></x-slot:actions>
        </x-page-header>
        <x-filter-panel title="Filtrer les notifications">
            <form method="GET" action="{{ route('notifications.index') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><x-input-label for="notification-status" value="État" /><select id="notification-status" name="status" class="mt-1 w-full"><option value="all">Toutes</option><option value="unread" @selected(request('status') === 'unread')>Non lues</option></select></div>
                <div><x-input-label for="notification-priority" value="Priorité" /><select id="notification-priority" name="priority" class="mt-1 w-full"><option value="">Toutes les priorités</option>@foreach(['information', 'warning', 'urgent'] as $priority)<option value="{{ $priority }}" @selected(request('priority') === $priority)>{{ App\Support\Ui\UiLabel::get($priority) }}</option>@endforeach</select></div>
                <div><x-input-label for="notification-category" value="Catégorie" /><select id="notification-category" name="category" class="mt-1 w-full"><option value="">Toutes les catégories</option>@foreach(['reservation', 'contract', 'fleet', 'insurance', 'maintenance', 'finance'] as $category)<option value="{{ $category }}" @selected(request('category') === $category)>{{ App\Support\Ui\UiLabel::get($category) }}</option>@endforeach</select></div>
                <div class="flex items-end gap-2"><x-primary-button class="flex-1">Filtrer</x-primary-button>@if(request()->hasAny(['status', 'priority', 'category']))<a href="{{ route('notifications.index') }}" class="rf-button-secondary">Effacer</a>@endif</div>
            </form>
        </x-filter-panel>
        <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600"><x-result-count :paginator="$notifications" /><p aria-live="polite"><strong>{{ $unreadCount }}</strong> non lue(s)</p></div>
        <div class="space-y-3">
            @forelse($notifications as $notification)
                <article class="rounded-2xl border p-5 shadow-sm {{ $notification->recipient_read_at ? 'border-slate-200 bg-white' : 'border-brand-200 bg-brand-50/40' }}">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><x-status-badge :value="$notification->priority" /><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ App\Support\Ui\UiLabel::get($notification->category) }}</span>@unless($notification->recipient_read_at)<span class="text-xs font-semibold text-brand-700">Non lue</span>@endunless</div><h2 class="mt-2 text-base font-semibold text-slate-950">{{ $notification->title }}</h2><p class="mt-1 text-sm text-slate-600">{{ $notification->summary }}</p><p class="mt-2 text-xs text-slate-500">{{ App\Support\Ui\UiLabel::dateTime($notification->occurred_at) }}@if($notification->agency) · {{ $notification->agency->name }}@endif</p></div>
                        <div class="flex shrink-0 flex-wrap gap-2"><a href="{{ route('notifications.open', $notification) }}" class="rf-button-primary">Consulter</a>@if($notification->recipient_read_at)<form method="POST" action="{{ route('notifications.unread', $notification) }}">@csrf @method('PATCH')<x-secondary-button type="submit">Marquer non lue</x-secondary-button></form>@else<form method="POST" action="{{ route('notifications.read', $notification) }}">@csrf @method('PATCH')<x-secondary-button type="submit">Marquer lue</x-secondary-button></form>@endif</div>
                    </div>
                </article>
            @empty
                <x-empty-state title="Aucune notification" description="Aucune alerte ne correspond aux filtres et à votre périmètre actuel." />
            @endforelse
        </div>
        {{ $notifications->links() }}
    </div>
</x-app-layout>
