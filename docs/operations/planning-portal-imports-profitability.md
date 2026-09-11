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
| Priorités | Tableau de bord | Ouvrir tous les dossiers d'une priorité, par pages de 25 |
| Abonnement | Administration → Abonnement | Comparer les tarifs/quotas et consulter l'activation effective, les factures et changements |
| Planning | Parc automobile → Planning de flotte | Filtrer agence, catégorie, statut et immatriculation sur 7/14/28 jours |
| Import initial | Démarrage guidé → Importer mes données | Voir la prochaine étape, reprendre un aperçu, télécharger les erreurs, confirmer ou abandonner |
| Portail | Clients → fiche client → Gérer l'accès au portail locataire | Créer un lien personnel, demander son envoi à l'adresse enregistrée ou révoquer les accès |
| Rentabilité | Finance → Rentabilité des véhicules | Comparer facturé, encaissé et dépenses ; exporter tout le périmètre autorisé en CSV |

La rentabilité exige les permissions `report.view`, `invoice.view` et `expense.view`.
Les menus desktop et mobile utilisent le même constructeur de navigation.
L'export exige aussi `report.export` et se limite à 5 000 véhicules ; sélectionner
une agence pour réduire le périmètre. Les devises et frais non affectés restent distincts.

## Import

Le modèle véhicules contient : `immatriculation;marque;modele;categorie;carburant;transmission;kilometrage`.
`categorie` correspond au code d'une catégorie active déjà créée. Les montants et identités
sensibles ne font pas partie de cet import. Le modèle clients concerne les particuliers :
`prenom;nom;email;telephone`. Toute erreur ou tout doublon bloque l'ensemble du fichier.

L'aperçu expire après une heure. Les limites de la formule SaaS sont revérifiées lors de la
confirmation, même si l'aperçu avait été accepté. Si le quota est dépassé au milieu du lot,
tous les enregistrements du lot sont annulés dans la même transaction.
Le rapport d'erreurs reprend seulement les lignes invalides et leurs motifs.
Après correction, téléverser un nouveau fichier. « Abandonner cet aperçu » efface
immédiatement son contenu ; aucune donnée métier n'a encore été créée.

## Portail

Le collaborateur confirme son mot de passe avant de créer un lien. Le client utilise ce
lien une fois, puis conserve sa session pendant 30 minutes. Demander un nouveau lien
après expiration. Les fichiers sont limités à 5 Mo et 10 dépôts par client sur 24 heures.
La réception d'un justificatif ne remplace pas sa vérification par l'agence.

L'émetteur doit disposer de `customer.update`, `customer.identity.view`,
`contract.view`, `invoice.view` et `document.download`. Un retrait de ces droits
rend les liens et sessions existants inutilisables. Le client voit les coordonnées
de son agence, son échéance de session et le détail de ses factures imprimables.

### Envoi du lien par e-mail

Configurer `MAIL_MAILER=smtp`, le serveur SMTP et l'adresse d'expédition dans
l'environnement (secrets hors Git). Renseigner l'adresse sur la fiche client.
La commande d'envoi utilise exclusivement cette adresse ; un transport `log` ou
`array` masque cette option et refuse la demande côté serveur.

Pour traiter l'envoi en arrière-plan, configurer `QUEUE_CONNECTION=database`
et démarrer un worker supervisé avec la même clé `APP_KEY` que l'application :

```powershell
php artisan queue:work database --queue=default --tries=3 --timeout=30
```

La table des tâches existe déjà. Après déploiement, redémarrer les workers via
`php artisan queue:restart` avec le cache partagé de l'environnement. Le mode
`sync` exécute SMTP dans la requête et n'apporte pas les reprises automatiques.
Le message « mis en file d'attente » confirme la demande, pas la réception.
Surveiller les tâches échouées selon le guide d'exploitation ; les erreurs SMTP
du portail sont génériques et le payload est chiffré. Corriger SMTP avant une reprise.
Un lien expiré, consommé, révoqué, ou dont l'adresse/droits ont changé n'est pas envoyé :
créer alors un nouvel accès. En cas de livraison multiple, il s'agit du même lien
à usage unique. Aucun envoi réel n'est effectué par les tests automatisés.

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
