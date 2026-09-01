"""Construit les deux notebooks Colab autoportants des TP1 et TP2."""

from __future__ import annotations

from pathlib import Path
from textwrap import dedent

import nbformat as nbf


ROOT = Path(__file__).resolve().parents[1]
NOTEBOOKS = ROOT / "notebooks"
CORE_SOURCE = (ROOT / "src" / "recommender_core.py").read_text(encoding="utf-8")


def markdown(text: str):
    # Les chaînes multilignes Python interprètent \f, \r et \b. On restaure ici
    # les commandes LaTeX concernées avant de créer la cellule Markdown.
    text = text.replace("\x0crac", "\\frac")
    text = text.replace("\rVert", "\\rVert")
    text = text.replace("\x08ar", "\\bar")
    return nbf.v4.new_markdown_cell(dedent(text).strip())


def code(text: str):
    return nbf.v4.new_code_cell(dedent(text).strip())


def notebook_metadata(title: str) -> dict:
    return {
        "kernelspec": {"display_name": "Python 3", "language": "python", "name": "python3"},
        "language_info": {"name": "python", "version": "3"},
        "colab": {"name": title, "provenance": []},
    }


def build_tp1() -> None:
    cells = [
        markdown(
            """
            # TP1 - Systèmes de recommandation

            **Master Sciences des Données - Université Abdelmalek Essaâdi, ENS Tétouan**  
            **Jeu de données : MovieLens Latest Small**

            Réponses aux questions **1.a à 1.o** : matrice d'utilité, filtrage
            user-based et item-based, RMSE, NDCG@10, SVD, choix du rang et
            correction du biais utilisateur. Le notebook est autoportant dans Colab et
            utilise une graine aléatoire fixée à 42.
            """
        ),
        markdown(
            """
            ## Préparation

            Les fonctions demandées sont définies ci-dessous. Le zéro code une valeur
            manquante et n'entre jamais dans le calcul des moyennes de notes.
            """
        ),
        code(CORE_SOURCE),
        code(
            """
            import gc, json, platform, time
            from pathlib import Path
            import matplotlib.pyplot as plt
            import seaborn as sns
            from IPython.display import display

            sns.set_theme(style="whitegrid", context="notebook")
            OUTPUT_DIR = Path(os.environ.get("TP_OUTPUT_DIR", "artifacts"))
            OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
            print("Python :", platform.python_version())
            print("NumPy  :", np.__version__)
            print("Pandas :", pd.__version__)
            """
        ),
        markdown(
            """
            ## Questions 1.a et 1.b - Chargement et matrice d'utilité

            `load_latest_small()` lit `ratings.csv`, attribue des indices contigus à partir
            de zéro, mélange les lignes et réalise le split global 80 % / 20 % demandé.
            """
        ),
        code(
            """
            data_dir = ensure_ml_latest_small()
            data = load_latest_small(data_dir, train_fraction=0.80, seed=SEED)
            ratings, movies = data["ratings"], data["movies"]
            utility, train_mask, test_set = data["utility"], data["mask"], data["test_set"]
            item_ids = data["items"]
            density = 100 * train_mask.sum() / train_mask.size
            print(f"Dossier              : {data_dir}")
            print(f"Évaluations          : {len(ratings):,}")
            print(f"Utilisateurs / films : {utility.shape[0]} / {utility.shape[1]}")
            print(f"Train / test         : {len(data['train_df']):,} / {len(data['test_df']):,}")
            print(f"Densité train        : {density:.3f} %")
            display(ratings.head())
            """
        ),
        markdown(
            """
            **Réponse.** La matrice est très creuse. Le zéro signifie « note inconnue » et
            ne doit pas être confondu avec une mauvaise note.
            """
        ),
        markdown(
            """
            ## Question 1.c - Similarité cosinus

            $$\operatorname{sim}_{cos}(x,y)=\frac{x^Ty}{\lVert x\rVert_2\lVert y\rVert_2}.$$

            Les quatre sorties demandées sont calculées par opérations matricielles, sans
            boucle Python : produits utilisateurs, produits films et inverses des normes.
            """
        ),
        code(
            """
            start = time.perf_counter()
            user_products, item_products, inv_user_norm, inv_item_norm = get_similarities(utility)
            similarity_time = time.perf_counter() - start
            print("Produits utilisateurs :", user_products.shape)
            print("Produits films        :", item_products.shape)
            print(f"Temps de calcul       : {similarity_time:.2f} s")
            """
        ),
        markdown("## Question 1.d - Classement des utilisateurs et des films"),
        code(
            """
            title_by_id = dict(zip(movies.movieId, movies.title))
            top_users = rank_users(0, user_products, inv_user_norm)[:5]
            top_items = rank_items(0, item_products, inv_item_norm)[:5]
            display(pd.DataFrame(top_users, columns=["user_index", "similarité"]))
            display(pd.DataFrame([
                {"film": title_by_id[item_ids[idx]], "similarité": score}
                for idx, score in top_items
            ]))
            """
        ),
        markdown(
            """
            ## Questions 1.e et 1.f - Prédiction pondérée

            User-based retient les utilisateurs proches ayant noté le film ; item-based
            retient les films proches déjà notés. Le score est une moyenne pondérée par les
            similarités.
            """
        ),
        code(
            """
            example_user, example_item, example_rating = test_set[0]
            example_user, example_item = int(example_user), int(example_item)
            global_mean = float(utility[train_mask].mean())
            p_user = recommend_user_based_item(
                example_user, example_item, utility, user_products, inv_user_norm,
                k=10, mask=train_mask, baseline=global_mean)
            p_item = recommend_item_based_item(
                example_user, example_item, utility, item_products, inv_item_norm,
                k=10, mask=train_mask, baseline=global_mean)
            print("Note réelle             :", float(example_rating))
            print(f"Prédiction user-based   : {np.clip(p_user, .5, 5):.3f}")
            print(f"Prédiction item-based   : {np.clip(p_item, .5, 5):.3f}")
            """
        ),
        markdown(
            """
            ## Questions 1.g et 1.h - RMSE

            $$\operatorname{RMSE}=\sqrt{\frac{1}{n}\sum_{j=1}^n(\hat r_j-r_j)^2}.$$

            Les dix voisins sont pré-calculés afin d'évaluer efficacement les 20 % de
            notes réservées au test.
            """
        ),
        code(
            """
            user_idx10, user_w10 = precompute_topk(user_products, inv_user_norm, 10)
            item_idx10, item_w10 = precompute_topk(item_products, inv_item_norm, 10)
            pred_user_raw = predict_user_topk_matrix(
                utility, train_mask, user_idx10, user_w10)
            pred_item_raw = predict_item_topk_matrix(
                utility, train_mask, item_idx10, item_w10)
            # Si aucun voisin n'a noté l'item, repli sur la moyenne globale.
            pred_user_raw[pred_user_raw == 0] = global_mean
            pred_item_raw[pred_item_raw == 0] = global_mean
            raw_cf_metrics = {
                "User-based k=10": rmse_from_matrix(test_set, pred_user_raw),
                "Item-based k=10": rmse_from_matrix(test_set, pred_item_raw),
            }
            display(pd.DataFrame.from_dict(raw_cf_metrics, orient="index", columns=["RMSE"]))
            """
        ),
        markdown(
            """
            ## Questions 1.i et 1.j - NDCG@10

            La NDCG mesure la qualité de l'ordre des recommandations. Les films du train
            sont exclus du classement et l'idéal place d'abord les meilleures notes du test.
            """
        ),
        code(
            """
            raw_cf_ndcg = {
                "User-based k=10": ndcg_at_k_from_matrix(test_set, pred_user_raw, train_mask, 10),
                "Item-based k=10": ndcg_at_k_from_matrix(test_set, pred_item_raw, train_mask, 10),
            }
            display(pd.DataFrame.from_dict(raw_cf_ndcg, orient="index", columns=["NDCG@10"]))
            """
        ),
        markdown(
            """
            ## Questions 1.k à 1.m - SVD, rang optimal et NDCG

            NumPy calcule $R=U\Sigma V^T$. La reconstruction au rang $N$ conserve les $N$
            plus grandes valeurs singulières. La décomposition est calculée une seule fois.
            """
        ),
        code(
            """
            start = time.perf_counter()
            raw_svd = svd_decompose(utility)
            raw_svd_time = time.perf_counter() - start
            ranks = [5, 10, 20, 40, 60, 80, 100, 150, 200]
            raw_svd_rmse = {
                rank: rmse_from_matrix(test_set, svd_predict(raw_svd, rank))
                for rank in ranks
            }
            best_raw_rank = min(raw_svd_rmse, key=raw_svd_rmse.get)
            pred_svd_raw = svd_predict(raw_svd, best_raw_rank)
            raw_svd_ndcg = ndcg_at_k_from_matrix(test_set, pred_svd_raw, train_mask, 10)
            print(f"Temps SVD brute     : {raw_svd_time:.2f} s")
            print(f"Meilleur rang brut  : {best_raw_rank}")
            print(f"RMSE minimale       : {raw_svd_rmse[best_raw_rank]:.4f}")
            print(f"NDCG@10             : {raw_svd_ndcg:.4f}")
            display(pd.DataFrame({"rang": ranks, "RMSE brute": [raw_svd_rmse[r] for r in ranks]}))
            """
        ),
        markdown(
            """
            ## Question 1.n - Distribution des scores

            La SVD brute apprend surtout à reconstruire les nombreux zéros. Les scores sont
            donc comprimés vers le bas et peuvent sortir de l'échelle valide.
            """
        ),
        code(
            """
            held_users = test_set[:, 0].astype(int)
            held_items = test_set[:, 1].astype(int)
            held_raw_scores = pred_svd_raw[held_users, held_items]
            print(pd.Series(held_raw_scores).describe(percentiles=[.01, .1, .5, .9, .99]))
            fig, ax = plt.subplots(figsize=(8, 4.5))
            sns.histplot(held_raw_scores, bins=50, ax=ax, color="#2457A7")
            ax.axvline(.5, color="#E87500", linestyle="--", label="borne minimale")
            ax.axvline(5, color="#161616", linestyle="--", label="borne maximale")
            ax.set(title="SVD brute : scores sur le test", xlabel="score prédit")
            ax.legend(); fig.tight_layout()
            fig.savefig(OUTPUT_DIR / "tp1_distribution_svd_brute.png", dpi=180)
            plt.show()
            """
        ),
        markdown(
            """
            ## Question 1.o - Centrage par utilisateur

            On soustrait la moyenne de chaque utilisateur aux seules notes connues, puis on
            la rajoute après prédiction :

            $$\hat r_{ui}=\bar r_u + \widehat{(r_{ui}-\bar r_u)}.$$
            """
        ),
        code(
            """
            del item_products, pred_user_raw, pred_item_raw
            gc.collect()
            centered_utility, user_means = center_observed(utility, axis=1)
            c_user_products, c_item_products, c_inv_user, c_inv_item = get_similarities(centered_utility)
            c_user_idx10, c_user_w10 = precompute_topk(c_user_products, c_inv_user, 10)
            c_item_idx10, c_item_w10 = precompute_topk(c_item_products, c_inv_item, 10)
            pred_user_centered = predict_user_topk_matrix(
                centered_utility, train_mask, c_user_idx10, c_user_w10, user_means)
            pred_item_centered = predict_item_topk_matrix(
                centered_utility, train_mask, c_item_idx10, c_item_w10, user_means)
            centered_cf_metrics = {
                "User-based centré k=10": rmse_from_matrix(test_set, pred_user_centered),
                "Item-based centré k=10": rmse_from_matrix(test_set, pred_item_centered),
            }
            centered_cf_ndcg = {
                "User-based centré k=10": ndcg_at_k_from_matrix(test_set, pred_user_centered, train_mask, 10),
                "Item-based centré k=10": ndcg_at_k_from_matrix(test_set, pred_item_centered, train_mask, 10),
            }

            start = time.perf_counter()
            centered_svd = svd_decompose(centered_utility)
            centered_svd_time = time.perf_counter() - start
            centered_svd_rmse = {
                rank: rmse_from_matrix(test_set, svd_predict(centered_svd, rank, user_means))
                for rank in ranks
            }
            best_centered_rank = min(centered_svd_rmse, key=centered_svd_rmse.get)
            pred_svd_centered = svd_predict(centered_svd, best_centered_rank, user_means)
            centered_svd_ndcg = ndcg_at_k_from_matrix(test_set, pred_svd_centered, train_mask, 10)

            summary = pd.DataFrame([
                *({"modèle": n, "RMSE": v, "NDCG@10": raw_cf_ndcg[n]} for n, v in raw_cf_metrics.items()),
                *({"modèle": n, "RMSE": v, "NDCG@10": centered_cf_ndcg[n]} for n, v in centered_cf_metrics.items()),
                {"modèle": f"SVD brute (rang {best_raw_rank})", "RMSE": raw_svd_rmse[best_raw_rank], "NDCG@10": raw_svd_ndcg},
                {"modèle": f"SVD centrée (rang {best_centered_rank})", "RMSE": centered_svd_rmse[best_centered_rank], "NDCG@10": centered_svd_ndcg},
            ]).sort_values("RMSE")
            display(summary)
            print(f"Temps SVD centrée : {centered_svd_time:.2f} s")
            """
        ),
        code(
            """
            fig, axes = plt.subplots(1, 2, figsize=(13, 4.7))
            axes[0].plot(ranks, [raw_svd_rmse[r] for r in ranks], marker="o", label="SVD brute")
            axes[0].plot(ranks, [centered_svd_rmse[r] for r in ranks], marker="o", label="SVD centrée")
            axes[0].set(title="Influence du rang SVD", xlabel="rang N", ylabel="RMSE")
            axes[0].legend()
            held_centered_scores = pred_svd_centered[held_users, held_items]
            sns.histplot(held_raw_scores, bins=45, alpha=.45, label="brute", ax=axes[1])
            sns.histplot(held_centered_scores, bins=45, alpha=.45, label="centrée", ax=axes[1])
            axes[1].set(title="Scores SVD sur le test", xlabel="score prédit")
            axes[1].legend(); fig.tight_layout()
            fig.savefig(OUTPUT_DIR / "tp1_comparaison_svd.png", dpi=180)
            plt.show()

            metrics_tp1 = {
                "dataset": {"ratings": len(ratings), "users": utility.shape[0], "items": utility.shape[1], "train_density_pct": float(density)},
                "raw_cf_rmse": {k: float(v) for k, v in raw_cf_metrics.items()},
                "raw_cf_ndcg10": {k: float(v) for k, v in raw_cf_ndcg.items()},
                "centered_cf_rmse": {k: float(v) for k, v in centered_cf_metrics.items()},
                "centered_cf_ndcg10": {k: float(v) for k, v in centered_cf_ndcg.items()},
                "svd_raw": {"best_rank": int(best_raw_rank), "rmse": float(raw_svd_rmse[best_raw_rank]), "ndcg10": float(raw_svd_ndcg), "curve": {str(k): float(v) for k, v in raw_svd_rmse.items()}},
                "svd_centered": {"best_rank": int(best_centered_rank), "rmse": float(centered_svd_rmse[best_centered_rank]), "ndcg10": float(centered_svd_ndcg), "curve": {str(k): float(v) for k, v in centered_svd_rmse.items()}},
            }
            (OUTPUT_DIR / "metrics_tp1.json").write_text(
                json.dumps(metrics_tp1, indent=2, ensure_ascii=False), encoding="utf-8")
            """
        ),
        markdown(
            """
            ## Conclusion TP1

            RMSE et NDCG répondent à deux objectifs différents. La SVD brute confond zéros
            manquants et faibles préférences ; le centrage sur les notes observées corrige
            une part importante du biais. Le rang optimal doit être choisi sur validation.
            """
        ),
    ]
    notebook = nbf.v4.new_notebook(
        cells=cells, metadata=notebook_metadata("TP1_Systemes_de_recommandation.ipynb"))
    nbf.validate(notebook)
    nbf.write(notebook, NOTEBOOKS / "TP1_Systemes_de_recommandation.ipynb")


