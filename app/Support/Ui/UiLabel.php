<?php

namespace App\Support\Ui;

use BackedEnum;
use Carbon\CarbonInterface;

class UiLabel
{
    private const LABELS = [
        'active' => 'Actif', 'inactive' => 'Inactif', 'archived' => 'Archivé', 'suspended' => 'Suspendu',
        'draft' => 'Brouillon', 'pending' => 'En attente', 'confirmed' => 'Confirmée', 'corrected' => 'Corrigée', 'converted' => 'Convertie',
        'cancelled' => 'Annulé', 'expired' => 'Expiré', 'ready' => 'Prêt', 'incomplete' => 'À compléter', 'accepted' => 'Accepté', 'ignored' => 'Ignoré',
        'return_pending' => 'Retour à traiter', 'returned' => 'Retourné', 'closed' => 'Clôturé',
        'issued' => 'Émise', 'partially_paid' => 'Partiellement payée', 'paid' => 'Payée', 'void' => 'Annulée',
        'posted' => 'Comptabilisé', 'reversed' => 'Contrepassé', 'approved' => 'Approuvé', 'rejected' => 'Rejeté',
        'planned' => 'Planifiée', 'in_progress' => 'En cours', 'completed' => 'Terminée',
        'reported' => 'Déclaré', 'submitted' => 'Soumis', 'under_review' => 'En revue', 'settled' => 'Réglé',
        'resolved' => 'Résolu', 'dismissed' => 'Écarté', 'validated' => 'Validé',
        'queued' => 'En attente', 'running' => 'En cours', 'succeeded' => 'Terminé', 'failed' => 'Échec',
        'vehicle_color_consultative_scientific_threshold_reached' => 'Seuil scientifique validé atteint',
        'vehicle_color_consultative_candidate_to_review' => 'Couleur indicative à contrôler visuellement',
        'vehicle_color_consultative_not_exploitable' => 'Résultat non exploitable',
        'vehicle_color_consultative_unavailable' => 'Résultat indisponible',
        'accepted_for_demo_review' => 'Accepté pour revue de démonstration',
        'tenant-owner' => 'Administrateur de l’entreprise', 'tenant owner' => 'Administrateur de l’entreprise',
        'agency-manager' => 'Responsable d’agence', 'agency manager' => 'Responsable d’agence',
        'rental-agent' => 'Agent de location', 'rental agent' => 'Agent de location',
        'fleet-manager' => 'Responsable de flotte', 'fleet manager' => 'Responsable de flotte',
        'accountant' => 'Comptable', 'viewer-auditor' => 'Lecteur / auditeur', 'viewer/auditor' => 'Lecteur / auditeur',
        'platform-admin' => 'Administrateur de la plateforme', 'platform admin' => 'Administrateur de la plateforme',
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
        'information' => 'Information', 'warning' => 'Avertissement',
        'fleet' => 'Flotte', 'finance' => 'Finance',
    ];

    private const TONES = [
        'active' => 'success', 'confirmed' => 'success', 'corrected' => 'info', 'accepted' => 'success', 'accepted_for_demo_review' => 'success', 'paid' => 'success',
        'completed' => 'success', 'approved' => 'success', 'settled' => 'success', 'verified' => 'success', 'validated' => 'success',
        'pending' => 'warning', 'ready' => 'success', 'incomplete' => 'warning', 'return_pending' => 'warning', 'partially_paid' => 'warning',
        'planned' => 'warning', 'under_review' => 'warning', 'submitted' => 'warning', 'suspended' => 'warning',
        'queued' => 'warning', 'running' => 'info', 'succeeded' => 'success', 'failed' => 'danger',
        'vehicle_color_consultative_scientific_threshold_reached' => 'success',
        'vehicle_color_consultative_candidate_to_review' => 'warning',
        'vehicle_color_consultative_not_exploitable' => 'warning',
        'vehicle_color_consultative_unavailable' => 'muted',
        'cancelled' => 'danger', 'expired' => 'danger', 'rejected' => 'danger', 'void' => 'danger', 'ignored' => 'muted',
        'out_of_service' => 'danger', 'critical' => 'danger', 'inactive' => 'muted', 'archived' => 'muted',
        'draft' => 'muted', 'closed' => 'muted', 'returned' => 'info', 'in_progress' => 'info',
        'information' => 'info', 'warning' => 'warning', 'urgent' => 'danger',
    ];

