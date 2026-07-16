<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6">
        <x-page-header title="Mon profil" eyebrow="Compte utilisateur" description="Modifiez vos informations personnelles et sécurisez votre accès. Les droits et rattachements sont administrés séparément." />

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                @include('profile.partials.update-profile-information-form')
            </section>

            <aside class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold">Rattachement</h2>
                <dl class="mt-4 space-y-4 text-sm">
                    <div><dt class="text-slate-500">Tenant</dt><dd class="font-medium">{{ $user->tenant?->name ?? 'Administration plateforme' }}</dd></div>
                    <div><dt class="text-slate-500">Agence</dt><dd class="font-medium">{{ $user->agency?->name ?? ($user->is_platform_admin ? '—' : 'Toutes les agences autorisées') }}</dd></div>
                    <div><dt class="text-slate-500">Rôle</dt><dd class="font-medium">{{ $user->is_platform_admin ? 'Administrateur plateforme' : App\Support\Ui\UiLabel::get($user->role?->slug) }}</dd></div>
                    <div><dt class="text-slate-500">État du compte</dt><dd><x-status-badge :value="$user->is_active ? 'active' : 'inactive'" /></dd></div>
                    <div><dt class="text-slate-500">Dernière connexion</dt><dd class="font-medium">{{ App\Support\Ui\UiLabel::dateTime($user->last_login_at) }}</dd></div>
                </dl>
                <p class="mt-5 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">Le rôle, le tenant, l’agence et l’état du compte ne sont pas modifiables depuis le profil.</p>
            </aside>
        </div>

        <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            @include('profile.partials.update-password-form')
        </section>
    </div>
</x-app-layout>
