<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-6">
        <x-page-header title="Modifier la garantie" eyebrow="Assurance" description="Mettez à jour les limites contractuelles dans la devise de la police.">
            <x-slot:actions><a href="{{ route('insurance.policies.show', $policy) }}" class="rf-button-secondary">Retour à la police</a></x-slot:actions>
        </x-page-header>
        <x-form-errors />
        <form method="POST" action="{{ route('insurance.coverages.update', [$policy, $coverage]) }}" class="grid gap-5 rounded-xl bg-white p-6 shadow-sm md:grid-cols-2">
            @csrf
            @method('PUT')
            <div>
                <x-input-label for="coverage-edit-type" value="Type de garantie" required />
                <select id="coverage-edit-type" name="coverage_type" aria-describedby="coverage-edit-type-error" class="mt-1 w-full rounded border-slate-300">
                    @foreach(['liability','collision','theft','fire','glass','assistance','legal_defence','other'] as $type)
                        <option value="{{ $type }}" @selected(old('coverage_type', $coverage->coverage_type) === $type)>{{ App\Support\Ui\UiLabel::get($type) }}</option>
                    @endforeach
                </select>
                <x-field-error id="coverage-edit-type-error" :messages="$errors->get('coverage_type')" />
            </div>
            <div>
                <x-input-label for="coverage-edit-label" value="Libellé de la garantie" required />
                <input id="coverage-edit-label" name="label" required value="{{ old('label', $coverage->label) }}" aria-describedby="coverage-edit-label-error" class="mt-1 w-full rounded border-slate-300">
                <x-field-error id="coverage-edit-label-error" :messages="$errors->get('label')" />
            </div>
            <div>
                <x-input-label for="coverage-edit-limit" :value="'Plafond ('.$policy->currency.')'" />
                <input id="coverage-edit-limit" name="limit_amount" inputmode="decimal" value="{{ old('limit_amount', $coverage->limit_amount) }}" aria-describedby="coverage-edit-limit-error" class="mt-1 w-full rounded border-slate-300">
                <x-field-error id="coverage-edit-limit-error" :messages="$errors->get('limit_amount')" />
            </div>
            <div>
                <x-input-label for="coverage-edit-deductible" :value="'Franchise ('.$policy->currency.')'" />
                <input id="coverage-edit-deductible" name="deductible_amount" inputmode="decimal" value="{{ old('deductible_amount', $coverage->deductible_amount) }}" aria-describedby="coverage-edit-deductible-error" class="mt-1 w-full rounded border-slate-300">
                <x-field-error id="coverage-edit-deductible-error" :messages="$errors->get('deductible_amount')" />
            </div>
            <div class="md:col-span-2 flex justify-end"><button class="rf-button-primary">Enregistrer</button></div>
        </form>
    </div>
</x-app-layout>
