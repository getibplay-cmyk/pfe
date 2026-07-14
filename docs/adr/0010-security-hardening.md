# ADR 0010 — Socle de sécurité de la release candidate

## Statut

Accepté — lot 06.

## Décision

La release candidate conserve l’authentification Breeze, le throttling de
connexion, la régénération de session, les protections CSRF et l’échappement
Blade existants. Deux middlewares globaux ajoutent une défense HTTP homogène :

- un identifiant de corrélation UUID est généré ou repris uniquement s’il est
  valide, ajouté au contexte de log et renvoyé dans `X-Correlation-ID` ;
- les réponses portent `nosniff`, `DENY`, une politique de référent sobre, une
  politique de permissions restrictive et l’isolation d’ouverture de fenêtre.

HSTS est envoyé uniquement en environnement `production` et sur une requête
HTTPS. Une CSP stricte est reportée : Livewire et Vite exigent d’abord un
inventaire précis des scripts et styles, et une politique improvisée risquerait
de casser l’interface sans améliorer la démonstration.

Les erreurs 403, 404, 419, 422 et 500 utilisent des pages génériques sans trace
ni détail interne. Le health check journalise seulement un événement générique
si PostgreSQL est indisponible. L’audit filtre récursivement les secrets,
identifiants, données de carte et références sensibles.

## Conséquences

- les réponses web ont un socle vérifiable sans dépendance externe ;
- les événements d’une requête peuvent être corrélés sans exposer son contenu ;
- les cookies sécurisés, le chiffrement de session et HTTPS restent des
  paramètres obligatoires du fichier de production ;
- la terminaison TLS et la rotation des secrets relèvent de l’exploitation.
