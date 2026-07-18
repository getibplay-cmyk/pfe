# Déploiement manuel de la release candidate

Ce runbook décrit une production simple sans effectuer de déploiement externe.
PostgreSQL est l’unique moteur pris en charge.

## Prérequis serveur

- PHP 8.5 avec `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `fileinfo` et
  `intl` ;
- Composer 2, PostgreSQL 18, serveur web maintenu et HTTPS ;
- Node.js/npm sur la machine de build seulement ;
- document root strictement limité à `public/` ;
- `storage/app/private` persistant, non public et inscriptible ;
- cache PostgreSQL partagé pour les verrous du scheduler ;
- cron ou planificateur système appelant Laravel chaque minute.

Aucune tâche applicative n’implémente actuellement `ShouldQueue` ou `dispatch`.
Le pilote `database` reste prêt, mais aucun worker `queue:work` n’est requis pour
cette release. Ne pas ajouter Redis.

## Préparer le code et les secrets

Récupérer le commit approuvé dans un nouveau répertoire de release. Ne pas
mettre les secrets dans Git :

```bash
git fetch --all --prune
git checkout <commit-release-approuve>
composer install --prefer-dist --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund
npm run build
```

Créer `.env` depuis `.env.production.example` dans l’environnement cible et
injecter `APP_KEY`, identifiants PostgreSQL et SMTP depuis le gestionnaire de
secrets. Exiger `APP_ENV=production`, `APP_DEBUG=false`, une URL HTTPS, cookies
secure/httpOnly/SameSite, logs quotidiens et stockage privé. Conserver `APP_KEY`
séparément des sauvegardes de données.

Le compte du service reçoit uniquement l’écriture sur `storage/` et
`bootstrap/cache`. Le code et `public/` restent en lecture seule lorsque
l’hébergeur le permet.

## Contrôle avant bascule

Sous Windows/Herd :

```powershell
$env:PHP_BINARY = 'C:\Users\pc\.config\herd\bin\php85\php.exe'
$env:COMPOSER_BINARY = 'C:\Users\pc\.config\herd\bin\composer.bat'

& $env:PHP_BINARY artisan rentfleet:doctor --production
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/deploy-check.ps1 `
  -SkipTests -KeepCaches
```

Sous Linux :

```bash
export PHP_BINARY=/usr/bin/php8.5
export COMPOSER_BINARY=/usr/local/bin/composer
pwsh -NoProfile -File scripts/deploy-check.ps1 -SkipTests -KeepCaches
```

Le contrôle vérifie versions, extensions, Composer, Node/npm, PostgreSQL,
configuration production, migrations, stockage, build, caches, doctor,
scheduler, heartbeat, routes interdites et absence de secrets suivis. Il ne
lance aucune migration ni restauration.

## Migration et bascule

Créer et vérifier d’abord une sauvegarde PostgreSQL + documents selon le
runbook dédié. Après approbation seulement :

```bash
php artisan down --retry=60
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan optimize
php artisan rentfleet:doctor --production
php artisan up
```

Configurer ensuite le scheduler système.

Linux, chaque minute :

```cron
* * * * * cd /var/www/rentfleet/current && /usr/bin/php8.5 artisan schedule:run >> /dev/null 2>&1
```

Windows Task Scheduler : programme
`C:\Users\pc\.config\herd\bin\php85\php.exe`, arguments
`artisan schedule:run`, répertoire de démarrage égal à la release, fréquence
d’une minute. `schedule:list` doit montrer le heartbeat chaque minute,
l’expiration des réservations chaque minute et l’expiration des polices à
00:15, fuseau `Africa/Casablanca`.

## Vérifications post-déploiement

- `/health` répond `ok` sans secret ;
- `rentfleet:doctor --production` est vert, heartbeat compris ;
- aucune route `register`, `signup` ou `storage/*` ;
- connexion, dashboard, isolation tenant/agence et téléchargement privé ;
- logs quotidiens exploitables par l’identifiant de corrélation ;
- migration, sauvegarde et commit consignés sans donnée personnelle.

## Retour arrière

1. remettre l’application en maintenance ;
2. conserver logs et identifiant de corrélation de l’incident ;
3. replacer le code et les assets du commit précédent ;
4. reconstruire les caches ;
5. exécuter doctor et les smoke tests ;
6. sortir du mode maintenance après décision explicite.

Ne jamais lancer automatiquement `migrate:rollback` sur des données réelles :
une migration additive peut avoir transformé des données que l’ancien code ne
comprend plus. La restauration base + documents est un dernier recours. Elle se
teste d’abord dans `rentfleet_restore_test`, puis fait l’objet d’une procédure
d’incident séparée et d’une décision humaine.
