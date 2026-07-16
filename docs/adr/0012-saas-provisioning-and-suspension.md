# ADR 0012 — Provisioning et suspension SaaS

## Statut

Accepté — lot 06D.

## Décision

Les opérations plateforme sont séparées des routes tenant sous `/platform/*` et
exigent un compte `is_platform_admin`. Le provisioning est exécuté dans une
transaction PostgreSQL unique qui crée le tenant, ses paramètres JSON, une
agence initiale active et un Tenant Owner actif.

Le secret initial est généré avec une source aléatoire cryptographique, haché
avant stockage et transmis uniquement dans la réponse immédiate marquée
`Cache-Control: no-store, private`. Il n’est ni envoyé à un service externe, ni
placé en session, audit ou log. L’utilisateur doit le remplacer avant d’accéder
aux routes métier.

La suspension est un état explicite du tenant avec date, motif et acteur. Elle
révoque les sessions du tenant et est vérifiée à trois frontières : connexion,
résolution du contexte tenant et exécution explicite dans `TenantContext`. Une
réactivation restaure l’accès sans modifier les données métier.

Les désactivations d’agences et d’utilisateurs sont logiques. Les dépendances
actives, la dernière agence active et le dernier Tenant Owner actif empêchent
une transition incohérente. Aucune interface du lot ne réalise de suppression
physique de ces ressources.

## Conséquences

- un échec d’un sous-élément annule tout le provisioning ;
- le mot de passe affiché une fois doit être transmis par un canal organisationnel
  adapté, hors RentFleet ;
- la suspension ne remplace pas une politique contractuelle de rétention ;
- invitation par e-mail, SSO, facturation d’abonnement et administration avancée
  de rôles restent hors de ce lot.
