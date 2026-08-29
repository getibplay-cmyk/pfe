# Base homogène `rentfleet_demo_v1`

## Finalité et périmètre

`rentfleet_demo_v1` est le jeu canonique fictif destiné aux tests locaux, aux
rapports, à la démonstration SaaS et au module d’anomalies d’usage. Il complète
les scénarios interactifs des seeders Lot 02 à Lot 05 sans remplacer les
fixtures scientifiques ni introduire de données personnelles réelles.

La source de vérité est le code versionné :

- `database/seeders/RentFleetDemoV1HistoricalSeeder.php` génère les lignes ;
- `docs/demo/rentfleet-demo-v1-manifest.json` fige provenance, contrôles et
  volumes ;
- `tests/Feature/RentFleetDemoV1HistoricalSeederTest.php` vérifie les
  invariants et l’idempotence.

Un dump PostgreSQL n’est volontairement pas distribué. Les champs d’identité
et de permis existants sont chiffrés avec l’`APP_KEY` locale ; un dump portable
couplerait donc les données à un secret et serait moins sûr que le seeder.

## Résultat généré

Période événementielle UTC : du 1er février 2025 au 18 août 2026.

| Objet | Ajout exact v1 | Usage principal |
|---|---:|---|
| Réservations converties | 240 | activité, rapports, export |
| Contrats | 240 | 120 retournés et 120 clôturés |
| Conducteurs de contrat | 240 | cohérence du cycle |
| Versions et acceptations | 240 + 240 | preuve contractuelle fictive |
| Inspections | 480 | départ et retour pour chaque contrat |
| Éléments d’inspection | 1 440 | carrosserie, habitacle, pneus |
| Blocs véhicule libérés | 240 | historique de disponibilité |
| Factures émises | 180 | 120 payées, 60 partiellement payées |
| Paiements comptabilisés | 180 | finance et rapports en MAD |
| Allocations | 180 | rapprochement facture-paiement |
| Lignes utilisables par l’anomalie | 240 | minimum applicatif : 200 |

Les montants utilisent uniquement des entiers en unités mineures avant leur
conversion en `numeric(14,2)`. Cinq usages atypiques déterministes sont inclus
pour exercer la revue humaine ; ils ne constituent pas des vérités terrain.

## Audit et homogénéisation des fichiers reçus

| Fichier | Lignes | Décision | Motif |
|---|---:|---|---|
| MouvAuto XLSX / CSV | 583 | Profil statistique retenu | Source officielle, Licence Ouverte 2.0 ; 379 couples durée-distance complets |
| CSV pédagogique clients | 19 | Structure seulement | 3 prénoms et 2 permis manquants ; valeurs personnelles non reprises |
| CSV pédagogique propriétaires | 13 | Exclu du modèle métier | 2 e-mails manquants, doublons et aucune entité « propriétaire » dans RentFleet |
| CSV pédagogique véhicules | 20 | Catégories générales seulement | Plaques et rattachements non réutilisés ; flotte RentFleet fictive conservée |
| CSV pédagogique locations | 133 | Mapping structurel seulement | identifiants primaires dupliqués, 124 durées nulles, dates 2000/2010 incompatibles avec les années 2015–2017 |

Les jointures des quatre CSV pédagogiques étaient techniquement complètes, mais
leur origine et leur licence n’ont pas pu être vérifiées. Aucun nom, e-mail,
adresse, téléphone, permis ou numéro d’immatriculation de ces fichiers n’est
copié dans la base finale.

### Mapping de domaine

| Concept source | Cible RentFleet | Règle v1 |
|---|---|---|
| Client | `customers`, `drivers` | réutilise exclusivement les personnes fictives `@example.test` déjà seedées |
| Automobile | `vehicles`, `vehicle_categories` | réutilise la flotte fictive et ses clés tenant/agence |
| Location | `reservations`, `rental_contracts` | un cycle converti, retourné puis éventuellement clôturé |
| Durée / distance | kilométrage, période, carburant | profils déterministes dérivés des 379 couples MouvAuto complets |
| Disponibilité | `vehicle_blocks` | un bloc contractuel libéré ; jamais de champ `available` |
| Paiement | `invoices`, `payments`, `payment_allocations` | 120 soldés, 60 partiels, 60 sans facture |
| Propriétaire automobile | aucune | concept hors périmètre du SaaS B2B actuel |

