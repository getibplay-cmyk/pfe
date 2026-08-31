<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Contrat de location — {{ $contract->contract_number }}</title>
    <style>
        :root{color:#101827;font-family:Arial,"Segoe UI",sans-serif;font-size:10px}*{box-sizing:border-box}body{margin:0;background:#e7ebf0;color:#101827}.toolbar{display:flex;justify-content:center;padding:14px}.toolbar button{border:0;border-radius:7px;background:#123d77;color:#fff;cursor:pointer;font-weight:700;padding:10px 18px}.sheet{position:relative;width:210mm;min-height:297mm;margin:0 auto 18px;padding:10mm 11mm 12mm;background:#fff;box-shadow:0 8px 24px rgb(15 23 42 / 14%)}.page-number{position:absolute;right:11mm;bottom:6mm;color:#536174;font-size:8px}.masthead{display:grid;grid-template-columns:1.15fr .85fr;gap:8mm;align-items:start;border-bottom:3px solid #123d77;padding-bottom:4mm}.company-name{margin:0;color:#123d77;font-size:19px;line-height:1.15}.document-title{margin:1mm 0 0;font-size:13px;letter-spacing:.04em;text-transform:uppercase}.arabic-title{margin:1mm 0 0;font-size:14px;font-weight:700}.company-details{margin-top:2mm;color:#435065;line-height:1.45}.reference{border:1px solid #9ba8b8;border-radius:5px;padding:3mm}.reference dl,.facts{display:grid;grid-template-columns:34mm 1fr;gap:1.2mm 3mm;margin:0}dt{color:#536174}dd{margin:0;font-weight:700;overflow-wrap:anywhere}.notice{margin:3mm 0;border:1px solid #d9a72a;border-radius:4px;background:#fff8df;padding:2.5mm 3mm;color:#5c4310}.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:3mm}.section{margin-top:3mm;border:1px solid #9ba8b8;border-radius:5px;break-inside:avoid}.section h2{margin:0;padding:1.7mm 2.5mm;background:#edf3fb;color:#123d77;font-size:10.5px;text-transform:uppercase}.section-body{padding:2.4mm}.section .facts{grid-template-columns:31mm 1fr}.driver+.driver{margin-top:2mm;border-top:1px dashed #b8c0ca;padding-top:2mm}.driver-role{margin-bottom:1mm;color:#123d77;font-weight:700}table{width:100%;border-collapse:collapse}th,td{border:1px solid #aeb8c5;padding:1.35mm 1.6mm;text-align:left;vertical-align:top}th{background:#edf3fb;color:#26364d}.financial td:last-child{text-align:right;white-space:nowrap}.financial .total td{border-top:2px solid #123d77;font-weight:700}.vehicle-state{display:grid;grid-template-columns:55mm 1fr;gap:3mm;align-items:center}.vehicle-diagram{width:100%;max-height:32mm;color:#536174}.inspection-summary{color:#435065;font-size:8.5px}.inspection-summary strong{color:#101827}.mini-list{margin:1mm 0 0;padding-left:4mm}.extension{margin-top:3mm;border:1px solid #9ba8b8;padding:2.5mm}.signatures{display:grid;grid-template-columns:repeat(3,1fr);gap:3mm;margin-top:3mm}.signature-box{min-height:22mm;border:1px solid #9ba8b8;padding:2mm}.signature-box strong{display:block;margin-bottom:2mm;color:#123d77}.acceptance{margin-top:2mm;font-size:8.5px}.legal-note{margin-top:3mm;color:#536174;font-size:8px}.terms-header{display:grid;grid-template-columns:1fr 1fr;gap:5mm;align-items:end;border-bottom:3px solid #123d77;padding-bottom:3mm}.terms-header h1{margin:0;color:#123d77;font-size:15px}.term-row{display:grid;grid-template-columns:1fr 1fr;border:1px solid #9ba8b8;border-top:0;break-inside:avoid}.term{padding:1.7mm 2mm;font-size:7.8px;line-height:1.35}.term+.term{border-left:1px solid #9ba8b8}.term h2{margin:0 0 .8mm;color:#123d77;font-size:8.4px}.term p{margin:0}.term[dir=rtl]{font-family:Tahoma,Arial,sans-serif;font-size:8.2px;text-align:right}.privacy{margin-top:2.5mm;border-top:1px solid #9ba8b8;background:#f6f8fb}.historical-clauses{margin-top:5mm;border:1px solid #9ba8b8;padding:4mm}@page{size:A4 portrait;margin:0}@media print{body{background:#fff}.toolbar{display:none!important}.sheet{width:210mm;height:297mm;min-height:297mm;margin:0;box-shadow:none;break-after:page;page-break-after:always;overflow:hidden}.sheet:last-child{break-after:auto;page-break-after:auto}}@media screen and (max-width:850px){.sheet{width:calc(100% - 20px);min-height:auto;padding:18px}.grid-2,.masthead,.terms-header{grid-template-columns:1fr}.term-row{grid-template-columns:1fr}.term+.term{border-left:0;border-top:1px solid #9ba8b8}.signatures{grid-template-columns:1fr}}
    </style>
</head>
<body>
    @php
        $document = $contractDocument['document'];
        $company = data_get($document, 'company', []);
        $agency = data_get($document, 'agency', []);
        $customer = data_get($document, 'customer', []);
        $drivers = collect(data_get($document, 'drivers', []));
        $vehicle = data_get($document, 'vehicle', []);
        $rental = data_get($document, 'rental', []);
        $inspections = collect(data_get($document, 'inspection_summary', []));
        $returnInspection = $inspections->firstWhere('type', 'return');
        $accessories = $inspections->flatMap(fn ($inspection) => data_get($inspection, 'items', []))
            ->filter(fn ($item) => preg_match('/key|cle|document|accessor/i', (string) data_get($item, 'code')) === 1);
        $conditions = collect(data_get($document, 'conditions', []));
        $financial = $contractDocument['financial'];
        $money = fn ($amount) => App\Support\Ui\UiLabel::money($amount, $financial['currency']);
        $date = function ($value, bool $withTime = false) {
            if (! $value) return 'Non renseigné';
            try {
                $parsed = Carbon\CarbonImmutable::parse($value);
                return $withTime ? App\Support\Ui\UiLabel::dateTime($parsed) : App\Support\Ui\UiLabel::date($parsed);
            } catch (Throwable) {
                return 'Non renseigné';
            }
        };
        $display = fn ($value) => filled($value) ? $value : 'Non renseigné';
    @endphp

    <div class="toolbar" aria-label="Actions d’impression"><button type="button" onclick="window.print()"><x-icon name="print" size="xs" />Imprimer le contrat</button></div>
    <main>
        <section class="sheet" data-contract-page="1">
            <header class="masthead">
                <div>
                    <h1 class="company-name">{{ $display(data_get($company, 'legal_name') ?: data_get($company, 'name')) }}</h1>
                    <p class="document-title">Contrat de location de véhicule</p>
                    <p class="arabic-title" dir="rtl" lang="ar">عقد كراء مركبة</p>
                    <div class="company-details">
                        @if(data_get($company, 'address'))<div>{{ data_get($company, 'address') }}</div>@endif
                        @if(data_get($company, 'phone'))<div>{{ data_get($company, 'phone') }}</div>@endif
                        @if(data_get($company, 'email'))<div>{{ data_get($company, 'email') }}</div>@endif
                        <div>Agence : {{ $display(data_get($agency, 'name')) }}</div>
                    </div>
                </div>
                <div class="reference"><dl>
                    <dt>Contrat</dt><dd>{{ $contract->contract_number }}</dd>
                    <dt>Version</dt><dd>{{ $contractDocument['version']?->version_number ?? 'Non renseignée' }}</dd>
                    <dt>État</dt><dd>{{ App\Support\Ui\UiLabel::get($contract->status) }}</dd>
                    <dt>Émise le</dt><dd>{{ $date(data_get($document, 'issued_at'), true) }}</dd>
                </dl></div>
            </header>

            @if($contractDocument['historical'])
                <div class="notice" role="status">Version historique : ce document restitue uniquement les données et clauses figées lors de sa création. Les conditions bilingues actuelles ne lui sont pas appliquées rétroactivement.</div>
            @else
                <div class="notice" role="note">Modèle contractuel générique RentFleet à personnaliser et à faire valider par un professionnel du droit avant tout usage de production.</div>
            @endif

            <div class="grid-2">
                <section class="section"><h2>Entreprise et agence / الشركة والوكالة</h2><div class="section-body"><dl class="facts">
                    <dt>Entreprise</dt><dd>{{ $display(data_get($company, 'legal_name') ?: data_get($company, 'name')) }}</dd>
                    <dt>Agence</dt><dd>{{ $display(data_get($agency, 'name')) }}</dd>
                    <dt>Adresse</dt><dd>{{ $display(data_get($agency, 'address') ?: data_get($company, 'address')) }}</dd>
                    <dt>Téléphone</dt><dd>{{ $display(data_get($agency, 'phone') ?: data_get($company, 'phone')) }}</dd>
                    <dt>E-mail</dt><dd>{{ $display(data_get($agency, 'email') ?: data_get($company, 'email')) }}</dd>
                </dl></div></section>
                <section class="section"><h2>Locataire / المكتري</h2><div class="section-body"><dl class="facts">
                    <dt>Nom</dt><dd>{{ $display(data_get($customer, 'name') ?: data_get($customer, 'display_name')) }}</dd>
                    <dt>Naissance</dt><dd>{{ $date(data_get($customer, 'birth_date')) }}</dd>
                    <dt>Nationalité</dt><dd>{{ $display(data_get($customer, 'nationality')) }}</dd>
                    <dt>Adresse</dt><dd>{{ $display(collect([data_get($customer, 'address'),data_get($customer, 'city')])->filter()->join(', ')) }}</dd>
                    <dt>Téléphone</dt><dd>{{ $display(data_get($customer, 'phone')) }}</dd>
                    <dt>Identité</dt><dd>{{ $display(data_get($customer, 'identity_type')) }} — {{ $display(data_get($customer, 'identity_reference_masked')) }}</dd>
                </dl></div></section>
            </div>

            <div class="grid-2">
                <section class="section"><h2>Conducteurs autorisés / السائقون المأذون لهم</h2><div class="section-body">
                    @forelse($drivers as $driver)<div class="driver"><div class="driver-role">{{ data_get($driver, 'role') === 'primary' ? 'Conducteur principal' : 'Conducteur additionnel' }}</div><dl class="facts">
                        <dt>Nom</dt><dd>{{ $display(data_get($driver, 'name')) }}</dd>
                        <dt>Naissance</dt><dd>{{ $date(data_get($driver, 'birth_date')) }}</dd>
                        <dt>Permis</dt><dd>{{ $display(data_get($driver, 'licence_reference_masked')) }}</dd>
                        <dt>Catégorie</dt><dd>{{ $display(data_get($driver, 'licence_category')) }}</dd>
                        <dt>Validité</dt><dd>{{ $date(data_get($driver, 'licence_issued_at')) }} — {{ $date(data_get($driver, 'licence_expires_at')) }}</dd>
                    </dl></div>@empty<p>Aucun conducteur capturé dans cette version.</p>@endforelse
                </div></section>
                <section class="section"><h2>Véhicule / المركبة</h2><div class="section-body"><dl class="facts">
                    <dt>Marque / modèle</dt><dd>{{ $display(collect([data_get($vehicle, 'brand'),data_get($vehicle, 'model')])->filter()->join(' ')) }}</dd>
                    <dt>Immatriculation</dt><dd>{{ $display(data_get($vehicle, 'registration_number')) }}</dd>
                    <dt>Catégorie</dt><dd>{{ $display(data_get($vehicle, 'category')) }}</dd>
                    <dt>Couleur</dt><dd>{{ $display(data_get($vehicle, 'color')) }}</dd>
                    <dt>Carburant</dt><dd>{{ $display(App\Support\Ui\UiLabel::get(data_get($vehicle, 'fuel_type'))) }}</dd>
                    <dt>Transmission</dt><dd>{{ $display(App\Support\Ui\UiLabel::get(data_get($vehicle, 'transmission'))) }}</dd>
                </dl></div></section>
            </div>

            <div class="grid-2">
                <section class="section"><h2>Durée et lieux / المدة والأماكن</h2><div class="section-body"><dl class="facts">
                    <dt>Départ prévu</dt><dd>{{ $date(data_get($rental, 'expected_start_at'), true) }}</dd>
                    <dt>Retour prévu</dt><dd>{{ $date(data_get($rental, 'expected_return_at'), true) }}</dd>
                    @if($returnInspection)<dt>Retour constaté</dt><dd>{{ $date(data_get($returnInspection, 'inspected_at'), true) }}</dd>@endif
                    <dt>Lieu de départ</dt><dd>{{ $display(data_get($rental, 'departure_location')) }}</dd>
                    <dt>Lieu de retour</dt><dd>{{ $display(data_get($rental, 'return_location')) }}</dd>
                    <dt>Jours facturés</dt><dd>{{ $display($financial['billed_days']) }}</dd>
                </dl></div></section>
                <section class="section"><h2>Synthèse financière officielle / الملخص المالي</h2><div class="section-body"><table class="financial"><tbody>
                    <tr><td>Tarif journalier</td><td>{{ $money($financial['daily_rate']) }}</td></tr>
                    <tr><td>Sous-total location</td><td>{{ $money($financial['subtotal']) }}</td></tr>
                    @if($financial['options_total'] !== null)<tr><td>Options</td><td>{{ $money($financial['options_total']) }}</td></tr>@endif
                    @if($financial['tax_amount'] !== null)<tr><td>Taxe officielle</td><td>{{ $money($financial['tax_amount']) }}</td></tr>@endif
                    @foreach($financial['approved_return_charges'] as $charge)<tr><td>{{ $charge->description }}</td><td>{{ $money($charge->total_amount) }}</td></tr>@endforeach
                    <tr class="total"><td>Total</td><td>{{ $money($financial['total_amount']) }}</td></tr>
                    <tr><td>Caution requise</td><td>{{ $money($financial['deposit_required']) }}</td></tr>
                    <tr><td>Montant encaissé</td><td>{{ $money($financial['amount_paid']) }}</td></tr>
                    <tr><td>Solde</td><td>{{ $money($financial['balance_due']) }}</td></tr>
                </tbody></table></div></section>
            </div>

            <section class="section"><h2>État du véhicule et inspections / حالة المركبة والمعاينات</h2><div class="section-body vehicle-state">
                <svg class="vehicle-diagram" viewBox="0 0 260 150" role="img" aria-label="Schéma original avec vues avant, arrière, côté et dessus du véhicule, sans marqueur de dommage">
                    <g fill="none" stroke="currentColor" stroke-width="2">
                        <g transform="translate(4 8)"><path d="M8 25h8l8-12h50l10 12h9c7 0 12 5 12 12v8H2v-8c0-7 3-12 6-12Z"/><circle cx="25" cy="45" r="7" fill="white"/><circle cx="82" cy="45" r="7" fill="white"/><path d="M27 15 20 25h58l-7-10Z"/></g>
                        <g transform="translate(151 4)"><path d="M22 2h44l12 18v59L66 96H22L10 79V20Z"/><path d="M22 20h44M22 78h44M22 20 15 35v29l7 14M66 20l7 15v29l-7 14"/><circle cx="18" cy="30" r="4"/><circle cx="70" cy="30" r="4"/><circle cx="18" cy="68" r="4"/><circle cx="70" cy="68" r="4"/></g>
                        <g transform="translate(10 105)"><path d="M5 20 16 5h57l11 15v20H5Z"/><path d="M20 8h48l7 12H13Z"/><circle cx="18" cy="41" r="4"/><circle cx="71" cy="41" r="4"/></g>
                        <g transform="translate(145 105)"><path d="M5 20 16 7h57l11 13v20H5Z"/><path d="M14 20h61M20 31h48"/><circle cx="18" cy="41" r="4"/><circle cx="71" cy="41" r="4"/></g>
                    </g>
                    <g fill="currentColor" font-family="Arial,sans-serif" font-size="8"><text x="38" y="68">Côté</text><text x="180" y="108">Dessus</text><text x="42" y="148">Avant</text><text x="177" y="148">Arrière</text></g>
                </svg>
                <div class="inspection-summary">
                    @forelse($inspections as $inspection)<div><strong>{{ App\Support\Ui\UiLabel::get(data_get($inspection, 'type')) }}</strong> — {{ $date(data_get($inspection, 'inspected_at'), true) }} — {{ $display(data_get($inspection, 'mileage')) }} km — carburant {{ $display(data_get($inspection, 'fuel_level')) }}
                        @if(data_get($inspection, 'notes'))<div>Note : {{ data_get($inspection, 'notes') }}</div>@endif
                        @php($items=collect(data_get($inspection, 'items', [])))
                        @if($items->isNotEmpty())<ul class="mini-list">@foreach($items->take(8) as $item)<li>{{ $display(data_get($item, 'label')) }} : {{ App\Support\Ui\UiLabel::get(data_get($item, 'condition')) }}@if(data_get($item, 'notes')) — {{ data_get($item, 'notes') }}@endif</li>@endforeach @if($items->count()>8)<li>{{ $items->count()-8 }} autre(s) point(s) capturé(s) dans cette version.</li>@endif</ul>@endif
                    </div>@empty<p>Aucune inspection terminée n’était capturée dans cette version.</p>@endforelse
                    <div><strong>Accessoires et documents remis</strong> : @if($accessories->isEmpty()) aucune liste dédiée capturée dans cette version.@else {{ $accessories->pluck('label')->filter()->join(', ') }}.@endif</div>
                    @if($contractDocument['damages']->isNotEmpty())<div><strong>Dommages déclarés</strong></div><ul class="mini-list">@foreach($contractDocument['damages'] as $damage)<li>{{ $damage->description }} — {{ App\Support\Ui\UiLabel::get($damage->status) }}@if(!in_array($damage->responsibility,[App\Enums\DamageResponsibility::Pending,App\Enums\DamageResponsibility::Unknown],true)) — Décision humaine : {{ App\Support\Ui\UiLabel::get($damage->responsibility) }}@endif</li>@endforeach</ul>@endif
                </div>
            </div></section>

            <div class="extension">Toute prolongation doit être demandée avant l’échéance et reste soumise à la disponibilité, à l’accord de l’agence et à une mise à jour officielle du contrat. / <span lang="ar" dir="rtl">يجب طلب كل تمديد قبل حلول الأجل، ويظل خاضعاً لتوفر المركبة وموافقة الوكالة وتحيين رسمي للعقد.</span></div>
            <div class="acceptance">@forelse($contractDocument['acceptances'] as $acceptance)<strong>Acceptation électronique enregistrée</strong> — acteur : {{ $acceptance->accepted_by_name }} — rôle : locataire — date : {{ App\Support\Ui\UiLabel::dateTime($acceptance->accepted_at) }} — version acceptée : {{ $contractDocument['version']?->version_number }}.@empty Aucune acceptation n’est encore enregistrée pour cette version.@endforelse</div>
            <div class="signatures" aria-label="Emplacements de signature manuscrite"><div class="signature-box"><strong>Le locataire / المكتري</strong>Date et signature :<br>État au départ :<br>@if($returnInspection)État au retour :@endif</div>@if($drivers->contains(fn($driver)=>data_get($driver,'role')==='additional'))<div class="signature-box"><strong>Conducteur additionnel / السائق الإضافي</strong>Date et signature :</div>@endif<div class="signature-box"><strong>Représentant de l’agence / ممثل الوكالة</strong>Date, cachet et signature :<br>État au départ :<br>@if($returnInspection)État au retour :@endif</div></div>
            <p class="legal-note">L’enregistrement d’une acceptation dans RentFleet constitue une preuve applicative selon le processus configuré ; le document ne prétend pas utiliser une signature électronique qualifiée.</p>
            <span class="page-number">Page 1 / 2</span>
        </section>

        <section class="sheet" data-contract-page="2">
            <header class="terms-header"><h1>Conditions générales de location</h1><h1 lang="ar" dir="rtl">الشروط العامة لكراء المركبات</h1></header>
            @if($contractDocument['historical'])
                <div class="notice">Version historique : aucune condition bilingue actuelle n’est ajoutée à ce document.</div>
                <div class="historical-clauses"><h2>Clauses capturées dans la version d’origine</h2>@php($legacyClauses=collect(data_get($document,'legacy_clauses',[]))->flatten()->filter(fn($clause)=>is_scalar($clause))) @forelse($legacyClauses as $clause)<p>{{ $clause }}</p>@empty<p>Aucune clause textuelle n’avait été capturée dans cette version.</p>@endforelse</div>
            @else
                @foreach($conditions as $condition)
                    <article class="term-row" data-contract-article="{{ data_get($condition, 'number') }}"><div class="term" lang="fr"><h2>Article {{ data_get($condition, 'number') }} — {{ data_get($condition, 'fr.title') }}</h2><p>{{ data_get($condition, 'fr.body') }}</p></div><div class="term" lang="ar" dir="rtl"><h2>المادة {{ data_get($condition, 'number') }} — {{ data_get($condition, 'ar.title') }}</h2><p>{{ data_get($condition, 'ar.body') }}</p></div></article>
                @endforeach
                <section class="term-row privacy" aria-label="Protection des données personnelles"><div class="term" lang="fr"><h2>Protection des données personnelles</h2><p>{{ data_get($document, 'data_protection.fr') }}</p></div><div class="term" lang="ar" dir="rtl"><h2>حماية المعطيات ذات الطابع الشخصي</h2><p>{{ data_get($document, 'data_protection.ar') }}</p></div></section>
            @endif
            <p class="legal-note">Document bilingue original généré à partir des données figées de la version contractuelle. Les textes doivent être adaptés aux garanties, limites, pratiques et mentions légales réellement applicables à l’entreprise.</p>
            <span class="page-number">Page 2 / 2</span>
        </section>
    </main>
    @if($autoPrint)<script>window.addEventListener('load',function(){window.print();});</script>@endif
</body>
</html>
