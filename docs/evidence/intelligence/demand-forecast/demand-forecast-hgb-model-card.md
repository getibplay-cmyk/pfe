# Model Card — HGB de prévision D+1 à D+7

## Décision et usage

`hgb_poisson::regularized` est retenu uniquement comme référence consultative de prévision de départs à D+1…D+7. La qualification porte sur un proxy public munichois. Elle prouve la méthode et ne constitue jamais une accuracy locale RentFleet.

La PR [#9](https://github.com/getibplay-cmyk/pfe/pull/9), figée au commit `d5355bd475d76a4377f95089b2402e5f8cf071f1`, importe des lots pseudonymisés et versionnés. Une prévision ne confirme, n’annule et ne modifie aucune réservation, aucun contrat, tarif, véhicule, facture ou mouvement de flotte. La lecture et la validation humaines restent obligatoires.

## Modèle et protocole

- famille finale : `HistGradientBoostingRegressor`, perte de Poisson, 240 itérations, CPU ;
- horizon : sept modèles directs, D+1 à D+7 ;
- cible proxy : départs observés par jour, fournisseur et cellule fixe d’environ 2 km ;
- découpage chronologique : entraînement jusqu’au 1er février 2025, validation du 2 février au 1er avril, test final intact du 2 avril au 31 mai 2025 ;
- sélection : trois folds roulants, puis ouverture unique du test final sans retuning ;
- incertitude : quantiles P05/P50/P90/P95 et bootstrap apparié en blocs de 7 jours, graine `20260802`.

La documentation officielle scikit-learn décrit la perte `poisson` du HGB pour les comptes positifs : <https://scikit-learn.org/stable/modules/generated/sklearn.ensemble.HistGradientBoostingRegressor.html>.

## Résultats publics figés

| Méthode | WAPE test | MASE test | Décision |
|---|---:|---:|---|
| HGB Poisson régularisé | 0,152342 | 0,829556 | référence consultative |
| Médiane mobile 28 jours | 0,163494 | 0,890137 | baseline |
| Saisonnier t−7 | 0,184671 | 1,005409 | baseline |

Le HGB bat les deux baselines sur WAPE et MASE. Le bootstrap en blocs donne une probabilité de 0,993 de battre la médiane mobile. La couverture empirique P05–P95 est toutefois de 86,07 %, sous la cible nominale de 90 %.

## Limites et statut local

- Les départs de voitures partagées à Munich ne sont pas la demande de réservation RentFleet au Maroc.
- Le WAPE du test final est 26,60 % moins bon que celui de la validation verrouillée : un décalage temporel est plausible.
- Le record Zenodo expose les fichiers et leurs MD5 mais ne précise pas de licence dataset ; aucune donnée brute n’est redistribuée par RentFleet. L’article associé est CC BY 4.0.
- Aucun backtest historique RentFleet compatible et suffisant n’existe. Le statut local reste `non_validé`.
- Les identifiants fournisseur et cellule sont des proxies publics, sans tenant, secret ou donnée personnelle brute.

## Reproductibilité

Le notebook J15‑B contrôle les entrées J2–J5 déjà gelées dans Drive sans réouvrir le test final. L'inventaire, les chemins, les ID Drive et les empreintes privées restent exclusivement dans le paquet Drive J15‑B. `SHA256SUMS` couvre les preuves publiables de ce dossier. La réexécution scientifique intégrale nécessite les quatre fichiers Zenodo officiels, dont les SHA‑256 publics sont consignés dans la fiche de données, puis les scripts J2–J5 dans leur ordre original.
