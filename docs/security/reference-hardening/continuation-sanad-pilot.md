# SANAD PILOT — continuation du point 1

**Mise à jour du 14 septembre 2026 :** publication publique et fusion autorisées
explicitement par l’utilisateur. Les résultats ci-dessous décrivent le passage
local du 13 septembre ; la CI doit qualifier la version publiée avant fusion.

Le renommage et les corrections supplémentaires sont enregistrés dans le commit
local **`6f7e06b712c666b22b0c7be8d17ffa196d5c7143`**, branche
`feature/sanad-pilot-security`. Il prolonge le lot de durcissement `061467e`.
**Aucune publication publique, fusion ou mise en production de ce lot n’est faite.**

## Ce qui change

- **SANAD PILOT** devient le nom des pages publiques, interfaces métier, écrans
  d’authentification, notifications et rapports. Le symbole du logo utilise un S ;
  composants Blade, événements JavaScript, styles, thèmes de graphiques et tests
  suivent le nouveau nom. L’ancien nom d’expéditeur standard est repris automatiquement.
- Les opérations sensibles de plateforme, utilisateurs, rôles, agences et paramètres
  demandent un mot de passe confirmé depuis moins de 15 minutes. Une preuve absente,
  malformée, future ou expirée est refusée ; une durée configurée plus courte est
  respectée. La confirmation renouvelle l’identifiant de session et une nouvelle
  connexion efface l’ancienne preuve. La session absolue est plafonnée à huit heures.
- Les notifications de vérification d’adresse rejoignent les files chiffrées de
  récupération et de changement d’adresse. Les liens privés exigent un transport
  SMTP effectif sans sortie vers les logs ; les options de l’URL sont résolues comme
  dans Laravel. Le chiffrement est exigé en production ; le diagnostic contrôle
  aussi le délai SMTP effectif. Aucun message réel n’a été envoyé pour ces essais.
- Les mots de passe contenant NUL sont refusés avant hachage. Une version de document
  conserve l’empreinte des octets inspectés ; le client antivirus borne également
  une réponse arrivant très lentement.
- Un journal de sécurité dédié conserve les événements de niveau notice malgré
  `LOG_LEVEL=warning`. Les données brutes de requête restent exclues. Les exemples
  de production et la procédure du worker `notifications` sont mis à jour.

Les noms techniques des fonctions SQL déjà déployées sont conservés. Les anciennes
factures émises ne sont pas réécrites. Aucune dépendance applicative ni clé existante
n’a été changée. Le garde-fou PostgreSQL de test reste intact.

## Résultats locaux de cette version

| Vérification | Résultat | Portée |
|---|---|---|
| PHPUnit / PHP 8.5.10 | **43 tests, 1 946 assertions**, réussis | Tests purs : confirmation récente, transport mail, NUL, FR/AR, environnement processus, audit, protocole ClamAV et composants de marque |
| JavaScript | **103 tests**, réussis | Logique des composants ; aucune qualification visuelle navigateur revendiquée |
| Syntaxe PHP et Pint | **187 fichiers PHP**, aucune erreur ; Pint réussi | Fichiers PHP nouveaux/modifiés des deux lots cumulés |
| Vite 6.4.3 | Réussi, **80 modules** | Compilation du bundle |
| Blade et routes | Compilation réussie ; **387 routes** enregistrées | Confirmation présente sur les routes administratives sensibles inspectées ; pas une preuve d’autorisation métier |
| Parcours invités via noyau HTTP | Connexion, oubli et reset rendus en **200** avec SANAD PILOT ; JSON malformé en **422** | Stockage de session/cache en mémoire, clé locale éphémère, aucun accès PostgreSQL |
| En-têtes et logs du même essai | `no-store`, `nosniff`, nonces distincts ; événement de sécurité conservé avec log général warning | Valeurs synthétiques de mot de passe, e-mail, token, en-tête et IP absentes du journal dédié |
| Diagnostic local | **8 contrôles réussis** | Aucune configuration réelle de production inspectée |
| Composer 2.10.1 | Aucune alerte ni paquet abandonné | Audit des dépendances verrouillées ; PHAR officiel vérifié par SHA256 |
| npm | **0 vulnérabilité connue**, avec et sans dépendances de développement | Avis disponibles au moment de l’exécution |
| Semgrep 1.177.0 | **8 règles, 849 fichiers**, 0 résultat, 0 erreur | Règles locales PHP/JS/Python, métriques désactivées ; couverture ciblée |
| Gitleaks 8.30.1, sources | **1 670 fichiers**, 0 alerte après revue de 4 faux positifs | Binaire officiel vérifié ; trois exceptions exactes documentées ci-dessous |
| Registre et pièces jointes | **4 sources, 332 sections, 236 contrôles, 50 scénarios** ; empreintes concordantes | Complétude de traçabilité, pas conformité globale |
| SBOM | **396 composants verrouillés** inventoriés | Locks PHP/npm/Python ; pas tout le graphe installé, images ou modèles |