## Sources publiques évaluées

### Source retenue

- [Suivi de la location des automobiles MouvAuto](https://www.data.gouv.fr/datasets/suivi-de-la-location-des-automobiles-mouvauto), producteur : Communauté d’Agglomération du Pays de Saint-Omer, Licence Ouverte 2.0. La v1 utilise uniquement des profils statistiques durée-distance, jamais des personnes ni des véhicules réels.

### Sources évaluées puis non mélangées

- [Car sharing bookings in Turin](https://data.mendeley.com/datasets/drtn5499j2/1), CC BY 4.0 : données anonymisées mais service en libre-service avec coordonnées géographiques, donc domaine et géographie trop éloignés du cycle RentFleet.
- [National Travel Survey — ad hoc analyses](https://www.gov.uk/government/statistical-data-sets/ad-hoc-national-travel-survey-analysis) : statistiques de déplacements générales, pas des contrats de location.

L’absence d’une origine vérifiable pour les quatre CSV pédagogiques empêche
leur publication dans GitHub ou Drive. Le manifeste public conserve seulement
leurs types, leurs volumes et les résultats d’audit agrégés, sans nom de fichier
ni empreinte issue d’une pièce jointe privée.

## Installation locale sûre

### Validation reproductible dans `rentfleet_test`

Dans `.env.testing`, conserver `APP_ENV=testing`, `DB_CONNECTION=pgsql` et le
nom exact `rentfleet_test`. Injecter `DEMO_PASSWORD` depuis un gestionnaire de
secrets, puis :

```powershell
php artisan migrate:fresh --seed --env=testing --no-interaction
php artisan test --filter=RentFleetDemoV1HistoricalSeederTest
php artisan rentfleet:doctor --env=testing
```

Le garde-fou du projet refuse toute autre cible destructive.

### Première installation dans une base dédiée `rentfleet_demo`

Créer une base PostgreSQL vide dédiée et utiliser un fichier d’environnement
local non versionné. Définir `DB_DATABASE=rentfleet_demo`, conserver une valeur
forte dans `DEMO_PASSWORD`, puis vérifier visuellement la cible avant toute
écriture :

```powershell
php artisan db:show
php artisan rentfleet:demo:install
php artisan rentfleet:doctor --expect-database=rentfleet_demo
```

La commande exécute les migrations puis le `DatabaseSeeder` dans l’ordre requis.
Les migrations seules produisent volontairement un schéma vide ; les données de
démonstration restent séparées afin de ne jamais atteindre la production. Le
raccourci `composer demo:install` produit le même résultat.

Ne jamais lancer `migrate:fresh`, `db:wipe`, `migrate:reset` ou
`migrate:refresh` sur `rentfleet` ou `rentfleet_demo`.

### Enrichissement d’une base de démonstration déjà seedée

Sauvegarder la base, confirmer qu’elle contient déjà les Lots 02 à 05, puis
exécuter uniquement le seeder idempotent :

```powershell
php artisan db:show
php artisan db:seed --class='Database\Seeders\RentFleetDemoV1HistoricalSeeder' --force
```

Une seconde exécution n’ajoute aucune ligne `RES-DEMO-V1-*`.

## Contrôles attendus

```powershell
vendor/bin/pint --test
php artisan test
npm run build
```

Contrôles fonctionnels à réaliser dans l’interface :

1. filtrer les rapports entre février 2025 et août 2026 ;
2. vérifier les factures payées et partiellement payées en MAD ;
3. exporter les réservations sans donnée d’identité ni permis ;
4. préparer un export d’anomalies couvrant les deux agences Atlas et constater
   au moins 240 lignes éligibles ;
5. confirmer qu’un utilisateur Rif ne voit aucune ligne Atlas.

## Limites assumées

- les fréquences sont plausibles et reproductibles, pas représentatives du
  marché marocain réel ;
- les profils MouvAuto proviennent d’un service d’autopartage et sont adaptés
  à des cycles de un à trois jours ;
- aucun document d’identité réel, photo, dommage réel ou paiement externe
  n’est fourni ;
- le tenant Rif reste un tenant d’isolation léger, tandis que l’historique
  analytique v1 appartient au tenant Atlas.
