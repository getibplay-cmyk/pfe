# Checklist de production RentFleet

## Infrastructure

- [ ] PHP 8.5 et extensions `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`,
  `fileinfo` et `intl` disponibles.
- [ ] PostgreSQL 18 accessible avec un rôle applicatif dédié et une base dont ce
  rôle est propriétaire ; aucun rôle super-utilisateur dans `.env`.
- [ ] Le document root du serveur web pointe exclusivement vers `public/`.
- [ ] HTTPS est terminé par un proxy ou serveur maintenu et les redirections HTTP
  vers HTTPS sont actives.
- [ ] `storage/`, `storage/app/private` et `bootstrap/cache` sont inscriptibles
  par le compte du service, sans être publiquement servis.

## Configuration

- [ ] `.env.production.example` a été copié hors Git puis complété via un
  gestionnaire de secrets.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` est en HTTPS et une
  `APP_KEY` unique a été générée.
- [ ] PostgreSQL, sessions chiffrées, cookies secure/httpOnly/SameSite, cache et
  queue database sont configurés.
- [ ] Les paramètres mail, logs, rétention et stockage persistant sont validés.
- [ ] Aucun secret, dump ou document privé n’apparaît dans `git status`.

## Installation et bascule

```powershell
composer install --prefer-dist --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund
npm run build
php artisan rentfleet:doctor --production
powershell -ExecutionPolicy Bypass -File scripts/deploy-check.ps1 -SkipTests -KeepCaches
```

- [ ] Une sauvegarde PostgreSQL + documents privés a été créée et restaurée sur
  `rentfleet_restore_test` avant la bascule.
- [ ] `php artisan migrate --force` est exécuté seulement après approbation et
  sauvegarde.
- [ ] `php artisan optimize` réussit ; config, routes, vues et événements sont
  en cache.
- [ ] Un worker `php artisan queue:work` supervisé est redémarré après le code.
- [ ] Le système appelle `php artisan schedule:run` chaque minute.

## Vérifications post-déploiement

- [ ] `/health` répond sainement sans détail de connexion.
- [ ] Connexion, dashboard tenant, refus cross-tenant et téléchargement privé
  contrôlé ont été testés.
- [ ] Les logs sont collectés, protégés, rotatifs et recherchables par
  `X-Correlation-ID` ; espace disque, erreurs HTTP, queue et PostgreSQL sont
  surveillés.
- [ ] Les sauvegardes sont copiées vers un support chiffré avec une rétention
  définie et un test de restauration planifié.

## Rollback

- [ ] L’artefact applicatif précédent et la sauvegarde validée sont disponibles.
- [ ] Aucun rollback SQL automatique n’est utilisé sur des données réelles.
- [ ] En cas d’échec, remettre le code précédent, restaurer base et fichiers
  ensemble dans une nouvelle cible, vérifier, puis basculer explicitement.
