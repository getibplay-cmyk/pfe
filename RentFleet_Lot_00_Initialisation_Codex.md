# RentFleet - Lot 00 : initialisation du projet

**Duree cible :** jours 1 a 3  
**Objectif :** obtenir une application Laravel propre, connectee a PostgreSQL, avec authentification, interface de base, tests et documentation reproductible.  
**Prerequis :** lire `RentFleet_Cahier_Architecture_Executable_Codex.md` avant toute action.

---

## 1. Resultat attendu

A la fin du lot 00, un developpeur doit pouvoir cloner le depot, configurer son environnement, lancer RentFleet, creer la base, executer les tests, se connecter et consulter un tableau de bord vide mais professionnel.

Ce lot ne contient aucune logique de flotte, reservation, contrat, multitenance ou intelligence artificielle.

## 2. Pile de reference

| Composant | Choix |
|---|---|
| Framework | Laravel 13.x |
| PHP | 8.4 recommande ; 8.3 minimum |
| Base de donnees | PostgreSQL 18 stable |
| Frontend | Starter kit officiel Livewire, Blade et Tailwind CSS |
| Assets | Vite et version Node LTS installee |
| Tests | Pest si propose par le starter kit, sinon PHPUnit |
| Formatage | Laravel Pint |
| Versionnement | Git |

Ne pas ajouter de package de roles, multitenance, audit, API ou UI pendant ce lot.

---

## 3. Prompt principal a coller dans Codex VS Code

```text
LOT : 00 - Initialisation de RentFleet

Lis d'abord :
- RentFleet_Cahier_Architecture_Executable_Codex.md ;
- les fichiers AGENTS.md applicables ;
- le README et l'etat Git s'ils existent.

CONTEXTE
RentFleet est un PFE de Master en Sciences des Donnees a realiser en 45 jours.
Il s'agit d'un SaaS B2B multitenant de gestion de location automobile.

OBJECTIF
Initialiser ou normaliser un projet Laravel 13 avec PHP 8.4, PostgreSQL,
le starter kit officiel Livewire, Blade/Tailwind, authentification, un layout
RentFleet minimal, une page dashboard protegee, une configuration de test
PostgreSQL et une documentation de demarrage reproductible.

AVANT TOUTE MODIFICATION
1. Inspecte le repertoire, Git et les fichiers existants.
2. Si un projet Laravel existe, ne le recree pas : indique sa version,
   ses changements locaux et adapte le lot sans ecraser le travail present.
3. Verifie les versions de PHP, Composer, Node/NPM et PostgreSQL.
4. Signale tout prerequis manquant avec la commande de verification exacte.
5. N'installe aucune dependance globale ou systeme sans mon accord.

TRAVAIL DEMANDE
1. Initialiser Laravel 13 seulement si aucun projet Laravel n'existe.
2. Utiliser le starter kit officiel Livewire.
3. Configurer PostgreSQL pour le developpement et les tests.
4. Garder les secrets dans .env et fournir un .env.example sain.
5. Regler APP_NAME=RentFleet, locale fr, fallback en,
   timezone Africa/Casablanca et faker_locale fr_FR.
6. Utiliser les pilotes database pour queue, session et cache si les
   migrations officielles correspondantes sont presentes.
7. Desactiver l'inscription publique. Garder la connexion fonctionnelle.
8. Creer un layout responsive minimal : sidebar desktop, navigation mobile,
   entete, zone principale et menu utilisateur.
9. Creer /dashboard protege avec quatre KPI a zero, une activite recente vide
   et une zone d'alertes vide. Ne creer aucune fausse logique metier.
10. Ajouter /health ou une commande equivalente pour verifier application et
    PostgreSQL sans exposer de secret.
11. Ajouter/completer README.md, docs/adr/0001-architecture.md,
    docs/evidence/.gitkeep et AGENTS.md.
12. Tester : login accessible, inscription absente, dashboard refuse aux
    invites, dashboard accessible apres connexion, health sain et connexion
    PostgreSQL de test.
13. Executer formatage, build frontend, migrations et tests.

CONTRAINTES
- Ne pas utiliser SQLite pour la suite principale de tests.
- Ne pas implementer tenant_id, roles, permissions ou audit dans ce lot.
- Ne pas ajouter React, Vue, Inertia, Redis, API metier ou Docker obligatoire.
- Ne pas exposer APP_KEY, mots de passe ou traces sensibles.
- Ne pas modifier une migration deja appliquee si le projet existe.
- Ne pas produire de chiffres metier fictifs dans le dashboard.
- Ne pas elargir le perimetre.

CRITERES D'ACCEPTATION
- Laravel demarre localement.
- PostgreSQL est la connexion par defaut.
- Les migrations passent sur une base vide.
- Le build frontend reussit.
- L'authentification fonctionne et l'inscription publique est fermee.
- /dashboard est protege et s'affiche apres connexion.
- La suite de tests PostgreSQL est verte.
- README permet une installation depuis un clone propre.
- Aucun secret n'est suivi par Git.

COMPTE RENDU FINAL
- Resultat observable.
- Fichiers modifies.
- Versions detectees.
- Commandes de validation et resultats.
- Hypotheses et actions manuelles restantes.
- Etat Git final, sans commit automatique sauf demande explicite.
```

