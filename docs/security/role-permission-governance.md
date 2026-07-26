# Gouvernance des rôles et permissions

## Modèle d’autorité

Les rôles système globaux restent protégés et immuables. L’administrateur de
l’entreprise peut créer des rôles personnalisés propres à son entreprise,
choisir leurs permissions métier et les désactiver. Leur identifiant technique,
leur entreprise et leur nature
système ne sont jamais soumis par le navigateur.

Les permissions `platform.*`, le groupe `platform`, `role.manage` et
`role.delegate` sont interdites aux rôles personnalisés par l’application et
par un trigger PostgreSQL. Les collisions de nom insensibles à la casse sont
refusées par un index tenant-scopé.

## Délégation par agence

`role_agency_delegations` contient la liste explicite des rôles autorisés dans
chaque agence. Un responsable d’agence peut affecter un rôle uniquement lorsque :

1. le rôle est actif et explicitement délégué à son agence ;
2. il n’est ni administrateur de la plateforme ni administrateur de l’entreprise ;
3. toutes ses permissions appartiennent au plafond de permissions du manager ;
4. l’utilisateur cible reste dans la même agence ;
5. le manager ne se modifie pas lui-même.

Une requête forgée avec `tenant_id`, une autre agence ou un rôle supérieur est
refusée côté serveur. Un rôle personnalisé disposant explicitement de
`user.manage` peut modifier les informations et l’état d’un compte de son tenant,
mais ne peut changer ni son rôle ni son agence.

## Cycle d’un rôle personnalisé

Il n’existe aucune route de suppression. Une désactivation avec utilisateurs
affectés exige une confirmation explicite et un rôle de remplacement actif,
non réservé, du même périmètre, délégué dans chaque agence concernée et dont les
permissions sont un sous-ensemble du rôle retiré. Les utilisateurs, le rôle
source et le rôle cible sont verrouillés ; toutes les affectations réussissent
ou la transaction entière est annulée. La promotion vers administrateur de
l’entreprise est exclue de ce parcours ordinaire.

La migration `2026_07_30_000002_enforce_user_role_assignment_integrity`
précontrôle les données puis installe des triggers PostgreSQL. Ils refusent un
rôle personnalisé inter-entreprises, un rôle inactif, les mélanges
plateforme/entreprise, un rôle d’agence sans agence active et une délégation
dont l’acteur, l’agence ou le rôle est hors périmètre. Un rôle encore affecté
ou délégué ne peut pas être désactivé directement en SQL. Aucun précontrôle
défaillant n’est corrigé silencieusement.

Création, permissions, demande et exécution du remplacement, délégations, affectations, activation,
désactivation et refus significatifs produisent des audits sans secret.
