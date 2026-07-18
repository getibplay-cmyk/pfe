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
}
