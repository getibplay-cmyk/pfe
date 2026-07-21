# Preuve — Lot 06F-E2 navigateur et accessibilité

## Périmètre et point de départ

- branche : `main` ;
- commit E1 de départ : `789ead8 feat: finaliser l’expérience professionnelle lot 06F-E1` ;
- Laravel `12.63.0`, PHP Herd `8.5.8`, PostgreSQL `18.4` ;
- base QA : `rentfleet_test`, vérifiée avant le `migrate:fresh --seed` ;
- 64 migrations et seeders de démonstration réussis ;
- aucun accès destructif et aucune reconstruction de `rentfleet` ;
- aucune migration, policy, permission, transition ou formule métier modifiée.

Le serveur navigateur a reçu `APP_ENV=testing`, la locale française et des
drivers de session/cache PostgreSQL uniquement dans son processus. Une
configuration temporaire a été mise en cache après vérification de
`current_database()`, puis supprimée dans un bloc de nettoyage systématique.
Les mots de passe QA ont été générés en mémoire, jamais affichés ni persistés.

## Navigateurs et matrice

| Moteur réellement lancé | Version | Couverture |
|---|---|---|
| Google Chrome | 150.0.7871.129 | matrice complète, captures, clavier, contrastes |
| Microsoft Edge | 150.0.4078.83 | smoke desktop/mobile sur auth, dashboard, réservations, contrats et finance |

Viewports : 1440 × 900, 1024 × 768, 768 × 1024, 390 × 844,
320 × 720 et 720 × 450 pour le reflow automatisé équivalent 200 %.

Résultat final du harnais :

```text
browser_checks=258
page_audits=51
screenshots=29
issues_blocking_or_major=0
```

Le JSON final contient Chrome et Edge, 258 contrôles réussis, 51 audits DOM et
contraste, 29 captures et aucune anomalie bloquante ou majeure.

## Parcours réellement exécutés

- connexion, mot de passe oublié, reset, confirmation, vérification conservée,
  changement initial et page 419 ;
- sept rôles : arrivée, contexte, rôle affiché, navigation desktop/mobile,
  destinations interdites, profil, refus direct et déconnexion ;
- isolation Atlas/Rif et accès direct Casablanca/Rabat ;
- navigation mobile, piège Tab, Échap et restitution du focus ;
- disponibilité, création invalide/valide, confirmation, historique,
  annulation et recherche d’une réservation QA ;
- contrats de plusieurs statuts, véhicule, client masqué, finance,
  maintenance, assurance, rapports et utilisateurs ;
- pages 403, 404 et 419 sans trace, chemin, SQLSTATE ou secret.

Statuts de contrat observés dans les fiches et listes : Brouillon, Prêt,
Accepté, Actif, Retour à traiter, Retourné et Clôturé. Les libellés financiers,
dommages et cautions visibles ont aussi été contrôlés sans exposer de détail
technique.

## Défauts constatés et corrigés

| Sévérité | Défaut | Correction | Régression |
|---|---|---|---|
| majeur | contraste 4,24:1 sur fond sombre | `slate-400`, ratio 7,87:1 | 51 audits sans violation |
| majeur | focus menu utilisateur repris par le hamburger | gestionnaires Échap conditionnels et focus explicite | clavier Chrome réussi |
| majeur | champs visibles sans label | labels/identifiants assurance, client, documents et finance | aucun contrôle sans label |
| majeur | champs de retour contrat sans label | labels associés pour dommage, nettoyage et note de retour | audits du contrat sans violation |
| majeur | erreurs réservation globales et anglaises | erreurs par champ, attributs ARIA, focus et traduction `fr` | capture et test PHPUnit |
| majeur | tableaux mobiles et formulaire 320 px | composants responsive, hints et flex-wrap | aucun élément non confiné |
| mineur | type d’inspection technique dans un libellé photo | traduction par `UiLabel` | fiche contrat rejouée |
| mineur | absence d’indication de défilement | message mobile explicite | captures Finance/Utilisateurs |

Aucun défaut bloquant n’a été constaté. Aucun défaut majeur ne reste ouvert.

## Captures

Les 29 PNG réels se trouvent dans `docs/evidence/screenshots/lot06f-e2/` :

- 12 écrans en desktop et mobile : login, dashboard, réservations, fiche
  réservation, contrat, véhicule, client, finance, maintenance, assurance,
  rapports et utilisateurs ;
- menu mobile ouvert ;
- formulaire réservation invalide en français ;
- erreurs 403, 404 et 419.

Les captures utilisent uniquement les données fictives seedées. Aucun mot de
passe, token, cookie, document privé ou identité complète n’y apparaît.

## Accessibilité

Les couples principaux mesurés vont de 4,55:1 à 20,17:1 après correction ; le
bouton primaire atteint 6,03:1 et le texte secondaire de sidebar 7,87:1. Les
détails sont dans `docs/ux/accessibility-audit.md`.

L’inspection automatique vérifie langue, h1, main, noms accessibles, labels,
identifiants dupliqués, tableaux, débordements non confinés, ressources
distantes et marqueurs sensibles. L’inspection visuelle a porté sur les PNG
desktop/mobile, les scrollers, le menu ouvert, le focus d’erreur et les pages
d’erreur.

## Artefacts reproductibles

- harnais : `tests/Browser/lot06f_e2_browser.py` ;
- test structurel : `tests/Feature/Lot06FE2BrowserReadinessTest.php` ;
- rapport machine : `docs/evidence/browser-data/lot06f-e2-browser-results.json` ;
- matrice : `docs/ux/browser-validation-matrix.md` ;
- audit : `docs/ux/accessibility-audit.md`.

Le test E2 ciblé compte 5 tests et 132 assertions réussis. La validation
croisée E1 + E2 compte 17 tests et 984 assertions réussis.

## Validation finale

- suite PHPUnit complète : 258 tests et 2 546 assertions réussis ;
- Pint : correction puis contrôle `--test` réussis ;
- Vite 6.4.3 : 56 modules transformés, build réussi ;
- caches Blade, configuration, routes et optimisation : réussis ;
- routes : 192, aucune route `register`, `signup`, `storage` ou
  `profile.destroy` ;
- `composer validate` : valide ;
- `composer audit --locked --no-interaction` : aucun avis de sécurité ;
- `npm audit --omit=dev` : aucune vulnérabilité ;
- aucune migration, policy, permission, dépendance ou configuration secrète
  modifiée.

Un premier passage complet a rencontré un échec isolé sur le stockage simulé
d’un document contractuel (257 tests réussis, 2 536 assertions). Le test en
cause a immédiatement réussi seul (1 test, 7 assertions), puis la suite
complète a réussi sans modification de la règle métier. L’incident est donc
consigné comme non reproductible et aucun assouplissement de sécurité n’a été
appliqué.

## Limites déclarées

- Firefox absent ;
- aucun lecteur d’écran pilotable ;
- axe et Lighthouse absents ;
- reflow équivalent 200 %, sans zoom natif dans une fenêtre visible ;
- mode headless pour les moteurs réels.

Ces limites interdisent de présenter E2 comme une certification WCAG ou une
validation Firefox. Elles n’empêchent pas la clôture de la couverture réellement
exécutée sur Chrome et Edge disponibles.
