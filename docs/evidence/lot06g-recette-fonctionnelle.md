# Lot 06G-B — Recette fonctionnelle isolée sans IA

## Décision

**Recette bloquée par un défaut P1 reproductible.** Aucun correctif produit
n’a été appliqué, aucun commit n’a été créé et le Lot 07/IA n’a pas commencé.

Le cycle navigateur atteint l’inspection de retour avec un Agent de location,
mais l’interface ne lui présente pas l’action « Finaliser le retour ». Le rôle
possède `contract.return` et ne possède pas `charge.review`. Dans
`resources/views/contracts/show.blade.php`, le formulaire de finalisation est
imbriqué dans une condition exigeant `charge.review`, alors que la policy de
retour et l’action métier autorisent un retour sans frais avec
`contract.return`.

Scénario minimal observé :

1. se connecter comme Agent de location ;
2. créer et confirmer une réservation fictive ;
3. créer, documenter, préparer et accepter le contrat ;
4. terminer l’inspection de départ ;
5. faire enregistrer la caution par le Comptable ;
6. activer le contrat puis terminer l’inspection de retour comme Agent ;
7. constater le statut `return_pending`, deux inspections terminées, aucun
   dommage et aucun frais ;
8. constater l’absence du bouton « Finaliser le retour ».

Preuve serveur assainie : contrat QA `CTR-2026-000009`, réservation
`RES-2026-000015`, statut `return_pending`, `contract.return=1`,
`charge.review=0`, deux inspections terminées, zéro dommage et zéro frais.
Les identifiants de tenant et d’agence, les personnes, les mots de passe et les
documents privés ne sont pas consignés.

## Référence et état initial

- branche : `main` ;
- HEAD : `2e74a02361b28e6442a9e00739885363f4bf81b3` ;
- parent : `6f8d233dd198681c6875258599bc68f60666129f` ;
- PHP : Herd 8.5.8 ;
- PostgreSQL : 18.4 ;
- cible destructive unique :
  `rentfleet_06g_acceptance` ;
- bases protégées :
  `rentfleet`, `rentfleet_test`, `rentfleet_restore_test`.

Le worktree contenait uniquement les adaptations 06G en cours : garde Laravel,
harnais navigateur E2/G2, tests unitaires du garde, preuve 06G-A et scripts
`scripts/acceptance`. Aucun fichier d’environnement n’a été modifié.

## Harnais et gardes

Fichiers ajoutés ou adaptés :

- `app/Support/Testing/TestDatabaseGuard.php` ;
- `scripts/acceptance/common.ps1` ;
- `scripts/acceptance/guard-self-test.ps1` ;
- `scripts/acceptance/dump-source.ps1` ;
- `scripts/acceptance/prepare-target.ps1` ;
- `scripts/acceptance/restore-target.ps1` ;
- `scripts/acceptance/verify-target.ps1` ;
- `scripts/acceptance/reset-target.ps1` ;
- `scripts/acceptance/destroy-target.ps1` ;
- `scripts/acceptance/run-phpunit.ps1` ;
- `scripts/acceptance/run-browser.ps1` ;
- `tests/Unit/TestDatabaseGuardTest.php` ;
- `tests/TestCase.php` et les assertions historiques imposant littéralement
  `rentfleet_test` ;
- `tests/Browser/lot06f_e2_browser.py` ;
- `tests/Browser/lot06f_g2_browser.py`.

Les gardes exigent simultanément `APP_ENV=testing`, `pgsql`, le nom exact de la
cible, `RENTFLEET_ACCEPTANCE_MODE=1`, la résolution réelle de
`current_database()`, l’hôte, le port, l’utilisateur, un OID distinct des trois
bases protégées et une racine canonique sous
`C:\tmp\RentFleet06G`. Les scripts PostgreSQL utilisent `pgpass` et
`--no-password`. Ils refusent les variantes de nom et ne lisent aucun secret.

Les scripts PowerShell passent leur analyse syntaxique et le self-test des
gardes. Les scripts Python passent `py_compile`.

## Dump, création et restauration

Le dump custom existant et validé a été réutilisé sans être recréé :

