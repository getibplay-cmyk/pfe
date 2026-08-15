# Fiche de données — mobilité partagée Munich v2

## Source et finalité

Jeu public : *Dataset of Idling Positions and Derived Trips of Shared Mobility Vehicles in Munich, Germany (2023–2025)*, Herbst, Zubareva et Lienkamp, record Zenodo v2, DOI <https://doi.org/10.5281/zenodo.16947276>.

Le record décrit des positions d’arrêt et trajets dérivés, collectés à Munich. RentFleet utilise seulement les trajets Miles et ShareNow pour démontrer une méthode de prévision temporelle. Le benchmark ne mesure aucune performance locale RentFleet.

## Licence et redistribution

Le record consulté le 15 août 2026 n’indique pas de licence dataset. L’article associé est CC BY 4.0. Par prudence :

- `raw_redistribution=false` ;
- les fichiers bruts restent hors Git et ne sont pas recopiés dans le paquet J15‑B ;
- le DOI, les MD5 officiels, les SHA‑256 calculés et les transformations sont conservés ;
- toute redistribution attend une clarification explicite du détenteur des droits.

## Fichiers utilisés

| Fichier | Octets | MD5 du record | SHA‑256 observé |
|---|---:|---|---|
| `trips_miles.parquet.gz` | 104 702 560 | `0e7aa3a5993591d840f010ac23f9513a` | `d3da9bf59ddd1e40d13ff8723b63fe716e209a1e2f6c04b5fd40a2192b829615` |
| `trips_sharenow.parquet.gz` | 31 336 330 | `2dc13ab6d5610b7b390854cea90e48c7` | `ae5fc59f72921b4ff7533cf35a5890e99afb7294ddf907dd21c9a95caa99d64b` |
| `vehicles_miles.parquet.gz` | 51 132 | `422b3110352dfde55d53b40bae813811` | `14881364b17d740680c4d2e487c958d204679fb859cd53e27e05a9fc71e9660d` |
| `vehicles_sharenow.parquet.gz` | 19 876 | `367c8c4bca16fc68a0b44a3886c37e5e` | `c4abd552249f31afd36ce91ffab8e4e3ca73e913d615aedcde1a814aa324000a` |

## Population, transformations et qualité

- 3 997 758 trajets bruts, 3 215 212 trajets valides ;
- agrégation déterministe en 123 552 lignes quotidiennes ;
- deux fournisseurs, 216 cellules fixes, 396 dates ;
- cible : nombre de départs observés ;
- les mois de collecte jugés non fiables sont exclus selon le manifeste J2 ;
- les vitesses-proxy extrêmes et jours sans trajet restent des indicateurs de qualité, pas des observations routières corrigées.

L'empreinte du panel transformé reste dans le manifeste privé Drive J15‑B ; elle n'est pas publiée avec la provenance privée.

## Découpage et fuites

Le split est chronologique, en fuseau `Europe/Berlin`, avec test final verrouillé du 2 avril au 31 mai 2025. Les features décalées s’arrêtent strictement avant la cible. Le test final n’a servi ni au choix des variables, ni au choix du modèle, ni au réglage d’hyperparamètres.

## Données sensibles et limites de domaine

Le dataset ne contient pas de client RentFleet, réservation, contrat ou identifiant tenant. Les identifiants véhicule publics ne sont jamais importés dans le SaaS. Toute future donnée RentFleet devra être dérivée côté serveur, pseudonymisée et exempte de secret ou donnée personnelle brute.

Les départs de car-sharing ne couvrent ni demandes refusées, ni réservations futures, ni prix, ni capacité RentFleet. Ils ne peuvent donc pas établir une accuracy locale.
