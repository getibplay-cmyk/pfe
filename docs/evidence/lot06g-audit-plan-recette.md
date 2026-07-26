# Lot 06G-A — Audit initial et plan de recette sans IA

## Référence

- branche auditée : `main` ;
- HEAD : `2e74a02361b28e6442a9e00739885363f4bf81b3` ;
- parent : `6f8d233dd198681c6875258599bc68f60666129f` ;
- Laravel 12.63.0, PHP Herd 8.5.8 et PostgreSQL 18.4 ;
- 69 migrations appliquées, aucune migration en attente ;
- suite historique au HEAD : 295 tests et 3 091 assertions.

Le Lot 06G-A a été réalisé en lecture seule. Aucun code, aucune dépendance,
aucune donnée et aucune base n’ont été modifiés pendant cet audit.

## Sources et périmètre

L’audit a relu les sources disponibles suivantes :

- [`AGENTS.md`](../../AGENTS.md) ;
- le [cahier d’architecture](../../RentFleet_Cahier_Architecture_Executable_Codex.md) ;
- le [README](../../README.md) ;
- les preuves versionnées des lots précédents, notamment
  [G2](lot06f-g2-notifications-rbac-ux.md) et
  [D3 post-migration](lot06f-g2-d3-backup-restore.md) ;
- les runbooks de démonstration, déploiement, sauvegarde et restauration ;
- les gardes de base de test et les harnais navigateur E2 et G2.

Trois documents demandés n’existaient pas dans le dépôt :

- `RentFleet_Codex_Etapes_A_Z.md` ;
- `RentFleet_Audit_SaaS_Complet_2026-07-15.md` ;
- `AUDIT_SAAS_AVANT_LOT_06F.md`.

Aucune définition 06G antérieure n’était versionnée. Le périmètre officiel
retenu est la recette complète du SaaS soutenable sans IA, conformément à la
porte de validation du cahier d’architecture. Les Lots 07 à 09, l’API
prédictive, Colab, le ML et l’IA restent exclus.

## État technique observé

- worktree et index propres ;
- `main` synchronisée avec `origin/main` ;
- 204 routes, dont 198 nommées ;
- aucune route `register`, `signup`, `/storage` ou API métier ;
- 22 policies et 44 Form Requests ;
- 88 fichiers applicatifs transactionnels et 79 usages de verrou pessimiste ;
- 68 vues appelées, aucune vue manquante ;
- 184 références Blade à des routes, aucune référence cassée ;
- stockage documentaire privé, versionné et contrôlé ;
- aucune queue applicative, aucun Redis et aucun service HTTP externe requis ;
- aucune dépendance à l’IA dans les flux opérationnels.

`rentfleet:doctor` était vert pour les contrôles applicatifs, PostgreSQL,
contraintes, triggers, intégrité financière, reporting et RBAC. Le heartbeat
scheduler était absent dans l’environnement local, sans constituer une
incohérence de données.

## Lacunes classées

### P0

Aucune faille ou incohérence bloquant la préparation d’une recette isolée.

### P1

1. `TestDatabaseGuard` et les harnais E2/G2 n’autorisaient que le nom exact
   `rentfleet_test`. Une garde additive explicite est nécessaire pour la cible
   jetable.
2. Aucun passage navigateur unique au HEAD G2 ne démontrait tout le cycle
   client → réservation → contrat → départ → retour → finance → clôture.
3. Les sept rôles avaient une couverture de navigation et de refus, mais les
   parcours métier profonds restaient principalement exercés avec le Tenant
   Owner.

### P2

- erreur 500 contrôlée à confirmer dans un navigateur ;
- scénarios 422 représentatifs à étendre aux principales familles de formulaires ;
- Firefox, lecteur d’écran et zoom natif non couverts ;
- heartbeat scheduler local absent ;
- preuve E2 historique à rejouer sur le HEAD G2 ;
- trois documents de cadrage demandés absents ;
- audit explicite des modifications de catégories de véhicules à confirmer
  comme décision de gouvernance, sans exigence documentaire actuelle.

## Couverture existante

La suite historique validée compte 295 tests et 3 091 assertions : Feature,
Unit, PostgreSQL, RBAC, multitenancy, documents privés, cycles réservation et
contrat, finance, maintenance, assurance, reporting, exploitation et sécurité.

Les preuves navigateur disponibles indiquent :

- E2 : 258 contrôles, 51 audits DOM et 29 captures Chrome/Edge ;
- G2 : 140 contrôles, 10 captures et aucune anomalie ;
- viewports desktop, tablette, 390 px et 320 px ;
- sept rôles, navigation mobile, refus RBAC, notifications et remplacement de rôle.

Ces résultats sont historiques et ne sont pas présentés comme réexécutés
pendant le Lot 06G-A.

## Environnement de recette autorisé

La seule base jetable proposée est :

`rentfleet_06g_acceptance`

Les bases suivantes restent protégées contre toute écriture de recette :

- `rentfleet` ;
- `rentfleet_test` ;
- `rentfleet_restore_test`.

`rentfleet_test` peut uniquement servir de source en lecture seule pour un dump
PostgreSQL custom-format. La configuration de la cible et du stockage privé doit
rester limitée aux variables du processus et à
`C:\tmp\RentFleet06G\<run-id>`.

Les gardes doivent vérifier le nom exact, PostgreSQL, l’environnement de test,
le mode d’acceptation explicite, `current_database()`, l’utilisateur, le
serveur, le port, les OID distincts et le chemin temporaire canonique.

## Plan de recette

La recette estimée comprend environ 126 scénarios :

1. précontrôles Git et environnement ;
2. création protégée de la copie jetable ;
3. contrôle des migrations et de l’intégrité ;
4. préparation de données fictives ;
5. navigation par rôle ;
6. cycles métier de bout en bout ;
7. refus multitenant et inter-agence ;
8. contrôles financiers et PostgreSQL ;
9. responsive et accessibilité ;
10. journaux et erreurs sans divulgation ;
11. tests automatisés sur la cible ;
12. comparaison avant/après ;
13. destruction sécurisée de la copie ;
14. preuve finale 06G.

## Décision

Aucun correctif fonctionnel produit n’était requis avant la recette. La
préparation additive du harnais QA est une exigence d’exploitation isolée.

Audit 06G-A terminé. Le périmètre de recette est défini et aucune modification
applicative ou de base n’a été effectuée. La recette 06G-B peut être proposée
pour autorisation séparée sur une base jetable.
