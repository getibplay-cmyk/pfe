# RentFleet

RentFleet est un SaaS B2B de gestion de location automobile réalisé dans le
cadre d’un PFE de Master en Sciences des Données. Les lots 00 à 06 fournissent
un cycle démontrable de la réservation à la clôture, avec isolation
multitenant, documents privés, contraintes PostgreSQL et outillage de release.

## Stack

- Laravel 12.63 ;
- PHP 8.5 avec `pdo_pgsql` et `pgsql` ;
- PostgreSQL 18 ;
- Breeze Blade, Livewire 3.8, Tailwind CSS et Vite ;
- PHPUnit et Laravel Pint.

Livewire reste réservé aux interactions ciblées ; les pages actuelles utilisent Blade.

## Prérequis

- PHP 8.5 avec les extensions PostgreSQL ;
- Composer 2 ;
- PostgreSQL 18 ;
- Node.js et npm.

Vérification sous PowerShell :

```powershell
php -v
composer --version
php -m | Select-String -Pattern "pdo_pgsql|pgsql"
psql --version
node --version
npm.cmd --version
```

## Installation

Depuis un clone propre :

```powershell
composer install --prefer-dist --no-interaction
Copy-Item .env.example .env
php artisan key:generate
npm.cmd install --no-audit --no-fund
```

Configurer `.env` localement. Ne jamais commiter ce fichier ni une clé ou un
mot de passe réel :

```dotenv
APP_NAME=RentFleet
APP_ENV=local
APP_URL=http://localhost:8000
APP_LOCALE=fr
APP_FALLBACK_LOCALE=en

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rentfleet
DB_USERNAME=rentfleet_app
DB_PASSWORD=
```

## Bases PostgreSQL

Créer un rôle applicatif et deux bases séparées depuis un compte PostgreSQL
administrateur :

```sql
CREATE ROLE rentfleet_app WITH LOGIN;
\password rentfleet_app
CREATE DATABASE rentfleet OWNER rentfleet_app;
CREATE DATABASE rentfleet_test OWNER rentfleet_app;
```

Le développement utilise `rentfleet`. Les tests utilisent exclusivement
`rentfleet_test`, configurée dans un fichier local `.env.testing` ignoré par Git.
Ne jamais lancer `RefreshDatabase` avec la base de développement.

## Migrations et lancement

```powershell
php artisan optimize:clear
php artisan migrate
php artisan migrate:status
php artisan serve
```

L’application est disponible sur `http://localhost:8000`. L’inscription
publique est désactivée ; un utilisateur doit être créé localement avant la
connexion. Le dashboard est protégé par le middleware `auth`.

## Tests, formatage et frontend

```powershell
php artisan test
php vendor/bin/pint --test
npm.cmd run build
```

Pour corriger le formatage :

```powershell
php vendor/bin/pint
```

## État du lot 00

- authentification Breeze Blade et connexion disponibles ;
- inscription publique désactivée ;
- dashboard protégé avec quatre KPI à zéro ;
- sections d’activité et d’alertes vides ;
- endpoint `/health` vérifiant l’application et PostgreSQL sans secret ;
- sessions, cache et queue configurés avec le pilote `database` ;
- aucune table métier, aucun tenant, rôle, véhicule, contrat ou composant IA.

## Lot 01 — socle multitenant

Le lot 01 ajoute les entreprises clientes, agences, rôles, permissions,
utilisateurs rattachés, contexte tenant serveur, policies et audit minimal.
L’isolation combine middleware, `TenantContext`, global scope, policies,
contraintes PostgreSQL et tests cross-tenant. Aucun sélecteur libre de tenant
n’est exposé dans les routes métier.

Réinitialiser exclusivement la base de test avec les données fictives :

```powershell
php artisan migrate:fresh --seed --env=testing
```

Ne jamais exécuter `migrate:fresh` sur `rentfleet`. Pour une démonstration
persistante, créer une base dédiée `rentfleet_demo`, vérifier sa configuration
avec `php artisan db:show`, puis suivre le runbook. Les seeders créent deux
tenants fictifs, trois agences, les six rôles initiaux et des utilisateurs de
démonstration avec les domaines `@atlas-demo.test` et `@rif-demo.test`. Le mot
de passe de démonstration peut être fourni localement avec `DEMO_PASSWORD` ;
aucun mot de passe réel n’est documenté ou versionné.

Routes principales :

- `/tenant` : informations de l’entreprise courante ;
- `/agencies` : agences autorisées ;
- `/users` : utilisateurs autorisés et attribution des rôles ;
- `/audit-logs` : journal tenant filtré ;
- `/platform/dashboard` : espace séparé du platform admin.

## Lot 02 — véhicules, clients et documents privés

