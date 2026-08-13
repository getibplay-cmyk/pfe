# J14-B — import et revue humaine d’un lot de résultats

## Décision

J14-B ferme la boucle contractuelle J14-A sans activer de modèle. RentFleet
importe un JSON synthétique qualitatif uniquement s’il correspond exactement à
un snapshot privé existant. L’import est transactionnel, idempotent et
append-only ; une décision humaine distincte peut ensuite accepter la preuve
pour une revue de démonstration ou la rejeter.

Le lot n’écrit jamais dans les véhicules, réservations, contrats, maintenances,
factures ou paiements. Son effet est toujours `NO_OPERATIONAL_ACTION`. Il ne
lance aucun Python, modèle, entraînement, solveur ou service externe.

## Contrat fermé v1.0.0

La source normative lisible par machine est
`docs/intelligence/schemas/j14-result-batch-v1.0.0.json`. Tous les objets
refusent les propriétés inconnues. Les contrôles applicatifs ajoutent les
invariants inter-champs que JSON Schema ne relie pas à PostgreSQL :

- `export.run_id` doit exister dans le tenant courant ;
- versions, nombre de lignes et SHA-256 doivent correspondre au registre J14-A ;
- RentFleet relit le CSV privé et recalcule sa taille et son SHA-256 ;
- chaque `row_id` doit apparaître une fois, dans l’ordre exact du CSV ;
- les trois facteurs sont qualitatifs (`normal` ou `elevated`) et ordonnés ;
- la priorité vaut `low` pour zéro facteur élevé, `medium` pour un et `high`
  pour deux ou trois ;
- la source doit rester la fixture contractuelle synthétique déclarant
  `not_run_synthetic_contract_fixture` ;
- toute donnée client réelle, identité directe, coordonnée, action automatique,
  disponibilité SaaS ou production est déclarée absente/interdite.

Aucun score, probabilité, valeur brute de retard, kilométrage, carburant,
identifiant métier direct ou payload libre n’appartient au contrat.

## Empreinte canonique et idempotence

`idempotency.policy` vaut `SAME_KEY_SAME_PAYLOAD_ONLY`. Pour calculer
`canonical_payload_sha256` :

1. retirer uniquement `idempotency.canonical_payload_sha256` ;
2. trier récursivement les clés de chaque objet par ordre lexical ;
3. préserver strictement l’ordre des tableaux ;
4. encoder un JSON UTF-8 compact, sans échapper les barres obliques ni Unicode ;
5. calculer SHA-256 sur ces octets, sans saut de ligne final.

Le serveur reproduit ce calcul. Le même périmètre et la même clé avec la même
empreinte donnent un rejeu sûr sans nouvelle ligne. La même clé avec un payload
différent renvoie `409 Conflict`. Un verrou transactionnel PostgreSQL protège
également deux imports concurrents.

## Persistance privée et invariants PostgreSQL

Le JSON brut téléversé n’est pas conservé. Après validation, RentFleet écrit sa
forme canonique sur le disque Laravel privé sous un nom serveur aléatoire, puis
enregistre :

- `intelligence_result_batches` pour la lignée, les versions, empreintes et
  métadonnées d’import ;
- `intelligence_result_rows` pour les sorties qualitatives et leur position ;
- `intelligence_result_batch_decisions` pour l’unique décision humaine.

Des clés étrangères composites protègent tenant et agence. Des triggers
vérifient la correspondance avec l’export, le nombre et les positions des
lignes, le périmètre de l’acteur et refusent toute mise à jour ou suppression.
Le fichier canonique est supprimé si la transaction échoue.

Avant chaque téléchargement ou sélection de fallback, taille et SHA-256 sont
recalculés. Une absence renvoie `410`, une altération `409`, et aucun contenu
n’est servi.

## RBAC, revue et fallback

- `prediction.view` permet de consulter le registre dans le tenant/agence
  autorisé ;
- `prediction.demo.review` permet l’import et la décision humaine ;
- Tenant Owner peut agir sur son tenant ; Fleet Manager reste limité à son
  agence ; les autres rôles suivent la matrice existante ;
- tenant et agence proviennent uniquement de la session et de `TenantContext`.

La décision est append-only : `accepted_for_demo_review` ou `rejected`, avec un
motif fermé. Même acceptée, la preuve demeure synthétique et non opérationnelle.

Le fallback cherche la décision acceptée la plus récente dont le fichier privé
reste intègre. S’il n’en existe aucune, l’état explicite est « aucune
recommandation » et toutes les fonctions métier restent inchangées.

## Audit sans fuite

Les événements suivants conservent l’identifiant de lot, le `run_id`, le
résultat de l’idempotence, le nombre de lignes, la décision et le motif utiles :

- `prediction.result_batch.imported` ;
- `prediction.result_batch.replayed` ;
- `prediction.result_batch.downloaded` ;
- `prediction.result_batch.human_decision_recorded`.

Ils excluent contenu JSON/CSV, chemins privés, clés d’idempotence, empreintes,
`row_id`, valeurs quantitatives, secrets et identifiants métier. Le middleware
ajoute la corrélation de requête côté serveur.

## Validation attendue

```bash
php artisan test --filter=J14ResultBatchImportReviewTest
php artisan test --filter=J14ResultBatchContractTest
php artisan test
./vendor/bin/pint --test
composer validate --strict --no-interaction
composer audit --locked --no-interaction
npm ci
npm audit
npm run build
php artisan rentfleet:doctor --json --env=testing --expect-database=rentfleet_test
git diff --check
```

## Références

- OWASP File Upload Cheat Sheet :
  <https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html>
- PostgreSQL 18, contraintes :
  <https://www.postgresql.org/docs/18/ddl-constraints.html>
- NIST AI RMF, interaction humain–IA :
  <https://airc.nist.gov/airmf-resources/airmf/appendices/app-c-ai-risk-management-and-human-ai-interaction/>
- Laravel 12, validation des fichiers :
  <https://laravel.com/docs/12.x/validation#validating-files>
