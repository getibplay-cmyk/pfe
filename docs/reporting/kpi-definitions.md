# Définitions canoniques des KPI RentFleet

Le service `BuildMinimalReport` est la source unique des indicateurs affichés
sur le rapport, exportés en CSV et repris sur le dashboard pour les rôles qui
possèdent `report.view`. Chaque appel reçoit explicitement le tenant courant,
les agences autorisées, le début, la fin exclusive, le fuseau et une devise
optionnelle.

## Convention commune

- La période métier est semi-ouverte : `[report_start, report_end)`.
- L’interface reçoit deux dates locales inclusives. Dans
  `Africa/Casablanca`, la date de début devient `report_start` à `00:00` et le
  lendemain de la date de fin devient `report_end` à `00:00`.
- Une période stockée intersecte le rapport si `starts_at < report_end` et
  `ends_at > report_start`. Une fin exactement égale au début et un début
  exactement égal à la fin sont exclus.
- Toute durée est bornée avec `GREATEST(starts_at, report_start)` et
  `LEAST(ends_at, report_end)` dans PostgreSQL.
- Le tenant vient de `TenantContext`. Les agences viennent de `AgencyAccess` et
  ne sont jamais acceptées comme périmètre de confiance depuis le navigateur.
- Les montants restent en `numeric` et passent par `DecimalMoney`, jamais par
  `float`.
- Chaque devise est une ligne indépendante. Il n’existe ni conversion ni total
  consolidé multi-devise.

La période d’un rapport ou export est limitée à 366 jours.

## Exploitation

| KPI | Définition temporelle | Inclus | Exclus / limite |
|---|---|---|---|
| Réservations créées | `reservations.created_at` dans la période | Réservations non supprimées | Créations hors période |
| Réservations confirmées | Événements `reservation_status_histories.to_status = confirmed` dans la période | Chaque transition enregistrée | Statut courant sans événement dans la période |
| Réservations annulées | Événements vers `cancelled` dans la période | Annulations historisées | Annulations hors période |
| Réservations expirées | Événements vers `expired` dans la période | Expirations historisées | Expirations hors période |
| Contrats actifs | Intervalle entre `activated_at` et le premier retour, clôture ou annulation connu intersectant la période | Contrats ayant réellement été activés | Brouillons et contrats jamais activés |
| Retours attendus | `expected_return_at` dans la période | Contrats non annulés | Retours attendus hors période |
| Retours en retard | Retour attendu dans la période, déjà échu à la date d’observation, sans retour réalisé à temps | Retours réels tardifs ou encore absents | Échéances futures et contrats annulés |
| Contrats clôturés | `closed_at` dans la période | Clôtures financières | Statut courant sans clôture dans la période |

## Flotte et utilisation

Les véhicules disponibles, loués, bloqués et en maintenance sont une photo à
la fin de période. Pour une période qui se termine dans le futur, la photo est
prise au moment de la consultation. Le statut est reconstruit depuis le dernier
`vehicle_status_history` connu à cette date.

- **Disponible** : statut `active`, aucun bloc actif à l’instant de la photo.
- **Loué** : bloc actif de type `contract`.
- **Bloqué** : véhicule `active` avec bloc actif `reservation` ou `manual`.
- **En maintenance** : statut `maintenance` ou bloc actif `maintenance`.

### Taux d’utilisation

```text
100 × secondes de blocs actifs intersectant la capacité
    / secondes de capacité des véhicules exploitables
```

Le numérateur inclut les types `reservation`, `contract`, `manual` et
`maintenance`. Les blocs `released` et `cancelled` sont exclus. Chaque bloc est
également borné aux intervalles de capacité de son véhicule. La contrainte GiST
`vehicle_blocks_no_active_overlap_excl` empêche le double comptage d’un même
véhicule.

Le dénominateur additionne les intervalles issus des historiques véhicule dont
le statut est `active` ou `maintenance`. Les statuts `out_of_service` et
`archived` n’offrent aucune capacité. Si le dénominateur vaut zéro, le taux est
`0,00 %`. La durée totale louée ou bloquée est le numérateur avant division.

## Maintenance, assurance et échéances

| KPI | Règle | Statuts / périmètre |
|---|---|---|
| Maintenances planifiées | `scheduled_start_at` dans la période | `planned`, `approved` |
| Maintenances en retard | `scheduled_start_at` antérieur à la date d’observation | `planned`, `approved` |
| Maintenances en cours | Intervalle `actual_start_at` / `actual_end_at` intersectant la période | Ordres ayant réellement démarré |
| Sinistres ouverts | État reconstruit à `report_end` depuis l’historique | Tout sauf `rejected`, `closed` |
| Documents à échéance | `retention_until` local dans la période | Documents non archivés et agency-scopés |
| Permis à échéance | `licence_expires_at` local dans la période | Conducteurs non archivés, agence dérivée du client |

`retention_until` représente une échéance documentaire interne, pas une
certification juridique de validité.

## Finance par devise

| KPI | Numérateur / formule | Règle temporelle et statuts |
|---|---|---|
| Factures émises | `SUM(invoices.total_amount)` et nombre | `issued_at` dans la période, `status <> void` |
| Encaissé net | `SUM(allocation)` pour `incoming`, `-SUM(allocation)` pour `outgoing` | `payments.posted_at` dans la période, statuts `posted` ou `reversed`; `pending` et `void` exclus |
| Solde dû | Facturé de la période moins allocations nettes de ces factures | Allocations comptabilisées avant `report_end`; les contrepassations sont déduites |
| Cautions détenues | Reçus + ajustements entrants - retenus - remboursés - ajustements sortants, contrepassations inversées | Toutes les lignes du ledger avant `report_end` |
| Cautions retenues | Retenues de la période moins contrepassations de retenue | `occurred_at` dans la période |
| Cautions remboursées | Remboursements de la période moins contrepassations de remboursement | `occurred_at` dans la période |
| Dépenses par statut | Nombre de lignes `draft`, `approved`, `rejected` | `expense_date` locale dans la période |
| Montant des dépenses approuvées | `SUM(expenses.amount)` | Uniquement statut `approved` dans la période |

Les contraintes composites garantissent qu’une allocation relie paiement et
facture du même tenant, de la même agence, du même client et de la même devise.

## Limites d’interprétation

Le rapport est opérationnel et descriptif. Il ne fournit pas grand livre,
rapprochement bancaire, reconnaissance de revenu, TVA officielle, consolidation
multi-devise, prévision ou décision automatisée. Une facture n’a pas encore
d’historique de statut daté : son exclusion `void` reflète donc son état
autoritatif courant. Les registres append-only et le journal d’audit restent la
preuve détaillée des corrections.
