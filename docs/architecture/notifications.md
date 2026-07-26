# Centre de notifications internes

## Périmètre

RentFleet fournit uniquement des notifications internes. Aucun e-mail, SMS,
push externe ou URL libre n’est stocké. La cloche du header, l’aperçu et la
page paginée utilisent la même source `NotificationInbox`.

## Stockage et isolation

- `internal_notifications` porte le tenant, l’agence concernée, la catégorie,
  la priorité, un résumé non sensible, le type et l’identifiant de ressource ;
- `internal_notification_recipients` porte le destinataire et son état de lecture ;
- les clés étrangères composites et triggers PostgreSQL refusent un
  destinataire d’un autre tenant ou d’une autre agence ;
- `TenantContext`, le global scope, la permission requise et l’agence du compte
  sont tous contrôlés lors de la lecture ;
- le Platform Admin n’utilise pas ce centre tenant.

La clé d’incident est stockée dans `deduplication_key` et reste unique par
entreprise. Elle identifie la cause fonctionnelle, jamais une date d’échéance ou
une priorité. `created_at` conserve la première détection, `last_detected_at`
la dernière observation, `due_at` l’échéance évolutive et
`occurrence_count` le nombre de réapparitions pertinentes.

La commande `notifications:generate-operational` sérialise chaque entreprise
avec un verrou transactionnel PostgreSQL, verrouille les notifications
existantes, puis crée ou actualise l’incident. Une relance identique ne crée
aucun doublon. Une cause disparue renseigne `resolved_at` et le code non
sensible `cause_disparue`. Sa réapparition réactive la même ligne, incrémente
le compteur une fois et la remet en non-lu pour les destinataires autorisés.
L’index unique `(tenant_id, deduplication_key)` reste l’arbitre final en cas de
concurrence.

L’état de lecture est propre à chaque destinataire et reste indépendant de
l’état actif/résolu. Les notifications résolues sortent du compteur et de
l’aperçu actifs, mais restent consultables avec le filtre d’historique.

## Destinations sûres

`NotificationDestination` est une liste fermée de couples modèle/route :
réservation, contrat, police d’assurance, maintenance et facture. La ressource
est rechargée dans le contexte courant, sa policy ou sa permission est vérifiée
et l’agence est comparée au compte. Le navigateur ne fournit ni URL, ni tenant,
ni type polymorphe.

## Alertes générées

La commande planifiée toutes les quinze minutes couvre les réservations en
attente, expirantes, annulées ou expirées, les prochaines actions et retours de
contrat, les assurances à échéance, les maintenances planifiées ou en retard,
les factures avec solde et les cautions à encaisser ou régulariser. Les titres
et résumés excluent identité, document, mot de passe, token et référence privée.

Création, évolution, résolution, réactivation et changement d’état de lecture
sont audités. L’audit ne stocke jamais le contenu complet de la notification.

## Migration G2

`2026_07_30_000001_add_internal_notification_lifecycle` ajoute uniquement les
colonnes de cycle, leurs contrôles et deux index partiels PostgreSQL. Elle ne
supprime ni ne renomme aucune donnée existante.
