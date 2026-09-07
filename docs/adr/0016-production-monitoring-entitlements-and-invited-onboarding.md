# ADR 0016 — Supervision, droits de plan et accueil invité

## Statut

Accepté — finalisation SaaS.

## Décision

La supervision utilise les composants déjà présents : Laravel Scheduler,
PostgreSQL, journaux applicatifs et file `database`. Une collecte planifiée
contrôle le heartbeat du scheduler, le volume et l’ancienneté de la file, les
échecs, les callbacks CMI refusés, les paiements CMI non rapprochés et l’espace
disque. Elle ouvre, réouvre ou résout un incident persistant et ajoute un
événement de transition immuable. Les résumés ne contiennent ni payload, chemin,
secret, donnée de carte ou trace d’exception.

Les quotas et capacités sont stockés sur chaque plan puis copiés dans
l’abonnement. Cette copie est contractuelle et immuable : une modification du
plan n’altère jamais les clients déjà abonnés. Les comptes historiques qui n’ont
jamais possédé d’abonnement restent sans limite afin d’éviter une rupture lors du
déploiement. Dès qu’un historique d’abonnement existe, l’absence d’abonnement
utilisable bloque toute nouvelle consommation sans supprimer les données.

La création et la réactivation d’agences, d’utilisateurs et de véhicules ainsi
que chaque nouvelle analyse sont contrôlées dans la transaction métier avec un
verrou consultatif PostgreSQL. Un assistant intelligent doit passer trois portes :
activation globale/runtime, autorisation opérationnelle du tenant et inclusion
contractuelle avec quota mensuel disponible.

L’inscription publique reste absente. Un administrateur plateforme émet une
invitation personnelle rattachée à un plan et à une durée d’essai. Le jeton
aléatoire n’est conservé que sous forme SHA-256 et n’est ni audité ni journalisé.
L’e-mail est envoyé de façon synchrone afin que le jeton brut ne soit jamais
sérialisé dans la file de travaux. Le lien signé expire, n’est utilisable qu’une
fois et crée atomiquement le tenant,
l’agence initiale, le propriétaire vérifié, les accès inclus dans le plan et
l’abonnement d’essai. Le mot de passe est choisi par le destinataire et haché
avant stockage. Les réponses du parcours interdisent le référent et la mise en
cache. Un écran guidé calcule ensuite sa progression depuis les données réelles,
sans état déclaratif falsifiable.

## Conséquences

- aucun service de monitoring ou de file supplémentaire n’est requis ;
- le scheduler et un worker de queue supervisé sont obligatoires en production ;
- les seuils opérationnels sont configurables par environnement ;
- une alerte persistante nécessite encore une procédure humaine d’escalade ;
- modifier un quota de plan n’agit que sur les futurs abonnements ;
- une invitation dont l’envoi SMTP échoue est révoquée immédiatement ;
- les invitations terminales et leurs identités ne peuvent pas être réécrites ou
  supprimées physiquement.
