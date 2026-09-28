# Exploitation, vérification et reprise

Ce dossier prépare les opérations ; il ne prouve pas leur exécution sur un serveur.
Le registre conserve les mesures d’hébergement en `NON_VERIFIE` jusqu’à réception
d’une preuve datée. Aucun domaine, compte, clé, sauvegarde ou serveur de production
n’a été modifié par ce lot.

## Responsabilités et périmètre

| Domaine | Responsable fonctionnel à nommer | Preuve attendue |
|---|---|---|
| Développement et CI | Mainteneur du dépôt | Revue et CI du commit exact |
| Accès, DNS, TLS, système | Administrateur d’exploitation et suppléant | Inventaire réel et tests depuis une source autorisée |
| Surveillance et incident | Responsable sécurité et suppléant | Alerte reçue, exercice et délai mesuré |
| Sauvegarde et reprise | Responsable exploitation distinct de l’identité web | Restauration métier complète |
| Données et sous-traitants | Responsable traitement/conseil juridique | Registre, contrats, formalités et échéances applicables |
| Risque résiduel | Propriétaire du SaaS | Décision motivée, compensations et expiration |

Les rôles ci-dessus ne désignent pas implicitement une personne. L’annuaire nominatif
et ses moyens de récupération restent dans un espace privé accessible hors du SaaS.
Les tests actifs portent uniquement sur une cible locale ou une préproduction
explicitement autorisée, deux tenants fictifs, comptes synthétiques, mails de test,
paiement sandbox, temps et coûts bornés. Arrêter en cas de donnée réelle, impact
imprévu, saturation, destinataire externe ou accès au réseau de production.

## Préparer un déploiement

1. Identifier domaine canonique, origine IPv4/IPv6, proxies, comptes Git/cloud/DNS,
   environnements, versions, disques privés, files, certificats et tiers. Comparer
   cet inventaire aux routes exportées par `php artisan route:list --json`.
2. Faire réussir les tests PostgreSQL autorisés, analyses et build du commit exact.
   Conserver artefact, SBOM, empreintes, rapport et preuve de provenance ensemble.
   Ne pas promouvoir une reconstruction différente ou un artefact provenant d’une
   PR non approuvée. Vérifier l’identité du workflow, du dépôt et de la référence.
3. Examiner la migration additive des champs de changement d’e-mail ; sauvegarder
   avant migration. Appliquer avec l’identité de migration, jamais le rôle web.
4. Intégrer `production.env.example` au coffre/configuration existant. Ne pas
   remplacer le fichier complet ni régénérer `APP_KEY`. Enrôler les administrateurs
   au MFA et vérifier leur récupération avant de rendre MFA obligatoire.
5. Configurer clamd privé et la mise à jour de ses signatures. Une socket absente,
   une erreur, une limite ou un résultat inconnu bloque la publication/livraison
   des fichiers en production. Aucun fichier n’est transmis à un antivirus public.
6. Déployer en lecture seule pour l’identité PHP, sauf `storage` et `bootstrap/cache`.
   Document root strictement `public/`, aucun lien public vers le disque privé.
   Désactiver pages de debug, listing, dumps et accès publics aux métriques.
7. Revoir `nginx.conf.example`, les limites PHP d’upload/mémoire et le pare-feu.
   Le corps HTTP est borné à l’entrée ; l’application borne JSON à 1 Mio. Les
   limites multipart doivent correspondre au nombre autorisé de photos par action.
   Valider certificat et nom jusqu’à l’origine. Ne jamais désactiver TLS pour
   corriger une erreur. L’accès direct à l’origine est restreint dans les deux
   familles IP. N’exposer ni PostgreSQL, ni cache, ni scanner, ni administration.
8. Démarrer des workers distincts pour `notifications` et les files IA existantes.
   Réserver de la capacité aux notifications. Trois essais et timeout 15 secondes
   pour les notifications ; `retry_after=660` dépasse le plus long timeout IA
   actuel (630 secondes). Surveiller attente, échecs, disque temporaire et heartbeat.
