<x-guest-layout>
    <x-auth-heading eyebrow="Invitation personnelle" :title="'Créer votre espace '.config('brand.name')" :description="'Votre adresse '.$invitation->email.' a été invitée sur l’offre '.$invitation->plan->name.'.'" />

    <div class="mt-5 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm leading-6 text-blue-950">
        Un essai de <strong>{{ $invitation->trial_days }} jours</strong> commencera à la validation. Votre adresse e-mail sera considérée comme vérifiée grâce à ce lien personnel.
    </div>

    <form method="POST" action="{{ $acceptUrl }}" class="mt-7 space-y-7" data-loading-form>
        @csrf
        <x-form-errors />

        <fieldset class="space-y-4">
            <legend class="font-semibold text-belkhir-space-text">Votre entreprise</legend>
            <label class="block text-sm">Nom commercial<input name="company_name" required value="{{ old('company_name') }}" autocomplete="organization" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="block text-sm">Identifiant de l’espace<input name="company_slug" required value="{{ old('company_slug') }}" placeholder="exemple-location" pattern="[a-z0-9_-]+" class="mt-1 w-full rounded border-slate-300"><span class="mt-1 block text-xs text-slate-500">Lettres minuscules, chiffres, tirets et tirets bas.</span></label>
            <label class="block text-sm">Raison sociale <span class="text-slate-500">(facultatif)</span><input name="legal_name" value="{{ old('legal_name') }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="block text-sm">Téléphone <span class="text-slate-500">(facultatif)</span><input name="company_phone" value="{{ old('company_phone') }}" autocomplete="tel" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="block text-sm">Adresse <span class="text-slate-500">(facultatif)</span><textarea name="company_address" class="mt-1 w-full rounded border-slate-300">{{ old('company_address') }}</textarea></label>
        </fieldset>

        <fieldset class="space-y-4 border-t border-slate-200 pt-6">
            <legend class="font-semibold text-belkhir-space-text">Agence initiale</legend>
            <div class="grid gap-4 sm:grid-cols-2"><label class="text-sm">Code<input name="agency_code" required value="{{ old('agency_code') }}" class="mt-1 w-full rounded border-slate-300"></label><label class="text-sm">Nom<input name="agency_name" required value="{{ old('agency_name') }}" class="mt-1 w-full rounded border-slate-300"></label></div>
            <label class="block text-sm">E-mail <span class="text-slate-500">(facultatif)</span><input type="email" name="agency_email" value="{{ old('agency_email') }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="block text-sm">Téléphone <span class="text-slate-500">(facultatif)</span><input name="agency_phone" value="{{ old('agency_phone') }}" class="mt-1 w-full rounded border-slate-300"></label>
            <label class="block text-sm">Adresse <span class="text-slate-500">(facultatif)</span><textarea name="agency_address" class="mt-1 w-full rounded border-slate-300">{{ old('agency_address') }}</textarea></label>
        </fieldset>

        <fieldset class="space-y-4 border-t border-slate-200 pt-6">
            <legend class="font-semibold text-belkhir-space-text">Votre compte administrateur</legend>
            <label class="block text-sm">Nom complet<input name="owner_name" required value="{{ old('owner_name') }}" autocomplete="name" class="mt-1 w-full rounded border-slate-300"></label>
            <x-password-field id="password" name="password" label="Mot de passe" :messages="$errors->get('password')" autocomplete="new-password" />
            <x-password-field id="password_confirmation" name="password_confirmation" label="Confirmer le mot de passe" autocomplete="new-password" />
        </fieldset>

        <x-submit-button label="Créer mon espace" loading-label="Création sécurisée…" class="w-full" />
        <p class="text-center text-xs leading-5 text-slate-500">Ce lien ne pourra plus être utilisé après la création de votre espace.</p>
    </form>
</x-guest-layout>