- source en lecture seule : `rentfleet_test` ;
- taille : 375 599 octets ;
- SHA-256 :
  `3ffd9042eb763616e2305d409aeb4a729411e5af14a4d626a9bd033310e9464d` ;
- entrées `pg_restore --list` : 762 ;
- migrations source : 69 ;
- empreinte des migrations :
  `4d53fec07af92d56ff184ba782271789db888d59d3bc5c6b7e9026011d50cf66`.

La cible a été créée, restaurée transactionnellement et réinitialisée entre
les phases. Son dernier OID de recette est `593840`. La restauration contient :

- 57 tables ;
- 47 séquences ;
- 239 fonctions ;
- 856 contraintes ;
- 30 triggers ;
- 218 index.

PostgreSQL reformate 47 expressions `CHECK` utilisant `ANY(array)` et deux
prédicats d’index lors du dump/restore. Le comparateur vérifie donc leurs
attributs structurels stables plutôt que le texte décompilé. Les noms, types,
colonnes, actions de clés étrangères, indicateurs d’index, 69 migrations et
objets critiques sont identiques.

Contrôles post-restauration :

- cinq colonnes de cycle de vie des notifications : présentes ;
- trois triggers RBAC G2 : présents ;
- trois index G2 : présents ;
- neuf compteurs d’intégrité : tous à zéro ;
- `rentfleet:doctor --expect-database=rentfleet_06g_acceptance` : contrôles
  applicatifs verts ; avertissements attendus pour l’environnement `testing`,
  la queue `sync` et le heartbeat local absent.

## Tests automatisés et qualité

- garde ciblée : **17 tests, 36 assertions, succès** ;
- première suite : 301 tests, 3 089 assertions, 12 échecs de compatibilité du
  harnais, sans échec métier ;
- relance ciblée après adaptation stricte du harnais :
  **123 tests, 641 assertions, succès** ;
- suite complète finale sur la cible restaurée :
  **301 tests, 3 133 assertions, succès** ;
- Pint `--test` : succès ;
- `composer validate --no-interaction` : succès ;
- `npm audit --omit=dev` : zéro vulnérabilité ;
- `npm run build` : succès, Vite 6.4.3, 56 modules ;
- `git diff --check` : succès.

`composer audit --locked --no-interaction` est **non concluant**. La relance
avec accès réseau a échoué exactement avec :

`curl error 28 while downloading https://packagist.org/api/security-advisories/: Connection timed out after 10013 milliseconds`

Ce contrôle n’est pas présenté comme réussi.

## Matrice de recette navigateur

Chrome 150.0.7871.182 a exécuté 173 contrôles : 170 réussis, trois attentes de
libellés historiques à recalibrer, trois audits de page, trois audits de
contraste et cinq captures temporaires assainies. Les comptes utilisent un mot
de passe aléatoire conservé uniquement en mémoire.

| Domaine | Résultat |
|---|---|
| Authentification, inscription absente, 419 | Validé dans Chrome |
| Compte inactif et changement obligatoire | Couvert par PHPUnit ; étape navigateur ultérieure non atteinte |
| Administration plateforme | Navigation atteinte ; deux attentes de libellé du harnais à recalibrer |
| Entreprise, agences, utilisateurs, rôles, délégations | Navigation et refus par rôle validés avant le blocage |
| Catégories, véhicules, clients, conducteurs | Navigation validée ; client et conducteur du cycle utilisés |
| Documents privés et refus d’accès | Deux PDF fictifs ajoutés sur le stockage QA privé |
| Tarifs, disponibilité et conflits | Disponibilité et validation négative exercées |
| Réservation complète | Création, validation 422, confirmation et bloc actif exercés |
| Conversion en contrat | Exercée |
| Acceptation, départ et inspection | Exercés |
| Retour, dommages et frais | Inspection de retour exercée ; **finalisation bloquée P1** |
| Facture, paiement, allocation, idempotence | Non exécuté dans le navigateur après le blocage ; PHPUnit vert |
| Caution puis clôture | Réception de caution exercée ; remboursement et clôture non atteints |
| Dépenses | Non rejoué après le blocage ; PHPUnit vert |
| Maintenance et blocage | Non rejoué après le blocage ; PHPUnit vert |
| Assurances et sinistres | Non rejoué après le blocage ; PHPUnit vert |
| Dashboards, rapports, CSV | Navigation par rôle atteinte ; campagne complète interrompue |
| Notifications et audit | PHPUnit vert ; G2 navigateur non lancé après le P1 |
| 403, 404, 419 et 422 | 403, 419 et 422 observés ; phase 404 postérieure non atteinte |
| Responsive et accessibilité | Menu mobile et clavier partiels ; matrice complète et Edge non exécutés |

