<x-app-layout>
    <form class="mx-auto max-w-4xl space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-sm" method="POST" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}">
        @csrf @if($customer->exists) @method('PUT') @endif
        <h1 class="text-2xl font-bold">{{ $customer->exists ? 'Modifier le client' : 'Nouveau client' }}</h1>
        <x-form-errors />
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm">Type *<select name="customer_type" class="mt-1 w-full">@foreach($types as $type)<option value="{{ $type->value }}" @selected(old('customer_type', $customer->customer_type?->value) === $type->value)>{{ App\Support\Ui\UiLabel::get($type) }}</option>@endforeach</select></label>
            <label class="text-sm">Agence<select name="agency_id" class="mt-1 w-full"><option value="">Aucune</option>@foreach($agencies as $agency)<option value="{{ $agency->id }}" @selected(old('agency_id', $customer->agency_id) == $agency->id)>{{ $agency->name }}</option>@endforeach</select></label>
            @foreach(['first_name'=>'Prénom','last_name'=>'Nom','company_name'=>'Société','email'=>'E-mail','phone'=>'Téléphone','city'=>'Ville','nationality'=>'Nationalité','birth_date'=>'Naissance','identity_type'=>'Type d’identité','identity_number'=>'Numéro d’identité'] as $name => $label)
                <label class="text-sm">{{ $label }}<input class="mt-1 w-full" type="{{ str_contains($name, 'date') ? 'date' : ($name === 'email' ? 'email' : 'text') }}" name="{{ $name }}" value="{{ old($name, $customer->$name) }}"><x-input-error :messages="$errors->get($name)" /></label>
            @endforeach
            <label class="text-sm">Vérification *<select name="verification_status" class="mt-1 w-full">@foreach($verificationStatuses as $status)<option value="{{ $status->value }}" @selected(old('verification_status', $customer->verification_status?->value) === $status->value)>{{ App\Support\Ui\UiLabel::get($status) }}</option>@endforeach</select></label>
        </div>
        <p class="text-xs text-slate-500">Le numéro d’identité est chiffré avant stockage et n’est jamais affiché dans les listes.</p>
        <button type="submit" class="rounded-lg bg-slate-950 px-4 py-2 text-white">Enregistrer</button>
    </form>
</x-app-layout>