9. Exécuter `php artisan config:cache`, puis les diagnostics ci-dessous avec la
   configuration effective. Un rapport sans erreur ne certifie que ses contrôles.
10. Contrôler login/MFA, confirmation e-mail, deux tenants, pièces privées,
    contrat/facture, callback CMI sandbox, IA et mode manuel. Tester les alertes.

```powershell
php artisan rentfleet:security-audit --production --database --json
php artisan rentfleet:doctor --json
```

La commande de sécurité est en lecture seule. Elle ne révèle pas de valeur de clé,
mot de passe, connexion complète ou adresse privée. Le profil impose un cookie
`__Host-` sans Domain ; `Secure` et `Path=/` sont indispensables. SameSite=Lax est
conservé pour les navigations de paiement ; les callbacks CMI sont protégés par
signature et invariants serveur. Seules leurs routes sont exemptées du CSRF métier.

## Segmentation et accès d’exploitation

L’identité web ne possède ni schéma, ni rôle de migration, ni sauvegarde. Les
comptes Git, hébergement, DNS, coffre et sauvegardes ont une MFA résistante au
phishing, un compte quotidien séparé et une récupération testée. Retirer au départ
sessions, clés SSH, tokens, permissions, partages Drive/Colab et accès fournisseurs.
Encadrer le support par tenant, finalité et expiration, avec audit. Vérifier postes
à jour, disques chiffrés, verrouillage et extensions autorisées. Protéger une
identité d’urgence séparée avec alerte à chaque usage.

Le template SQL n’est pas une migration automatique. Il nécessite inventaire des
tables/fonctions, reprise d’ownership, tests avec les vrais rôles et revue des grants
fins, notamment sur audits et finance. Ne pas retirer les triggers et contraintes
existants. `scripts/backup.ps1` impose actuellement `rentfleet_app` : son évolution
vers une identité de sauvegarde dédiée reste à qualifier avant changement du rôle.
Une restriction de privilèges appliquée sans cette qualification bloquerait la reprise.

L’environnement transmis aux processus Python est fermé par liste autorisée. Cela
n’isole pas le système de fichiers ou le réseau d’un processus de même UID. La mise
en production doit utiliser une identité de calcul dédiée, modèles en lecture seule,
répertoire temporaire limité, absence d’accès aux fichiers de secrets métier, quotas
CPU/RAM/PID et refus des sorties réseau non nécessaires. Valider le lanceur privilégié
ou le profil système avant intégration ; ne pas ajouter un `sudo` général au worker.
Sur conteneurs existants : non-root, rootfs en lecture seule, capacités supprimées,
seccomp/AppArmor, aucun socket Docker ni montage hôte sensible. Un monolithe n’a
pas besoin d’être transformé en Kubernetes pour appliquer ces frontières.

Limiter egress à la messagerie prévue, aux stockages privés explicitement autorisés
et au service de vérification de mots de passe. Ce dernier reçoit un préfixe SHA-1
de cinq caractères, jamais le mot de passe ni son empreinte complète ; padding,
timeout et taille de réponse sont bornés. Documenter ce flux dans l’inventaire tiers.
Le processus IA ne doit pas atteindre ces destinations ni les métadonnées cloud.

Protéger registrar/DNS : MFA, renouvellement, alertes de certificat, nettoyage des
sous-domaines orphelins, CAA et DNSSEC si l’équipe maîtrise rotation et récupération.
Déployer SPF/DKIM et DMARC progressivement après inventaire des expéditeurs.

## Journaux, alertes et pannes

Les événements applicatifs ont heure UTC, action/résultat, identifiants techniques et
corrélation. Aucun corps de requête, mot de passe, token, document complet ou valeur
CMI secrète ne doit être collecté. Les erreurs de production n’écrivent pas le
message arbitraire de l’exception. Les audits bornent profondeur, nombre et taille
des valeurs. Le proxy ne journalise pas les chemins contenant des liens secrets.

