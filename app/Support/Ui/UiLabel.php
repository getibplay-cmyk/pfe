<?php

namespace App\Support\Ui;

use BackedEnum;
use Carbon\CarbonInterface;

class UiLabel
{
    private const LABELS = [
        'active' => 'Actif', 'inactive' => 'Inactif', 'archived' => 'Archivé', 'suspended' => 'Suspendu',
        'draft' => 'Brouillon', 'pending' => 'En attente', 'confirmed' => 'Confirmée', 'converted' => 'Convertie',
        'cancelled' => 'Annulé', 'expired' => 'Expiré', 'ready' => 'Prêt', 'accepted' => 'Accepté',
        'return_pending' => 'Retour à traiter', 'returned' => 'Retourné', 'closed' => 'Clôturé',
        'issued' => 'Émise', 'partially_paid' => 'Partiellement payée', 'paid' => 'Payée', 'void' => 'Annulée',
        'posted' => 'Comptabilisé', 'reversed' => 'Contrepassé', 'approved' => 'Approuvé', 'rejected' => 'Rejeté',
        'planned' => 'Planifiée', 'in_progress' => 'En cours', 'completed' => 'Terminée',
        'reported' => 'Déclaré', 'submitted' => 'Soumis', 'under_review' => 'En revue', 'settled' => 'Réglé',
        'resolved' => 'Résolu', 'dismissed' => 'Écarté',
        'tenant-owner' => 'Propriétaire du tenant', 'tenant owner' => 'Propriétaire du tenant',
        'agency-manager' => 'Responsable d’agence', 'agency manager' => 'Responsable d’agence',
        'rental-agent' => 'Agent de location', 'rental agent' => 'Agent de location',
        'fleet-manager' => 'Responsable de flotte', 'fleet manager' => 'Responsable de flotte',
        'accountant' => 'Comptable', 'viewer-auditor' => 'Lecteur / auditeur', 'viewer/auditor' => 'Lecteur / auditeur',
        'platform-admin' => 'Administrateur plateforme', 'platform admin' => 'Administrateur plateforme',
        'customer_identity' => 'Pièce d’identité client', 'driving_licence' => 'Permis de conduire',
        'vehicle_registration' => 'Carte grise', 'vehicle_insurance' => 'Assurance du véhicule',
        'vehicle_photo' => 'Photo du véhicule', 'contract_acceptance' => 'Acceptation du contrat',
        'contract_signature' => 'Signature du contrat', 'inspection_photo' => 'Photo d’inspection',
        'damage_photo' => 'Photo de dommage', 'other' => 'Autre',
        'cash' => 'Espèces', 'bank_transfer' => 'Virement bancaire', 'cheque' => 'Chèque', 'card' => 'Carte (référence externe)',
        'incoming' => 'Encaissement', 'outgoing' => 'Décaissement',
        'preventive' => 'Préventive', 'corrective' => 'Corrective', 'inspection' => 'Contrôle', 'repair' => 'Réparation',
        'maintenance' => 'Maintenance', 'insurance' => 'Assurance', 'fuel' => 'Carburant', 'cleaning' => 'Nettoyage',
        'administration' => 'Administration', 'base_rental' => 'Location de base', 'late_fee' => 'Retard',
        'extra_kilometre' => 'Kilomètre supplémentaire', 'missing_fuel' => 'Carburant manquant', 'damage' => 'Dommage',
        'minor' => 'Mineur', 'moderate' => 'Modéré', 'major' => 'Majeur', 'critical' => 'Critique',
        'customer' => 'Client', 'agency' => 'Agence', 'unknown' => 'Indéterminée',
        'checkbox' => 'Case de consentement', 'typed_name' => 'Nom saisi', 'handwritten_capture' => 'Signature manuscrite',
        'good' => 'Bon état', 'damaged' => 'Endommagé', 'missing' => 'Manquant', 'not_checked' => 'Non vérifié',
        'departure' => 'Départ', 'return' => 'Retour', 'individual' => 'Particulier', 'company' => 'Entreprise',
        'verified' => 'Vérifié', 'out_of_service' => 'Hors service', 'reservation' => 'Réservation',
        'manual' => 'Bloc manuel', 'contract' => 'Contrat', 'released' => 'Libéré',
        'petrol' => 'Essence', 'diesel' => 'Diesel', 'hybrid' => 'Hybride', 'electric' => 'Électrique',
        'manual' => 'Manuelle', 'automatic' => 'Automatique', 'low' => 'Basse', 'normal' => 'Normale', 'high' => 'Haute', 'urgent' => 'Urgente',
        'liability' => 'Responsabilité civile', 'collision' => 'Collision', 'theft' => 'Vol', 'fire' => 'Incendie',
        'glass' => 'Bris de glace', 'assistance' => 'Assistance', 'legal_defence' => 'Protection juridique',
        'received' => 'Caution reçue', 'retained' => 'Caution retenue', 'refunded' => 'Caution remboursée',
        'adjustment_in' => 'Ajustement entrant', 'adjustment_out' => 'Ajustement sortant', 'reversal' => 'Contrepassation',
    ];

