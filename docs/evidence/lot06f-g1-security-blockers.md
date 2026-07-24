# Preuve — Lot 06F-G1

## Objet

Ce lot corrige quatre blocages de sécurité sans migration métier et sans
réinitialiser la base `rentfleet` :

- bootstrap transactionnel du premier administrateur plateforme ;
- refus générique des empreintes de mot de passe incompatibles ;
- garde absolue des opérations destructives de test ;
- suppression des mots de passe de démonstration fixes versionnés.

## Conception

`rentfleet:bootstrap-platform-admin` demande interactivement le nom, l’e-mail et
deux saisies masquées. La politique `Password::defaults()`, le pilote
`Hash::make()`, une transaction et un verrou consultatif PostgreSQL protègent la
création. Le rôle système `platform-admin` est créé ou réactivé dans la même
transaction. Le compte reste hors tenant et hors agence. Un audit minimal ne
contient ni mot de passe ni empreinte.

`PasswordHashInspector` vérifie l’algorithme attendu avant l’authentification.
Une incompatibilité produit `auth.failed`, incrémente le rate limiter et génère
uniquement un événement technique avec l’identifiant interne du compte.
`rentfleet:audit-password-hashes` ne retourne que des compteurs.

`rentfleet:reset-user-password` exige soit le slug exact d’un tenant, soit
`--platform`. Il demande le secret deux fois de manière masquée, révoque les
autres sessions et impose un remplacement à la prochaine connexion.

`TestDatabaseGuard` est exécuté dans `Tests\TestCase::createApplication()`, donc
avant les traits tels que `RefreshDatabase`. Un écouteur `CommandStarting`
applique la même garde avant `migrate:fresh`, `migrate:refresh`,
`migrate:reset` et `db:wipe`. Seule la combinaison suivante est autorisée :

- environnement `testing` ;
- connexion et pilote `pgsql` ;
- base exacte `rentfleet_test` ;
- aucune URL de base contradictoire.

Les messages de refus ne reprennent jamais une URL ni ses identifiants.

## Résultats observés

- état initial : `HEAD 8e8ef48`, worktree propre, aucun fichier G1 préexistant ;
- environnement : Laravel 12.63.0, PHP Herd 8.5.8, Composer 2.10.1 et
  PostgreSQL 18.4 ;
- précontrôle destructif : un ancien cache local résolvait `rentfleet` malgré
  `--env=testing` ; la garde a répondu `GARDE_BLOQUEE_AVANT_MIGRATION` et aucune
  migration n’a été lancée ;
- après `config:clear` : environnement `testing`, connexion et pilote `pgsql`,
  base exacte `rentfleet_test`, garde autorisée ;
- `migrate:fresh --seed --env=testing` : 66 migrations et tous les seeders
  réussis exclusivement sur `rentfleet_test` ;
- tests de garde purs : 11 tests, 25 assertions ;
- suite dédiée G1 : 11 tests, 76 assertions ;
- régressions ciblées G1, authentification, RBAC, multitenance et Lot 06F-F :
  65 tests, 288 assertions ;
- suite PHPUnit complète : 290 tests, 2 759 assertions ;
- Pint correcteur : trois détails de style corrigés dans le seul test G1 ;
  `pint --test` réussi ;
- Vite 6.4.3 : 56 modules, build réussi après relance hors sandbox ; le premier
  essai avait été bloqué par un `EPERM` Windows sur le profil utilisateur ;
- caches configuration, événements, routes et vues : réussis ;
- `composer validate` : manifeste valide ;
- `composer audit --locked --no-interaction` : aucun avis de vulnérabilité ;
- `npm audit --omit=dev` : 0 vulnérabilité ;
- recherche ciblée : 0 valeur `DEMO_PASSWORD` non vide dans les exemples ;
- `.env` et `.env.testing` : ignorés et non suivis ;
- `git diff --check` : réussi ;
- `rentfleet:doctor` en lecture seule : PostgreSQL 18.4, 66 migrations,
  contraintes critiques au vert ; avertissements locaux attendus pour
  l’environnement local et le heartbeat absent.

Le Lot G1 ne crée aucune migration. Aucune commande `migrate:fresh`, aucun
seeding et aucune suppression de donnée n’a ciblé `rentfleet`. Aucun commit
automatique n’a été créé.