    private const ACTIONS = [
        'agency.created' => 'Agence créée', 'agency.updated' => 'Agence mise à jour',
        'fleet.agency_distance.created' => 'Distance inter-agences créée',
        'fleet.agency_distance.corrected' => 'Distance inter-agences corrigée',
        'fleet.agency_distance.activated' => 'Distance inter-agences activée',
        'fleet.agency_distance.deactivated' => 'Distance inter-agences désactivée',
        'user.created' => 'Utilisateur créé', 'user.updated' => 'Utilisateur mis à jour',
        'user.password_reset' => 'Mot de passe utilisateur réinitialisé',
        'user.initial_password_changed' => 'Mot de passe initial remplacé',
        'tenant.settings.updated' => 'Paramètres de l’entreprise mis à jour',
        'reservation.created' => 'Réservation créée', 'reservation.confirmed' => 'Réservation confirmée',
        'reservation.cancelled' => 'Réservation annulée', 'contract.created' => 'Contrat créé',
        'platform.tenant.provisioned' => 'Entreprise cliente créée', 'platform.tenant.suspended' => 'Entreprise cliente suspendue',
        'platform.tenant.reactivated' => 'Entreprise cliente réactivée', 'customer.identity.viewed' => 'Identité client consultée',
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
        'prediction.dataset.exported' => 'Dataset Intelligence anonymisé exporté',
        'prediction.dataset.snapshot_downloaded' => 'Snapshot Intelligence téléchargé',
        'prediction.dataset.manifest_downloaded' => 'Manifeste Intelligence téléchargé',
        'prediction.demo.fixture_imported' => 'Fixture Intelligence synthétique ajoutée',
        'prediction.demo.fixture_replayed' => 'Fixture Intelligence synthétique rejouée sans duplication',
        'prediction.demo.human_decision_recorded' => 'Décision humaine de démonstration enregistrée',
        'prediction.result_batch.imported' => 'Lot de résultats Intelligence synthétique importé',
        'prediction.result_batch.replayed' => 'Lot de résultats Intelligence rejoué sans duplication',
        'prediction.result_batch.downloaded' => 'Lot de résultats Intelligence téléchargé',
        'prediction.result_batch.human_decision_recorded' => 'Décision humaine sur un lot Intelligence enregistrée',
        'prediction.demand_history.exported' => 'Historique quotidien de demande exporté',
        'prediction.demand_history.downloaded' => 'Historique quotidien de demande téléchargé',
        'prediction.demand_history.manifest_downloaded' => 'Manifeste de demande téléchargé',
        'prediction.demand_forecast.imported' => 'Prévision de demande consultative importée',
        'prediction.demand_forecast.replayed' => 'Prévision de demande rejouée sans duplication',
        'prediction.demand_forecast.execution_queued' => 'Inférence HGB ajoutée à la queue',
        'prediction.demand_forecast.execution_succeeded' => 'Inférence HGB terminée',
        'prediction.demand_forecast.execution_failed' => 'Échec contrôlé de l’inférence HGB',
        'prediction.fleet_reallocation.imported' => 'Proposition de réallocation synthétique importée',
        'prediction.fleet_reallocation.replayed' => 'Proposition de réallocation rejouée sans duplication',
        'prediction.fleet_reallocation.run_queued' => 'Calcul OR-Tools ajouté à la queue',
        'prediction.fleet_reallocation.run_succeeded' => 'Calcul OR-Tools terminé',
        'prediction.fleet_reallocation.run_failed' => 'Échec contrôlé du calcul OR-Tools',
        'prediction.fleet_reallocation.downloaded' => 'Proposition de réallocation téléchargée',
        'prediction.fleet_reallocation.human_decision_recorded' => 'Décision humaine sur une réallocation enregistrée',
        'prediction.vehicle_color.run_queued' => 'Analyse couleur ajoutée à la queue',
        'prediction.vehicle_color.run_succeeded' => 'Analyse couleur ONNX terminée',
        'prediction.vehicle_color.run_failed' => 'Échec contrôlé de l’analyse couleur',
        'prediction.vehicle_color.input_viewed' => 'Photo privée de l’analyse couleur consultée',
        'prediction.vehicle_color.human_decision_recorded' => 'Décision humaine sur une couleur enregistrée',
        'prediction.vehicle_damage.run_queued' => 'Analyse dommages ajoutée à la queue',
        'prediction.vehicle_damage.run_succeeded' => 'Analyse dommages ONNX terminée',
        'prediction.vehicle_damage.run_failed' => 'Échec contrôlé de l’analyse dommages',
        'prediction.vehicle_damage.input_viewed' => 'Photo privée de l’analyse dommages consultée',
        'prediction.vehicle_damage.human_decision_recorded' => 'Vérification humaine d’une zone candidate enregistrée',
        'prediction.vehicle_plate.run_queued' => 'Analyse de plaque ajoutée à la queue',
        'prediction.vehicle_plate.run_succeeded' => 'Analyse locale de plaque terminée',
        'prediction.vehicle_plate.run_failed' => 'Échec contrôlé de l’analyse de plaque',
        'prediction.vehicle_plate.input_viewed' => 'Image source privée de plaque consultée',
        'prediction.vehicle_plate.crop_viewed' => 'Crop OCR privé de plaque consulté',
        'prediction.vehicle_plate.human_correction_recorded' => 'Correction humaine de plaque enregistrée',
        'notification.generated' => 'Notification générée', 'notification.read' => 'Notification marquée comme lue',
        'notification.unread' => 'Notification marquée comme non lue', 'notification.all_read' => 'Notifications marquées comme lues',
        'notification.updated' => 'Notification actualisée', 'notification.resolved' => 'Notification résolue',
        'notification.reactivated' => 'Notification réactivée',
        'role.created' => 'Rôle personnalisé créé', 'role.updated' => 'Rôle personnalisé modifié',
        'role.replacement.requested' => 'Remplacement de rôle confirmé',
        'role.assignments.replaced' => 'Affectations du rôle remplacées', 'role.delegations.updated' => 'Délégations de rôle mises à jour',
        'user.role.assigned' => 'Rôle utilisateur affecté', 'user.activated' => 'Compte utilisateur activé',
        'user.deactivated' => 'Compte utilisateur désactivé', 'user.assignment.denied' => 'Tentative d’affectation refusée',
        'customer.updated' => 'Client modifié', 'customer.archived' => 'Client archivé', 'customer.restored' => 'Client restauré',
        'customer.verification.verified' => 'Client vérifié', 'customer.verification.rejected' => 'Vérification du client refusée',
        'driver.updated' => 'Conducteur modifié', 'driver.archived' => 'Conducteur archivé', 'driver.restored' => 'Conducteur restauré',
        'driver.verification.verified' => 'Conducteur vérifié', 'driver.verification.rejected' => 'Vérification du conducteur refusée',
        'driver.licence.viewed' => 'Permis du conducteur consulté', 'document.archived' => 'Document archivé',
        'pricing_rule.created' => 'Règle tarifaire créée', 'pricing_rule.versioned' => 'Règle tarifaire versionnée',
        'reservation.updated' => 'Réservation modifiée', 'contract.version.created' => 'Version contractuelle créée',
        'contract.ready' => 'Contrat déclaré prêt', 'contract.accepted' => 'Contrat accepté', 'contract.activated' => 'Contrat activé',
        'contract.returned' => 'Retour du contrat confirmé', 'contract.cancelled' => 'Contrat annulé', 'contract.closed' => 'Contrat clôturé',
        'inspection.departure.completed' => 'Inspection de départ terminée', 'inspection.return.completed' => 'Inspection de retour terminée',
        'inspection.return.future_blocks_impacted' => 'Blocs futurs signalés après retour', 'damage.reported' => 'Dommage signalé',
        'damage.review.started' => 'Revue du dommage commencée', 'damage.reviewed' => 'Dommage revu',
        'invoice.created' => 'Facture créée', 'invoice.issued' => 'Facture émise', 'invoice.voided' => 'Facture annulée',
        'payment.recorded' => 'Paiement saisi', 'payment.posted' => 'Paiement comptabilisé', 'payment.reversed' => 'Paiement contrepassé',
        'deposit.received' => 'Caution encaissée', 'deposit.retained' => 'Caution retenue', 'deposit.refunded' => 'Caution remboursée',
        'deposit.reversed' => 'Mouvement de caution contrepassé', 'expense.created' => 'Dépense créée', 'expense.approved' => 'Dépense approuvée',
        'insurance_claim.reported' => 'Sinistre déclaré', 'insurance_claim.status.changed' => 'État du sinistre modifié',
        'platform.tenant.updated' => 'Entreprise cliente mise à jour',
    ];