---

## 4. Preparation sous Windows 10

Dans PowerShell ou le terminal integre de VS Code :

```powershell
php -v
composer --version
node --version
npm --version
psql --version
git --version
```

Attendus : PHP 8.3 minimum, Composer 2, Node LTS, PostgreSQL stable et Git.

Verifier les extensions PHP :

```powershell
php -m | Select-String -Pattern "pgsql|pdo_pgsql"
```

Les extensions importantes incluent `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo` et `curl`.

Si `psql` n'est pas reconnu mais PostgreSQL est installe, pgAdmin peut etre utilise pour creer les bases. Cela ne bloque pas Laravel si la connexion fonctionne.

### 4.1 Creation si aucun projet n'existe

Apres verification et accord pour l'installation globale :

```powershell
composer global require laravel/installer
laravel new rentfleet
```

Dans l'assistant, choisir :

- starter kit Livewire ;
- authentification Laravel standard ;
- Pest si propose ;
- PostgreSQL ;
- aucun module SaaS tiers.

Puis :

```powershell
cd rentfleet
npm install
npm run build
```

Ne jamais executer `laravel new` dans un projet existant.

---

## 5. Configuration PostgreSQL

Creer deux bases distinctes : `rentfleet` et `rentfleet_test`.

```sql
CREATE ROLE rentfleet_app WITH LOGIN PASSWORD 'CHANGE_ME_LOCALLY';
CREATE DATABASE rentfleet OWNER rentfleet_app;
CREATE DATABASE rentfleet_test OWNER rentfleet_app;
```

Le mot de passe reel ne doit jamais entrer dans Git, une capture ou le rapport.

### 5.1 Exemple `.env` local

```dotenv
APP_NAME=RentFleet
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_LOCALE=fr
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=fr_FR

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rentfleet
DB_USERNAME=rentfleet_app
DB_PASSWORD=CHANGE_ME_LOCALLY

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
```

Configurer explicitement `Africa/Casablanca` dans Laravel.

### 5.2 Exemple `.env.testing` local

```dotenv
APP_ENV=testing
APP_DEBUG=true
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rentfleet_test
DB_USERNAME=rentfleet_app
DB_PASSWORD=CHANGE_ME_LOCALLY
CACHE_STORE=array
MAIL_MAILER=array
QUEUE_CONNECTION=sync
SESSION_DRIVER=array
```

Ne jamais utiliser `rentfleet` avec `RefreshDatabase`, car les donnees de developpement seraient reinitialisees.

---

## 6. Structure attendue

```text
rentfleet/
  AGENTS.md
  README.md
  composer.json
  package.json
  .env.example
  app/
  config/
  database/
  docs/
    adr/0001-architecture.md
    evidence/.gitkeep
  resources/views/
  routes/
  tests/Feature/
```

Le nom exact des vues peut suivre le starter kit. Ne pas dupliquer le layout existant.

### 6.1 Contenu minimal de `AGENTS.md`

```markdown
# Instructions RentFleet

Lire `RentFleet_Cahier_Architecture_Executable_Codex.md` avant une modification
architecturale ou un nouveau module.

- Laravel monolithique modulaire avec PostgreSQL.
- Inspecter Git et preserver les changements existants.
- Developper par vertical slice avec tests dans le meme lot.
- Ne jamais accepter tenant_id depuis le client.
- Utiliser transactions et contraintes pour les invariants critiques.
- Ne pas ajouter de package, SPA ou microservice sans accord.
- Ne jamais commiter .env, secrets ou donnees personnelles.
- Executer les tests pertinents et Laravel Pint avant de terminer.
- Rapporter fichiers modifies, commandes, resultats et limites.
```

---

## 7. Validation

Commandes a adapter au projet :

```powershell
php artisan about
php artisan config:clear
php artisan migrate:fresh --seed
php artisan route:list
php artisan test
php vendor/bin/pint --test
npm run build
git status --short
```

Controle manuel :

1. Ouvrir le login.
2. Verifier l'absence d'inscription publique.
3. Se connecter avec un compte local de demonstration.
4. Verifier le dashboard en desktop et en largeur mobile.
5. Se deconnecter puis acceder directement a `/dashboard`.
6. Verifier que `/health` ne divulgue aucun secret.

### Porte de sortie

Le lot 01 ne commence que si :

- migrations, tests, formatage et build sont verts ;
- PostgreSQL est utilise en developpement et en test ;
- le demarrage est documente ;
- l'authentification fonctionne ;
- le depot ne contient aucun secret ;
- l'etat Git est compris.

---

## 8. Preuves pour le rapport

- `php artisan about` sans secret ;
- page de connexion et dashboard initial ;
- resultat des tests ;
- ADR Laravel/Livewire/PostgreSQL ;
- tableau des versions ;
- justification de PostgreSQL des le lot 00 afin de tester plus tard ses contraintes specifiques.

## 9. Etape suivante

Le lot 01 introduira tenants, agences, utilisateurs administratifs, roles, permissions, resolution du contexte tenant et tests d'isolation cross-tenant.
