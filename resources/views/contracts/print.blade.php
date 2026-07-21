<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contrat {{ $contract->contract_number }} — RentFleet</title>
    <style>
        :root{color:#172033;font-family:"Segoe UI",Arial,sans-serif;font-size:15px}*{box-sizing:border-box}body{max-width:920px;margin:32px auto;padding:0 24px;line-height:1.5}.brand{display:flex;align-items:center;gap:12px;border-bottom:3px solid #1859da;padding-bottom:18px}.mark{display:grid;width:42px;height:42px;place-items:center;border-radius:12px;background:#1859da;color:#fff;font-weight:800}.brand strong{font-size:22px}.muted{color:#64748b}.top{display:flex;justify-content:space-between;gap:24px;margin:30px 0}h1{font-size:28px;margin:0}h2{font-size:17px;margin:28px 0 12px;padding-bottom:6px;border-bottom:1px solid #dbe2ea}dl{display:grid;grid-template-columns:210px 1fr;gap:8px 20px;margin:0}dt{color:#64748b}dd{margin:0;font-weight:600}.amounts{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.box{border:1px solid #dbe2ea;border-radius:10px;padding:14px}ul{padding-left:20px}.print{border:0;border-radius:8px;background:#1859da;color:#fff;padding:10px 16px;font-weight:700;cursor:pointer}@page{margin:18mm}@media(max-width:620px){.top{display:block}.print{margin-top:16px}dl{grid-template-columns:1fr}.amounts{grid-template-columns:1fr}}@media print{body{margin:0;max-width:none;padding:0}.print{display:none}.box{break-inside:avoid}}
    </style>
</head>
<body>
    @php($terms = $contract->currentVersion?->terms_snapshot ?? [])
    <header class="brand"><span class="mark" aria-hidden="true">RF</span><div><strong>RentFleet</strong><div class="muted">Contrat de location automobile</div></div></header>
    <div class="top"><div><p class="muted">Contrat</p><h1>{{ $contract->contract_number }}</h1><p class="muted">Version {{ $contract->currentVersion?->version_number ?? '—' }} · document versionné</p></div><button type="button" class="print" onclick="window.print()">Imprimer</button></div>
    <h2>Parties et véhicule</h2>
    <dl><dt>Client</dt><dd>{{ $contract->customer->displayName() }}</dd><dt>Conducteur principal</dt><dd>{{ data_get($terms, 'driver.name', '—') }}</dd><dt>Véhicule</dt><dd>{{ $contract->vehicle->registration_number }} — {{ $contract->vehicle->brand }} {{ $contract->vehicle->model }}</dd><dt>Période prévue</dt><dd>{{ App\Support\Ui\UiLabel::dateTime($contract->expected_start_at) }} au {{ App\Support\Ui\UiLabel::dateTime($contract->expected_return_at) }}</dd></dl>
    <h2>Conditions tarifaires</h2>
    <div class="amounts"><div class="box"><span class="muted">Montant de location</span><br><strong>{{ App\Support\Ui\UiLabel::money($contract->rental_subtotal, $contract->currency) }}</strong></div><div class="box"><span class="muted">Caution requise</span><br><strong>{{ App\Support\Ui\UiLabel::money($contract->deposit_required, $contract->currency) }}</strong></div></div>
    <dl style="margin-top:14px"><dt>Kilométrage inclus par jour</dt><dd>{{ data_get($terms, 'included_km_per_day', '—') }} km</dd><dt>Kilomètre supplémentaire</dt><dd>{{ App\Support\Ui\UiLabel::money(data_get($terms, 'extra_km_rate'), $contract->currency) }}</dd><dt>Heure de retard</dt><dd>{{ App\Support\Ui\UiLabel::money(data_get($terms, 'late_hour_rate'), $contract->currency) }}</dd><dt>Carburant</dt><dd>Retour au même niveau que lors du départ</dd></dl>
    @if(collect(data_get($terms, 'clauses', []))->isNotEmpty())<h2>Clauses complémentaires</h2><ul>@foreach(collect(data_get($terms, 'clauses', []))->flatten() as $clause)@if(is_scalar($clause))<li>{{ $clause }}</li>@endif @endforeach</ul>@endif
    <h2>Acceptation</h2>
    @forelse($contract->acceptances as $acceptance)<p class="box">Accepté par <strong>{{ $acceptance->accepted_by_name }}</strong> le {{ App\Support\Ui\UiLabel::dateTime($acceptance->accepted_at) }}, par {{ mb_strtolower(App\Support\Ui\UiLabel::get($acceptance->acceptance_method)) }}.</p>@empty<p class="muted">Ce contrat n’est pas encore accepté.</p>@endforelse
    <p class="muted" style="margin-top:32px;font-size:12px">Document généré par RentFleet. La version contractuelle et ses preuves sont conservées dans le stockage privé de l’organisation.</p>
</body>
</html>