    private const PERMISSION_GROUPS = [
        'tenant' => 'Entreprise', 'agency' => 'Agences', 'user' => 'Utilisateurs', 'role' => 'Rôles et délégations',
        'vehicle' => 'Véhicules', 'vehicle_block' => 'Disponibilité', 'customer' => 'Clients', 'document' => 'Documents privés',
        'pricing' => 'Tarification', 'reservation' => 'Réservations', 'contract' => 'Contrats', 'inspection' => 'Inspections',
        'damage' => 'Dommages', 'charge' => 'Frais', 'invoice' => 'Factures', 'payment' => 'Paiements',
        'deposit' => 'Cautions', 'expense' => 'Dépenses', 'maintenance' => 'Maintenance', 'insurance' => 'Assurance',
        'claim' => 'Sinistres', 'prediction' => 'Intelligence', 'fleet' => 'Flotte', 'report' => 'Rapports', 'audit' => 'Audit',
    ];

    private const PERMISSION_ENTITIES = [
        'tenant' => 'les paramètres de l’entreprise',
        'agency' => 'les agences',
        'user' => 'les utilisateurs',
        'role' => 'les rôles',
        'vehicle' => 'les véhicules',
        'vehicle_block' => 'les blocs de disponibilité',
        'customer' => 'les clients',
        'document' => 'les documents privés',
        'pricing' => 'la tarification',
        'reservation' => 'les réservations',
        'contract' => 'les contrats',
        'inspection' => 'les inspections',
        'damage' => 'les dommages',
        'charge' => 'les frais',
        'invoice' => 'les factures',
        'payment' => 'les paiements',
        'deposit' => 'les cautions',
        'expense' => 'les dépenses',
        'maintenance' => 'la maintenance',
        'insurance' => 'les assurances',
        'claim' => 'les sinistres',
        'report' => 'les rapports',
        'audit' => 'le journal d’audit',
        'prediction' => 'les prédictions',
        'fleet_distance' => 'les distances inter-agences',
        'platform' => 'les entreprises clientes de la plateforme',
    ];

