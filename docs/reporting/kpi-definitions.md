# Définitions des KPI du rapport minimal

Tous les indicateurs sont limités au tenant authentifié. Quand l’utilisateur est
rattaché à une agence, cette agence est imposée côté serveur. Les bornes de date
sont inclusives en date locale `Africa/Casablanca`. Les montants sont lus et
agrégés en `numeric`, puis affichés avec deux décimales sans passage par `float`.

## Indicateurs opérationnels

- **Réservations par statut** : réservations dont le début appartient à la
  période, groupées selon leur statut courant.
- **Contrats par statut** : contrats dont la date de création appartient à la
  période, groupés selon leur statut courant.
- **Véhicules disponibles** : véhicules actifs sans bloc actif au moment de la
  consultation.
- **Véhicules loués** : véhicules portant un contrat `active` ou
  `return_pending` au moment de la consultation.
- **Véhicules en maintenance** : véhicules dont le statut opérationnel courant
  est `maintenance`.
- **Utilisation de flotte** : durée cumulée des blocs de réservation/contrat
  actifs intersectant la période, divisée par la durée de la période multipliée
  par le nombre de véhicules actifs. Les chevauchements sont empêchés par la
  contrainte PostgreSQL de disponibilité.
- **Maintenances proches** : ordres non terminés dont l’échéance tombe dans les
  trente jours après la fin de période.
- **Maintenances en retard** : ordres non terminés dont l’échéance est antérieure
  à la date de fin.
- **Sinistres ouverts** : sinistres dont le statut n’est ni `closed`, ni
  `rejected` à la fin de la période.

## Indicateurs financiers

- **Factures émises** : nombre et total TTC des factures dont l’émission tombe
  dans la période, hors factures annulées.
- **Encaissements alloués** : somme nette des allocations de paiements
  comptabilisés ; les contrepassations sont soustraites.
- **Solde impayé** : somme du solde courant des factures `issued` ou
  `partially_paid` à la consultation.
- **Cautions détenues** : somme courante reçue moins retenue et remboursée dans
  le registre des contrats.
- **Dépenses approuvées** : dépenses au statut `approved` dont la date appartient
  à la période.

## Limites

Le rapport est opérationnel et descriptif. Il ne fournit pas de grand livre,
rapprochement bancaire, TVA officielle, reconnaissance de revenu, consolidation
multi-devise, prévision, décision automatisée ou indicateur IA. Les KPI courants
peuvent évoluer après une correction append-only ; l’audit et les registres
restent les sources de preuve détaillées.
