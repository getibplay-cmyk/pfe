<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Facture {{ $invoice->number }} — BELKHIR SPACE</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white text-slate-900">
    <main class="rf-page mx-auto max-w-4xl p-6">
        <a class="mb-4 inline-block underline print:hidden" href="{{ auth()->user()->is_platform_admin ? route('platform.tenants.show', $invoice->tenant_id) : route('tenant-saas-account.show') }}">Retour au compte SaaS</a>
        <x-page-header :title="'Facture SaaS '.$invoice->number" eyebrow="Document de facturation" description="Document imprimable depuis le navigateur (Imprimer → Enregistrer en PDF)." />
        <x-section-card title="Détail de la période">
            <x-metadata-list>
                <x-metadata-item label="Émetteur">{{ $invoice->snapshot['issuer'] }}</x-metadata-item>
                <x-metadata-item label="Entreprise">{{ $invoice->snapshot['customer'] }}</x-metadata-item>
                <x-metadata-item label="Formule">{{ $invoice->snapshot['plan'] }}</x-metadata-item>
                <x-metadata-item label="Période">{{ App\Support\Ui\UiLabel::dateTime($invoice->period_starts_at) }} → {{ App\Support\Ui\UiLabel::dateTime($invoice->period_ends_at) }}</x-metadata-item>
                <x-metadata-item label="Montant">{{ App\Support\Ui\UiLabel::money($invoice->amount, $invoice->currency) }}</x-metadata-item>
                <x-metadata-item label="Échéance">{{ App\Support\Ui\UiLabel::dateTime($invoice->due_at) }}</x-metadata-item>
                <x-metadata-item label="État">{{ match($invoice->status) { 'paid' => 'Réglée', 'void' => 'Annulée', default => 'À régler' } }}</x-metadata-item>
                <x-metadata-item label="Date de règlement">{{ App\Support\Ui\UiLabel::dateTime($invoice->paid_at) }}</x-metadata-item>
            </x-metadata-list>
            <p class="mt-6 text-sm text-slate-600">Document de suivi SaaS. Mentions légales et régime fiscal à valider avant émission commerciale ; ce document n’atteste pas à lui seul d’une facture fiscale conforme. Aucun prélèvement automatique ni remboursement bancaire n’est déclenché par cette page.</p>
        </x-section-card>
    </main>
</body>
</html>
