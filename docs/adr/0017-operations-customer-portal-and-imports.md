# ADR 0017 — Planning, portail locataire et import initial

Les parcours et l'envoi explicite du lien par e-mail sont complétés par l'ADR 0018.

## Demande et décisions

Le lot demandé complète huit axes : isolation tenant, abonnements, planning de flotte,
actions du jour, fluidité des formulaires, import initial, portail locataire et rentabilité.
Il étend le monolithe Blade/PostgreSQL après les PR #31 et #32, sans nouveau package.
Les comptes collaborateurs, rôles et circuits financiers restent ceux du projet.

## Isolation

Le trait `BelongsToTenant` refuse désormais la modification ou suppression d'une instance
chargée sous une autre entreprise et le changement de son tenant. Cette défense protège
les écritures Eloquent par instance ; les écritures SQL directes nécessitent toujours un
périmètre explicite et les contraintes relationnelles existantes. Les nouvelles tables
ont des clés étrangères composites tenant/ressource. Les filtres d'agence passent par
le contexte serveur. Les pages privées sont `no-store`.

## Portail locataire

Un collaborateur autorisé sur la fiche client crée un lien signé de 48 heures après
confirmation de son mot de passe. Le lien est montré une fois et transmis personnellement
par l'agence ; aucun e-mail n'est envoyé automatiquement par cette fonctionnalité.
Le GET ne consomme pas le lien. Un POST protégé CSRF l'échange atomiquement contre une
session serveur de 30 minutes, avec preuve aléatoire dont seule l'empreinte est stockée
dans la table d'accès. Toute réémission révoque les précédents liens et sessions.

Chaque requête recontrôle l'expiration, la révocation, l'entreprise, l'agence, le client
et les droits actuels de l'émetteur. Le contexte vient du droit d'accès conservé en base,
jamais d'un paramètre de tenant ou d'agence. Le client n'obtient aucun compte collaborateur.
Toutes les requêtes métier du portail vérifient aussi `customer_id` et `agency_id` :
l'isolation entreprise seule ne protège pas deux locataires d'une même agence.

Le portail affiche les réservations confirmées ou finalisées, les contrats acceptés et
les factures émises non annulées. Les contrats attachés à une version courante verrouillée
se téléchargent via le stockage privé. Les factures s'affichent dans une vue imprimable
permettant « Enregistrer en PDF » dans le navigateur. Les documents déposés restent privés,
sans valider automatiquement l'identité du client. Le portail n'expose pas les notes internes,
les instantanés JSON, les documents d'autres clients ni un historique de documents tiers.
Un lien transféré donne accès à son détenteur : l'agence doit vérifier son destinataire.

## Imports

CSV UTF-8 uniquement : véhicules et clients particuliers, 200 lignes / 1 Mo. Les en-têtes
peuvent apparaître dans les 20 premières lignes ; séparateurs virgule, point-virgule ou tabulation.
Le schéma est volontairement fixe. L'aperçu est chiffré en base, appartient à son auteur
et expire après une heure. La confirmation revalide les données et quotas sous transaction
et verrou tenant ; une erreur annule tout le lot. La relance d'un même import terminé ne
recrée rien. Les doublons sont signalés, sans fusion automatique. Le contenu est effacé à
la confirmation ou par la purge horaire après expiration. Aucune donnée de ligne n'est auditée.

## Planning et rentabilité

Le calendrier lit les blocs actifs avec intersection stricte `[début, fin)`, sur 7, 14
ou 28 jours et 20 véhicules par page. L'absence de bloc ne garantit pas à elle seule
qu'un véhicule est réservable. Aucun déplacement graphique ne modifie les réservations.

La rentabilité complète `BuildMinimalReport` et partage son contrôle de périmètre. Les
agrégats factures, allocations et dépenses sont indépendants pour éviter leur multiplication
par jointure. Une dépense liée à une maintenance n'est pas additionnée au coût du bon
de maintenance. Les coûts sans affectation sont signalés. Chaque devise reste séparée.
La marge affichée est partielle : factures émises moins dépenses approuvées enregistrées.
Elle ne constitue ni un bénéfice comptable, ni une ventilation des coûts absents.

## Abonnements

Les factures immuables, changements de formule, quotas, callbacks CMI et renouvellements
consentis de la PR #32 sont conservés. Le tableau de bord signale les droits indisponibles
et les échéances proches aux propriétaires. La recette SMTP/CMI réelle reste conditionnée
aux accès correspondants ; aucun secret, débit ou activation de production n'est ajouté.

## Vérification

`SaasOperationsImprovementsTest` vérifie les frontières tenant/locataire/agence, les liens
à usage unique, les sessions révoquées ou expirées, les fichiers privés, l'import atomique,
les doublons et la purge, les bornes du calendrier et les montants exacts par devise.
La suite PostgreSQL existante et les tests JavaScript constituent les gates de livraison.