    private const TONES = [
        'active' => 'success', 'confirmed' => 'success', 'accepted' => 'success', 'paid' => 'success',
        'completed' => 'success', 'approved' => 'success', 'settled' => 'success', 'verified' => 'success',
        'pending' => 'warning', 'ready' => 'warning', 'return_pending' => 'warning', 'partially_paid' => 'warning',
        'planned' => 'warning', 'under_review' => 'warning', 'submitted' => 'warning', 'suspended' => 'warning',
        'cancelled' => 'danger', 'expired' => 'danger', 'rejected' => 'danger', 'void' => 'danger',
        'out_of_service' => 'danger', 'critical' => 'danger', 'inactive' => 'muted', 'archived' => 'muted',
        'draft' => 'muted', 'closed' => 'muted', 'returned' => 'info', 'in_progress' => 'info',
    ];

    private const ACTIONS = [
        'agency.created' => 'Agence créée', 'agency.updated' => 'Agence mise à jour',
        'user.created' => 'Utilisateur créé', 'user.updated' => 'Utilisateur mis à jour',
        'user.password_reset' => 'Mot de passe utilisateur réinitialisé',
        'user.initial_password_changed' => 'Mot de passe initial remplacé',
        'tenant.settings.updated' => 'Paramètres de l’entreprise mis à jour',
        'reservation.created' => 'Réservation créée', 'reservation.confirmed' => 'Réservation confirmée',
        'reservation.cancelled' => 'Réservation annulée', 'contract.created' => 'Contrat créé',
        'platform.tenant.provisioned' => 'Tenant provisionné', 'platform.tenant.suspended' => 'Tenant suspendu',
        'platform.tenant.reactivated' => 'Tenant réactivé', 'customer.identity.viewed' => 'Identité client consultée',
        'profile.updated' => 'Profil mis à jour', 'profile.password_changed' => 'Mot de passe du profil modifié',
        'vehicle_block.manual.created' => 'Bloc manuel créé',
        'vehicle_block.manual.released' => 'Bloc manuel libéré',
        'vehicle_block.manual.cancelled' => 'Bloc manuel annulé',
        'expense.rejected' => 'Dépense rejetée',
    ];

    public static function get(mixed $value): string
    {
        if ($value instanceof BackedEnum && method_exists($value, 'label')) {
            return $value->label();
        }

        $key = mb_strtolower(trim((string) ($value instanceof BackedEnum ? $value->value : $value)));

        return self::LABELS[$key] ?? 'Valeur inconnue';
    }

    public static function tone(mixed $value): string
    {
        $key = mb_strtolower(trim((string) ($value instanceof BackedEnum ? $value->value : $value)));

        return self::TONES[$key] ?? 'muted';
    }

    public static function action(?string $action): string
    {
        return self::ACTIONS[$action ?? ''] ?? 'Activité enregistrée';
    }

    public static function date(?CarbonInterface $date): string
    {
        return $date?->timezone(config('app.timezone'))->format('d/m/Y') ?? '—';
    }

    public static function dateTime(?CarbonInterface $date): string
    {
        return $date?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? '—';
    }

    public static function money(string|int|null $amount, string $currency): string
    {
        if ($amount === null || preg_match('/^-?\d+(?:\.\d{1,4})?$/', (string) $amount) !== 1) {
            return '—';
        }

        [$units, $decimals] = array_pad(explode('.', (string) $amount, 2), 2, '');

        return $units.','.str_pad(substr($decimals, 0, 2), 2, '0').' '.strtoupper($currency);
    }
}