Le lot 02 fournit les catégories, véhicules et historiques de statut
opérationnel, ainsi que les clients, conducteurs et documents versionnés.
La disponibilité locative n’est pas stockée : elle sera dérivée au lot 03.

Les numéros d’identité et de permis sont normalisés, chiffrés avec le service
Laravel et accompagnés d’une empreinte HMAC tenant-scopée. Les listes ne
montrent qu’une valeur masquée. Une consultation complète nécessite la
permission dédiée et produit un audit sans inclure la valeur consultée.

Les fichiers sont stockés sous `storage/app/private` avec un nom aléatoire.
Ils ne disposent d’aucune URL publique et sont téléchargés uniquement via un
contrôleur autorisé. La taille maximale initiale est de 10 Mio et peut être
configurée localement :

```dotenv
PRIVATE_DOCUMENT_MAX_KB=10240
```

Les seeders ajoutent quatre catégories, seize véhicules, douze clients avec
conducteurs et trois PDF strictement fictifs. Aucun numéro réel n’est utilisé.

Routes principales supplémentaires :

- `/vehicle-categories` ;
- `/vehicles` ;
- `/customers` ;
- `/documents/{document}` et téléchargement contrôlé associé.

## Lot 03 — tarification, réservations et disponibilité

Les règles tarifaires sont versionnées : une modification archive la version
active et crée une nouvelle ligne. La résolution privilégie une règle d’agence,
puis la priorité, la date de validité la plus récente et enfin l’identifiant.
Les devis utilisent des centimes entiers et les montants PostgreSQL
`numeric(14,2)` ; aucun calcul monétaire ne dépend d’un `float`.

Une réservation `draft` ou `pending` ne bloque pas le véhicule. La confirmation
transactionnelle résout le tarif, fige `pricing_snapshot`, crée un
`vehicle_block` actif, l’historique et l’audit. L’annulation libère le bloc.
Les intervalles sont semi-ouverts `[début, fin)` : deux créneaux qui se touchent
exactement sont autorisés.

PostgreSQL impose l’absence de chevauchement avec l’extension `btree_gist` et
la contrainte `vehicle_blocks_no_active_overlap_excl`. Cette extension doit
pouvoir être créée par le rôle de migration. Elle n’est volontairement pas
supprimée lors d’un rollback, car elle peut être partagée.

Routes principales :

- `/pricing-rules` : règles et création de versions ;
- `/availability` : recherche par agence, catégorie et période ;
- `/reservations` : brouillons, devis, confirmation, annulation et historique.

La durée maximale initiale est configurable sans secret :

```dotenv
RESERVATION_MAXIMUM_DURATION_DAYS=365
```

La commande planifiée suivante expire les attentes arrivées à échéance :

```powershell
php artisan reservations:expire-pending
```

Le scheduler Laravel l’exécute chaque minute. Pour la démonstration, le seeder
ajoute des tarifs MAD et des réservations fictives dans tous les états du lot.

## Lot 04 — contrats, départ et retour

Une réservation confirmée peut désormais être convertie en un contrat unique.
La conversion conserve le `vehicle_block` existant, le rattache au contrat et
passe la réservation à `converted` sans recalculer son tarif figé.

Le contrat suit la machine d’états suivante :

```text
draft → ready → accepted → active → return_pending → returned
   └──────────────→ cancelled
```

Chaque contrat possède des versions JSON canoniques avec empreinte SHA-256.
L’acceptation trace la version exacte, la méthode, la date, l’adresse IP et le
user-agent. Une version acceptée reste immuable ; un avenant crée une nouvelle
version qui doit être acceptée à nouveau. Cette acceptation PFE n’est pas
présentée comme une signature électronique qualifiée.

Le départ exige une inspection terminée, un véhicule actif, un permis valide
et le bloc contractuel actif. Le retour compare kilométrage, carburant et
éléments contrôlés. Les frais restent proposés jusqu’à une approbation ou un
rejet explicite. Les dommages ne reçoivent jamais de responsabilité automatique.
Les photos d’inspection et de dommage réutilisent le stockage documentaire privé.

Le lot se termine à `returned`. Le statut `closed`, les paiements et les
mouvements de caution sont volontairement réservés au lot 05.

Routes principales :

- `/contracts` : liste filtrée des contrats ;
- `/contracts/{contract}` : timeline, versions, inspections, dommages et frais ;
- `/contracts/{contract}/print` : vue HTML imprimable, sans package PDF ;
- création du contrat depuis la fiche d’une réservation confirmée.

Les scénarios de démonstration créent six contrats fictifs (`draft`, `ready`,
`accepted`, `active`, `return_pending`, `returned`), des inspections, deux
dommages et des frais proposés/approuvés. Pour rejouer uniquement sur la base
de test :

```powershell
php artisan migrate:fresh --seed --env=testing
php artisan test tests/Feature/Lot04RentalContractLifecycleTest.php
```

