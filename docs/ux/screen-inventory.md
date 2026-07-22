# Inventaire des écrans — Lots 06F-E1 et 06F-E2

Les rôles indiqués sont des familles de visibilité ; les policies et le
périmètre agence restent déterminants pour chaque enregistrement.

| Domaine | Route ou écran | Rôles | État avant E1 | Correction E1 | Plan de validation E2 |
|---|---|---|---|---|---|
| Auth | `/login` | public | Breeze anglais, logo Laravel | identité B2B française, mot de passe visible/masqué, sans register | clavier, zoom, captures |
| Auth | mot de passe oublié/reset | public | texte Breeze anglais | parcours français cohérent | e-mail réel et navigateurs |
| Auth | confirmation/vérification | authentifié | texte d’inscription anglais | contexte de sécurité B2B | focus et lecteurs d’écran |
| Compte | changement initial | tous | formulaire compact sans erreurs par champ | composants mot de passe, résumé et aide | test navigateur complet |
| Compte | profil | tous | cartes disparates | métadonnées en lecture seule et formulaires partagés | responsive réel |
| Shell | sidebar/header/mobile | tous | marque texte, focus mobile incomplet | logo, contexte, groupes, dialogue mobile et skip-link | focus multi-navigateurs |
| Plateforme | dashboard | Platform Admin | statuts bruts | badges, hiérarchie et états vides | captures desktop/mobile |
| Plateforme | tenants | Platform Admin | table large et actions ad hoc | design global, badges et en-tête | table mobile |
| Tenant | dashboard | rôles tenant | sections hétérogènes | cartes et panneaux harmonisés selon permission | densité avec données réelles |
| Administration | entreprise/agences/utilisateurs/audit | rôles autorisés | composants partiels | shell, formulaires et styles globaux | parcours complet par rôle |
| Flotte | véhicules | lecture flotte | table desktop | wrapper global et fiche structurée | débordement liste |
| Flotte | fiche véhicule | lecture flotte | métadonnées essentielles absentes | agence, catégorie, kilométrage, état, documents et blocs | données longues |
| Flotte | catégories/blocs | rôles autorisés | formulaires et tables ad hoc | composants globaux et libellé manual contextualisé | responsive réel |
| Clients | liste/fiche | rôles autorisés | fonctionnel, densité variable | shell et états communs conservant le masquage | documents et longues identités |
| Conducteurs | fiche/formulaire | rôles autorisés | fonctionnel | composants globaux et autorisations conservées | clavier et fichiers |
| Locations | disponibilité | rôles réservation | champs sans labels | filtre étiqueté, fuseau, erreurs et cartes résultat | sélecteurs mobiles |
| Locations | réservations | rôles réservation | « Booking », montants bruts, table large | français, devise, filtres, table responsive et vide | données volumineuses |
| Locations | fiche réservation | rôles réservation | snapshot JSON et actions peu explicites | métadonnées, tarif lisible, timeline et confirmations | cycle réel complet |
| Locations | contrats | rôles contrat | page très dense, statuts techniques | en-tête, prérequis, métadonnées, timeline et montants | tous les statuts du cycle |
| Locations | contrat imprimable | rôles contrat | JSON et empreinte exposés | document lisible sans détail technique | impression A4 réelle |
| Finance | synthèse | rôles finance | sections déjà séparées | système visuel commun conservant la séparation RBAC | jeux multi-devises |
| Finance | facture/paiements | Accountant/Owner, lecture autorisée | statuts et montants bruts | badges, devise et actions distinguées | confirmations navigateur |
| Maintenance | liste/fiche | rôles maintenance | fonctionnel, styles locaux | shell, cartes, statuts et formulaires globaux | timeline chargée |
| Assurance | compagnies/polices/sinistres | rôles assurance | fonctionnel, tables denses | shell, masquage et badges conservés | documents et échéances |
| Pilotage | rapports | rôles report | exact mais dense | présentation harmonisée, formules D1 intactes | impression/zoom/export |
| Erreurs | 403/404/419/422/500 | tous | carte minimale | identité RentFleet et corrélation sans trace | simulation navigateur |

Les anciennes vues `register`, `welcome`, navigation Breeze et composants
orphelins associés ont été supprimés après vérification de leur absence de route
et d’usage.

## État après validation E2

Le plan de la dernière colonne a été exécuté sur Chrome et Edge disponibles.
Les écrans prioritaires ont été capturés en 1440 × 900 et 390 × 844 ; dashboard,
réservations, contrat et finance ont aussi été contrôlés à 1024 × 768,
768 × 1024, 320 × 720 et en reflow équivalent 200 %.

| Groupe | Résultat E2 |
|---|---|
| authentification et erreurs | connexion, reset, confirmation, 403/404/419, focus et messages français réussis |
| shell et rôles | sept rôles, parité desktop/mobile, route active, profil, refus directs et déconnexion réussis |
| locations et contrats | cycle réservation QA complet et sept états contractuels observés |
| flotte, clients et documents | responsive, identité masquée et labels des fichiers validés |
| finance, maintenance, assurance | tableaux internes défilables, montants/devise et restrictions visibles validés |
| rapports et administration | filtres, tables, reflow et absence de champ tenant contrôlable validés |
| accessibilité | 51 audits sans violation majeure finale ; clavier essentiel réussi |

La matrice détaillée se trouve dans `docs/ux/browser-validation-matrix.md` et
les limites dans `docs/ux/accessibility-audit.md`. Firefox, lecteur d’écran,
axe et Lighthouse n’étaient pas disponibles et ne sont pas déclarés validés.

## Extension Lot 06F-F

| Domaine | Écran | Utilisateurs | Résultat |
|---|---|---|---|
| Notifications | cloche, aperçu et `/notifications` | comptes tenant autorisés | centre filtré, paginé, accessible et destinations serveur sûres |
| Administration | `/roles` et délégations | Tenant Owner | rôles personnalisés, matrice de permissions et délégation agence |
| Administration | affectation utilisateur | Tenant Owner et Agency Manager borné | rôles filtrés côté serveur et plafond de permissions contrôlé |
