# Durcissement à partir des quatre référentiels

**État : publication et fusion autorisées le 14 septembre 2026 ; qualification
PostgreSQL et CI requise avant fusion, aucune mise en production effectuée.** Les tests PostgreSQL et la nouvelle CI sécurité n’ont pas
été exécutés pour ce lot. La continuation SANAD PILOT dispose de résultats locaux
PHP, JavaScript, Composer/npm, Semgrep et scan des sources avec Gitleaks, détaillés
dans `continuation-sanad-pilot.md`. Les protections de production ne sont pas réputées actives
par la présence de leur configuration dans le dépôt.

Les quatre fichiers ont été lus entièrement. Le registre conserve leurs empreintes,
**332 sections**, **229 contrôles du référentiel principal**, **7 prescriptions
machine supplémentaires** et **50 scénarios de vérification**. Ce sont des unités de
traçabilité ; ce décompte n’est pas un score de conformité. Aucun contrôle n’est
marqué CONFORME sur la seule base de code, d’un scan prévu ou d’une procédure écrite.

## Lire le dossier

- `control-register.json` : source/lignes, exigences, état, responsable fonctionnel,
  code/procédures, tests concernés, preuves disponibles, risque et prochaine action.
- `architecture-and-decisions.md` : périmètre réel, scénarios STRIDE et décisions
  explicites sur les prescriptions incompatibles ou non applicables.
- `operations.md` : préparation du déploiement, segmentation, accès, alertes,
  sauvegarde, incidents, confidentialité, réentraînement et rollback.
- `production.env.example`, `nginx.conf.example`, `postgresql-roles.sql.example` :
  configurations à examiner, pas des opérations déjà appliquées.
- `continuation-sanad-pilot.md` : changements et résultats les plus récents, commit vérifié et suite à donner.
- `local-verification-2026-09-13.json` : preuves locales structurées, versions et limites.
- `verification.md` : résultats historiques du lot initial et commandes PostgreSQL à exécuter.

## Changements préparés

| Zone | Comportement |
|---|---|
| Comptes | Phrases de passe ≥15 caractères, refus de compromission en production, limites compte/source, reset transactionnel à usage unique |
| E-mail personnel | Ancienne identité conservée jusqu’à confirmation explicite ; notifications ancien/nouveau canal via file chiffrée |
| Sessions | Durée absolue, preuve liée au compte/version, rotation et révocation ; reset administrateur et changement de droits invalident les preuves |
| Administration | Confirmation du mot de passe récente, plafonnée à 15 minutes, sur les changements plateforme, utilisateurs, rôles, agences et réglages sensibles ; rotation après confirmation |
| Entrées HTTP | JSON borné, proxy explicitement autorisé, erreurs génériques, contexte de logs nettoyé |
| Navigateur | CSP base/object/frame bloquante et nonce ; scripts/styles en Report-Only en attendant la qualification Alpine |
| Fichiers | Type réel/extension, scanner privé en production, images réencodées, hash versionné et copie exacte inspectée au téléchargement |
| IA | Variables héritées refusées sauf liste système/runtime ; admission atomique par tenant et globale pour six familles de runs |
| Journalisation | Événements structurés sur un canal dédié conservant les notices même si le log général est en warning ; audit borné |
| Notifications privées | Récupération, changement et vérification d’adresse en queue chiffrée ; transport SMTP effectif contrôlé, TLS exigé en production |
| Qualification | Diagnostic `rentfleet:security-audit`, tests négatifs supplémentaires, analyses PHP/JS/Python et secrets proposées pour CI |
| Exploitation | Registre complet, templates, procédures d’accès, incidents, reprise et maintien |

Les clauses restant partielles sont détaillées, notamment MFA résistant au phishing,
qualification PostgreSQL des nouvelles réauthentifications administratives, rôle DB non propriétaire,
isolation système des parseurs/IA, CSP scripts bloquante, rétention juridique et tests
d’hébergement. Les contrôles absents ne sont pas supprimés du registre.

## Conditions de poursuite

Le refus initial de publication dans le dépôt public `getibplay-cmyk/pfe` est
documenté dans les résultats historiques. Le 14 septembre 2026, l’utilisateur a
explicitement répondu « Oui et fusionner puis continuer » à la demande de publication
publique de ce lot. Cette autorisation couvre la PR, les corrections nécessaires,
la CI puis la fusion après vérification. Les contrôles de test restent obligatoires.

Avant exposition en production : traiter les critères bloquants du registre,
prouver les contrôles de l’hébergement et la reprise, accepter formellement les
écarts résiduels avec date de réexamen. Ce dossier ne vaut ni acceptation du risque,
ni certification ASVS/ISO/SOC, ni promesse de sécurité absolue.
