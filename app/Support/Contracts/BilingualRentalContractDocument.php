<?php

namespace App\Support\Contracts;

use App\Enums\ContractChargeStatus;
use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Models\ContractVersion;
use App\Models\RentalContract;
use App\Models\Tenant;
use App\Support\SensitiveData\IdentityProtector;
use Illuminate\Contracts\Encryption\DecryptException;

final class BilingualRentalContractDocument
{
    public const TEMPLATE_ID = 'rentfleet-rental-contract-fr-ar';

    public const TEMPLATE_VERSION = '1.0';

    public const CONDITIONS_VERSION = '2026-08-30';

    public const PRIMARY_LANGUAGE = 'fr';

    public function __construct(private readonly IdentityProtector $identities) {}

    /** @return array<string, mixed> */
    public function snapshot(RentalContract $contract): array
    {
        $contract->loadMissing([
            'reservation',
            'agency',
            'customer',
            'vehicle.category',
            'drivers.driver',
            'inspections.items',
        ]);

        $tenant = Tenant::query()->findOrFail($contract->tenant_id);
        $drivers = $contract->drivers
            ->sortByDesc('is_primary')
            ->values()
            ->map(fn ($contractDriver): array => [
                'role' => $contractDriver->is_primary ? 'primary' : 'additional',
                'name' => trim($contractDriver->driver->first_name.' '.$contractDriver->driver->last_name),
                'birth_date' => $contractDriver->driver->birth_date?->toDateString(),
                'licence_reference_masked' => $this->mask($contractDriver->driver->licence_number_encrypted),
                'licence_category' => $contractDriver->driver->licence_category,
                'licence_issued_at' => $contractDriver->driver->licence_issued_at?->toDateString(),
                'licence_expires_at' => $contractDriver->driver->licence_expires_at?->toDateString(),
            ])
            ->all();

        $inspections = $contract->inspections
            ->where('status', InspectionStatus::Completed)
            ->sortBy('inspected_at')
            ->values()
            ->map(fn ($inspection): array => [
                'type' => $inspection->inspection_type->value,
                'inspected_at' => $inspection->inspected_at?->toIso8601String(),
                'mileage' => $inspection->mileage,
                'fuel_level' => $inspection->fuel_level,
                'notes' => $inspection->notes,
                'items' => $inspection->items->map(fn ($item): array => [
                    'code' => $item->item_code,
                    'label' => $item->label,
                    'condition' => $item->condition->value,
                    'notes' => $item->notes,
                ])->values()->all(),
            ])
            ->all();

        return [
            'template_id' => self::TEMPLATE_ID,
            'template_version' => self::TEMPLATE_VERSION,
            'conditions_version' => self::CONDITIONS_VERSION,
            'primary_language' => self::PRIMARY_LANGUAGE,
            'issued_at' => now()->toIso8601String(),
            'company' => [
                'name' => $tenant->name,
                'legal_name' => $tenant->legal_name,
                'address' => data_get($tenant->settings, 'address'),
                'phone' => $tenant->phone,
                'email' => $tenant->email,
            ],
            'agency' => [
                'name' => $contract->agency->name,
                'address' => $contract->agency->address,
                'phone' => $contract->agency->phone,
                'email' => $contract->agency->email,
            ],
            'customer' => [
                'name' => $contract->customer->displayName(),
                'birth_date' => $contract->customer->birth_date?->toDateString(),
                'nationality' => $contract->customer->nationality,
                'address' => $contract->customer->address,
                'city' => $contract->customer->city,
                'phone' => $contract->customer->phone,
                'identity_type' => $contract->customer->identity_type,
                'identity_reference_masked' => $this->mask($contract->customer->identity_number_encrypted),
            ],
            'drivers' => $drivers,
            'vehicle' => [
                'brand' => $contract->vehicle->brand,
                'model' => $contract->vehicle->model,
                'registration_number' => $contract->vehicle->registration_number,
                'category' => $contract->vehicle->category?->name,
                'color' => $contract->vehicle->color,
                'fuel_type' => $contract->vehicle->fuel_type,
                'transmission' => $contract->vehicle->transmission,
            ],
            'rental' => [
                'expected_start_at' => $contract->expected_start_at->toIso8601String(),
                'expected_return_at' => $contract->expected_return_at->toIso8601String(),
                'departure_location' => $contract->agency->name,
                'return_location' => $contract->agency->name,
                'billed_days' => data_get($contract->reservation->pricing_snapshot, 'calculation.billed_days'),
            ],
            'inspection_summary' => $inspections,
            'conditions' => $this->conditions(),
            'data_protection' => [
                'fr' => 'Les données sont traitées pour gérer la location et les obligations associées. Leur accès est limité aux personnes autorisées et leur conservation suit les obligations applicables. Les droits d’accès, de rectification et d’opposition s’exercent conformément à la loi n° 09-08 auprès de l’entreprise lorsque ses coordonnées sont configurées.',
                'ar' => 'تُعالج البيانات من أجل تدبير عملية الكراء والالتزامات المرتبطة بها. ويقتصر الولوج إليها على الأشخاص المأذون لهم، كما تُحفظ وفق الالتزامات المعمول بها. ويمكن ممارسة حقوق الولوج والتصحيح والتعرض طبقاً للقانون رقم 09-08 لدى الشركة متى كانت بيانات الاتصال بها مضبوطة.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function present(RentalContract $contract): array
    {
        $contract->loadMissing([
            'currentVersion',
            'acceptances',
            'inspections.items',
            'damages',
            'charges',
            'invoice.lines',
        ]);

        $version = $contract->currentVersion;
        $isCurrentTemplate = $this->isCurrentTemplate($version);
        $document = $isCurrentTemplate
            ? data_get($version?->terms_snapshot, 'document', [])
            : $this->historicalSnapshot($version);
        $departure = $contract->inspections->first(
            fn ($inspection): bool => $inspection->inspection_type === InspectionType::Departure
                && $inspection->status === InspectionStatus::Completed,
        );
        $return = $contract->inspections->first(
            fn ($inspection): bool => $inspection->inspection_type === InspectionType::Return
                && $inspection->status === InspectionStatus::Completed,
        );
        $pricing = $version?->pricing_snapshot ?? [];
        $invoice = $contract->invoice;

        return [
            'historical' => ! $isCurrentTemplate,
            'document' => $document,
            'version' => $version,
            'acceptances' => $version
                ? $contract->acceptances->where('contract_version_id', $version->id)->sortBy('accepted_at')->values()
                : collect(),
            'departure' => $departure,
            'return' => $return,
            'damages' => $contract->damages->sortBy('created_at')->values(),
            'financial' => [
                'currency' => data_get($pricing, 'calculation.currency', $contract->currency),
                'billed_days' => data_get($pricing, 'calculation.billed_days', data_get($pricing, 'billed_days')),
                'daily_rate' => data_get($pricing, 'calculation.daily_rate', data_get($pricing, 'daily_rate')),
                'subtotal' => data_get($pricing, 'calculation.subtotal', $contract->rental_subtotal),
                'options_total' => data_get($pricing, 'calculation.options_total'),
                'tax_amount' => $invoice && $invoice->tax_mode !== 'none' ? $invoice->tax_amount : null,
                'deposit_required' => data_get($pricing, 'calculation.deposit_amount', $contract->deposit_required),
                'amount_paid' => $invoice?->paid_amount ?? $contract->amount_paid,
                'balance_due' => $invoice?->balance_due ?? $contract->balance_due,
                'approved_return_charges' => $contract->charges
                    ->where('status', ContractChargeStatus::Approved)
                    ->sortBy('created_at')
                    ->values(),
                'total_amount' => $invoice?->total_amount
                    ?? data_get($pricing, 'calculation.total_amount', $contract->total_amount),
            ],
        ];
    }

    /** @return array{historical: bool, template_version: ?string, conditions_version: ?string} */
    public function metadata(RentalContract $contract): array
    {
        $version = $contract->currentVersion;

        return [
            'historical' => ! $this->isCurrentTemplate($version),
            'template_version' => data_get($version?->terms_snapshot, 'document.template_version'),
            'conditions_version' => data_get($version?->terms_snapshot, 'document.conditions_version'),
        ];
    }

    /** @return list<array{number: int, key: string, fr: array{title: string, body: string}, ar: array{title: string, body: string}}> */
    public function conditions(): array
    {
        return [
            [
                'number' => 1,
                'key' => 'parties-object',
                'fr' => [
                    'title' => 'Parties et objet du contrat',
                    'body' => 'Le présent contrat identifie l’entreprise et l’agence qui mettent le véhicule à disposition, ainsi que le locataire. Il précise le véhicule, la période de location et les conducteurs expressément autorisés. Toute autre personne est exclue de l’autorisation de conduite.',
                ],
                'ar' => [
                    'title' => 'أطراف العقد وموضوعه',
                    'body' => 'يحدد هذا العقد الشركة والوكالة اللتين تضعان المركبة رهن إشارة المكتري، كما يحدد هوية المكتري. ويبين المركبة ومدة الكراء والسائقين المأذون لهم صراحة. ولا يسمح لأي شخص آخر بقيادتها.',
                ],
            ],
            [
                'number' => 2,
                'key' => 'vehicle-handover-return',
                'fr' => [
                    'title' => 'État, remise et restitution du véhicule',
                    'body' => 'L’état du véhicule est constaté lors du départ. Le véhicule doit être restitué dans l’état documenté, sous réserve de l’usure normale, avec le kilométrage, le carburant, les clés, accessoires et documents relevés. Une inspection contradictoire est réalisée lorsque les parties peuvent y participer.',
                ],
                'ar' => [
                    'title' => 'حالة المركبة وتسليمها وإرجاعها',
                    'body' => 'تُثبت حالة المركبة عند الانطلاق. ويجب إرجاعها بالحالة الموثقة، مع مراعاة الاستعمال العادي، ومع بيان المسافة المقطوعة والوقود والمفاتيح والملحقات والوثائق المسلمة. ويُنجز فحص بحضور الطرفين كلما أمكن ذلك.',
                ],
            ],
            [
                'number' => 3,
                'key' => 'maintenance-breakdown-repair',
                'fr' => [
                    'title' => 'Entretien, panne et réparation',
                    'body' => 'L’entretien normal du véhicule relève de l’agence. En cas de panne ou d’immobilisation, le locataire contacte l’agence et suit ses instructions avant toute réparation. Aucun remboursement n’est promis sans justificatif recevable et accord préalable de l’agence.',
                ],
                'ar' => [
                    'title' => 'الصيانة والعطل والإصلاح',
                    'body' => 'تتحمل الوكالة الصيانة العادية للمركبة. وعند وقوع عطل أو توقف المركبة، يتعين على المكتري الاتصال بالوكالة واتباع تعليماتها قبل أي إصلاح. ولا يُضمن أي تعويض دون إثبات مقبول وموافقة مسبقة من الوكالة.',
                ],
            ],
            [
                'number' => 4,
                'key' => 'authorized-use',
                'fr' => [
                    'title' => 'Utilisation autorisée',
                    'body' => 'La conduite est réservée aux conducteurs inscrits au contrat et titulaires d’un permis valide. Le véhicule doit être utilisé conformément au Code de la route et aux limitations géographiques réellement convenues. Sont interdits l’usage illégal, la compétition, la sous-location et toute conduite non autorisée.',
                ],
                'ar' => [
                    'title' => 'الاستعمال المسموح به',
                    'body' => 'تقتصر القيادة على السائقين المسجلين في العقد والحاملين لرخصة سياقة سارية. ويجب استعمال المركبة وفق قانون السير والحدود الجغرافية المتفق عليها فعلياً. ويُمنع الاستعمال غير المشروع أو في المنافسات أو الكراء من الباطن أو القيادة دون إذن.',
                ],
            ],
            [
                'number' => 5,
                'key' => 'insurance-incident',
                'fr' => [
                    'title' => 'Assurance, accident, vol et incident',
                    'body' => 'Les garanties applicables sont uniquement celles effectivement souscrites et communiquées. En cas d’accident, vol ou incident, le locataire sécurise le véhicule, prévient rapidement l’agence, établit les constats et déclarations dans les délais applicables et remet les documents nécessaires. Il coopère au traitement du dossier.',
                ],
                'ar' => [
                    'title' => 'التأمين والحادث والسرقة والواقعة',
                    'body' => 'لا تسري إلا الضمانات المكتتبة فعلياً والمبلغة. وعند وقوع حادث أو سرقة أو واقعة، يعمل المكتري على تأمين المركبة وإخبار الوكالة سريعاً وإنجاز المعاينات والتصريحات داخل الآجال المعمول بها وتسليم الوثائق اللازمة. كما يتعاون في معالجة الملف.',
                ],
            ],
            [
                'number' => 6,
                'key' => 'price-deposit-payment-extension-return',
                'fr' => [
                    'title' => 'Prix, caution, paiement, prolongation et retour',
                    'body' => 'Le prix, la caution et les frais applicables sont présentés avant l’acceptation. Toute prolongation doit être demandée avant l’échéance et reste soumise à la disponibilité et à l’accord de l’agence, puis à une mise à jour officielle du contrat. Un retour tardif est traité uniquement selon les règles et montants acceptés.',
                ],
                'ar' => [
                    'title' => 'الثمن والضمان والأداء والتمديد والإرجاع',
                    'body' => 'يُعرض الثمن والضمان والمصاريف المطبقة قبل القبول. ويجب طلب أي تمديد قبل حلول الأجل، ويظل رهيناً بتوفر المركبة وموافقة الوكالة وتحيين العقد رسمياً. ولا يُعالج التأخر في الإرجاع إلا وفق القواعد والمبالغ المقبولة.',
                ],
            ],
            [
                'number' => 7,
                'key' => 'damage-fees',
                'fr' => [
                    'title' => 'Dommages et frais',
                    'body' => 'Les dommages sont documentés par les inspections et les preuves disponibles, en distinguant l’usure normale. Aucun outil automatisé ne décide de la responsabilité. Toute responsabilité et tout frais exigent une validation humaine, un détail compréhensible, une justification et une trace dans le dossier.',
                ],
                'ar' => [
                    'title' => 'الأضرار والمصاريف',
                    'body' => 'تُوثق الأضرار بواسطة الفحوص والأدلة المتاحة مع تمييز الاستعمال العادي. ولا تقرر أي أداة آلية المسؤولية. وتستلزم كل مسؤولية أو مصاريف قراراً بشرياً وتفصيلاً واضحاً وتبريراً وأثراً محفوظاً في الملف.',
                ],
            ],
            [
                'number' => 8,
                'key' => 'keys-accessories-documents',
                'fr' => [
                    'title' => 'Clés, accessoires et documents du véhicule',
                    'body' => 'Les clés, accessoires et documents remis sont inventoriés dans l’état de départ. Ils doivent être restitués avec le véhicule. Toute perte est examinée à partir des éléments documentés et ne peut donner lieu qu’à des coûts justifiés et acceptés selon le contrat.',
                ],
                'ar' => [
                    'title' => 'مفاتيح المركبة وملحقاتها ووثائقها',
                    'body' => 'تُدرج المفاتيح والملحقات والوثائق المسلمة ضمن بيان حالة الانطلاق، ويجب إرجاعها مع المركبة. وتُدرس كل حالة فقدان استناداً إلى العناصر الموثقة، ولا تُحتسب إلا التكاليف المبررة والمقبولة وفق العقد.',
                ],
            ],
            [
                'number' => 9,
                'key' => 'offences-liability-claims',
                'fr' => [
                    'title' => 'Infractions, responsabilités et réclamations',
                    'body' => 'Le conducteur répond des infractions commises pendant sa période d’utilisation, sous réserve des règles impératives. Les informations ne sont transmises qu’aux autorités légalement habilitées. Toute réclamation est adressée à l’entreprise pour examen amiable ; le droit marocain s’applique sans priver une partie des protections obligatoires.',
                ],
                'ar' => [
                    'title' => 'المخالفات والمسؤوليات والشكايات',
                    'body' => 'يتحمل السائق مسؤولية المخالفات المرتكبة خلال مدة استعماله مع مراعاة القواعد الآمرة. ولا تُنقل المعلومات إلا إلى السلطات المخول لها قانوناً. وتوجه كل شكاية إلى الشركة قصد دراستها ودياً، ويطبق القانون المغربي دون حرمان أي طرف من الحماية الإلزامية.',
                ],
            ],
        ];
    }

    private function isCurrentTemplate(?ContractVersion $version): bool
    {
        return data_get($version?->terms_snapshot, 'document.template_id') === self::TEMPLATE_ID
            && data_get($version?->terms_snapshot, 'document.template_version') === self::TEMPLATE_VERSION;
    }

    /** @return array<string, mixed> */
    private function historicalSnapshot(?ContractVersion $version): array
    {
        $terms = $version?->terms_snapshot ?? [];
        $driver = data_get($terms, 'driver');

        return [
            'template_id' => null,
            'template_version' => null,
            'conditions_version' => null,
            'primary_language' => 'fr',
            'issued_at' => $version?->created_at?->toIso8601String(),
            'company' => [],
            'agency' => [],
            'customer' => $version?->customer_snapshot ?? [],
            'drivers' => is_array($driver) ? [['role' => 'primary', ...$driver]] : [],
            'vehicle' => $version?->vehicle_snapshot ?? [],
            'rental' => [
                'expected_start_at' => data_get($terms, 'expected_start_at'),
                'expected_return_at' => data_get($terms, 'expected_return_at'),
                'departure_location' => null,
                'return_location' => null,
                'billed_days' => data_get($version?->pricing_snapshot, 'calculation.billed_days'),
            ],
            'inspection_summary' => [],
            'conditions' => [],
            'legacy_clauses' => data_get($terms, 'clauses', []),
            'data_protection' => null,
        ];
    }

    private function mask(?string $encrypted): ?string
    {
        if (! $encrypted) {
            return null;
        }

        try {
            return $this->identities->maskEncrypted($encrypted);
        } catch (DecryptException) {
            return null;
        }
    }
}
