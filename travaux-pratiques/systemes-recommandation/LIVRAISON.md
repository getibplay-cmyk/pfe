# Livraison finale — TP 1 et TP 2

## À remettre

- `report/Compte_rendu_TP1_TP2_Systemes_de_recommandation.pdf`
- `notebooks/TP1_Systemes_de_recommandation.ipynb`
- `notebooks/TP2_Filtrage_collaboratif.ipynb`

## Exécution dans Colab

1. Importer l'un des fichiers `.ipynb` dans Google Colab.
2. Sélectionner **Exécution > Tout exécuter**.
3. Attendre le message indiquant le nombre d'évaluations chargées.
4. Le téléchargement tente d'abord GroupLens puis utilise automatiquement un miroir si la source est indisponible.
5. Les sorties finales sont déjà enregistrées dans les notebooks et peuvent être consultées sans relancer les calculs.

## Validation réalisée

- TP1 : 100 836 évaluations, 610 utilisateurs, 9 724 films.
- TP2 : 100 000 évaluations, 943 utilisateurs, 1 682 films.
- 6 tests unitaires réussis.
- 21 cellules de code exécutées, aucune erreur.
- Compte rendu : 11 pages contrôlées visuellement.

## Principaux résultats

- SVD centrée, rang 5 : RMSE = 0,9162.
- SVD brute, rang 10 : NDCG@10 = 0,2433.
- Item-based centré, k=200 : MSE test = 0,9416.