## Lot 05 — finance, clôture, maintenance et assurance

Le workflow contractuel atteint désormais `closed` uniquement après règlement :

```text
returned → invoice issued → payment allocation + deposit settlement → closed
```

Les factures émises et leurs lignes sont protégées contre la mutation par PostgreSQL. Paiements et cautions sont des registres idempotents et traçables ; toute correction produit une contrepassation. Les montants utilisent `numeric(14,2)` et `DecimalMoney`, sans `float`. Les taxes sont configurables et figées, mais ne constituent pas un calcul fiscal officiel.

Une maintenance approuvée crée un `vehicle_block` soumis à la même contrainte GiST que les réservations. La terminer libère le bloc et peut générer une dépense brouillon. Les polices, garanties et sinistres sont suivis administrativement ; numéros et références sensibles sont chiffrés, et aucune responsabilité juridique n’est décidée automatiquement.

Routes principales :

- `/finance` et `/finance/invoices/{invoice}` ;
- `/maintenance` ;
- `/insurance` ;
- `/dashboard` avec KPI financiers et opérationnels tenant/agence-scopés.

Scénarios fictifs du seeder : factures partiellement payée et payée, cautions remboursée et partiellement retenue, contrat clôturé, maintenances planifiée/en cours, assurance proche expiration et sinistre en revue.

Validation ciblée :

```powershell
php artisan test tests/Feature/Lot05FinancePhaseATest.php
php artisan test tests/Feature/Lot05MaintenanceInsurancePhaseBTest.php
```

Ce lot n’ajoute ni comptabilité générale, grand livre, paiement bancaire réel, stockage de carte, déclaration fiscale, paie ou IA.

## Lot 06 — sécurité, exploitation et release candidate

Le lot 06 ajoute des en-têtes HTTP défensifs, un identifiant de corrélation,
des pages d’erreur génériques, une configuration de production sans secret et
la commande non destructive suivante :

```powershell
php artisan rentfleet:doctor
```

Le contrôle de déploiement vérifie migrations, diagnostic, caches, tests,
formatage et build :

```powershell
powershell -ExecutionPolicy Bypass -File scripts/deploy-check.ps1
```

Les procédures détaillées sont dans `docs/deployment/production-checklist.md`,
`docs/operations/backup-and-restore.md` et `docs/demo/runbook.md`. Sauvegarde et
restauration associent PostgreSQL et le stockage privé ; la restauration
automatisée est limitée à `rentfleet_restore_test`. Les mots de passe passent
par `pgpass`/`PGPASSFILE`, jamais par un argument ou un fichier versionné.

La release candidate reste volontairement sans IA. Les règles, modèles et
prédictions relèvent des lots suivants et ne conditionnent aucun flux métier.

## Lot 06D — administration SaaS et reporting minimal

L’administration plateforme est isolée sous `/platform/*` et réservée aux
comptes `is_platform_admin`. Elle fournit un dashboard global non sensible et
le cycle de vie des tenants : recherche, création transactionnelle, détail,
mise à jour, suspension motivée et réactivation.

Le provisioning crée atomiquement le tenant, son agence initiale, ses paramètres
et son premier Tenant Owner. Le mot de passe temporaire est aléatoire, affiché
une seule fois avec une réponse `no-store`, absent des audits et doit être changé
avant l’accès aux routes métier. Aucun service mail n’est requis pour ce flux.

Dans un tenant actif, le Tenant Owner administre ses paramètres, agences et
utilisateurs. Les Agency Managers restent bornés à leur agence et à une liste de
rôles délégables. Désactiver une agence avec des réservations, contrats,
maintenances ou blocs actifs est refusé ; le dernier Tenant Owner actif et la
dernière agence active sont également protégés. Ces opérations ne font aucune
suppression physique.

Les écrans ajoutés sont :

- `/platform/dashboard` et `/platform/tenants` pour l’administration SaaS ;
- `/tenant`, `/agencies` et `/users` pour l’administration tenant ;
- `/reports` pour le rapport opérationnel et financier minimal ;
- `/reservations/export` pour l’export CSV filtré et streamé.

Le CSV UTF-8 est compatible tableur, neutralise les cellules commençant comme
une formule, utilise un nom déterministe et exclut numéros d’identité et permis.
Les définitions, périmètres et limites des indicateurs sont documentés dans
`docs/reporting/kpi-definitions.md`. Il ne s’agit ni d’une comptabilité générale,
ni d’un reporting fiscal ou décisionnel avancé.

Validation ciblée :

```powershell
php artisan test tests/Feature/Lot06DSaasAdministrationReportingTest.php
php artisan route:list --path=platform
php artisan route:list --path=reports
```

## Lot 06E — expérience utilisateur et autorisations visibles

