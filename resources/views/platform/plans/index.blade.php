<x-app-layout>
    @php($activeFilterCount = collect(['q', 'active'])->filter(fn (string $key): bool => request()->filled($key))->count())
    <div class="rf-page">
        <x-page-header title="Plans SaaS" eyebrow="Administration de la plateforme" :description="'Définissez les offres commerciales publiées par '.config('brand.name').' et utilisées pour les abonnements.'">
            <x-slot:actions><a href="{{ route('platform.subscriptions.index') }}" class="rf-button-secondary"><x-icon name="view" size="xs" />Voir les abonnements</a></x-slot:actions>
        </x-page-header>

        <x-form-errors />

        <x-section-card title="Créer un plan" description="Aucun tarif n’est préchargé : chaque montant est défini explicitement par l’administrateur de la plateforme.">
            <form method="POST" action="{{ route('platform.plans.store') }}" class="grid gap-4 lg:grid-cols-2" data-loading-form>
                @csrf
                <div><x-input-label for="plan-code" value="Code stable" required /><input id="plan-code" name="code" value="{{ old('code') }}" required maxlength="50" class="mt-1 w-full" autocomplete="off"><x-field-error :messages="$errors->get('code')" /></div>
                <div><x-input-label for="plan-name" value="Nom du plan" required /><input id="plan-name" name="name" value="{{ old('name') }}" required maxlength="255" class="mt-1 w-full"><x-field-error :messages="$errors->get('name')" /></div>
                <div><x-input-label for="plan-interval" value="Périodicité" required /><select id="plan-interval" name="billing_interval" required class="mt-1 w-full">@foreach($intervals as $interval)<option value="{{ $interval->value }}" @selected(old('billing_interval') === $interval->value)>{{ $interval->value === 'monthly' ? 'Mensuelle' : 'Annuelle' }}</option>@endforeach</select><x-field-error :messages="$errors->get('billing_interval')" /></div>
                <div class="grid grid-cols-[minmax(0,1fr)_7rem] gap-3"><div><x-input-label for="plan-price" value="Prix" required /><input id="plan-price" name="price_amount" inputmode="decimal" value="{{ old('price_amount') }}" required pattern="\d+(\.\d{1,2})?" class="mt-1 w-full"><x-field-error :messages="$errors->get('price_amount')" /></div><div><x-input-label for="plan-currency" value="Devise" required /><input id="plan-currency" name="currency" value="{{ old('currency', 'MAD') }}" required maxlength="3" class="mt-1 w-full uppercase"><x-field-error :messages="$errors->get('currency')" /></div></div>
                <div class="lg:col-span-2"><x-input-label for="plan-description" value="Description courte" /><textarea id="plan-description" name="description" rows="2" maxlength="4000" class="mt-1 w-full">{{ old('description') }}</textarea><x-field-error :messages="$errors->get('description')" /></div>
                <fieldset class="lg:col-span-2" x-data="{ features: @js(array_values(old('features', ['Accès à '.config('brand.name')]))) }">
                    <legend class="text-sm font-medium text-slate-700">Fonctionnalités descriptives</legend>
                    <div class="mt-2 space-y-2"><template x-for="(feature, index) in features" :key="index"><div class="flex gap-2"><input name="features[]" x-model="features[index]" required maxlength="160" class="w-full" :aria-label="`Fonctionnalité ${index + 1}`"><button type="button" class="rf-button-secondary" x-on:click="features.splice(index, 1)" x-bind:disabled="features.length === 1"><x-icon name="close" size="xs" />Retirer</button></div></template></div>
                    <button type="button" class="rf-button-link mt-2" x-on:click="features.push('')" x-bind:disabled="features.length >= 30"><x-icon name="add" size="xs" />Ajouter une fonctionnalité</button>
                    <noscript><input name="features[]" value="{{ 'Accès à '.config('brand.name') }}" required class="mt-2 w-full"></noscript>
                    <x-field-error :messages="$errors->get('features')" />
                </fieldset>
                <div class="lg:col-span-2"><input type="hidden" name="is_active" value="0"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', true))> Plan actif dès sa création</label><x-field-error :messages="$errors->get('is_active')" /></div>
                <div class="lg:col-span-2"><x-submit-button label="Créer le plan" loading-label="Création…" /></div>
            </form>
        </x-section-card>

        <x-filter-panel title="Filtrer les plans" :active-count="$activeFilterCount" :result-count="$plans->total()">
            <form class="rf-filter-grid" method="GET" data-loading-form>
                <div><x-input-label for="plan-search" value="Recherche" /><input id="plan-search" name="q" value="{{ request('q') }}" class="mt-1 w-full" placeholder="Nom ou code"></div>
                <div><x-input-label for="plan-active" value="État" /><select id="plan-active" name="active" class="mt-1 w-full"><option value="">Tous</option><option value="1" @selected(request('active') === '1')>Actifs</option><option value="0" @selected(request('active') === '0')>Inactifs</option></select></div>
                <div class="flex items-end gap-2"><x-submit-button label="Filtrer" loading-label="Filtrage…" />@if($activeFilterCount > 0)<a href="{{ route('platform.plans.index') }}" class="rf-button-secondary"><x-icon name="reset" /> Effacer</a>@endif</div>
            </form>
        </x-filter-panel>

        <x-responsive-table label="Plans SaaS">
            <table>
                <thead><tr><th>Plan</th><th>Périodicité</th><th>Prix</th><th>Utilisation</th><th>État</th><th class="text-right">Gestion</th></tr></thead>
                <tbody>
                    @forelse($plans as $plan)
                        <tr>
                            <td><strong>{{ $plan->name }}</strong><br><span class="text-slate-500">{{ $plan->code }}</span></td>
                            <td>{{ $plan->billing_interval->value === 'monthly' ? 'Mensuelle' : 'Annuelle' }}</td>
                            <td>{{ App\Support\Ui\UiLabel::money($plan->price_amount, $plan->currency) }}</td>
                            <td>{{ App\Support\Ui\BusinessNumber::count($plan->subscriptions_count, 'abonnement') }}</td>
                            <td><x-status-badge :value="$plan->is_active ? 'active' : 'inactive'" /></td>
                            <td class="text-right">
                                <details class="inline-block text-left"><summary class="group relative inline-flex h-11 w-11 cursor-pointer list-none items-center justify-center rounded-xl border border-belkhir-space-border bg-white text-belkhir-space-muted shadow-sm transition hover:border-slate-400 hover:text-belkhir-space-blue [&::-webkit-details-marker]:hidden" aria-label="Modifier {{ $plan->name }}" title="Modifier {{ $plan->name }}"><x-icon name="edit" /><span class="sr-only">Modifier {{ $plan->name }}</span><span role="tooltip" class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 hidden -translate-x-1/2 whitespace-nowrap rounded-lg bg-belkhir-space-ink px-2.5 py-1.5 text-xs font-semibold text-white shadow-lg group-hover:block group-focus:block">Modifier {{ $plan->name }}</span></summary>
                                    <form method="POST" action="{{ route('platform.plans.update', $plan) }}" class="mt-3 w-[min(34rem,80vw)] space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xl" x-data="{ features: @js(array_values($plan->features ?: ['Accès à '.config('brand.name')])) }" data-loading-form>
                                        @csrf @method('PATCH')
                                        <div><x-input-label :for="'plan-name-'.$plan->id" value="Nom" required /><input id="plan-name-{{ $plan->id }}" name="name" value="{{ $plan->name }}" required class="mt-1 w-full"><x-field-error :messages="$errors->updatePlan->get('name')" /></div>
                                        <div><x-input-label :for="'plan-description-'.$plan->id" value="Description" /><textarea id="plan-description-{{ $plan->id }}" name="description" rows="2" class="mt-1 w-full">{{ $plan->description }}</textarea><x-field-error :messages="$errors->updatePlan->get('description')" /></div>
                                        <div class="grid grid-cols-[minmax(0,1fr)_7rem] gap-3"><input name="price_amount" value="{{ $plan->price_amount }}" required inputmode="decimal" aria-label="Prix"><input name="currency" value="{{ $plan->currency }}" required maxlength="3" aria-label="Devise"></div>
                                        <div class="space-y-2"><template x-for="(feature, index) in features" :key="index"><div class="flex gap-2"><input name="features[]" x-model="features[index]" required maxlength="160" class="w-full" :aria-label="`Fonctionnalité ${index + 1}`"><button type="button" class="rf-button-secondary" x-on:click="features.splice(index, 1)" x-bind:disabled="features.length === 1"><x-icon name="close" size="xs" />Retirer</button></div></template><button type="button" class="rf-button-link" x-on:click="features.push('')"><x-icon name="add" size="xs" />Ajouter</button></div>
                                        <input type="hidden" name="is_active" value="0"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($plan->is_active)> Plan actif</label>
                                        <x-confirmation-button :message="$plan->is_active ? 'Enregistrer ces changements ? Désactiver le plan empêchera seulement de nouveaux abonnements.' : 'Enregistrer ces changements ?'">Enregistrer</x-confirmation-button>
                                    </form>
                                </details>
                            </td>
                        </tr>
                    @empty<tr><td colspan="6"><x-empty-state title="Aucun plan SaaS" description="Créez un premier plan lorsque ses conditions commerciales sont décidées." /></td></tr>@endforelse
                </tbody>
            </table>
            <x-slot:footer>{{ $plans->links() }}</x-slot:footer>
        </x-responsive-table>
    </div>
</x-app-layout>
