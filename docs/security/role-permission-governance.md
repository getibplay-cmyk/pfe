# Gouvernance des rôles et permissions

## Modèle d’autorité

Les rôles système globaux restent protégés et immuables. Le Tenant Owner peut
créer des rôles personnalisés propres à son tenant, choisir leurs permissions
métier et les désactiver. Leur identifiant technique, leur tenant et leur nature
système ne sont jamais soumis par le navigateur.

Les permissions `platform.*`, le groupe `platform`, `role.manage` et
`role.delegate` sont interdites aux rôles personnalisés par l’application et
par un trigger PostgreSQL. Les collisions de nom insensibles à la casse sont
refusées par un index tenant-scopé.

## Délégation par agence

`role_agency_delegations` contient la liste explicite des rôles autorisés dans
chaque agence. Un Agency Manager peut affecter un rôle uniquement lorsque :

1. le rôle est actif et explicitement délégué à son agence ;
2. il n’est ni Platform Admin ni Tenant Owner ;
3. toutes ses permissions appartiennent au plafond de permissions du manager ;
4. l’utilisateur cible reste dans la même agence ;
5. le manager ne se modifie pas lui-même.

Une requête forgée avec `tenant_id`, une autre agence ou un rôle supérieur est
refusée côté serveur. Un rôle personnalisé disposant explicitement de
`user.manage` peut modifier les informations et l’état d’un compte de son tenant,
mais ne peut changer ni son rôle ni son agence.

## Cycle d’un rôle personnalisé

Il n’existe aucune route de suppression. Une désactivation avec utilisateurs
affectés exige un rôle de remplacement actif ; le remplacement et la
désactivation sont transactionnels. La clé étrangère `users.role_id` interdit
également la suppression SQL d’un rôle encore affecté.

Création, permissions, remplacement, délégations, affectations, activation,
désactivation et refus significatifs produisent des audits sans secret.
