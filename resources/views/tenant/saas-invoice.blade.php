<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Facture') }} {{ $invoice->number }} — BELKHIR SPACE</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white text-slate-900">
    <main class="rf-page mx-auto max-w-4xl p-6">
        <a class="mb-4 inline-block underline print:hidden" href="{{ auth()->user()->is_platform_admin ? route('platform.tenants.show', $invoice->tenant_id) : route('tenant-saas-account.show') }}">{{ __('Retour au compte SaaS') }}</a>
        <x-page-header :title="__('Facture SaaS ').$invoice->number" :eyebrow="__('Document de facturation')" :description="__('Document imprimable depuis le navigateur (Imprimer → Enregistrer en PDF).')" />
        <x-section-card :title="__('Détail de la période')">
            <x-metadata-list>
                <x-metadata-item :label="__('Émetteur')">{{ $invoice->snapshot['issuer'] }}</x-metadata-item>
                <x-metadata-item :label="__('Entreprise')">{{ $invoice->snapshot['customer'] }}</x-metadata-item>
                <x-metadata-item :label="__('Formule')">{{ $invoice->snapshot['plan'] }}</x-metadata-item>
                <x-metadata-item :label="__('Période')">{{ App\Support\Ui\UiLabel::dateTime($invoice->period_starts_at) }} → {{ App\Support\Ui\UiLabel::dateTime($invoice->period_ends_at) }}</x-metadata-item>
                <x-metadata-item :label="__('Montant')">{{ App\Support\Ui\UiLabel::money($invoice->amount, $invoice->currency) }}</x-metadata-item>
                <x-metadata-item :label="__('Échéance')">{{ App\Support\Ui\UiLabel::dateTime($invoice->due_at) }}</x-metadata-item>
                <x-metadata-item :label="__('État')">{{ match($invoice->status) { 'paid' => __('Réglée'), 'void' => __('Annulée'), default => __('À régler') } }}</x-metadata-item>
                <x-metadata-item :label="__('Date de règlement')">{{ App\Support\Ui\UiLabel::dateTime($invoice->paid_at) }}</x-metadata-item>
            </x-metadata-list>
            <p class="mt-6 text-sm text-slate-600">{{ __('Document de suivi SaaS. Mentions légales et régime fiscal à valider avant émission commerciale ; ce document n’atteste pas à lui seul d’une facture fiscale conforme. Aucun prélèvement automatique ni remboursement bancaire n’est déclenché par cette page.') }}</p>
        </x-section-card>
    </main>
</body>
</html>
