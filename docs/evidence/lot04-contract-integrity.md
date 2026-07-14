# Preuves d’intégrité — lot 04

## Reproductibilité PostgreSQL

Commande exécutée sur la base séparée `rentfleet_test` :

```powershell
php artisan migrate:fresh --seed --env=testing --no-interaction
```

Résultat observé : les 11 migrations du lot 04 et les seeders des lots 00 à 04
se terminent avec succès. Six scénarios contractuels fictifs sont créés.

## Versionnement et immutabilité

La suite `Lot04RentalContractLifecycleTest` vérifie :

- création automatique de la version 1 et d’une empreinte SHA-256 de 64 caractères ;
- conservation de la version précédente lors d’une correction ;
- verrouillage de la version acceptée ;
- rejet PostgreSQL d’une modification directe de cette version ;
- rejet PostgreSQL d’une modification d’élément d’inspection terminée.

Une empreinte peut être communiquée sans révéler le snapshot personnel. Exemple
de forme :

```text
9f4e2c6a7d8b1e305c4492a6308de3eb469bf942c59be7d6a46a1f5e8c287410
```

## Réutilisation et libération du bloc

Le test de conversion mémorise l’identifiant du bloc de réservation et confirme
que la même ligne porte ensuite `block_type=contract` et `rental_contract_id`.
Aucun bloc supplémentaire n’est inséré.

Le test de retour crée également un bloc futur. Après `MarkRentalReturned`, le
bloc du contrat est `released` tandis que le bloc futur reste `active`. La
contrainte d’exclusion GiST demeure active et n’est jamais contournée.

## Responsabilité humaine

Un dommage nouvellement signalé conserve `responsibility=pending` et ne crée
aucun frais. Le frais dommage n’apparaît qu’après une revue humaine explicite
avec responsabilité client et coût approuvé. Les audits d’acceptation ne
contiennent ni nom du signataire ni identité complète.
