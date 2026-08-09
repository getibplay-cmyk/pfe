# J12 — adaptateur contractuel J11 désactivé

## Décision

J12 intègre les quatre contrats et fixtures synthétiques scellés de J11 dans
Laravel comme preuve d’intégration locale. Cet adaptateur ne constitue pas une
activation Intelligence : `ready_for_saas=false`, chaque feature flag de
modèle reste à `false` et toute décision conserve l’effet
`NO_OPERATIONAL_ACTION`.

Le verrou applicatif est littéral dans `config/intelligence.php` :
`contract_demo.enabled=false`. Il n’est pas pilotable par une variable
d’environnement. Même si un test le remplace en mémoire, le verrou reste fermé
en environnement `production` et exige simultanément les invariants
`synthetic_only=true`, `operational_actions_allowed=false` et
`ready_for_saas=false`.

## Artefacts scellés

Les quatre schémas et les quatre fixtures J11 sont copiés sans transformation
dans `resources/intelligence/j11`. Leur SHA-256 attendu est déclaré par module
dans `J11AdvisoryModule`; toute absence ou altération ferme l’import avant
l’ouverture d’une transaction.

| Module | Fixture | Décision scientifique conservée |
|---|---|---|
| Prévision de la demande | `demand_forecast.accepted.json` | `CONFIRMED_FOR_OPTIMIZER_BENCHMARK` |
| Optimisation de flotte | `fleet_optimization.rejected.json` | `OPTIMIZER_CONDITIONAL_GATE_NOT_PASSED_NO_RETUNING` |
| Maintenance prédictive | `predictive_maintenance.pending.json` | `RESEARCH_GATE_NOT_PASSED_NO_RETUNING` |
| Usages atypiques | `rental_usage_anomaly.accepted.json` | `RESEARCH_GATE_PASSED_PUBLIC_PROXY_NOT_FOR_SAAS` |

La validation native est fermée sur les clés de premier niveau et vérifie les
invariants communs et propres à chaque module. Elle refuse notamment
l’activation d’un flag, une action automatique, une revendication de fraude ou
de probabilité de panne, l’exécution d’un solveur, un digest incohérent, des
coordonnées et des clés d’identité ou de contact.

## Stockage isolé et idempotence

Trois tables PostgreSQL dédiées et append-only sont créées :

- `ai_advisory_records_demo` pour la copie validée de la fixture ;
- `ai_idempotency_keys_demo` pour la clé et l’empreinte canonique ;
- `ai_human_decisions_demo` pour les revues humaines locales.

Les contraintes et triggers PostgreSQL imposent le tenant, l’agence, les
valeurs synthétiques, l’effet non opérationnel et l’immutabilité. Une même clé
avec la même empreinte produit `REPLAY_SAFE` sans second enregistrement. Une
empreinte différente est un conflit. Aucun code J12 n’écrit dans les véhicules,
réservations, contrats, maintenances, blocs, factures, paiements ou autres
tables métier.

## RBAC et routes

La lecture réutilise `prediction.view`. L’ajout d’une fixture scellée et la
revue humaine exigent `prediction.demo.review`, attribué seulement aux rôles
système Administrateur de l’entreprise et Responsable de flotte. Le tenant et
l’agence proviennent du contexte serveur; ils sont interdits dans les
requêtes. Les responsables d’agence et lecteurs autorisés conservent une
lecture limitée à leur périmètre.

Les routes isolées sont sous `/intelligence/contracts-demo`. Elles répondent
`404` tant que le verrou J12 est fermé. Aucun endpoint public, appel HTTP,
modèle, solveur, entraînement ou import de sorties publiques n’est ajouté.

## Vérification locale

Sur la pile RentFleet autorisée (PHP 8.5, Laravel 12 et PostgreSQL 18), exécuter :

```bash
php artisan test --filter=J12ContractAdapterTest
php artisan test --filter=J12DisabledContractAdapterTest
php artisan test
./vendor/bin/pint --test
npm run build
```

Les tests J12 couvrent les huit empreintes scellées, les invariants négatifs,
la matrice des six rôles, l’isolation tenant/agence, le rejeu idempotent,
l’audit minimal, les triggers append-only et l’absence d’écriture métier.

## Limite de livraison

La livraison s’arrête à la preuve d’adaptation contractuelle désactivée. Une
activation future exige un lot distinct, des données locales représentatives,
une validation scientifique explicite, une décision produit et sécurité, puis
une nouvelle autorisation de migration. J12 ne fournit aucune preuve de qualité
prédictive ni de préparation à la production.
