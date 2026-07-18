<?php

namespace App\Enums;

enum DocumentType: string
{
    case CustomerIdentity = 'customer_identity';
    case DrivingLicence = 'driving_licence';
    case VehicleRegistration = 'vehicle_registration';
    case VehicleInsurance = 'vehicle_insurance';
    case VehiclePhoto = 'vehicle_photo';
    case ContractAcceptance = 'contract_acceptance';
    case ContractSignature = 'contract_signature';
    case InspectionPhoto = 'inspection_photo';
    case DamagePhoto = 'damage_photo';
    case MaintenanceQuote = 'maintenance_quote';
    case MaintenanceRepairOrder = 'maintenance_repair_order';
    case MaintenanceSupplierInvoice = 'maintenance_supplier_invoice';
    case MaintenanceInterventionReport = 'maintenance_intervention_report';
    case InsurancePolicySigned = 'insurance_policy_signed';
    case InsurancePolicyCertificate = 'insurance_policy_certificate';
    case InsurancePolicyEndorsement = 'insurance_policy_endorsement';
    case InsurancePolicyReceipt = 'insurance_policy_receipt';
    case InsuranceClaimDeclaration = 'insurance_claim_declaration';
    case InsuranceClaimReport = 'insurance_claim_report';
    case InsuranceClaimCorrespondence = 'insurance_claim_correspondence';
    case InsuranceClaimExpertise = 'insurance_claim_expertise';
    case InsuranceClaimSettlementProof = 'insurance_claim_settlement_proof';
    case Other = 'other';

    public static function maintenanceTypes(): array
    {
        return [
            self::MaintenanceQuote,
            self::MaintenanceRepairOrder,
            self::MaintenanceSupplierInvoice,
            self::MaintenanceInterventionReport,
            self::Other,
        ];
    }

    public static function insurancePolicyTypes(): array
    {
        return [
            self::InsurancePolicySigned,
            self::InsurancePolicyCertificate,
            self::InsurancePolicyEndorsement,
            self::InsurancePolicyReceipt,
            self::Other,
        ];
    }

    public static function insuranceClaimTypes(): array
    {
        return [
            self::InsuranceClaimDeclaration,
            self::InsuranceClaimReport,
            self::InsuranceClaimCorrespondence,
            self::InsuranceClaimExpertise,
            self::InsuranceClaimSettlementProof,
            self::Other,
        ];
    }
}
