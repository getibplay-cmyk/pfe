# RentFleet

RentFleet est un SaaS B2B de gestion de location automobile réalisé dans le
cadre d’un PFE de Master en Sciences des Données. Les lots 00 à 03 fournissent
le socle authentifié multitenant, les référentiels, les documents privés, la
tarification versionnée et les réservations protégées contre la double
affectation par PostgreSQL.

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

Pour réinitialiser volontairement la base locale de développement :

```powershell
php artisan migrate:fresh --seed
```

Cette dernière commande détruit les données locales. Les seeders créent deux
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
