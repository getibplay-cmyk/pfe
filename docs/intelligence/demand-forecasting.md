# Prévision de demande D+1 à D+7

## Décision d’intégration

RentFleet intègre le modèle gelé `hgb_poisson::regularized` (`j5-v1`) en mode
`consultative_shadow`. Le modèle produit une moyenne conditionnelle, les
quantiles P05, P50, P90 et P95, ainsi que trois sensibilités locales pour
chaque horizon D+1 à D+7. Aucun résultat ne modifie une réservation, un tarif,
un véhicule ou une autre table opérationnelle.

L’algorithme est un `HistGradientBoostingRegressor` avec perte de Poisson. Ce
choix est cohérent avec une cible de comptage positive et avec une exécution
CPU. La documentation officielle décrit la perte de Poisson et les contraintes
du modèle : [scikit-learn — HistGradientBoostingRegressor](https://scikit-learn.org/stable/modules/generated/sklearn.ensemble.HistGradientBoostingRegressor.html).

## Niveau de preuve

Le benchmark public gelé porte sur des départs observés de mobilité partagée à
Munich, et non sur la demande totale du marché ni sur des locations RentFleet.
Le jeu public de référence est archivé sur
[Zenodo](https://zenodo.org/records/15629735).
Le holdout final gelé couvre 60 jours, 68 séries et 28 560 prévisions sur les
sept horizons ; il n’a pas été rouvert pour régler le modèle.

La fiche Zenodo ne précise pas de licence pour les données brutes. RentFleet ne
les redistribue donc pas : le dépôt conserve seulement l’adaptateur, le contrat
et les preuves dérivées ; l’article associé est annoncé sous CC BY 4.0 dans le
manifeste scientifique J5.

| Indicateur | Résultat public gelé | Interprétation autorisée |
|---|---:|---|
| WAPE | 15,2342 % | Erreur pondérée publique ; plus faible est meilleur |
| `1 - WAPE` | 84,7658 % | Complément lisible du WAPE, jamais une accuracy de classification |
| MASE | 0,829556 | Meilleur que la référence naïve car inférieur à 1 |
| Couverture P05–P95 | 86,07 % | Couverture publique observée pour un intervalle nominal à 90 % |

Ces valeurs ne sont pas une validation locale. Le SaaS conserve donc
`local_holdout_status=not_available_pending_real_history` et refuse toute
affirmation de performance en production.

## Données RentFleet et prétraitement

Le snapshot est une série quotidienne par agence :

- cible : nombre de contrats dont `actual_start_at` tombe dans le jour local ;
- fuseau : `Africa/Casablanca` ;
- statut admissible : `active`, `return_pending`, `returned` ou `closed` ;
- contrats archivés exclus ;
- grille de dates continue, avec zéro pour chaque jour sans départ ;
- catégorie véhicule : `all`, car le modèle public gelé n’a pas appris les
  catégories RentFleet ;
- identifiants tenant, agence et série pseudonymisés par HMAC ;
- aucune identité client, coordonnée, étiquette de décision ou donnée libre ;
- unité de distance du contrat SaaS : `km`. Les miles ne sont jamais acceptés.
  Le modèle de demande ne consomme lui-même aucune variable de distance ; ce
  verrou garantit la cohérence des contrats communs et des modèles suivants.

Le minimum technique est de 35 jours, nécessaire aux lags jusqu’à J-28 et aux
horizons jusqu’à D+7. Ce minimum permet seulement de tester l’intégration. Une
évaluation locale crédible requiert un historique beaucoup plus long, de
préférence au moins 180 jours couvrant les régimes hebdomadaires et saisonniers.
Un snapshot entièrement nul peut être exporté pour audit, mais l’adaptateur
refuse d’en produire une prévision, car celle-ci ne serait pas informative.

Les variables numériques reproduisent le prétraitement gelé : lags J-1, J-2,
J-3, J-7, J-14 et J-28 au cutoff, saisonnalité J-7, moyennes, médianes,
écarts-types et comptes mobiles sur 7 et 28 jours, week-end et encodage cyclique
du jour de l’année. Pour la validation locale future, les découpages resteront
strictement temporels ; la documentation officielle de
[TimeSeriesSplit](https://scikit-learn.org/stable/modules/generated/sklearn.model_selection.TimeSeriesSplit.html)
explique pourquoi des observations futures ne doivent pas entrer dans
l’entraînement du passé.

## Artefact gelé

- fichier : `demand_forecast_munich_j5_v1.0.joblib` ;
- SHA-256 : `992217b4887623ca924a3dc36686c69ab616634aace64cf993ad50b61ace6802` ;
- Python : 3.12 ;
- NumPy : 2.0.2 ;
- pandas : 2.2.2 ;
- scikit-learn : 1.6.1 ;
- joblib : 1.5.3 ;
- calcul : CPU, sans GPU requis.

L’artefact conserve `ready_for_saas=false`. L’adaptateur respecte cette
limite : il autorise uniquement une inférence shadow et génère un payload où
`ready_for_production=false`, `automatic_action_allowed=false` et
`operational_effect=NO_OPERATIONAL_ACTION`.

## Exécution dans Colab ou en local

1. Depuis l’écran **Prévision de demande**, générer le CSV et télécharger son
   manifeste JSON.
2. Récupérer l’artefact gelé depuis le dossier de modèles du projet et vérifier
   son SHA-256.
3. Utiliser Python 3.12 avec les versions du manifeste J5. Dans Colab,
   installer les dépendances figées puis redémarrer le runtime avant de charger
   le fichier joblib :

   ```python
   %pip install --quiet --force-reinstall -r scripts/intelligence/requirements-demand-forecast.txt
   ```

   Un GPU Colab peut rester désactivé : ce modèle est volontairement CPU.
4. Exécuter :

   ```bash
   python scripts/intelligence/run_demand_forecast.py \
     --snapshot rentfleet_demand_history_<run-id>.csv \
     --manifest rentfleet_demand_manifest_<run-id>.json \
     --model-bundle demand_forecast_munich_j5_v1.0.joblib \
     --output rentfleet_demand_forecast_<run-id>.json
   ```

5. Importer le JSON sur la ligne du snapshot correspondant. Le serveur vérifie
   le modèle, sa version et son empreinte, la lignée exacte du snapshot, les
   sept horizons, l’ordre des quantiles, l’idempotence et toutes les limites de
   sécurité.

La CI installe cet environnement figé et teste le prétraitement ainsi que le
payload complet avec des pipelines déterministes. Elle ne télécharge pas le
bundle privé : le test d’inférence avec l’artefact dont l’empreinte est indiquée
ci-dessus reste une preuve Colab séparée.

## Explications affichées

L’adaptateur applique une sensibilité locale « une variable à la fois ». Pour
chaque prévision, il remplace successivement un facteur par une référence
neutre, recalcule la sortie et conserve les trois variations absolues les plus
fortes. Le signe indique une pression locale à la hausse ou à la baisse.
L’interface affiche aussi l’écart de prévision en nombre de départs pour rendre
cette sensibilité vérifiable.

Cette explication est déterministe et compréhensible, mais elle n’est ni une
preuve causale, ni une probabilité, ni une recommandation d’augmenter la flotte.
L’intervalle P05–P95 communique l’incertitude ; il ne garantit pas que la
demande réelle restera dans cet intervalle pour RentFleet.

## Passage futur de shadow à validation locale

Le statut ne pourra évoluer qu’après :

1. accumulation d’un historique réel suffisant ;
2. gel d’un cutoff et d’un holdout temporel jamais utilisé pour le réglage ;
3. comparaison contre la naïve saisonnière J-7 et la médiane mobile J-28 ;
4. mesure WAPE, MASE, biais, couverture et largeur d’intervalle par agence ;
5. contrôle des segments à faible volume et des changements de régime ;
6. revue humaine documentée et nouvelle version de contrat.

Même après validation, une prévision restera une aide à la planification. La
décision métier appartient toujours à un utilisateur autorisé.
