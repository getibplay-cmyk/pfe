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

La clé de déduplication est unique par tenant. La commande
`notifications:generate-operational` utilise `firstOrCreate` puis
`insertOrIgnore`, ce qui rend une relance idempotente.

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

Toute création et tout changement d’état de lecture sont audités. L’audit ne
stocke jamais le contenu complet de la notification.