    private const PERMISSION_ACTIONS = [
        'view' => 'Consulter',
        'manage' => 'Gérer',
        'create' => 'Créer',
        'update' => 'Modifier',
        'archive' => 'Archiver',
        'upload' => 'Ajouter',
        'download' => 'Télécharger',
        'delete' => 'Archiver',
        'confirm' => 'Confirmer',
        'cancel' => 'Annuler',
        'export' => 'Exporter',
        'version' => 'Créer une version de',
        'accept' => 'Accepter',
        'activate' => 'Activer',
        'return' => 'Traiter le retour de',
        'report' => 'Signaler',
        'review' => 'Examiner',
        'issue' => 'Émettre',
        'void' => 'Annuler',
        'post' => 'Comptabiliser',
        'allocate' => 'Allouer',
        'reverse' => 'Contrepasser',
        'approve' => 'Approuver',
        'reject' => 'Rejeter',
        'close' => 'Clôturer',
        'start' => 'Démarrer',
        'complete' => 'Terminer',
        'delegate' => 'Déléguer',
    ];

    private const SPECIAL_PERMISSIONS = [
        'customer.identity.view' => 'Consulter une identité client complète',
        'contract.version' => 'Créer une version contractuelle',
        'contract.return' => 'Traiter le retour d’un contrat',
        'contract.close' => 'Clôturer financièrement un contrat',
        'damage.review' => 'Décider explicitement la responsabilité d’un dommage',
        'charge.review' => 'Examiner les frais contractuels',
        'payment.allocate' => 'Allouer un paiement à une facture',
        'platform.tenants.manage' => 'Administrer les entreprises clientes de la plateforme',
        'prediction.demo.review' => 'Générer, importer et revoir les preuves Intelligence synthétiques',
        'prediction.forecast.import' => 'Importer les prévisions de demande consultatives',
        'prediction.color.review' => 'Analyser et revoir la couleur d’un véhicule',
        'prediction.damage.review' => 'Analyser et revoir les zones de dommage d’un retour',
        'prediction.plate.review' => 'Analyser et corriger une plaque de véhicule',
        'prediction.anomaly.review' => 'Analyser et revoir les usages de location atypiques',
        'fleet.distance.view' => 'Consulter les distances inter-agences',
        'fleet.distance.manage' => 'Gérer les distances inter-agences vérifiées',
    ];