| Signal | Détection initiale à calibrer | Réaction et preuve |
|---|---|---|
| Échecs/login limité | Agréger `auth.login` par empreinte source et compte sur 5 min | Alerte privée, test avec comptes fictifs |
| Refus d’accès | Hausse 401/403 et audits de refus par tenant | Corréler route et actor ; ne pas déduire une fuite d’un 403 seul |
| Élévation/MFA/récupération | Audit de changement de droits ou récupération MFA | Notification exploitant, revue d’identité |
| Exports/IA inhabituels | Volume, jobs actifs, temps en attente, stockage | Suspendre la capacité concernée et conserver le mode manuel |
| Antivirus | Service/signatures en retard, échecs, limites atteintes | Bloquer fichiers, réparer scanner, retester sain/suspect/erreur |
| Silence de collecte | Heartbeat manquant, horloge incohérente, disque plein | Alerte depuis un système indépendant du SaaS |
| Sauvegarde | Dernière sauvegarde/restauration trop ancienne ou hash invalide | Alerte et nouvelle vérification isolée |

Écriture, lecture et administration des logs sont des identités séparées. Un SIEM
doit recevoir ces signaux et démontrer la réception d’une alerte de test : la présence
d’un `Log::notice` ne suffit pas. Retenir les journaux selon une politique justifiée,
limiter accès et cardinalité, protéger la copie contre suppression. Une panne
d’audit transactionnel annule l’opération sensible ; une panne du stockage du
limiteur ne doit jamais autoriser des requêtes sans limite. Tester ces pannes.

Les quotas d’admission IA couvrent six familles et leur cumul, par tenant et global,
avec verrou transactionnel PostgreSQL non bloquant. Ils ne remplacent pas un budget
CPU/RAM, les files de notifications/exports ou le plafond de facturation cloud.
Les runs périmés nécessitent une récupération contrôlée : ne pas les ignorer pour
faire artificiellement redescendre la capacité. Surveiller les jobs orphelins.

## Sauvegardes et preuve de reprise

Définir RPO/RTO par service avant toute promesse commerciale ; aucun chiffre n’est
revendiqué ici. Sauvegarder PostgreSQL, objets privés, configuration et inventaire
des clés avec manifeste/empreintes. Séparer le coffre de clés et les identités de
sauvegarde de la production ; au moins une copie hors de leur capacité de suppression.
Prévoir immutabilité ou copie hors ligne, chiffrement, expiration et surveillance.
PITR nécessite une chaîne WAL continue vérifiée ; un dump quotidien ne prouve pas PITR.

Utiliser les scripts existants avec `pgpass`/`PGPASSFILE` protégé, sans afficher son
contenu. La restauration automatisée vise **uniquement `rentfleet_restore_test`**,
avec confirmation et répertoire temporaire validé hors dépôt/stockage vivant.
Réseau, mails, paiement, scheduler et intégrations de production sont désactivés sur
la cible de restauration. `verify-restore.ps1` compare manifeste, fichiers et données.

L’exercice vérifie aussi login de test, isolation A/B, compteurs financiers,
contrat/facture figés, GiST, fichiers déchiffrables, version de clé nécessaire et
absence d’envoi externe. Relever début/fin, point récupéré, erreurs et perte réelle.
Rejouer perte de serveur **et** perte d’un accès au coffre/DNS. Tester une restauration
par tenant seulement si elle est promise et que ses dépendances ont été modélisées.
Ne jamais copier directement les dumps réels dans des tests développeur.

## Incidents

Pour chaque incident : ouvrir un identifiant privé, qualifier l’impact, préserver
des preuves minimales horodatées, choisir le confinement, révoquer depuis une identité
saine, corriger, tester, reprendre sous surveillance et conduire une revue sans blâme.

