# J14-A — snapshot d’export et manifeste reproductible

## Décision

J14-A transforme l’export CSV réel v1.1 en une preuve reproductible avant tout
import de résultats. Chaque téléchargement crée un `run_id` unique, conserve le
CSV pseudonymisé sur le disque privé et enregistre un manifeste immuable dans
PostgreSQL.

Ce lot ne lance aucun modèle, n’importe aucune prédiction et ne crée aucune
recommandation. Son effet reste `NO_OPERATIONAL_ACTION`.

## Snapshot privé

Le contenu du CSV reste identique au contrat Lot 07B1 : UTF-8 avec BOM,
séparateur `;`, dix colonnes fermées, ordre stable par identifiant interne et
maximum de 10 000 lignes. Le `run_id` et l’heure de création ne sont pas ajoutés
au CSV afin que deux exports du même état métier et du même périmètre produisent
la même empreinte SHA-256.

Le fichier est écrit dans le disque Laravel `local`, dont la racine est privée.
Son chemin serveur aléatoire n’est ni rendu dans l’interface, ni placé dans le
manifeste, ni enregistré dans l’audit. Avant chaque téléchargement, RentFleet
recalcule la taille et le SHA-256 du flux ; une absence renvoie `410` et une
altération renvoie `409` sans servir le contenu.

## Registre append-only

`intelligence_dataset_export_runs` conserve :

- `run_id`, version du manifeste, version du schéma et version du dataset ;
- période inclusive demandée et fuseau métier ;
- périmètre tenant ou agence avec une clé HMAC pseudonymisée ;
- nombre de lignes, limite, taille et SHA-256 du snapshot ;
- format, nom de téléchargement, créateur et date ;
- effet constant `NO_OPERATIONAL_ACTION`.

Les contraintes PostgreSQL vérifient les versions gelées, la cohérence du
périmètre, les formats, la période et le digest. Un trigger refuse toute mise à
jour ou suppression. Le tenant vient exclusivement de `TenantContext` et les
clés étrangères composites protègent l’agence et le créateur.

## Manifeste JSON

Le manifeste téléchargeable contient seulement des clés pseudonymisées et des
métadonnées nécessaires à J14-B :

- `manifest_version` et `run_id` ;
- `schema_version` et `dataset_version` ;
- périmètre et période ;
- format, nombre de lignes, taille et `content_sha256` ;
- frontières de sécurité explicites.

Il exclut les identifiants bruts de tenant, agence, contrat, client ou
utilisateur, le chemin privé, le payload CSV, toute étiquette et toute décision
humaine.

## RBAC, isolation et audit

La création, le manifeste et le téléchargement réutilisent
`prediction.export`. Tenant Owner peut voir les exports de son tenant ; Agency
Manager reste limité à son agence. Les autres rôles sont refusés selon la
matrice existante. Le global scope, la policy et les contraintes PostgreSQL se
complètent.

Les événements `prediction.dataset.exported`,
`prediction.dataset.snapshot_downloaded` et
`prediction.dataset.manifest_downloaded` enregistrent le `run_id`, le résultat
et les versions utiles, sans contenu, chemin, secret ou identifiant métier.

## Validation attendue

```bash
php artisan test --filter=Lot07B1IntelligenceExportTest
php artisan test
./vendor/bin/pint --test
composer validate --strict --no-interaction
composer audit --locked --no-interaction
npm audit
npm run build
php artisan rentfleet:doctor --json --env=testing --expect-database=rentfleet_test
git diff --check
```

Les tests couvrent le déterminisme, le manifeste fermé, le disque privé,
l’immutabilité PostgreSQL, l’isolation tenant/agence, le RBAC et le refus d’un
snapshot altéré.

## Étape suivante

J14-B pourra importer un lot uniquement s’il référence un `run_id` existant et
si `schema_version`, `dataset_version`, nombre de lignes et checksum
correspondent exactement à ce manifeste. Cette future étape restera
idempotente, contrôlée par un humain et livrée dans une PR distincte.

## Références

- NIST AI RMF, interaction humain–IA : <https://airc.nist.gov/airmf-resources/airmf/appendices/app-c-ai-risk-management-and-human-ai-interaction/>
- OWASP Logging Cheat Sheet : <https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html>
