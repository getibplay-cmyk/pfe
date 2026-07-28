# Lot 07B1 — contrat d’intégration Intelligence

## Portée

Ce sous-lot prépare l’intégration du modèle unique
`rental_anomaly_iforest` version `0.1.0`. Ce modèle Isolation Forest est gelé,
utilise `late_hours`, `km_per_day` et `fuel_drop_pct`, applique le seuil
`0.5740760891923362` et a été étudié sur des données synthétiques. Il ne doit
jamais être présenté comme validé sur les retours réels de RentFleet.

Le calcul et l’adaptation Colab utilisent le CPU. Aucun GPU n’est nécessaire.
Le sous-lot n’exécute aucun modèle dans Laravel, n’importe aucune prédiction et
ne crée aucune table ML.

## Objets d’intégration

- `PredictionInput` est un snapshot readonly ordonné du dataset réel v1.1.
- `PredictionResult` décrit un résultat versionné sans imposer son stockage.
- `PredictionScoringService` définit le contrat de scoring.
- `RuleBasedScoringService` fournit un fallback `source=rule`, déterministe,
  explicable et sans écriture.

La baseline emploie trois seuils configurables dans
`config/intelligence.php`. Ses facteurs indiquent seulement si une variable
mérite une revue. Elle ne décide jamais de fraude, de responsabilité, de
dommage ou de frais et n’est pas un résultat Isolation Forest.

## Dataset réel v1.1

Le schéma canonique est
`docs/intelligence/schemas/rental-anomaly-export-v1.1.json`.

Une ligne est produite uniquement pour un contrat `returned` ou `closed` du
tenant et des agences autorisées, avec dates, kilométrages, carburant et
inspection de retour `completed` cohérents. Les dates de l’interface sont
converties en intervalle semi-ouvert `[début, fin)` dans le fuseau du tenant,
par défaut `Africa/Casablanca`.

Formules à l’instant du retour :

- `event_at` : `actual_return_at` en ISO-8601 UTC ;
- `late_hours` : `max(0, actual_return_at - expected_return_at) / 3600` ;
- `distance_km` : `max(0, return_mileage - start_mileage)`, non exportée ;
- `km_per_day` : `distance_km × 86400 / durée_réelle_en_secondes` ;
- `fuel_drop_pct` : `max(0, start_fuel_level - return_fuel_level)`.

Les trois variables sont arrondies à six décimales avec un point. Une durée
non positive, un kilométrage décroissant ou une donnée requise absente exclut
explicitement la ligne ; aucune donnée métier n’est corrigée.

Le CSV UTF-8 avec BOM utilise le point-virgule, neutralise les formules
tableur, est streamé par lots et limité à 10 000 lignes. Il ne contient aucune
cible, étiquette, prédiction, donnée financière postérieure ou décision
humaine.

## Pseudonymisation

`INTELLIGENCE_EXPORT_HMAC_KEY` est le seul secret dédié. Il reste vide dans
les exemples versionnés, doit être fourni par l’environnement et n’est jamais
accepté depuis une requête. Une valeur absente ou trop courte ferme l’export
avec un message générique.

Les pseudonymes HMAC-SHA-256 complets utilisent une séparation de domaine :

- `tenant|v1|{tenant_id}` → `t_<hex>` ;
- `agency|v1|{tenant_id}|{agency_id}` → `a_<hex>` ;
- `contract|v1|{tenant_id}|{contract_id}` → `c_<hex>` ;
- `row|v1|{tenant_id}|{contract_id}|{event_at_utc}` → `r_<hex>`.

Ils sont stables pour un même secret, différents entre tenants et non
inversables sans le secret. Ils ne sont ni affichés dans la page ni inscrits
dans l’audit.

## Colonnes interdites

L’export exclut tout identifiant brut, identité, contact, adresse, CIN,
passeport, permis, plaque, VIN, marque, modèle, document, chemin privé, note,
texte libre, utilisateur, client, conducteur, responsabilité, frais,
paiement, caution, facture, label d’anomalie et décision de revue.

## RBAC

| Rôle système | Consulter | Exporter |
|---|---:|---:|
| Administrateur de l’entreprise | Oui | Oui |
| Responsable d’agence | Oui, agence assignée | Oui, agence assignée |
| Agent de location | Non | Non |
| Responsable de flotte | Oui | Non |
| Comptable | Non | Non |
| Lecteur / auditeur | Oui | Non |

Les rôles personnalisés et délégations existants sont préservés. La migration
ajoute `prediction.export` et un index partiel d’export. Son `down` supprime
seulement l’index : la permission et ses attributions sont conservées par
prudence, car elles peuvent avoir préexisté ou avoir été déléguées après
migration.

## Procédure Drive / Colab

1. Configurer le secret dans l’environnement RentFleet, hors Git.
2. Ouvrir **Pilotage → Intelligence** avec un rôle autorisé.
3. Choisir la période et l’agence autorisée, puis télécharger le CSV.
4. Déposer manuellement le CSV dans un espace Drive privé contrôlé.
5. Dans Colab, charger le fichier avec UTF-8 BOM, séparateur `;` et les dix
   colonnes dans l’ordre du schéma v1.1.
6. Vérifier l’absence de colonnes supplémentaires et adapter le notebook à ce
   dataset réel non étiqueté sans modifier le modèle gelé 07A.

La prochaine étape est l’adaptation scientifique du notebook au CSV réel,
puis le Lot 08 pour l’import idempotent et la revue humaine. Aucun import,
upload de prédiction ou endpoint `/api/v1` n’existe dans 07B1.
