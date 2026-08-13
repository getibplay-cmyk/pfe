# J13 — tableau consultatif désactivé

## Décision

J13 ajoute un tableau de preuves scientifiques en lecture seule à l’écran Intelligence. Il ne transforme aucun benchmark, artefact synthétique ou fixture contractuelle en prédiction RentFleet.

La source d’autorité reste `docs/intelligence/j12-scientific-evidence-manifest.json`, décision `J13_CONSULTATIVE_DISABLED_ONLY`. Le catalogue applicatif refuse de rendre les cartes si la version, les quatre modules, les décisions gelées, les empreintes déclarées ou une frontière de sécurité ne correspondent plus aux contrats J11/J12.

## Résultat utilisateur

Un utilisateur possédant `prediction.view` voit exactement quatre cartes :

1. prévision de la demande ;
2. optimisation de flotte ;
3. maintenance prédictive ;
4. usages atypiques.

Chaque carte affiche :

- l’étape scientifique autoritaire ;
- le résultat explicite de la gate du benchmark proxy ;
- le score d’audit ;
- la classe et le rôle de la preuve ;
- l’affirmation anglaise exacte autorisée par le manifeste ;
- les affirmations interdites ;
- `feature flag : désactivé`, `SaaS : non` et `Production : non`.

Les formulations scientifiques anglaises sont rendues sans traduction interprétative afin de ne pas élargir les affirmations gelées. Les titres et explications d’interface restent en français.

## Frontières inchangées

- Aucun nouveau modèle et aucune tarification dynamique.
- Aucune inférence, aucun entraînement et aucun solveur.
- Aucun import de sorties historiques publiques.
- Aucune action automatique ou recommandation opérationnelle.
- Aucune écriture dans les véhicules, blocs, réservations, contrats, maintenances ou registres financiers.
- Aucune migration, route, dépendance, API ou tâche planifiée supplémentaire.
- Les routes `/intelligence/contracts-demo` restent derrière le verrou J12 et répondent 404 par défaut.
- Toute décision humaine de la démonstration isolée reste append-only, auditée et porte l’effet `NO_OPERATIONAL_ACTION`.
- Le tenant et l’agence continuent de provenir exclusivement du contexte serveur.

## Lignée des usages atypiques

Trois objets restent explicitement distincts :

| Objet | Nature | Autorisé dans J13 |
|---|---|---|
| `robust_mad_top2` | candidat sélectionné par le benchmark public proxy J9 Munich Shared Mobility v2 | non |
| `rental_anomaly_iforest 0.1.0` | artefact historique Lot 07B1 entraîné sur données synthétiques | non |
| fixture J11/J12 | preuve de schéma, validation, idempotence et audit avec `not_run_synthetic_contract_fixture` | aucune exécution de modèle |

La page ne qualifie donc plus l’Isolation Forest historique de « modèle J9 » et ne présente aucune fixture comme un résultat calculé.

## Autorisation et accessibilité

La route existante `/intelligence` conserve la Form Request et la permission `prediction.view`. Tenant Owner, Agency Manager, Fleet Manager et Viewer/Auditor peuvent consulter selon la matrice RBAC existante ; Rental Agent et Accountant restent refusés.

Les états sont communiqués par un texte explicite, pas uniquement par une couleur. Les affirmations et limites utilisent des titres et listes sémantiques. Le composant partagé des messages d’état conserve `role="status"` ou `role="alert"` selon le résultat.

## Traçabilité

J13 n’ajoute aucun événement d’audit à la simple consultation d’une preuve globale. Les imports et décisions de la démonstration J12 conservent les événements existants :

- `prediction.demo.fixture_imported` ;
- `prediction.demo.fixture_replayed` ;
- `prediction.demo.human_decision_recorded`.

Ils enregistrent le tenant, l’agence, l’acteur, le module, le résultat ou motif et `NO_OPERATIONAL_ACTION`, sans payload complet, secret ou empreinte visible dans l’interface.

## Validation attendue

- tests unitaires du catalogue et de la lignée ;
- tests fonctionnels des quatre cartes, de la permission et du verrou 404 ;
- tests J12 ciblés ;
- suite PostgreSQL complète ;
- Pint ;
- Composer validate et audit ;
- npm audit et build ;
- `rentfleet:doctor` ;
- gardes PowerShell et `git diff --check` via le workflow `quality`.

## Conditions avant une future activation

Une activation n’appartient pas à cette PR. Elle exige au minimum une validation temporelle locale RentFleet compatible, des seuils revus, des labels humains documentés, une analyse de dérive, un rollback, une nouvelle revue de sécurité/RBAC et une PR séparée. Aucune réussite sur un proxy public ne remplace ces preuves locales.

## Références méthodologiques

- NIST AI RMF, interaction humain–IA : <https://airc.nist.gov/airmf-resources/airmf/appendices/app-c-ai-risk-management-and-human-ai-interaction/>
- OWASP Logging Cheat Sheet : <https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html>
- W3C WCAG 2.2, messages d’état : <https://www.w3.org/WAI/WCAG22/Understanding/status-messages.html>