    private const ENTITIES = [
        'Agency' => 'Agence', 'AgencyDistance' => 'Distance inter-agences', 'User' => 'Utilisateur', 'Role' => 'Rôle', 'Tenant' => 'Entreprise',
        'Reservation' => 'Réservation', 'RentalContract' => 'Contrat', 'Customer' => 'Client', 'Driver' => 'Conducteur',
        'Vehicle' => 'Véhicule', 'VehicleBlock' => 'Bloc véhicule', 'Document' => 'Document privé', 'Invoice' => 'Facture',
        'Payment' => 'Paiement', 'DepositTransaction' => 'Caution', 'Expense' => 'Dépense', 'MaintenanceOrder' => 'Maintenance',
        'InsurancePolicy' => 'Police d’assurance', 'InsuranceClaim' => 'Sinistre', 'InternalNotification' => 'Notification',
        'FleetReallocationProposal' => 'Proposition de réallocation', 'FleetReallocationRun' => 'Exécution OR-Tools',
        'IntelligenceDatasetExportRun' => 'Snapshot Intelligence', 'IntelligenceResultBatch' => 'Lot de résultats Intelligence',
        'DemandHistoryExportRun' => 'Snapshot d’historique de demande', 'DemandForecastRun' => 'Exécution de prévision de demande',
        'DemandForecast' => 'Prévision de demande',
        'VehicleColorPredictionRun' => 'Analyse de couleur véhicule',
        'VehicleColorPredictionReview' => 'Revue humaine de couleur véhicule',
        'VehicleDamagePredictionRun' => 'Analyse de dommages véhicule',
        'VehicleDamagePredictionReview' => 'Revue humaine de zone candidate',
        'VehiclePlatePredictionRun' => 'Analyse OCR de plaque',
        'VehiclePlatePredictionReview' => 'Correction humaine de plaque',
        'RentalUsageAnomalyRun' => 'Classement d’usages atypiques',
        'RentalUsageAnomalyResult' => 'Résultat d’usage atypique',
        'RentalUsageAnomalyReview' => 'Revue humaine d’usage atypique',
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

    public static function permissionGroup(string $group): string
    {
        return self::PERMISSION_GROUPS[$group] ?? 'Autres permissions';
    }

    public static function permission(string $permission): string
    {
        if (isset(self::SPECIAL_PERMISSIONS[$permission])) {
            return self::SPECIAL_PERMISSIONS[$permission];
        }

        $parts = explode('.', $permission);
        $action = array_pop($parts);
        $entity = implode('_', $parts);

        if (! isset(self::PERMISSION_ENTITIES[$entity], self::PERMISSION_ACTIONS[$action])) {
            return 'Permission non documentée';
        }

        return self::PERMISSION_ACTIONS[$action].' '.self::PERMISSION_ENTITIES[$entity];
    }

    public static function permissionRisk(string $permission): string
    {
        return self::permissionDescription($permission);
    }

    public static function permissionDescription(string $permission): string
    {
        if ($permission === 'prediction.color.review') {
            return 'Effet : analyse consultative et revue humaine auditée, sans modification automatique du véhicule.';
        }
        if ($permission === 'prediction.damage.review') {
            return 'Effet : analyse consultative d’une photo de retour et revue humaine auditée, sans dommage, frais ni responsabilité automatiques.';
        }
        if ($permission === 'prediction.plate.review') {
            return 'Effet : OCR local consultatif et correction humaine auditée, sans modification automatique de l’immatriculation.';
        }
        if ($permission === 'prediction.anomaly.review') {
            return 'Effet : classement consultatif et revue humaine append-only, sans sanction, frais, accusation ni modification de contrat.';
        }

        return str_ends_with($permission, '.view')
            ? 'Effet : consultation des informations autorisées, sans modification.'
            : 'Effet : exécution d’une action métier contrôlée et auditée lorsque nécessaire.';
    }

    public static function permissionScope(string $permission): string
    {
        $group = explode('.', $permission)[0];

        return in_array($group, ['tenant', 'role', 'fleet', 'report', 'audit'], true)
            ? 'Portée : entreprise cliente.'
            : 'Portée : agence autorisée ou ensemble des agences pour l’administrateur de l’entreprise.';
    }

    public static function permissionCriticality(string $permission): string
    {
        $critical = [
            'customer.identity.view', 'document.download', 'document.delete',
            'role.manage', 'role.delegate', 'invoice.issue', 'invoice.void',
            'payment.post', 'payment.allocate', 'payment.reverse',
            'deposit.create', 'deposit.reverse', 'expense.approve',
            'expense.reject', 'contract.close', 'damage.review',
        ];

        return in_array($permission, $critical, true)
            ? 'Criticité : élevée.'
            : (str_ends_with($permission, '.view') ? 'Criticité : lecture.' : 'Criticité : standard.');
    }

    public static function entity(?string $class): string
    {
        $basename = class_basename((string) $class);

        return self::ENTITIES[$basename] ?? 'Élément métier';
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
