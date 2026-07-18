# Checklist de production RentFleet

## Infrastructure

- [ ] PHP 8.5 et extensions `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`,
  `fileinfo`, `intl`.
- [ ] PostgreSQL 18 accessible avec le rôle propriétaire `rentfleet_app`, sans
  privilège super-utilisateur applicatif.
- [ ] `psql`, `pg_dump` et `pg_restore` correspondent à PostgreSQL 18.
- [ ] Document root du serveur web limité à `public/` et HTTPS actif.
- [ ] `storage/`, racine documentaire privée et `bootstrap/cache` sont
  inscriptibles par le service, jamais servis publiquement.
- [ ] Volume chiffré et restreint prévu pour les sauvegardes.

## Configuration

- [ ] `.env.production.example` est complété hors Git par un gestionnaire de
  secrets.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...` et
  `APP_TIMEZONE=Africa/Casablanca`.
- [ ] `APP_KEY` unique est sauvegardée dans le gestionnaire de secrets, jamais
  dans les archives de données.
- [ ] `DB_CONNECTION=pgsql`, aucune connexion SQLite de repli.
- [ ] sessions chiffrées, cookies secure/httpOnly/SameSite et cache database.
- [ ] stockage local privé non servi ; aucun `storage:link` nécessaire.
- [ ] logs quotidiens rotatifs et SMTP configuré avec `MAIL_SCHEME`.
- [ ] HSTS activé seulement avec `APP_ENV=production` et HTTPS.
- [ ] `DEMO_PASSWORD` vide ; seeders de démonstration interdits.
- [ ] inscription publique et comptes par défaut absents.

## Scheduler

- [ ] Le cache database partagé permet `withoutOverlapping` et `onOneServer`.
- [ ] Le système lance `php artisan schedule:run` chaque minute.
- [ ] `schedule:list` montre : heartbeat et expiration des réservations chaque
  minute, expiration des polices à 00:15 en `Africa/Casablanca`.
- [ ] Le heartbeat a moins de cinq minutes et `rentfleet:doctor --production`
  le considère sain.
- [ ] Aucun worker de queue n’est lancé inutilement : cette release ne dépêche
  aucune tâche applicative en queue.

## Sauvegarde et preuve avant bascule

- [ ] `pgpass.conf`/`.pgpass` protégé permet les connexions `--no-password`.
- [ ] La base exacte `rentfleet_restore_test` existe et appartient à
  `rentfleet_app`.
- [ ] Une sauvegarde réelle de `rentfleet` a produit dump, archive privée et
  manifeste complet hors Git.
- [ ] Tailles et SHA-256 sont valides.
- [ ] La restauration réelle n’a ciblé que `rentfleet_restore_test` et une
  racine documentaire temporaire hors dépôt.
- [ ] Les comptages agrégés, contraintes, triggers, index, documents et valeurs
  chiffrées ont été vérifiés sans divulgation.
- [ ] `rentfleet:doctor` est vert contre la restauration.
- [ ] La source `rentfleet` est inchangée après la preuve.

## Contrôle et bascule

```powershell
$env:PHP_BINARY = 'C:\Users\pc\.config\herd\bin\php85\php.exe'
$env:COMPOSER_BINARY = 'C:\Users\pc\.config\herd\bin\composer.bat'
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/deploy-check.ps1 -SkipTests -KeepCaches
```

- [ ] `composer install --no-dev`, `npm ci` et `npm run build` réussissent.
- [ ] `composer validate`, audits Composer/npm et tests approuvés sont verts.
- [ ] `config:cache`, `route:cache`, `view:cache`, `event:cache`, `optimize` et
  `rentfleet:doctor --production` réussissent.
- [ ] `register`, `signup`, `storage/*`, secrets et artefacts suivis sont absents.
- [ ] Sauvegarde approuvée avant `php artisan migrate --force`.
- [ ] Mode maintenance utilisé autour de la migration et de la bascule.

## Après déploiement et rollback

- [ ] `/health`, login, dashboard, isolation tenant/agence et document privé
  sont vérifiés.
- [ ] Logs, espace disque, PostgreSQL et ancienneté du heartbeat sont surveillés.
- [ ] Commit précédent et assets associés sont disponibles.
- [ ] Aucun `migrate:rollback` automatique n’est prévu sur données réelles.
- [ ] La restauration base + fichiers reste le dernier recours après test isolé
  et décision explicite.
