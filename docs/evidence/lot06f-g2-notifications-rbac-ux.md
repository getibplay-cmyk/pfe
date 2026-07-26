# Preuve — Lot 06F-G2

Date de clôture observée : 26 juillet 2026  
Branche inspectée : `main`  
Commit de départ et HEAD non commité : `6f8d233`

Cette preuve ne contient aucun secret, mot de passe de démonstration, document
privé ou donnée personnelle réelle.

## Périmètre livré

- cycle de vie des notifications opérationnelles : création, actualisation,
  résolution, historique et réactivation ;
- clé d’incident stable, compteur d’occurrences, dernière détection et échéance ;
- sérialisation concurrente par entreprise au moyen d’un verrou transactionnel
  PostgreSQL ;
- contraintes PostgreSQL sur les affectations utilisateur/rôle et les
  délégations par agence ;
- remplacement atomique d’un rôle personnalisé avec filtrage anti-escalade,
  confirmation explicite et audit ;
- libellés français centralisés pour toutes les permissions connues ;
- formulaires prioritaires et navigation responsive améliorés ;
- campagne navigateur dédiée à Chrome et Edge.

## Environnement et garde de test

| Contrôle | Résultat observé |
|---|---|
| PHP | Laravel Herd 8.5.8 |
| PostgreSQL | 18.4, pilote `pgsql` |
| Environnement de test | `APP_ENV=testing` |
| Base de test exacte | `rentfleet_test` |
| Garde G1 | active |
| Migrations de test | 69 `Ran` |

Les caches de configuration et d’application ont été vidés après vérification
de la garde. Les opérations de reconstruction déjà réalisées avant cette
clôture ciblaient uniquement `rentfleet_test`. Aucune commande destructive n’a
été exécutée sur `rentfleet`.

## Tests PHP observés

Toutes les commandes ont utilisé explicitement PHP Herd 8.5.8.

| Commande / périmètre | Code | Résultat final |
|---|---:|---:|
| `artisan test tests/Feature/Lot06FG2NotificationsRbacAccessibilityTest.php` | 0 | 5 tests, 327 assertions |
| `artisan test tests/Feature/Lot06FFNotificationsAndGovernanceTest.php` | 0 | 10 tests, 75 assertions |
| `artisan test tests/Feature/Lot06FG1SecurityBootstrapTest.php` | 0 | 11 tests, 76 assertions |
| Authentification, profil, RBAC, multitenance et navigation | 0 | 48 tests, 342 assertions |
| `artisan test` | 0 | 295 tests, 3 090 assertions |

La suite complète finale a duré 257,52 secondes. Aucun test n’est échoué,
ignoré ou incomplet.

Les premières passes ont mis en évidence des fixtures historiques qui créaient
encore des utilisateurs d’entreprise sans rôle ou sans agence valide. Les
helpers de test ont été alignés sur les contraintes G2. Deux attentes textuelles
obsolètes et un scénario d’autorisation d’administration ont également été
adaptés à la terminologie française et au périmètre agence réellement protégé.
Après correction, les tests ciblés puis la suite complète ont été rejoués.

## Formatage et build

| Contrôle | Résultat observé |
|---|---|
| `php vendor/bin/pint` | code 0, aucun fichier modifié |
| `php vendor/bin/pint --test` | code 0 |
| `npm.cmd run build` hors environnement restreint | code 0 |
| Vite | 6.4.3, 56 modules, build en 6,88 s |

Artefacts générés :

- `public/build/manifest.json` ;
- `public/build/assets/app-Bx5qqx5G.css` — 59,30 kB ;
- `public/build/assets/app-B_SNwhqu.js` — 93,32 kB.

Les empreintes de `package.json` et `package-lock.json` sont restées
inchangées. Aucune dépendance n’a été modifiée.

## Campagne navigateur finale

Le harnais a été rejoué intégralement sur `rentfleet_test` avec un mot de passe
aléatoire conservé uniquement en mémoire. Lorsque la suite PHPUnit avait laissé
la base migrée mais vide, le harnais a réensemencé exclusivement
`rentfleet_test`, après vérification du nom exact de la base.

| Navigateur | Version observée |
|---|---|
| Chrome | 150.0.7871.182 |
| Edge | 150.0.4078.99 |

Résultat : **140 contrôles réussis, 0 incident, 10 captures**.

Dimensions réellement contrôlées :

- 1440 × 900 ;
- 1024 × 768 ;
- 390 × 844 ;
- 320 × 568.

À 320 px, Chrome et Edge ont chacun confirmé :

