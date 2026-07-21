# Matrice de validation navigateur — Lot 06F-E2

## Environnement réellement exécuté

- base QA gardée côté Laravel et PostgreSQL : `rentfleet_test` ;
- Google Chrome `150.0.7871.129`, Chromium système, headless ;
- Microsoft Edge `150.0.4078.83`, Chromium système, headless ;
- Playwright Python `1.54.0`, déjà présent avant E2 ;
- 1440 × 900, 1024 × 768, 768 × 1024, 390 × 844 et 320 × 720 ;
- reflow équivalent à 200 % : viewport CSS 720 × 450 sur les parcours essentiels.

Le rapport machine complet est conservé dans
`docs/evidence/browser-data/lot06f-e2-browser-results.json`. Les contrôles
clavier ci-dessous sont pilotés par Playwright avec de vrais événements
Tabulation, Entrée, Espace et Échap, sans clic pour les étapes indiquées.

## Écrans et parcours

| Écran | Route | Rôle | Navigateurs | Viewport / zoom | Clavier et accessibilité | Responsive | Résultat | Défaut et correction | Capture |
|---|---|---|---|---|---|---|---|---|---|
| Connexion | `/login` | public | Chrome, Edge | desktop, mobile, 200 % équivalent | labels, autocomplete, focus, afficher/masquer, Entrée | sans débordement | réussi | contraste secondaire 4,24:1 porté à 7,87:1 | `01-login-*` |
| Mot de passe oublié/reset | `/forgot-password`, `/reset-password/{token}` | public | Chrome | desktop | champs nommés, mot de passe masqué | exploitable | réussi | aucune correction supplémentaire | — |
| Session expirée | POST `/login` sans CSRF | public | Chrome | desktop | page française, sans trace | carte centrée | réussi | aucune fuite ; référence de corrélation seulement | `17-erreur-419.png` |
| Dashboard | `/dashboard` | Tenant Owner | Chrome, Edge | tous les viewports | h1, main, navigation active | cartes empilées à 320 px | réussi | aucun débordement global | `02-dashboard-*` |
| Navigation mobile | shell | Tenant Owner | Chrome | 390 × 844 | ouverture Entrée, piège Tab, fermeture Échap, retour du focus | panneau défilable | réussi | collision Échap corrigée | `13-navigation-mobile-ouverte.png` |
| Réservations | `/reservations` | Tenant Owner | Chrome, Edge | tous les viewports | filtre et ouverture au clavier | table défilable | réussi | aucun défaut restant | `03-reservations-*` |
| Disponibilité/création | `/availability`, `/reservations/create` | Tenant Owner | Chrome | desktop | invalide puis valide, erreur liée, focus, Entrée | champs bornés | réussi | erreurs par champ, focus et traduction française ajoutés | `14-reservation-validation.png` |
| Fiche réservation | `/reservations/{id}` | Tenant Owner | Chrome | desktop, mobile | confirmation et annulation par Entrée | actions accessibles | réussi | scénario QA éligible sélectionné côté serveur | `04-reservation-*` |
| Contrats | `/contracts`, `/contracts/{id}` | Tenant Owner | Chrome, Edge | desktop, mobile, 320 px, 200 % | ouverture au clavier, h1 unique, timeline, champs de retour étiquetés | reflow sans sortie globale | réussi | contrepassation flexible ; labels dommage/nettoyage/retour et type d’inspection traduit | `05-contract-*` |
| Véhicule | `/vehicles/{id}` | Tenant Owner | Chrome | desktop, mobile | métadonnées et actions nommées | fiche empilée | réussi | aucun défaut restant | `06-vehicle-*` |
| Client/conducteurs | `/customers/{id}` | Tenant Owner | Chrome | desktop, mobile | identité masquée, fichiers et rejet étiquetés | actions flexibles | réussi | labels de fichier et motif ajoutés | `07-customer-*` |
| Finance | `/finance` | Tenant Owner | Chrome, Edge | desktop, mobile, 320 px, 200 % | en-têtes et statuts textuels | scrollers internes annoncés | réussi | confinement horizontal et indication mobile ajoutés | `08-finance-*` |
| Maintenance | `/maintenance` | Tenant Owner | Chrome | desktop, mobile | table avec en-têtes | composant responsive partagé | réussi | table migrée vers `responsive-table` | `09-maintenance-*` |
| Assurance | `/insurance` | Tenant Owner | Chrome | desktop, mobile | six filtres associés à des labels | cartes et table utilisables | réussi | labels de recherche/statut/type ajoutés | `10-insurance-*` |
| Rapports | `/reports` | Tenant Owner | Chrome | desktop, mobile | filtres et montants nommés | sans débordement | réussi | formules D1 inchangées | `11-report-*` |
| Utilisateurs | `/users` | Tenant Owner | Chrome | desktop, mobile | filtres et table avec en-têtes | scroller annoncé | réussi | table migrée vers `responsive-table` | `12-administration-*` |
| Profil | `/profile` | sept rôles | Chrome | desktop | h1 unique, rattachements en lecture seule | cartes adaptatives | réussi | assertion de test normalisée | — |
| Erreur interdite | `/reservations/create` | Viewer/Auditor | Chrome | desktop | message français et référence | carte centrée | réussi | aucune trace ni SQLSTATE | `16-erreur-403.png` |
| Erreur absente | route inexistante | Tenant Owner | Chrome | desktop | message français et référence | carte centrée | réussi | aucune trace ni SQLSTATE | `15-erreur-404.png` |

## Matrice des rôles

| Rôle | Arrivée | Contexte vérifié | Navigation desktop/mobile | Refus direct | Déconnexion |
|---|---|---|---|---|---|
| Platform Admin | `/platform/dashboard` | administration plateforme | 2 entrées identiques | dashboard tenant : 403 | réussi |
| Tenant Owner | `/dashboard` | Atlas Location Démo, toutes agences | 18 entrées identiques | plateforme : 403 | réussi |
| Agency Manager | `/dashboard` | Atlas, Casablanca Centre | 17 entrées identiques | ressources Rabat : 403/404 | réussi |
| Rental Agent | `/dashboard` | Atlas, Casablanca Centre | 11 entrées identiques | plateforme : 403 | réussi |
| Fleet Manager | `/dashboard` | Atlas, Casablanca Centre | 11 entrées identiques | plateforme : 403 | réussi |
| Accountant | `/dashboard` | Atlas, Casablanca Centre | 8 entrées identiques | plateforme : 403 | réussi |
| Viewer/Auditor | `/dashboard` | Atlas, Casablanca Centre | 15 entrées identiques | création réservation : 403 | réussi |

Un compte du tenant Rif a aussi tenté les liens Atlas collectés dans les listes :
chaque accès direct a retourné 403 ou 404. Aucun rôle n’a reçu une destination
interdite définie par la matrice de contrôle E2.

## Limites exactes

- Firefox n’est pas installé ; aucune validation Firefox n’est revendiquée.
- Aucun lecteur d’écran pilotable n’est disponible.
- axe et Lighthouse ne sont pas installés ; aucun score de ces outils n’est revendiqué.
- Le contrôle 200 % est un test automatisé de reflow par viewport CSS divisé
  par deux, pas une manipulation du zoom natif dans une fenêtre visible.
- Les captures ont été produites par les moteurs réels en mode headless, puis
  inspectées visuellement ; aucune retouche ni simulation n’a été utilisée.