Edge n’a pas été lancé : le protocole arrête la campagne au premier défaut P1.
Les cinq captures et les résultats JSON sont restés sous la racine temporaire
et ne sont pas ajoutés à Git.

## Intégrité après le scénario

- neuf compteurs d’intégrité RBAC : zéro ;
- clés étrangères non validées : zéro ;
- migrations : 69 ;
- `rentfleet:doctor` : contrôles applicatifs verts ;
- données fictives : 2 tenants, 3 agences, 9 utilisateurs, 20 véhicules,
  12 clients, 12 conducteurs, 15 réservations et 9 contrats ;
- cycle bloqué : deux inspections terminées, zéro dommage, zéro frais ;
- aucune ligne de log Laravel nouvelle pendant la fenêtre navigateur et aucune
  erreur Laravel inattendue observée ;
- stockage utilisé : uniquement la racine privée temporaire 06G.

La source `rentfleet_test` correspond encore exactement à l’empreinte capturée
dans le manifeste du dump. Les trois bases protégées conservent 69 migrations
et leurs OID observés sont respectivement `16387`, `16675` et `387455`.
Le harnais n’a ouvert aucune commande mutante vers elles. Une empreinte de
données initiale complète n’avait pas été capturée pour `rentfleet` et
`rentfleet_restore_test` ; cette limite interdit de présenter une comparaison
octet par octet de leur contenu.

## Nettoyage

La garde a relu le nom exact et l’OID `593840`, fermé uniquement les connexions
de la cible puis supprimé `rentfleet_06g_acceptance`. Une vérification
PostgreSQL confirme ensuite son absence.

La racine canonique exacte
`C:\tmp\RentFleet06G\run-20260726-06gb` a été validée avant suppression. Son
stockage privé, le dump, les journaux bruts, les sessions, les identifiants
temporaires, les résultats JSON et les captures temporaires ont été détruits.
La racine n’existe plus.

## Limites et lot correctif proposé

Un lot correctif séparé doit :

1. sortir le formulaire « Finaliser le retour » de la condition
   `charge.review`, tout en conservant la revue des frais sous cette permission ;
2. ajouter un test Blade/HTTP prouvant qu’un Agent de location disposant de
   `contract.return` peut finaliser un retour sans dommage ni frais ;
3. ajouter un test prouvant qu’il ne peut ni proposer ni approuver des frais ;
4. recalibrer les deux attentes de libellés du harnais sans modifier les valeurs
   techniques ;
5. reprendre la recette 06G-B depuis un dump propre et exécuter Chrome et Edge
   jusqu’à la clôture.

Décision finale : **blocage P1, aucun commit autorisé pour cette recette**.

## Reprise 06G-B2 sur le correctif 06G-C

La reprise a été exécutée sur
`beee690ea9da437336bdfb4b195b7f0d4364ca43`. Un nouveau dump custom de
`rentfleet_test` a été créé en lecture seule : 375 600 octets, 762 entrées,
SHA-256
`d671b8eda728096f6ecbd2f8f7dfab46cd4e86c6a241995186ea0aa8687e0ce3`.
La cible jetable a été créée, restaurée et contrôlée avec 69 migrations,
57 tables, 47 séquences, 239 fonctions, 856 contraintes, 30 triggers,
218 index et neuf compteurs d’intégrité à zéro.

Les validations automatisées sont vertes :

- gardes et 06G-C : 23 tests, 100 assertions ;
- régression contrats/retours/RBAC : 49 tests, 522 assertions ;
- suite complète : 307 tests, 3 199 assertions ;
- Pint, Composer validate strict, Composer audit, npm audit et Vite : succès.

