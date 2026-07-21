# Système visuel RentFleet

## Intention

RentFleet utilise une interface claire, dense mais lisible, adaptée à un SaaS
B2B de gestion de flotte. Le système privilégie l’information, les statuts et
la traçabilité. Il n’utilise ni CDN, ni police distante, ni image sous licence.

## Identité

- Le logo SVG associe un véhicule et un repère de mobilité. La variante complète
  porte le nom et la signature « Gestion de mobilité » ; la variante compacte
  conserve un nom accessible.
- La police est la pile système `Inter`, `Segoe UI`, puis sans-serif. Aucun
  téléchargement n’est requis.
- Le fond applicatif est clair. La sidebar sombre distingue la navigation de la
  zone de travail sans gradient décoratif.

## Palette

| Usage | Référence | Emploi |
|---|---|---|
| Principal | `brand-700` `#1859da` | actions, liens, focus, route active |
| Accent flotte | `fleet-600` `#0b8277` | mobilité, validation secondaire |
| Neutres | `slate-50` à `slate-950` | fonds, bordures et texte |
| Succès | emerald | action terminée ou état sain |
| Avertissement | amber | prérequis ou attention humaine |
| Danger | red | annulation, rejet, action irréversible |
| Information | blue | contexte sans décision |

Un statut associe toujours un libellé, un point et une couleur. La couleur seule
n’est jamais porteuse de sens.

## Composants canoniques

- `app-shell`, `mobile-navigation`, `navigation-item` : contexte et navigation ;
- `page-header`, `section-card`, `metadata-list`, `timeline` : hiérarchie ;
- `stat-card`, `status-badge`, `empty-state`, `result-count` : données et états ;
- `filter-panel`, `responsive-table` : listes et filtres ;
- `input-label`, `text-input`, `password-field`, `field-error`, `form-errors` :
  formulaires ;
- `primary-button`, `secondary-button`, `danger-button`, `link-button`,
  `confirmation-button`, `action-group` : actions ;
- `flash-message`, `error-page` : retour utilisateur et incidents.

Une nouvelle vue doit composer ces briques avant de créer des classes locales.

## Mise en page et responsive

- largeur de contenu maximale : `max-w-7xl` ;
- cartes : bordure neutre, rayon 16 px et ombre légère ;
- formulaires : une colonne sur mobile, deux à quatre selon le contexte ;
- tableaux : wrapper défilable, indication mobile et actions toujours visibles ;
- sidebar à partir du breakpoint `lg`, dialogue mobile en dessous ;
- zones tactiles principales d’au moins 40 px.

## Formulaires

Chaque champ possède un label visible, une indication textuelle obligatoire, la
bonne valeur `autocomplete`, `old()`, une erreur sous le champ et un résumé en
haut du formulaire. Les montants mentionnent la devise ; les dates indiquent le
fuseau du parcours. `tenant_id` n’est jamais rendu comme champ éditable.

## Accessibilité de base E1

- document français, titre de page et un seul `h1` ;
- lien d’évitement, landmarks et `aria-current` ;
- focus visible, menu mobile pilotable au clavier et fermeture par Échap ;
- `aria-live` pour erreurs et flash ;
- labels associés et erreurs décrites ;
- icônes décoratives masquées aux technologies d’assistance ;
- confirmation explicite avant action sensible.

La mesure formelle WCAG, les tests de zoom et les essais multi-navigateurs sont
réservés à E2.
