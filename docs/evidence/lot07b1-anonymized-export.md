# Preuve Lot 07B1 — export réel anonymisé

## Point de départ

- branche : `main` ;
- parent attendu : `6e0368fd849d434142bad80afa8aa5d3e67e9b66` ;
- `origin/main` accepté au même commit ;
- worktree et index initiaux propres ;
- PHP Herd 8.5.8 ;
- 69 migrations initiales ;
- suite initiale : 307 tests, 3 185 assertions, succès ;
- build initial : Vite 6.4.3, 56 modules, succès.

## Garanties implémentées

- objets `PredictionInput` et `PredictionResult` readonly et versionnés ;
- interface de scoring et baseline de règles `source=rule` sans écriture ;
- pseudonymisation HMAC-SHA-256 tenant-scopée avec secret dédié ;
- schéma réel `1.1`, dataset `rentfleet-real-returns-v1.1.0` ;
- calcul exact des trois variables à six décimales ;
- requête limitée aux retours complets `returned` ou `closed` ;
- filtrage tenant/agence dérivé du contexte serveur ;
- CSV streamé, UTF-8 BOM, point-virgule, neutralisé et limité à 10 000 lignes ;
- audit sans contenu, pseudonyme, contrat, secret ni variable individuelle ;
- page Intelligence sans fausse prédiction ;
- permission `prediction.export` et matrice des six rôles système ;
- aucun identifiant brut, document, identité, véhicule identifiable, finance
  postérieure, cible ou décision humaine dans le CSV.

## Validation

- migration `2026_07_31_000001_add_prediction_export_permission` appliquée sur
  `rentfleet` et `rentfleet_test` ;
- total final : 70 migrations ;
- tests ciblés : 13 tests, 122 assertions, succès ;
- suite complète : 320 tests, 3 328 assertions, succès ;
- base de tests confirmée : `testing|pgsql|rentfleet_test` ;
- Pint `--test` : succès ;
- Composer validate strict : succès ;
- Vite 6.4.3 : 56 modules, build de production réussi ;
- routes : 206, dont `intelligence.index` et `intelligence.export` ;
- `rentfleet:doctor` : PHP 8.5.8, PostgreSQL 18.4, 70 migrations et contrôles
  d’intégrité au vert ; avertissements locaux attendus pour l’environnement et
  le heartbeat absent ;
- schéma JSON : syntaxe valide ;
- aucune table `ml_models`, `ml_predictions`, `import_batches` ou
  `import_rows` ;
- aucun paquet Composer ou npm ajouté ;
- aucune modification du modèle ou notebook 07A ;
- contrôles Git finaux consignés avant le commit.

## Limites assumées

- le modèle 07A reste gelé et entraîné sur données synthétiques ;
- aucun import ou stockage de prédiction n’est implémenté ;
- aucune API token, exécution Python, Joblib ou ONNX n’est ajoutée ;
- aucun second modèle n’est introduit ;
- l’export réel ne possède pas d’étiquette de vérité terrain ;
- la revue humaine et l’import appartiennent au Lot 08.