Le scénario corrigé, le cycle continu jusqu’à la clôture et la séparation
`contract.return` / `charge.review` sont validés dans Chrome et Edge. La
campagne E2 compte 272 contrôles par navigateur. Chrome est entièrement vert ;
Edge réussit 271 contrôles et signale un débordement visuel P2 sur une fiche
client à 390 px. La campagne G2 Chrome/Edge réussit 201 contrôles sans anomalie.
Les adaptations du harnais ont uniquement aligné trois libellés canoniques,
stabilisé une attente de navigation, sélectionné le paiement créé par le cycle
et rendu le kilométrage fictif indépendant entre navigateurs.

La cible `rentfleet_06g_acceptance` a été supprimée avec son stockage privé.
`rentfleet_test` et `rentfleet_restore_test` correspondent exactement à leurs
empreintes initiales. `rentfleet` conserve le même OID, les mêmes 69 migrations
et le même schéma, mais une session locale externe a été créée pendant la
campagne : une ligne supplémentaire, aucun nouvel audit et une session active
depuis le début de la recette. Le harnais 06G-B2 n’a ouvert aucune connexion
mutante vers cette base.

Décision 06G-B2 : **validation fonctionnelle obtenue, clôture Git différée**.
Le critère strict « trois empreintes de données protégées inchangées » n’est
pas démontré à cause de cette écriture de session externe et l’écart responsive
P2 reste à qualifier. Aucun commit ni push n’est créé dans cette reprise.

## Clôture probatoire 06G-B3 de l’isolation

Le contrôle complémentaire B3 a été exécuté sans rejouer les 744 contrôles
navigateur acquis en B2. Le seul processus RentFleet externe identifié était
le serveur HTTP PHP local, PID `13060`, à l’écoute sur `127.0.0.1:8088`. Il a
été arrêté avant la capture initiale. Aucun worker, scheduler ou navigateur QA
concurrent n’a été trouvé et PostgreSQL n’a pas été arrêté.

La valeur `913` observée sur `rentfleet` correspond au total des lignes des
57 tables, et non au nombre de sessions. L’état stable avant contrôle était de
4 sessions et 123 audits. Deux lectures complètes espacées de dix secondes ont
retourné exactement 913 lignes et l’empreinte
`ed6c527e1898d703da3cab07945480a6d42798447c5ad1b8042616b5a120342a`.

Les instantanés initial et final incluent les OID, les 69 migrations et leurs
lots, le catalogue du schéma, les contraintes, triggers et index, les objets
G2, les neuf compteurs d’intégrité, les signatures de contenu de chacune des
57 tables — y compris `sessions` — et les signatures des séquences. Leur
comparaison JSON stricte est identique :

| Base protégée | OID | Tables | Lignes | Empreinte initiale et finale |
|---|---:|---:|---:|---|
| `rentfleet` | 16387 | 57 | 913 | `ed6c527e1898d703da3cab07945480a6d42798447c5ad1b8042616b5a120342a` |
| `rentfleet_test` | 16675 | 57 | 72 | `26a52537bef41dbfb4c016725477a62297dd399a4b6cf11907fc2e80903e18c1` |
| `rentfleet_restore_test` | 387455 | 57 | 908 | `02f3d27932511f4891a713680d2aedc179529f97c0e65672fc0acad7d39566c9` |

Les gardes statiques du harnais sont vertes. Le test unitaire ciblé des gardes
compte **17 tests, 36 assertions, succès**. Aucune cible jetable n’a été créée :
ce contrôle sans scénario destructif était suffisant pour prouver l’isolation,
et `rentfleet_06g_acceptance` est confirmée absente avant et après le contrôle.

Les résultats fonctionnels B2 restent acquis : **744 contrôles réussis**,
cycle de location complet jusqu’à la clôture dans Chrome et Edge, avec un seul
débordement responsive Edge à 390 px sur la fiche client, classé P2 non
bloquant. Aucun fichier produit, migration, dépendance ou règle métier n’a été
modifié dans B3.

Décision 06G-B3 : **isolation stricte démontrée et clôture Git autorisée**.
