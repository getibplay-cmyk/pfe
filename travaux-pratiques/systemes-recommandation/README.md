# TP 1 & TP 2 — Systèmes de recommandation

Réalisation reproductible des deux TP à partir de MovieLens Latest Small et MovieLens 100K.

## Exécuter dans Google Colab

- [Ouvrir le TP 1 dans Colab](https://colab.research.google.com/github/getibplay-cmyk/pfe/blob/academic/tp-recommendation-systems/travaux-pratiques/systemes-recommandation/notebooks/TP1_Systemes_de_recommandation.ipynb)
- [Ouvrir le TP 2 dans Colab](https://colab.research.google.com/github/getibplay-cmyk/pfe/blob/academic/tp-recommendation-systems/travaux-pratiques/systemes-recommandation/notebooks/TP2_Filtrage_collaboratif.ipynb)

Chaque notebook est autonome : il télécharge le jeu MovieLens requis depuis GroupLens lors de la première exécution, puis produit les tableaux, métriques et figures du compte rendu.

## Résultats principaux

| Sujet | Meilleur modèle | Mesure | Score |
|---|---|---:|---:|
| TP 1 | SVD centrée, rang 5 | RMSE | 0,9162 |
| TP 1 | SVD brute, rang 10 | NDCG@10 | 0,2433 |
| TP 2 | Item-based brut, k=200 | MSE test | 1,0615 |
| TP 2 | Item-based centré, k=200 | MSE test | 0,9416 |

Le centrage des valeurs observées améliore nettement la précision. La SVD brute obtient un NDCG élevé mais une RMSE médiocre, car elle conserve un signal de classement tout en traitant incorrectement les zéros structurels pour la calibration des notes.

## Structure

```text
notebooks/   notebooks exécutés avec sorties intégrées
src/         fonctions de chargement, similarité, prédiction et évaluation
tests/       tests unitaires des opérations sensibles
scripts/     génération et exécution reproductible des notebooks
artifacts/   métriques JSON
report/      compte rendu PDF
```

## Exécution locale

```bash
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
python -m unittest discover -s tests -v
python scripts/execute_notebooks.py notebooks/TP1_Systemes_de_recommandation.ipynb
python scripts/execute_notebooks.py notebooks/TP2_Filtrage_collaboratif.ipynb
```

Pour utiliser une copie locale des données sans téléchargement :

```bash
export ML_LATEST_SMALL_DIR=/chemin/vers/ml-latest-small
export ML_100K_DIR=/chemin/vers/ml-100k
```

## Données

Les archives MovieLens ne sont pas incluses dans le dépôt. Elles sont récupérées depuis les pages officielles [MovieLens Latest](https://grouplens.org/datasets/movielens/latest/) et [MovieLens 100K](https://grouplens.org/datasets/movielens/100k/). Leur utilisation reste soumise aux conditions de GroupLens.

