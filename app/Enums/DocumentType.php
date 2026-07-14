<?php

namespace App\Enums;

enum DocumentType: string
{
    case CustomerIdentity = 'customer_identity';
    case DrivingLicence = 'driving_licence';
    case VehicleRegistration = 'vehicle_registration';
    case VehicleInsurance = 'vehicle_insurance';
    case VehiclePhoto = 'vehicle_photo';
    case Other = 'other';
}
