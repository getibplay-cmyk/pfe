# Guide des rôles, menus et actions

La visibilité ci-dessous reflète les permissions serveur du lot 06E. Une agence
affectée réduit toujours les données à cette agence, même si le menu est visible.

| Rôle | Menus principaux | Actions caractéristiques |
|---|---|---|
| Administrateur plateforme | Vue plateforme, tenants | Provisionner, modifier, suspendre et réactiver un tenant |
| Tenant Owner | Tous les modules tenant, entreprise, agences, utilisateurs, audit | Administration tenant et toutes les actions métier permises |
| Agency Manager | Locations, flotte, maintenance, assurance, rapports, administration d’agence | Gérer son agence ; finance sensible en lecture seule |
| Rental Agent | Clients, disponibilité, réservations, contrats, finance en lecture | Créer et traiter la location ; aucune écriture financière sensible |
| Fleet Manager | Véhicules, catégories, réservations en lecture, contrats, maintenance, assurance | Gérer flotte, inspections, dommages et maintenance |
| Accountant | Réservations et contrats en lecture, tarification, finance, rapports | Factures, paiements, allocations, cautions, dépenses et clôture |
| Viewer/Auditor | Modules de consultation, rapports et audit | Lecture seule ; aucune mutation visible ou autorisée |

## Principes d’interface

- `NavigationBuilder` génère les deux surfaces, desktop et mobile.
- Les éléments stables `data-nav-key` permettent des tests sans dépendre du CSS.
- Une action de mutation est rendue uniquement si la policy ou la permission
  correspondante l’autorise ; un appel direct interdit retourne toujours 403.
- Le profil ne propose jamais la modification du rôle, tenant, agence ou statut.
- Les valeurs techniques restent en anglais dans PostgreSQL et sont présentées
  en français par `UiLabel`.

## Séparation financière

Seul le rôle Accountant, ainsi que le Tenant Owner qui possède explicitement
les permissions, voit les actions d’émission, allocation, comptabilisation,
contrepassation, caution, dépense et clôture. Agency Manager, Rental Agent,
Fleet Manager et Viewer/Auditor ne reçoivent pas ces contrôles.

## Groupes de navigation E1

`NavigationBuilder` ordonne les destinations autorisées dans sept groupes
stables : **Vue d’ensemble**, **Exploitation**, **Locations**, **Flotte**,
**Finance**, **Pilotage** et **Administration**. Les groupes vides disparaissent
pour le rôle courant ; aucune entrée factice n’est ajoutée pour remplir un menu.

L’en-tête rappelle l’organisation, l’agence et le rôle. Le menu utilisateur ne
contient que le profil et la déconnexion. Le profil ne permet jamais de modifier
le périmètre, le rôle ou l’état du compte. Le menu mobile est un dialogue avec
fermeture par Échap, boucle de focus et retour du focus sur son déclencheur.

Les routes actives utilisent `aria-current="page"`. Les icônes sont décoratives,
le texte du lien reste toujours présent et les badges combinent libellé et
couleur. Les autorisations serveur restent la référence en cas d’appel direct.

## Gouvernance Lot 06F-F

Le Tenant Owner dispose du menu **Rôles et permissions** pour créer les rôles
personnalisés et du panneau **Délégations par agence**. L’Agency Manager ne voit
pas ces écrans : il utilise uniquement la liste déléguée lors de la création ou
modification d’un utilisateur de sa propre agence. Le plafond de permissions
est recalculé côté serveur à chaque affectation.

La destination **Notifications** est présente pour tous les comptes tenant. Son
contenu est ensuite filtré par tenant, agence et permission. La cloche du header
et le menu mobile utilisent toujours les mêmes autorisations de navigation.
