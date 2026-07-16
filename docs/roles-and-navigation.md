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
