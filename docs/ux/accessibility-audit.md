# Audit d’accessibilité — Lot 06F-E2

## Méthode

L’audit combine 51 inspections DOM dans Chrome et Edge, des parcours clavier
réels pilotés sans souris, des mesures de contraste à partir des styles calculés
et une inspection visuelle des captures PNG. Il ne constitue pas une
certification WCAG ni un audit avec lecteur d’écran.

## Résultats

| Contrôle | Résultat observé |
|---|---|
| langue | `lang="fr"` sur les pages publiques et authentifiées |
| titres | un h1 visible sur chaque page inspectée |
| landmarks | un `main`, navigation et en-tête présents selon le layout |
| évitement | lien vers `#contenu` utilisable au focus |
| formulaires | aucun contrôle visible sans label après correction |
| interactions | aucun bouton ou lien visible sans nom accessible |
| erreurs | résumé `role="alert"`, `aria-live`, erreur liée par `aria-describedby` |
| focus après erreur | premier champ `aria-invalid` focalisé et ramené dans la vue |
| clavier | login, menu mobile, navigation, filtre, réservation, confirmation, contrat, menu utilisateur et logout réussis |
| Échap | menu mobile et menu utilisateur fermés, focus rendu au déclencheur |
| tableaux | en-têtes présents ; débordements contenus dans un scroller annoncé |
| reflow | aucun élément non confiné hors viewport à 320 px ou au reflow équivalent 200 % |
| ressources | aucun CDN ni ressource distante chargé |
| données sensibles | aucun marqueur secret, chemin privé, snapshot ou trace détecté |

## Contrastes mesurés

Les ratios proviennent des couleurs calculées par le navigateur. Le seuil est
4,5:1 pour le texte normal et 3:1 pour le grand texte.

| Usage représentatif | Texte | Fond | Ratio |
|---|---|---|---|
| titres sombres | `rgb(2, 6, 23)` | `rgb(255, 255, 255)` | 20,17:1 |
| texte principal | `rgb(51, 65, 85)` | `rgb(255, 255, 255)` | 10,35:1 |
| texte secondaire | `rgb(71, 85, 105)` | `rgb(255, 255, 255)` | 7,58:1 |
| texte discret sur fond clair | `rgb(100, 116, 139)` | `rgb(248, 250, 252)` | 4,55:1 |
| lien de marque | `rgb(24, 89, 218)` | `rgb(255, 255, 255)` | 6,03:1 |
| bouton primaire | `rgb(255, 255, 255)` | `rgb(24, 89, 218)` | 6,03:1 |
| texte sidebar corrigé | `rgb(148, 163, 184)` | `rgb(2, 6, 23)` | 7,87:1 |
| texte sidebar principal | `rgb(203, 213, 225)` | `rgb(2, 6, 23)` | 13,59:1 |
| statut succès | `rgb(2, 44, 34)` | `rgb(236, 253, 245)` | 14,38:1 |

Le seul écart initial mesuré était `rgb(100, 116, 139)` sur
`rgb(2, 6, 23)`, soit 4,24:1. Il concernait le pied de page de connexion et les
titres de groupes de la sidebar. Le passage à `slate-400` donne 7,87:1. Les 51
audits finaux enregistrent une liste de violations vide.

## Parcours clavier

1. La connexion est réalisée par saisie puis Entrée ; l’anneau de focus calculé
   est présent.
2. Le menu mobile s’ouvre par Entrée, piège Tab dans le dialogue, se ferme par
   Échap et rend le focus au bouton hamburger.
3. Le lien Réservations est activé par Entrée depuis le menu.
4. Le filtre de liste est soumis par Entrée.
5. Une fiche contrat est ouverte par Entrée depuis sa liste.
6. Le formulaire de réservation est soumis invalide puis valide par Entrée.
7. La confirmation et l’annulation sont acceptées au clavier dans le scénario QA.
8. Le menu utilisateur s’ouvre et se ferme par Échap avec restitution du focus.
9. La déconnexion est activée par Entrée.

Aucun piège clavier n’a été observé. Les tests n’ont pas couvert la restitution
vocale d’un lecteur d’écran, indisponible dans l’environnement.

## Corrections appliquées

- contraste du texte secondaire sur fond sombre ;
- labels explicites sur les filtres assurance, documents privés, motif de rejet
  client, motifs de contrepassation et champs de retour du contrat ;
- libellé français du type d’inspection dans le formulaire de photo privée ;
- restitution de focus du menu utilisateur sans collision avec le menu mobile ;
- erreurs de réservation par champ, focus automatique et traduction française ;
- tableaux Finance, Maintenance et Utilisateurs contenus et annoncés sur mobile ;
- formulaire de contrepassation flexible à 320 px ;
- confinement horizontal du document en conservant les scrollers internes.

## Limites

Firefox, axe, Lighthouse et lecteur d’écran ne sont pas disponibles. Les
contrôles visuels ne remplacent pas un audit humain avec technologies
d’assistance. Ces limites sont conservées dans la preuve, sans revendication de
conformité formelle WCAG AA.
