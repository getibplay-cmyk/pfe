# ADR 0007 — Registres financiers et clôture

## Statut

Accepté — lot 05.

## Décision

RentFleet conserve des registres opérationnels, sans comptabilité générale. Une facture est construite depuis un contrat `returned` et ses frais revus. Les calculs utilisent `DecimalMoney` et PostgreSQL `numeric`, jamais des `float`. Après émission, le contenu et les lignes sont immuables au niveau PostgreSQL.

Les paiements et mouvements de caution sont append-only, tenant-scopés, numérotés atomiquement et idempotents. Une correction crée une contrepassation. Le solde de facture provient des allocations ; le solde de caution provient des mouvements. Les colonnes du contrat ne sont que des résumés contrôlés.

La transition `returned → closed` est transactionnelle et exige une facture payée, aucun paiement en attente et une caution soldée. Un trigger PostgreSQL interdit une clôture incohérente même hors application.

## Limites

Les taxes sont paramétrables et figées, mais RentFleet n’est pas un calculateur fiscal officiel. Aucun grand livre, plan comptable, paiement bancaire réel ou stockage de carte n’est inclus.