def build_tp2() -> None:
    cells = [
        markdown(
            """
            # TP2 - Filtrage collaboratif

            **Master Sciences des Données - Université Abdelmalek Essaâdi, ENS Tétouan**  
            **Jeu de données : MovieLens 100K**

            Notebook complété : matrice d'interactions, densité, cosinus, premières
            prédictions, top-k, influence de $k$, débiaisage, films similaires et Pearson.
            """
        ),
        code(CORE_SOURCE),
        code(
            """
            import json, platform, time
            from pathlib import Path
            import matplotlib.pyplot as plt
            import seaborn as sns
            from IPython.display import display

            sns.set_theme(style="whitegrid", context="notebook")
            OUTPUT_DIR = Path(os.environ.get("TP_OUTPUT_DIR", "artifacts"))
            OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
            print("Python :", platform.python_version(), "- NumPy :", np.__version__)
            """
        ),
        markdown("## 1. Jeu de données et matrice d'interactions"),
        code(
            """
            data_dir = ensure_ml100k()
            data = load_ml100k(data_dir)
            ratings_df, movies_df = data["ratings"], data["movies"]
            ratings = data["utility"]
            density_pct = 100 * np.count_nonzero(ratings) / ratings.size
            print(f"Évaluations          : {len(ratings_df):,}")
            print(f"Utilisateurs / films : {ratings.shape[0]} / {ratings.shape[1]}")
            print(f"Forme matrice        : {ratings.shape}")
            print(f"Densité              : {density_pct:.4f} %")
            print(f"Sparsité stricte     : {100-density_pct:.4f} %")
            display(ratings_df.head())
            """
        ),
        markdown(
            """
            **Réponse.** Le code du sujet nomme `sparsity` la proportion de cases non
            nulles ; il calcule en réalité la densité. Environ 93,7 % des couples
            utilisateur-film sont inconnus.
            """
        ),
        markdown("## 2. Split par utilisateur et similarités cosinus"),
        code(
            """
            train, test, test_triplets = train_test_split_per_user(ratings, n_test=10, seed=SEED)
            start = time.perf_counter()
            user_similarity = fast_similarity(train, kind="user")
            item_similarity = fast_similarity(train, kind="item")
            cosine_time = time.perf_counter() - start
            user_one_scores = user_similarity[0].copy()
            user_one_scores[0] = -np.inf
            nearest_user_idx = int(np.argmax(user_one_scores))
            print(f"Train / test non nuls : {np.count_nonzero(train):,} / {np.count_nonzero(test):,}")
            print(f"Temps similarités     : {cosine_time:.3f} s")
            print(f"Plus proche de user 1 : user {nearest_user_idx+1}, cosinus={user_one_scores[nearest_user_idx]:.4f}")
            """
        ),
        markdown(
            """
            **Réponse.** La version matricielle remplace les trois boucles de la version
            lente par des produits matriciels exécutés dans les bibliothèques numériques
            optimisées.
            """
        ),
        markdown("## 3. Premières prédictions et recommandations pour l'utilisateur 1"),
        code(
            """
            user_prediction_simple = predict_fast_simple(train, user_similarity, kind="user")
            item_prediction_simple = predict_fast_simple(train, item_similarity, kind="item")
            simple_metrics = pd.DataFrame([
                {"modèle": "User-based simple", "MSE": mse_on_observed(user_prediction_simple, test), "RMSE": rmse_on_observed(user_prediction_simple, test)},
                {"modèle": "Item-based simple", "MSE": mse_on_observed(item_prediction_simple, test), "RMSE": rmse_on_observed(item_prediction_simple, test)},
            ])
            display(simple_metrics)

            title_by_idx = {int(mid)-1: title for mid, title in zip(movies_df.movie_id, movies_df.movie_title)}
            scores = user_prediction_simple[0].copy()
            scores[train[0] != 0] = -np.inf
            top_indices = np.argsort(scores)[::-1][:10]
            recodic = {int(idx+1): float(scores[idx]) for idx in top_indices}
            sortedreco = dict(sorted(recodic.items(), key=lambda pair: pair[1], reverse=True))
            display(pd.DataFrame([
                {"movie_id": movie_id, "titre": title_by_idx[movie_id-1], "score prédit": score}
                for movie_id, score in sortedreco.items()
            ]))
            """
        ),
        markdown(
            """
            **Réponse.** Les scores simples sont trop faibles : le dénominateur agrège tous
            les voisins, y compris ceux qui n'ont pas noté le film. Les zéros manquants
            tirent donc artificiellement les prédictions vers zéro.
            """
        ),
        markdown("## 4. Influence des $k$ plus proches voisins"),
        code(
            """
            k_values = [5, 15, 30, 50, 100, 200]
            raw_rows, raw_predictions = [], {}
            ones_user = np.ones(user_similarity.shape[0], dtype=np.float32)
            ones_item = np.ones(item_similarity.shape[0], dtype=np.float32)
            for k in k_values:
                u_idx, u_w = precompute_topk(user_similarity, ones_user, k)
                i_idx, i_w = precompute_topk(item_similarity, ones_item, k)
                user_pred = predict_user_topk_matrix(train, train != 0, u_idx, u_w)
                item_pred = predict_item_topk_matrix(train, train != 0, i_idx, i_w)
                raw_predictions[k] = (user_pred, item_pred)
                raw_rows += [
                    {"k": k, "modèle": "User-based", "jeu": "train", "MSE": mse_on_observed(user_pred, train)},
                    {"k": k, "modèle": "User-based", "jeu": "test", "MSE": mse_on_observed(user_pred, test)},
                    {"k": k, "modèle": "Item-based", "jeu": "train", "MSE": mse_on_observed(item_pred, train)},
                    {"k": k, "modèle": "Item-based", "jeu": "test", "MSE": mse_on_observed(item_pred, test)},
                ]
            raw_curve = pd.DataFrame(raw_rows)
            display(raw_curve[raw_curve.jeu == "test"].pivot(index="k", columns="modèle", values="MSE"))
            fig, ax = plt.subplots(figsize=(8.5, 5))
            sns.lineplot(data=raw_curve, x="k", y="MSE", hue="modèle", style="jeu", markers=True, ax=ax)
            ax.set_title("Filtrage collaboratif brut : influence de k")
            fig.tight_layout(); fig.savefig(OUTPUT_DIR / "tp2_influence_k_brut.png", dpi=180)
            plt.show()
            """
        ),
        markdown(
            """
            **Réponse.** Un voisinage très petit est instable ; un voisinage trop large
            dilue l'information avec des voisins peu pertinents. Le minimum sur la courbe
            test est le compromis à retenir.
            """
        ),
        markdown("## 5. Débiaisage et combinaison débiaisage + top-k"),
        code(
            """
            user_centered, user_means = center_observed(train, axis=1)
            item_centered, item_means = center_observed(train, axis=0)
            user_similarity_centered = fast_similarity(user_centered, kind="user")
            item_similarity_centered = fast_similarity(item_centered, kind="item")
            centered_rows, centered_predictions = [], {}
            ones_user_c = np.ones(user_similarity_centered.shape[0], dtype=np.float32)
            ones_item_c = np.ones(item_similarity_centered.shape[0], dtype=np.float32)
            for k in k_values:
                u_idx, u_w = precompute_topk(user_similarity_centered, ones_user_c, k)
                i_idx, i_w = precompute_topk(item_similarity_centered, ones_item_c, k)
                user_pred = predict_user_topk_matrix(
                    user_centered, train != 0, u_idx, u_w, baselines=user_means)
                item_pred = predict_item_topk_matrix(
                    item_centered, train != 0, i_idx, i_w) + item_means[None, :]
                centered_predictions[k] = (user_pred, item_pred)
                centered_rows += [
                    {"k": k, "modèle": "User-based centré", "jeu": "train", "MSE": mse_on_observed(user_pred, train)},
                    {"k": k, "modèle": "User-based centré", "jeu": "test", "MSE": mse_on_observed(user_pred, test)},
                    {"k": k, "modèle": "Item-based centré", "jeu": "train", "MSE": mse_on_observed(item_pred, train)},
                    {"k": k, "modèle": "Item-based centré", "jeu": "test", "MSE": mse_on_observed(item_pred, test)},
                ]
            centered_curve = pd.DataFrame(centered_rows)
            display(centered_curve[centered_curve.jeu == "test"].pivot(index="k", columns="modèle", values="MSE"))
            combined_test = pd.concat([
                raw_curve[raw_curve.jeu == "test"], centered_curve[centered_curve.jeu == "test"]
            ], ignore_index=True)
            fig, ax = plt.subplots(figsize=(9, 5.2))
            sns.lineplot(data=combined_test, x="k", y="MSE", hue="modèle", marker="o", ax=ax)
            ax.set_title("Erreur test : effet du centrage et de k")
            fig.tight_layout(); fig.savefig(OUTPUT_DIR / "tp2_comparaison_k_debiaisage.png", dpi=180)
            plt.show()
            """
        ),
        markdown(
            """
            **Réponse.** Le centrage est calculé uniquement sur les notes observées. Il
            distingue la préférence relative du niveau de sévérité de chaque utilisateur.
            La combinaison centrage + top-k est plus solide que les variantes naïves.
            """
        ),
        markdown("## 6. Films similaires : cosinus puis Pearson"),
        code(
            """
            movie_examples = {0: "Toy Story", 1: "GoldenEye", 20: "Muppet Treasure Island"}
            cosine_lists = {
                label: top_k_movies(item_similarity, title_by_idx, idx, 6)
                for idx, label in movie_examples.items()
            }
            print("Voisins selon le cosinus :")
            for label, values in cosine_lists.items():
                print()
                print(label, ":")
                for value in values:
                    print(" -", value)

            item_correlation = pearson_item_similarity(train)
            pearson_lists = {
                label: top_k_movies(item_correlation, title_by_idx, idx, 6)
                for idx, label in movie_examples.items()
            }
            print()
            print("Voisins selon Pearson :")
            for label, values in pearson_lists.items():
                print()
                print(label, ":")
                for value in values:
                    print(" -", value)
            """
        ),
        markdown(
            """
            **Réponse.** Le cosinus brut est influencé par la popularité. Pearson centre les
            profils et insiste sur les variations communes. Les listes ne coïncident donc
            que partiellement. Une proximité statistique ne garantit pas une proximité de
            genre ou de scénario.
            """
        ),
        code(
            """
            raw_test = raw_curve[raw_curve.jeu == "test"].copy()
            centered_test = centered_curve[centered_curve.jeu == "test"].copy()
            raw_best = raw_test.loc[raw_test.MSE.idxmin()].to_dict()
            centered_best = centered_test.loc[centered_test.MSE.idxmin()].to_dict()
            metrics_tp2 = {
                "dataset": {"ratings": int(len(ratings_df)), "users": int(ratings.shape[0]), "items": int(ratings.shape[1]), "density_pct": float(density_pct)},
                "nearest_user_to_user_1": {"user_id": int(nearest_user_idx+1), "cosine": float(user_one_scores[nearest_user_idx])},
                "simple": simple_metrics.to_dict(orient="records"),
                "raw_curve_test": raw_test.to_dict(orient="records"),
                "centered_curve_test": centered_test.to_dict(orient="records"),
                "best_raw": {"k": int(raw_best["k"]), "model": raw_best["modèle"], "mse": float(raw_best["MSE"])},
                "best_centered": {"k": int(centered_best["k"]), "model": centered_best["modèle"], "mse": float(centered_best["MSE"])},
                "cosine_neighbors": cosine_lists,
                "pearson_neighbors": pearson_lists,
            }
            (OUTPUT_DIR / "metrics_tp2.json").write_text(
                json.dumps(metrics_tp2, indent=2, ensure_ascii=False), encoding="utf-8")
            print("Meilleur brut   :", metrics_tp2["best_raw"])
            print("Meilleur centré :", metrics_tp2["best_centered"])
            """
        ),
        markdown(
            """
            ## Conclusion TP2

            La matrice est très creuse, les opérations matricielles rendent les calculs
            réalistes, $k$ doit être validé, et le débiaisage sur les seules notes observées
            améliore l'interprétation. Cosinus et Pearson représentent deux notions de
            proximité différentes.
            """
        ),
    ]
    notebook = nbf.v4.new_notebook(
        cells=cells, metadata=notebook_metadata("TP2_Filtrage_collaboratif.ipynb"))
    nbf.validate(notebook)
    nbf.write(notebook, NOTEBOOKS / "TP2_Filtrage_collaboratif.ipynb")


def main() -> None:
    NOTEBOOKS.mkdir(parents=True, exist_ok=True)
    build_tp1()
    build_tp2()
    print("Notebooks construits dans", NOTEBOOKS)


if __name__ == "__main__":
    main()