Les preuves structurées et les empreintes figurent dans
`local-verification-2026-09-13.json`. Le diff a également passé `git diff --check`.
Le dernier changement du script de charge ne modifie que son nom de User-Agent.

Gitleaks a initialement signalé un identifiant de ligne du modèle JSON, une sentinelle
de test vérifiant l’absence d’un token à l’écran, et les deux occurrences d’un mot de
passe d’invitation synthétique. `.gitleaks.toml` conserve les règles par défaut et
exige **chemin exact ET valeur exacte** pour chaque exception. Un essai distinct
confirme qu’une autre valeur à forte entropie dans le même fichier, et la valeur
exceptée dans un autre fichier, restent détectées. Aucun dossier ni commit entier
n’est exclu. Le scan de tout l’historique a été interrompu : le clone partiel
déclenchait des récupérations supplémentaires d’objets. **Aucun succès du scan
historique n’est revendiqué** ; la CI sur un checkout complet doit le réaliser.

## Ce qui reste avant validation du point 1

1. Exécuter les tests Feature et la suite complète sur la vraie base
   **`rentfleet_test` PostgreSQL**, puis la CI complète. Les nouveaux scénarios couvrent
   notamment les refus administratifs 423, le maintien des interdictions inter-tenant,
   les notifications, les sessions et les fichiers. Les assertions de comptage ont
   été ajustées aux **106 migrations** présentes ; aucune migration n’a été exécutée ici.
2. Qualifier sur l’environnement cible les notifications SMTP/TLS et leur worker,
   ClamAV et ses signatures, les cookies/proxies, le rôle DB non propriétaire,
   les alertes, la restauration mesurée et l’isolation système des traitements IA.
3. Qualifier les parcours navigateur avant de rendre la CSP scripts/styles bloquante,
   traiter le MFA résistant au phishing et les autres prochaines actions du registre.
   Les états restent **160 PARTIEL, 64 NON_VERIFIE, 12 NON_APPLICABLE_JUSTIFIE**.

Les tests protocolaires ClamAV utilisent un serveur synthétique, sans signatures
réelles. Les résultats de la précédente PR 37 ne sont pas attribués à cette version.
Les exemples `.env` doivent être intégrés aux paramètres existants ; le changement
de nom de cookie nécessite une nouvelle connexion. La migration d’adresse en attente
et le redémarrage des workers/caches font partie du déploiement à qualifier.

## Autorisation de publication

La revue automatique avait refusé la publication de ce lot précis dans le dépôt
**public** `getibplay-cmyk/pfe`, exigeant un accord explicite de divulgation publique.
L’utilisateur a donné cet accord le 14 septembre 2026, avec instruction de fusionner
puis continuer. Publier une PR de travail, exécuter les CI PostgreSQL et sécurité,
corriger les écarts puis faire la
revue avant fusion. Les quatre pièces jointes sources ne sont pas ajoutées au dépôt.

La base distante de référence est `9321b2565242fa49858f467b871769e7b5c19c59`.
L’historique local récupéré possède des SHA différents : la reprise distante doit
appliquer le diff sur la base distante vérifiée, sans imposer l’historique local
par un push forcé.
