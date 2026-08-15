# Datasheet — Hotel Booking Demand pour RentFleet

## Motivation et provenance

Le dataset a été créé pour mettre à disposition des données hôtelières réelles
pour la recherche et l’enseignement. Il provient des systèmes de gestion de
deux hôtels au Portugal. Les auteurs indiquent avoir supprimé les éléments
d’identification des hôtels et des clients.

- Auteurs : Nuno António, Ana de Almeida, Luis Nunes.
- Article primaire : <https://doi.org/10.1016/j.dib.2018.11.126>.
- Licence : [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
- Snapshot utilisé : miroir TidyTuesday épinglé au commit
  `1f5a20eae51d871ec4ac0f95d16e43b9ba3f1dec`.
- SHA-256 :
  `7c2ae42a7353905ea136e5c2287f17c92c5435826598bfbb8491c6f0c7b1fc06`.

## Composition

- 119 390 observations et 32 colonnes dans le snapshot combiné.
- 40 060 réservations resort et 79 330 réservations city hotel.
- Arrivées planifiées du 1er juillet 2015 au 31 août 2017.
- Réservations arrivées, annulées et no-show.
- Pas d’identifiant stable de réservation dans la publication.
- 40 165 lignes participent à un groupe de doublons exacts. Elles sont
  conservées : sans identifiant source, une déduplication pourrait supprimer de
  vraies réservations distinctes partageant les mêmes attributs.

## Traitement RentFleet

Le script vérifie l’empreinte et le schéma, reconstruit les dates, forme la
cible de recherche annulation/no-show, puis exclut 715 séjours de durée nulle.
Le résultat contient 118 675 lignes. Aucune imputation statistique apprise sur
le futur n’est réalisée.

Les colonnes d’identité ou sans équivalent local (`country`, `agent`,
`company`) ne sont pas utilisées. Les variables postérieures à l’issue sont
explicitement interdites. Le mapping complet est versionné dans
`feature-mapping.csv`.

## Usages permis et interdits

Permis : benchmark public, enseignement, comparaison méthodologique, étude de
dérive et résultat négatif reproductible, avec attribution CC BY 4.0.

Interdits dans RentFleet : présenter la performance comme locale, joindre une
prédiction à un client, entraîner avec une donnée personnelle brute, décider
une annulation, un refus, un tarif, une facture ou une réallocation.

## Limites

- Domaine hôtelier et pratiques commerciales non transposables directement.
- Deux établissements et une période historique courte.
- Variables construites au cutoff hôtelier, différent d’un événement
  RentFleet en temps réel.
- Pas de Maroc, de voiture, d’agence de location ni de kilomètre parcouru.
- Distribution et calibration instables dans le dernier bloc temporel.
- Mapping partiel de la caution et des options.

Une future fiche de données locale devra documenter séparément la collecte
RentFleet, le consentement/fondement, la pseudonymisation, les cutoffs, la
qualité par tenant/agence et la période prospective. Ce dataset public ne peut
pas la remplacer.