| Scénario | Confinement initial à décider | Conditions de reprise |
|---|---|---|
| Secret divulgué | Révoquer le secret concerné, désactiver sa consommation et restreindre les preuves | Nouvelle identité testée, consommateurs inventoriés ; changer Git seul ne suffit pas |
| Administrateur compromis | Désactiver compte, sessions, remember tokens, récupération et tokens tiers | Identité vérifiée hors canal compromis, MFA neuf, revue des actions |
| Accès inter-entreprises | Suspendre le chemin concerné et les exports, préserver les écritures | Cause corrigée, données rapprochées, tests négatifs A/B à tous les étages |
| RCE/webshell | Isoler réseau/service, préserver preuves, ne pas nettoyer au hasard | Reprovisionner depuis artefact sûr, identités révoquées, intégrité confirmée |
| DDoS/coût | Activer règles temporaires edge, quotas et arrêt IA, contacter fournisseur | Charge stable, budget revu, retrait progressif des règles et vérification d’accès légitime |
| Ransomware/corruption | Isoler écritures et protéger copies/clés indépendantes | Restauration isolée et métier vérifié, origine de compromission traitée |
| CI/dépendance compromise | Suspendre promotions et révoquer identités runner/publication | Artefact reconstruit depuis source/provenance fiable, contrôle des déploiements passés |

Les contacts prestataires, décideurs, juristes et autorités restent disponibles hors
production. Les obligations de notification dépendent des traitements, contrats et
territoires applicables ; ne pas appliquer automatiquement un délai universel.

## Vie privée et données d’entraînement

Tenir un registre : identité/permis, contrats, photos, coordonnées, factures,
événements, exports, jeux d’entraînement, sauvegardes ; pour chacun finalité, base,
destinataires, emplacement, transfert, durée, suppression et responsable. Vérifier
avec le responsable compétent les formalités CNDP, CIN et transferts hors Maroc,
ainsi que RGPD/PCI selon l’activité réelle. Ne pas déclarer de conformité sur la
seule base de ce code. Aucun numéro de carte/CVV ne doit entrer dans le SaaS.

Les droits d’accès/rectification/export/suppression exigent une identité vérifiée
et un périmètre tenant. Les pièces contractuelles et financières peuvent nécessiter
conservation ou gel juridique ; aucun purgeur automatique général n’est ajouté sans
politique définie. Les copies temporaires, exports, index, caches, logs et sauvegardes
ont une échéance et un mécanisme de retrait cohérents, à prouver.

Drive/Colab restent un calcul externe autorisé pour l’entraînement, sans clé métier.
Regrouper des données n’annule pas les droits des entreprises d’origine : consentement
ou autre base appropriée, sélection minimale, pseudonymisation, manifeste figé,
empreintes, provenance, séparation apprentissage/évaluation, qualité et dérive.
L’import du SaaS reste un résultat JSON strict ; pas d’upload exécutable ni de pickle
arbitraire. Toute promotion de modèle exige une revue indépendante des métriques,
des groupes concernés, des tests adversariaux et une décision humaine traçable.
Le modèle précédent et le mode manuel restent disponibles.

## Retrait d’un lot et maintien

Un rollback applicatif redéploie l’artefact précédent vérifié, vide/recharge les
workers et rétablit les flags compatiblement. Conserver les colonnes additives
pendant ce retrait ; ne pas supprimer les demandes d’e-mail en attente par une
migration destructive. Invalider les sessions si une ancienne version ne comprend
pas les nouvelles preuves. Ne pas désactiver les protections pour masquer un test
en échec. Les données métier ne se restaurent pas implicitement avec le code.

À chaque changement : tests/analyses. Quotidien : avis éditeurs, backups, alertes,
capacité. Hebdomadaire : vulnérabilités et exceptions. Mensuel : accès, tiers,
rétention et restauration partielle. Après changement majeur/trimestriel : menace,
reprise et incident. Une dérogation contient périmètre, justification, compensation,
décideur, date d’expiration et retest. Aucun risque n’est réputé accepté ici.

Prévoir un canal de signalement contrôlé avant de publier `security.txt` ; aucun
contact fictif n’est affiché. Son périmètre n’autorise ni données réelles, ni DoS,
ni scans de tiers. Fermer un signalement exige correction ou décision documentée,
puis retest ; une réponse au message ne suffit pas.