- ouverture du menu au clavier ;
- synchronisation `aria-expanded=true` ;
- focus initial et navigation par tabulation contenus dans le dialogue ;
- fermeture par Échap avec restitution du focus ;
- fermeture par le bouton explicite ;
- retour à `aria-expanded=false` ;
- absence de débordement horizontal ;
- absence du faux négatif de synchronisation observé antérieurement.

Les captures ont été inspectées : elles présentent uniquement des données de
démonstration, sans secret ni donnée personnelle réelle.

Résultat structuré :
`docs/evidence/lot06f-g2-browser-results.json`

Captures :
`docs/evidence/screenshots/lot06f-g2/`

## Contrôles d’exploitation

| Contrôle | Résultat observé |
|---|---|
| `rentfleet:doctor --env=testing --expect-database=rentfleet_test` | code 0, aucun blocage critique |
| `composer validate --no-interaction` | valide |
| `composer audit --locked --no-interaction` | aucun avis de sécurité |
| `npm.cmd audit --omit=dev` | 0 vulnérabilité |
| `git diff --check` | aucune erreur |
| Routes Laravel | 204 routes, 0 `register`/`signup`, 0 route `storage` |
| `.env` et `.env.testing` | ignorés et non suivis |

Le Doctor de test signale seulement les avertissements attendus pour
l’environnement `testing`, la queue `sync` sans tâche applicative et l’absence
de heartbeat permanent pendant une campagne ponctuelle. PHP, PostgreSQL,
contraintes critiques, stockage privé, build, reporting et RBAC sont valides.

Le test générique `ProductionConfigurationTest` inclus dans la suite complète
confirme l’absence de mot de passe ou secret codé en dur dans les sources
versionnables.

## Précontrôles et décision sur `rentfleet`

La connexion a été identifiée explicitement avant les lectures :

```text
environment=local
connection=pgsql
driver=pgsql
database=rentfleet
```

Les neuf compteurs d’intégrité sont tous égaux à zéro :

| Précontrôle | Nombre |
|---|---:|
| Rôle personnalisé hors entreprise | 0 |
| Rôle plateforme affecté à un utilisateur d’entreprise | 0 |
| Rôle d’entreprise affecté à un administrateur plateforme | 0 |
| Rôle inactif encore affecté | 0 |
| Utilisateur d’entreprise sans rôle | 0 |
| Administrateur d’entreprise rattaché à une agence | 0 |
| Rôle d’agence sans agence | 0 |
| Administrateur plateforme dans un périmètre d’entreprise | 0 |
| Délégation incohérente | 0 |

Les trois migrations additives exactes restent **Pending** sur `rentfleet` :

1. `2026_07_30_000001_add_internal_notification_lifecycle` ;
2. `2026_07_30_000002_enforce_user_role_assignment_integrity` ;
3. `2026_07_30_000003_localize_demo_account_names`.

La première ajoute le cycle de vie des notifications et initialise
explicitement `last_detected_at` depuis `occurred_at`. La deuxième refuse toute
incohérence avant de créer index, fonctions et déclencheurs ; elle n’effectue
aucune correction automatique. La troisième renomme uniquement les comptes de
démonstration connus. Aucune correction d’intégrité silencieuse n’est prévue.

`rentfleet:doctor --expect-database=rentfleet` confirme PHP 8.5.8,
PostgreSQL 18.4 et tous les contrôles métier existants. Son code est 1 uniquement
parce que les trois migrations G2 sont en attente ; le heartbeat local absent
reste un avertissement opérationnel.

La procédure D2 de sauvegarde/restauration existe et a été réellement vérifiée.
L’artefact, son manifeste, son dump et son archive sont encore présents, mais la
sauvegarde date du 18 juillet et contient 64 migrations alors que `rentfleet`
en compte maintenant 66 avant G2. Elle n’est pas considérée comme une sauvegarde
préalable suffisamment fraîche pour appliquer G2.

Conformément au caractère « validations uniquement » de la reprise et à la
condition de sauvegarde réelle préalable, **aucune migration G2 n’a été
appliquée à `rentfleet`**. Une nouvelle sauvegarde complète et vérifiée, puis
une autorisation explicite d’application, restent nécessaires.

## État Git

- HEAD inchangé : `6f8d233` ;
- worktree volontairement non propre avec l’implémentation G2 et les adaptations
  de tests ;
- aucun commit créé ;
- `composer.json`, `composer.lock`, `package.json` et `package-lock.json`
  inchangés pendant cette clôture ;
- aucune donnée de `rentfleet` modifiée.

