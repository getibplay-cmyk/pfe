# Déploiement de la release candidate

## Préparation

- PHP 8.5 avec `pdo_pgsql` et `pgsql` ;
- PostgreSQL 18 et rôle applicatif sans privilège super-utilisateur ;
- Node.js/npm disponibles uniquement pendant le build ;
- HTTPS configuré au proxy ou serveur web ;
- répertoire `storage/app/private` persistant, non public et inscriptible ;
- worker de queue et scheduler supervisés.

Copier `.env.production.example` vers un fichier `.env` non versionné. Générer
`APP_KEY` sur l’hôte et fournir les secrets par le gestionnaire de secrets de
l’hébergeur. Vérifier au minimum `APP_ENV=production`, `APP_DEBUG=false`, URL
HTTPS, PostgreSQL, cookies sécurisés, sessions/queue/cache en base et mail.

## Checklist avant bascule

```powershell
composer install --prefer-dist --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund
npm run build
php artisan rentfleet:doctor
php artisan migrate:status
powershell -ExecutionPolicy Bypass -File scripts/deploy-check.ps1 -SkipTests -KeepCaches
```

Effectuer une sauvegarde validée avant toute migration. Après approbation de la
bascule seulement :

```powershell
php artisan down --retry=60
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan up
```

Redémarrer ensuite les workers de queue afin qu’ils chargent le nouveau code.
Configurer le scheduler système pour exécuter `php artisan schedule:run` chaque
minute. Contrôler `/health`, la connexion, un accès tenant et les logs par
identifiant de corrélation.

## Retour arrière

Ne pas lancer automatiquement `migrate:rollback` sur des données réelles.
Remettre la version applicative précédente et restaurer le couple base/fichiers
depuis la sauvegarde validée dans une nouvelle cible, puis basculer après
contrôle. Documenter l’incident et conserver les archives concernées.
