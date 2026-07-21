# Preuve — Lot 06F-E1

## Périmètre

E1 modifie uniquement la présentation Blade/Tailwind/Alpine, les composants
partagés, les tests structurels et la documentation. Aucune migration, formule,
transition métier, policy, permission ou donnée métier de `rentfleet` n'est
modifiée. La branche contrôlée est `main`, au HEAD initial `9af4f60`.

## Environnement observé

- PHP Herd : `8.5.8` via `C:\Users\pc\.config\herd\bin\php85\php.exe` ;
- Composer : `2.10.1` via le PHAR Herd ;
- Laravel Framework : `12.63.0` ;
- PostgreSQL : `18.4` ;
- environnement de test chargé par Laravel : `pgsql|rentfleet_test` ;
- base réellement retournée par PostgreSQL : `rentfleet_test`.

`rentfleet_test` a été vérifiée comme différente de `rentfleet` et de
`rentfleet_restore_test` avant toute opération destructive. Aucun test ni
`migrate:fresh` n'a ciblé `rentfleet`.

## Migrations et seeders

La commande suivante a été exécutée exclusivement sur `rentfleet_test` :

```text
php artisan migrate:fresh --seed --env=testing
```

Résultat : 64 migrations réussies. Les seeders `RolesPermissionsSeeder`,
`DemoTenancySeeder` et `Lot02DemoSeeder` à `Lot05DemoSeeder` ont tous réussi.
Aucun fichier de migration n'est créé, modifié ou supprimé par E1.

## Tests PostgreSQL

| Groupe | Résultat |
| --- | --- |
| `Lot06FE1ProfessionalUxTest` initial | 12 tests, 847 assertions, réussi |
| Authentification, profil, mot de passe initial, navigation et administration | 39 tests, 346 assertions, réussi |
| RBAC, policies, tenant scope et agency scope | 34 tests, 205 assertions, réussi |
| Réservations, contrats, clients, documents et finance | 82 tests, 522 assertions, réussi |
| Maintenance, assurance, reporting, erreurs et stockage privé | 43 tests, 259 assertions, réussi |
| Suite PHPUnit complète après E1 | 253 tests, 2 409 assertions, réussi |
| E1 et navigation après correction de pagination | 20 tests, 1 030 assertions, réussi |
| HTTP et intégrations après correctif Guzzle | 52 tests, 1 225 assertions, réussi |
| Suite PHPUnit complète après correctif Guzzle | 253 tests, 2 410 assertions, réussi |

Une relance effectuée alors que `config:cache` était actif a d'abord produit
quatre réponses HTTP 419. `optimize:clear` a restauré le chargement correct de
l'environnement `testing`; la relance ciblée est ensuite entièrement verte.

## Corrections issues de la validation

- le composant `result-count` conserve le libellé contractuel
  `résultat(s)` attendu par le Lot 06E ;
- une surcharge locale de la pagination Tailwind remplace les libellés anglais
  visibles par `Précédent`, `Suivant`, `Affichage de` et `Aller à la page`.
- le sélecteur CSS de compatibilité des anciens panneaux utilise des attributs
  de classe afin d'éviter les fusions de sélecteurs invalides signalées par
  esbuild ; le build final ne produit aucun avertissement.

Ces corrections sont exclusivement Blade/UX. Aucun PHP métier n'a changé après
la suite complète.

## Formatage, Blade et caches

```text
php vendor/bin/pint                 : réussi
php vendor/bin/pint --test          : réussi
php artisan view:clear              : réussi
php artisan view:cache              : réussi
php artisan config:clear/cache      : réussi
php artisan route:clear/cache       : réussi
php artisan cache:clear             : réussi
php artisan optimize:clear/optimize : réussi
```

La compilation finale des vues et la reconstruction finale des caches ont été
rejouées après la correction de pagination.

## Dépendances et build

- `composer validate` : réussi, `composer.json` valide ;
- `npm.cmd audit --omit=dev` : réussi, 0 vulnérabilité ;
- correctif Composer ciblé : `guzzlehttp/guzzle` passe de `7.14.1` à
  `7.15.1` et sa seule dépendance strictement nécessaire
  `guzzlehttp/psr7` passe de `2.12.5` à `2.13.0` ;
- `composer.json` reste inchangé ; `composer.lock` contient 14 ajouts et
  14 suppressions, uniquement dans les deux entrées ci-dessus ;
- Laravel reste en `12.63.0`, `guzzlehttp/promises` en `2.5.1` et les autres
  dépendances inspectées sont inchangées ;
- `composer audit --locked --no-interaction` : réussi, aucun avis de sécurité.
  Une première tentative avait rencontré `curl error 28` après 10 003 ms ; la
  relance réseau a abouti ;
- `npm.cmd run build` : réussi avec Vite `6.4.3`, 56 modules transformés en
  5,18 s, sans avertissement lors de la compilation finale ;
- manifeste Vite : valide, avec deux entrées et deux assets présents :
  `assets/app-B7_1UkdN.css` (60 055 octets) et
  `assets/app-B_mJ28aH.js` (93 102 octets).

`view:clear` puis `view:cache` ont réussi après ce build. La mise à jour de
Guzzle n'est pas effectuée, car une modification de dépendance sort des
corrections E1 autorisées.

## Routes et contrôles statiques

`php artisan route:list` recense 192 routes.

```text
register                         : 0
signup                           : 0
storage/*                        : 0
DELETE profile                  : 0
parcours reset password          : 4 routes
confirmation du mot de passe     : 2 routes
vérification d'adresse e-mail    : 3 routes
```

Les recherches statiques confirment :

- aucun motif d'encodage corrompu dans le code, les vues, les tests ou la
  documentation ;
- aucun champ Blade `tenant_id` ;
- aucune référence active à Register, Signup, `profile.destroy` ou `storage/*` ;
- aucune ressource distante ou CDN ; l'unique URL trouvée dans les assets est
  l'espace de noms XML standard du SVG local ;
- aucun JSON de snapshot, hash ou chemin privé rendu brut ; les quelques champs
  de snapshot utilisés servent uniquement à produire des libellés métier ;
- `git diff --check` réussi ;
- `.env`, `.env.testing` et `pgpass.conf` sont ignorés et non suivis ;
- aucun secret ni artefact documentaire privé n'est suivi, couvert également
  par les tests de configuration et de stockage privé.

## État de clôture

Toutes les validations exécutables E1 sont réussies : tests PostgreSQL, Pint,
Vite, Blade, caches, Composer validate/audit, audit NPM, routes et contrôles
statiques. Le Lot 06F-E1 est clôturé sans commit automatique. Les validations
par navigateur réel restent réservées à E2.

## Validations réservées à E2

Faute de navigateur réel et de captures dans E1, restent exclusivement à E2 :

- rendus 1440×900 et 390×844 ;
- Chrome, Edge et Firefox ;
- zoom à 200 % ;
- navigation clavier complète ;
- mesures WCAG AA et lecteurs d'écran ;
- détection des débordements réels ;
- captures de soutenance.
