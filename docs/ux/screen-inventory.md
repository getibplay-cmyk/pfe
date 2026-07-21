# Inventaire des écrans — Lot 06F-E1

Les rôles indiqués sont des familles de visibilité ; les policies et le
périmètre agence restent déterminants pour chaque enregistrement.

| Domaine | Route ou écran | Rôles | État avant E1 | Correction E1 | À valider en E2 |
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
