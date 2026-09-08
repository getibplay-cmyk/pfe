# Utiliser les améliorations opérationnelles

## Mise à jour

Après récupération de la branche ou de la version fusionnée :

```powershell
composer install
npm ci
php artisan migrate
php artisan optimize:clear
npm run build
```

Les migrations ciblent la base configurée localement. Effectuer la sauvegarde habituelle
avant migration d'une base utilisée. Aucun nouveau package ni fichier de modèle IA n'est requis.
Le scheduler existant exécute la purge des aperçus d'import expirés.

## Écrans

| Fonction | Accès | Utilisation |
|---|---|---|
| Priorités | Tableau de bord | Ouvrir les départs, retours, factures échues et interventions |
| Abonnement | Administration → Abonnement | Consulter droits, quotas, factures et changements de formule |
| Planning | Parc automobile → Planning de flotte | Choisir agence, date et 7/14/28 jours ; ouvrir une occupation |
| Import initial | Démarrage guidé → Importer mes données | Télécharger le modèle, téléverser le CSV, vérifier et confirmer |
| Portail | Clients → fiche client → Gérer l'accès au portail locataire | Créer un lien personnel ou révoquer les accès |
| Rentabilité | Finance → Rentabilité des véhicules | Choisir la période et la devise ; comparer facturé, encaissé et dépenses |

La rentabilité exige les permissions `report.view`, `invoice.view` et `expense.view`.
Les menus desktop et mobile utilisent le même constructeur de navigation.

## Import

Le modèle véhicules contient : `immatriculation;marque;modele;categorie;carburant;transmission;kilometrage`.
`categorie` correspond au code d'une catégorie active déjà créée. Les montants et identités
sensibles ne font pas partie de cet import. Le modèle clients concerne les particuliers :
`prenom;nom;email;telephone`. Toute erreur ou tout doublon bloque l'ensemble du fichier.

L'aperçu expire après une heure. Les limites de la formule SaaS sont revérifiées lors de la
confirmation, même si l'aperçu avait été accepté. Si le quota est dépassé au milieu du lot,
tous les enregistrements du lot sont annulés dans la même transaction.

## Portail

Le collaborateur confirme son mot de passe avant de créer un lien. Le client utilise ce
lien une fois, puis conserve sa session pendant 30 minutes. Demander un nouveau lien
après expiration. Les fichiers sont limités à 5 Mo et 10 dépôts par client sur 24 heures.
La réception d'un justificatif ne remplace pas sa vérification par l'agence.

## Limites de lecture financière

La marge connue n'inclut ni frais généraux non affectés, ni amortissements, ni coûts non
enregistrés. Elle est séparée des encaissements. Les devises ne sont jamais additionnées.
Le rapport considère les véhicules actuellement rattachés au périmètre sélectionné ;
les rapports historiques globaux restent disponibles dans le reporting existant.

## Contrôles

```powershell
php artisan test --filter=SaasOperationsImprovementsTest
php artisan test
vendor\bin\pint --test
npm run test:js
npm run build
```

Le paiement CMI et l'envoi SMTP réels nécessitent toujours la recette de l'environnement
marchand décrite dans les guides SaaS précédents.
