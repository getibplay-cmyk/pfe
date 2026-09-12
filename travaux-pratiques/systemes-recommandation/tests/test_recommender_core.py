from __future__ import annotations

import sys
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import numpy as np


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))

from recommender_core import (  # noqa: E402
    center_observed,
    cosine_from_products,
    get_similarities,
    ndcg_at_k_from_matrix,
    observed_means,
    train_test_split_per_user,
)
import recommender_core as core  # noqa: E402


class RecommenderCoreTest(unittest.TestCase):
    def setUp(self):
        self.matrix = np.array(
            [
                [5.0, 0.0, 1.0, 4.0],
                [4.0, 2.0, 0.0, 5.0],
                [0.0, 3.0, 5.0, 2.0],
            ],
            dtype=np.float32,
        )

    def test_observed_means_ignore_missing_zeros(self):
        means = observed_means(self.matrix, axis=1)
        np.testing.assert_allclose(means, [10 / 3, 11 / 3, 10 / 3], rtol=1e-6)

    def test_centering_preserves_missing_positions(self):
        centered, means = center_observed(self.matrix, axis=1)
        self.assertTrue(np.all(centered[self.matrix == 0] == 0))
        for row in range(centered.shape[0]):
            self.assertAlmostEqual(float(centered[row][self.matrix[row] != 0].mean()), 0.0, places=6)
        self.assertEqual(means.shape, (3,))

    def test_cosine_matrix_has_unit_diagonal(self):
        user_products, _, inv_user, _ = get_similarities(self.matrix)
        similarity = cosine_from_products(user_products, inv_user)
        np.testing.assert_allclose(np.diag(similarity), np.ones(3), atol=1e-6)
        np.testing.assert_allclose(similarity, similarity.T, atol=1e-6)

    def test_split_is_disjoint_and_keeps_requested_count(self):
        dense = np.tile(np.arange(1, 6, dtype=np.float32), (4, 1))
        train, test, triplets = train_test_split_per_user(dense, n_test=2, seed=42)
        self.assertFalse(np.any((train != 0) & (test != 0)))
        self.assertEqual(np.count_nonzero(test), 8)
        self.assertEqual(len(triplets), 8)

    def test_ndcg_is_one_for_perfect_ranking(self):
        train_mask = np.array([[True, False, False, False]])
        test_set = np.array([[0, 1, 5], [0, 2, 3], [0, 3, 1]], dtype=np.float32)
        predictions = np.array([[99.0, 5.0, 3.0, 1.0]], dtype=np.float32)
        self.assertAlmostEqual(ndcg_at_k_from_matrix(test_set, predictions, train_mask, 3), 1.0)

    def test_latest_small_uses_fallback_when_archive_is_unavailable(self):
        previous = Path.cwd()
        with tempfile.TemporaryDirectory() as temporary:
            os.chdir(temporary)

            def create_fallback(folder, _files):
                folder.mkdir(parents=True, exist_ok=True)
                (folder / "ratings.csv").write_text("userId,movieId,rating,timestamp\n", encoding="utf-8")
                (folder / "movies.csv").write_text("movieId,title,genres\n", encoding="utf-8")

            try:
                with patch.object(core, "_download_and_extract", side_effect=RuntimeError("réseau")), \
                     patch.object(core, "_download_fallback_files", side_effect=create_fallback):
                    folder = core.ensure_ml_latest_small()
                    self.assertTrue((folder / "ratings.csv").exists())
                    self.assertTrue((folder / "movies.csv").exists())
            finally:
                os.chdir(previous)


if __name__ == "__main__":
    unittest.main()
