# RentFleet

RentFleet est un SaaS B2B de gestion de location automobile réalisé dans le
cadre d’un PFE de Master en Sciences des Données. Le lot 00 fournit le socle
technique : authentification, interface responsive, dashboard vide, contrôle
de santé, PostgreSQL et tests reproductibles. Aucun module métier ou mécanisme
multitenant n’est encore implémenté.

## Stack

- Laravel 12.63 ;
- PHP 8.5 avec `pdo_pgsql` et `pgsql` ;
- PostgreSQL 18 ;
- Breeze Blade, Tailwind CSS et Vite ;
- PHPUnit et Laravel Pint.

Livewire reste réservé aux interactions ciblées ; les pages du socle multitenant utilisent Blade.

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
