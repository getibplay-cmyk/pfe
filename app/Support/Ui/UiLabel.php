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
        'maintenance_quote' => 'Devis de maintenance', 'maintenance_repair_order' => 'Ordre de réparation',
        'maintenance_supplier_invoice' => 'Facture fournisseur non comptabilisée', 'maintenance_intervention_report' => 'Rapport d’intervention',
        'insurance_policy_signed' => 'Police signée', 'insurance_policy_certificate' => 'Attestation d’assurance',
        'insurance_policy_endorsement' => 'Avenant de police', 'insurance_policy_receipt' => 'Quittance d’assurance',
        'insurance_claim_declaration' => 'Déclaration de sinistre', 'insurance_claim_report' => 'Constat de sinistre',
        'insurance_claim_correspondence' => 'Correspondance assureur', 'insurance_claim_expertise' => 'Rapport d’expertise',
        'insurance_claim_settlement_proof' => 'Preuve de règlement du sinistre',
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
        'manual_block' => 'Bloc manuel', 'contract' => 'Contrat', 'released' => 'Libéré',
        'petrol' => 'Essence', 'diesel' => 'Diesel', 'hybrid' => 'Hybride', 'electric' => 'Électrique',
        'manual' => 'Manuelle', 'automatic' => 'Automatique', 'low' => 'Basse', 'normal' => 'Normale', 'high' => 'Haute', 'urgent' => 'Urgente',
        'liability' => 'Responsabilité civile', 'collision' => 'Collision', 'theft' => 'Vol', 'fire' => 'Incendie',
        'glass' => 'Bris de glace', 'assistance' => 'Assistance', 'legal_defence' => 'Protection juridique',
        'mandatory_liability' => 'Responsabilité civile obligatoire', 'comprehensive' => 'Tous risques', 'third_party' => 'Au tiers',
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
        'maintenance.created' => 'Maintenance créée', 'maintenance.updated' => 'Maintenance modifiée',
        'maintenance.rescheduled' => 'Maintenance replanifiée', 'maintenance.approved' => 'Maintenance approuvée',
        'maintenance.started' => 'Maintenance démarrée', 'maintenance.completed' => 'Maintenance terminée',
        'maintenance.cancelled' => 'Maintenance annulée',
        'insurance.company.created' => 'Compagnie d’assurance créée', 'insurance.company.updated' => 'Compagnie d’assurance modifiée',
        'insurance.company.deactivated' => 'Compagnie d’assurance désactivée', 'insurance.company.reactivated' => 'Compagnie d’assurance réactivée',
        'insurance.policy.created' => 'Police d’assurance créée', 'insurance.policy.updated' => 'Police d’assurance modifiée',
        'insurance.policy.activated' => 'Police d’assurance activée', 'insurance.policy.cancelled' => 'Police d’assurance annulée',
        'insurance.policy.expired' => 'Police d’assurance expirée', 'insurance.policy.renewed' => 'Police d’assurance renouvelée',
        'insurance.policy.document.remediated' => 'Preuve de police de démonstration régularisée',
        'insurance.coverage.created' => 'Garantie créée', 'insurance.coverage.updated' => 'Garantie modifiée',
        'insurance.coverage.archived' => 'Garantie archivée',
        'reservation.exported' => 'Export des réservations téléchargé',
        'report.exported' => 'Export du rapport téléchargé',
    ];

    private const REPORT_LABELS = [
        'reservations.created' => 'Réservations créées',
        'reservations.confirmed' => 'Réservations confirmées',
        'reservations.cancelled' => 'Réservations annulées',
        'reservations.expired' => 'Réservations expirées',
        'contracts.active' => 'Contrats actifs sur la période',
        'contracts.expected_returns' => 'Retours attendus',
        'contracts.overdue_returns' => 'Retours en retard',
        'contracts.closed' => 'Contrats clôturés',
        'fleet.available' => 'Véhicules disponibles',
        'fleet.rented' => 'Véhicules loués',
        'fleet.blocked' => 'Véhicules bloqués',
        'fleet.maintenance' => 'Véhicules en maintenance',
        'utilization.rate' => 'Taux d’utilisation',
        'utilization.duration' => 'Durée louée ou bloquée',
        'maintenance.planned' => 'Maintenances planifiées',
        'maintenance.overdue' => 'Maintenances en retard',
        'maintenance.in_progress' => 'Maintenances en cours',
        'insurance.open_claims' => 'Sinistres ouverts',
        'expirations.documents' => 'Documents arrivant à échéance',
        'expirations.driving_licences' => 'Permis arrivant à échéance',
        'expirations.total' => 'Documents et permis à échéance',
        'finance.issued_invoices' => 'Factures émises',
        'finance.invoiced_amount' => 'Montant facturé',
        'finance.collected_net' => 'Montant encaissé net',
        'finance.outstanding_balance' => 'Solde dû à fin de période',
        'finance.held_deposits' => 'Cautions détenues',
        'finance.retained_deposits' => 'Cautions retenues',
        'finance.refunded_deposits' => 'Cautions remboursées',
        'finance.approved_expenses' => 'Dépenses approuvées',
        'expenses.draft' => 'Dépenses brouillon',
        'expenses.approved' => 'Dépenses approuvées',
        'expenses.rejected' => 'Dépenses rejetées',
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

    public static function report(string $metric): string
    {
        return self::REPORT_LABELS[$metric] ?? 'Indicateur non documenté';
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

    public static function blockType(mixed $value): string
    {
        $key = mb_strtolower(trim((string) ($value instanceof BackedEnum ? $value->value : $value)));

        return $key === 'manual' ? self::LABELS['manual_block'] : self::get($value);
    }
}