Les menus desktop et mobile sont produits par la même matrice de permissions.
Chaque rôle ne voit que les modules réellement accessibles, la route active est
signalée et l’administration plateforme reste entièrement séparée. Les appels
directs demeurent protégés côté serveur même lorsqu’une action est masquée.

Le profil permet de modifier le nom, l’e-mail et le mot de passe. Tenant, agence,
rôle et état sont affichés en lecture seule. La route Breeze de suppression du
profil et son formulaire ont été retirés ; les désactivations sont réservées à
l’administration. Un changement de mot de passe ferme les autres sessions.

Le dashboard tenant/agence présente désormais, selon les permissions :

- réservations à venir et retours attendus ou en retard ;
- véhicules indisponibles et maintenances à surveiller ;
- échéances documentaires et permis proches de l’expiration ;
- factures impayées et soldes uniquement aux rôles financiers autorisés ;
- sinistres ouverts et activité d’audit non sensible.

Les libellés de statuts, rôles, paiements, documents, maintenance et assurance
sont centralisés en français sans modifier les valeurs techniques en base. Les
listes prioritaires conservent recherche, filtres et pagination.

La matrice complète est documentée dans `docs/roles-and-navigation.md`.

```powershell
php artisan test tests/Feature/Lot06EUxNavigationAuthorizationTest.php
php artisan test tests/Feature/ProfileTest.php
```

## Lot 06F-D1 — reporting exact et explicable

Le rapport `/reports` utilise désormais une seule définition pour l’écran, son
export `/reports/export` et les indicateurs mensuels repris sur le dashboard.
Les filtres sont obligatoires, bornés à 366 jours, convertis en période
semi-ouverte `[début, fin)` dans `Africa/Casablanca` et limités côté serveur aux
agences autorisées.

Les indicateurs couvrent réservations, contrats, flotte, utilisation,
maintenance, assurance, échéances et finance. Les durées sont agrégées par
PostgreSQL. Les montants restent en décimal et sur des lignes séparées pour
chaque devise ; aucun taux de change n’est appliqué. Les exports CSV UTF-8 sont
streamés, compatibles Excel francophone, neutralisés contre les formules et
audités sans contenu ni donnée privée.

Les formules et limites sont détaillées dans
`docs/reporting/kpi-definitions.md` et la décision dans
`docs/adr/0013-reporting-periods-and-currency.md`.

```powershell
php artisan rentfleet:doctor
php artisan test tests/Feature/Lot06FD1ReportingDiagnosticsTest.php
php artisan route:list --path=reports
```

## Lot 06F-D2 — exploitation et préparation release

Le scheduler exploite le fuseau `Africa/Casablanca`, les verrous PostgreSQL du
cache partagé et trois événements idempotents : heartbeat et expiration des
réservations chaque minute, expiration des polices chaque jour à 00:15. Le
heartbeat non sensible est stocké dans `operational_heartbeats` ;
`rentfleet:doctor` avertit en local et échoue en production lorsqu’il est trop
ancien.

La production exige PostgreSQL, HTTPS, debug désactivé, cookies sécurisés,
stockage documentaire privé, logs quotidiens et absence de seeders de
démonstration. Cette release ne dépêche aucune tâche applicative en queue : le
pilote database reste configuré, mais aucun worker n’est requis.

Contrôles locaux avec PHP Herd :

```powershell
$env:PHP_BINARY = 'C:\Users\pc\.config\herd\bin\php85\php.exe'
& $env:PHP_BINARY artisan schedule:list
& $env:PHP_BINARY artisan operations:scheduler-heartbeat
& $env:PHP_BINARY artisan rentfleet:doctor
```

Les scripts `backup.ps1`, `restore.ps1` et `verify-restore.ps1` n’acceptent
aucun mot de passe en ligne de commande. Ils utilisent `pgpass`, un dump custom,
une archive privée et un manifeste avec tailles/SHA-256. Toute restauration est
limitée au nom exact `rentfleet_restore_test` et à une racine documentaire
temporaire hors du dépôt ; `rentfleet` et son stockage vivant ne sont jamais
écrasés. La procédure reproductible est détaillée dans
`docs/operations/backup-and-restore.md` et le déploiement manuel dans
`docs/operations/deployment.md`.

Validation ciblée :

```powershell
& $env:PHP_BINARY artisan test tests/Feature/Lot06FD2OperationsReleaseTest.php
php vendor/bin/pint --test
npm.cmd run build
```

Une sauvegarde n’est déclarée restaurable qu’après restauration réelle,
comparaison agrégée et doctor vert sur `rentfleet_restore_test`. L’absence de
`pgpass.conf` ou de cette base dédiée bloque donc honnêtement la preuve réelle,
sans bloquer les contrôles statiques du lot.
