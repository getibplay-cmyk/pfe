"""Private, offline retraining protocol. Never connects to a SaaS database.

Data is downloaded deliberately by the platform administrator. Test predictions
are imported as JSON; executable model files never go through the SaaS upload.
"""
from __future__ import annotations

import hashlib
import json
import math
import os
import re
from collections import defaultdict
from datetime import date, timedelta
from pathlib import Path

FAMILIES = {"demand", "anomaly", "color", "damage", "plate"}
COLORS = ["black", "blue", "gray", "green", "orange", "red", "white", "yellow", "__reject__"]


def require(condition, message):
    if not condition:
        raise ValueError(message)


def sha256(path):
    digest = hashlib.sha256()
    with Path(path).open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def write_json(path, value):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    with path.open("x", encoding="utf-8") as stream:
        json.dump(value, stream, ensure_ascii=False, allow_nan=False, indent=2)
    os.chmod(path, 0o600)


def load_campaign(path):
    path = Path(path)
    require(path.stat().st_size <= 30 * 1024 * 1024, "Manifest is too large")
    envelope = json.loads(path.read_text(encoding="utf-8"))
    raw = envelope["manifest_json"].encode("utf-8")
    require(hashlib.sha256(raw).hexdigest() == envelope["manifest_sha256"], "Manifest digest mismatch")
    manifest = json.loads(raw)
    require(manifest["schema_version"] == "1.0" and manifest["family"] in FAMILIES, "Unknown protocol")
    rows = manifest["rows"]
    require(60 <= len(rows) <= 20000, "Expected 60–20000 rows")
    require(len({r["key"] for r in rows}) == len(rows), "Duplicate sample identifiers")
    partitions = {s: [r for r in rows if r["split"] == s] for s in ("train", "validation", "test")}
    require(sum(map(len, partitions.values())) == len(rows), "Unknown partition")
    require(all(len(v) >= 10 for v in partitions.values()), "Insufficient independent test data")
    for row in rows:
        require(re.fullmatch(r"[a-f0-9]{64}", row["key"]) is not None, "Unsafe sample key")
        require(re.fullmatch(r"[a-f0-9]{64}", row["group"]) is not None, "Unsafe group key")
    if manifest["family"] == "demand":
        for left, right in (("train", "validation"), ("validation", "test")):
            require(max(r["date"] for r in partitions[left]) < min(r["date"] for r in partitions[right]), "Temporal leakage")
        for series in grouped(rows).values():
            dates = sorted(date.fromisoformat(r["date"]) for r in series)
            require(len(dates) >= 120 and len(set(dates)) == len(dates), "Insufficient/duplicate dates")
            require((dates[-1] - dates[0]).days + 1 == len(dates), "Missing dates must not be guessed")
            require(all(isinstance(r["value"], int) and 0 <= r["value"] <= 100000 for r in series), "Invalid demand")
    else:
        groups = {}
        images = set()
        for row in rows:
            require(groups.setdefault(row["group"], row["split"]) == row["split"], "Group leakage")
            if "image_sha256" in row:
                require(re.fullmatch(r"[a-f0-9]{64}", row["image_sha256"]) is not None, "Unsafe image digest")
                require(row["image_sha256"] not in images, "Duplicate image")
                images.add(row["image_sha256"])
    manifest["manifest_sha256"] = envelope["manifest_sha256"]
    return manifest


def grouped(rows):
    result = defaultdict(list)
    for row in rows:
        result[row["group"]].append(row)
    return dict(result)


def report(manifest, baseline, candidate, artifact, version, output):
    require(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{2,99}", version) is not None, "Invalid version")
    require(version != manifest["baseline_version"], "Candidate needs its own version")
    expected = {r["key"] for r in manifest["rows"] if r["split"] == "test"}
    require(set(baseline) == expected == set(candidate), "Both models must cover exactly the same test")
    write_json(output, {
        "schema_version": "1.0", "campaign_id": manifest["campaign_id"],
        "manifest_sha256": manifest["manifest_sha256"], "baseline_version": manifest["baseline_version"],
        "candidate_version": version, "artifact_sha256": sha256(artifact),
        "predictions": [{"key": key, "baseline": baseline[key], "candidate": candidate[key]} for key in sorted(expected)],
    })


def prepare_vision(manifest, image_root, output):
    """COCO/ImageFolder/Paddle datasets; no augmentation before the group split."""
    from PIL import Image, ImageOps
    require(manifest["family"] in {"color", "damage", "plate"}, "Vision family required")
    root, output = Path(image_root).resolve(strict=True), Path(output)
    output.mkdir(parents=True, exist_ok=False, mode=0o700)
    Image.MAX_IMAGE_PIXELS = 64_000_000
    coco = {split: {"images": [], "annotations": [], "categories": [{"id": 0, "name": "damage"}]} for split in ("train", "validation", "test")}
    ocr = defaultdict(list)
    paths = {}
    for index, row in enumerate(manifest["rows"]):
        candidates = [root / (row["image_sha256"] + ext) for ext in (".jpg", ".png", ".webp")]
        found = [p for p in candidates if p.is_file()]
        require(len(found) == 1, "Each image digest needs exactly one .jpg, .png or .webp file")
        source = found[0]
        require(not source.is_symlink() and source.resolve().is_relative_to(root), "Unsafe image location")
        require(source.stat().st_size <= 8 * 1024 * 1024 and sha256(source) == row["image_sha256"], "Image digest/size mismatch")
        folder = output / row["split"] / (row["label"] if manifest["family"] == "color" else "images")
        if manifest["family"] == "color":
            require(row["label"] in COLORS, "Unknown colour")
        folder.mkdir(parents=True, exist_ok=True, mode=0o700)
        destination = folder / (row["key"] + ".png")
        with Image.open(source) as image:
            require(image.width <= 8000 and image.height <= 8000, "Oversized image")
            # For boxes, annotations refer to the EXIF-oriented displayed image.
            rgb = ImageOps.exif_transpose(image).convert("RGB")
            width, height = rgb.size
            rgb.save(destination, "PNG")  # Strip EXIF/GPS, preserve pixels (lossless).
        os.chmod(destination, 0o600)
        paths[row["key"]] = str(destination)
        split = coco[row["split"]]
        split["images"].append({"id": index, "file_name": destination.name, "width": width, "height": height})
        for box in row.get("boxes", []):
            require(all(math.isfinite(float(v)) for v in box.values()), "Non-finite box")
            require(0 <= box["x"] < 1 and 0 <= box["y"] < 1 and 0 < box["w"] <= 1 and 0 < box["h"] <= 1, "Invalid box")
            require(box["x"] + box["w"] <= 1.000001 and box["y"] + box["h"] <= 1.000001, "Out-of-bounds box")
            split["annotations"].append({"id": len(split["annotations"]) + 1, "image_id": index, "category_id": 0, "bbox": [box["x"] * width, box["y"] * height, box["w"] * width, box["h"] * height], "area": box["w"] * width * box["h"] * height, "iscrowd": 0})
        if manifest["family"] == "plate":
            require("\n" not in row["label"] and "\t" not in row["label"], "Invalid OCR label")
            # The whole canonical transcript (including separators) belongs in the approved dictionary.
            ocr[row["split"]].append(f"{destination.relative_to(output).as_posix()}\t{row['label']}")
    for split, payload in coco.items():
        if manifest["family"] == "damage":
            write_json(output / split / "annotations.json", payload)
        if manifest["family"] == "plate":
            (output / f"{split}.txt").write_text("\n".join(ocr[split]) + "\n", encoding="utf-8")
    write_json(output / "image-index.json", paths)
    return paths


def demand_examples(manifest, make_feature_row):
    import pandas as pd
    examples = {h: {s: [] for s in ("train", "validation", "test")} for h in range(1, 8)}
    for group, rows in grouped(manifest["rows"]).items():
        rows = sorted(rows, key=lambda row: row["date"])
        frame = pd.DataFrame({"date_local": pd.to_datetime([r["date"] for r in rows]), "observed_departures": [r["value"] for r in rows], "series_id": group, "tenant_key": group})
        for target_index, row in enumerate(rows):
            for horizon in range(1, 8):
                cutoff = target_index - horizon
                if cutoff < 27:
                    require(row["split"] != "test", "Test lacks 28 preceding days for every horizon")
                    continue
                features = make_feature_row(frame.iloc[:cutoff + 1], horizon)
                examples[horizon][row["split"]].append((row["key"], features, row["value"]))
    return examples


def train_demand(manifest, baseline_path, output, version):
    import joblib
    import numpy as np
    import pandas as pd
    from sklearn.compose import ColumnTransformer
    from sklearn.ensemble import HistGradientBoostingRegressor
    from sklearn.pipeline import Pipeline
    from sklearn.preprocessing import OneHotEncoder
    # The existing adapter checks Python/packages AND the trusted frozen artifact digest before joblib.load.
    import sys
    sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
    import run_demand_forecast as runtime
    baseline = runtime.load_bundle(Path(baseline_path))
    examples = demand_examples(manifest, runtime.make_feature_row)
    columns = baseline["feature_columns"]
    categorical = [name for name in columns if name in {"series_id", "provider", "target_weekday"}]
    numeric = [name for name in columns if name not in categorical]
    output = Path(output)
    output.mkdir(parents=True, exist_ok=False, mode=0o700)
    trained = {**baseline, "version": version, "ready_for_saas": False, "point_models": {}, "quantile_models": {}}
    before, after = defaultdict(lambda: [None] * 7), defaultdict(lambda: [None] * 7)
    selection = {}
    for horizon, partitions in examples.items():
        def xy(split):
            values = partitions[split]
            require(len(values) >= 10, "Insufficient history after temporal embargo")
            return pd.concat([r[1] for r in values], ignore_index=True)[columns], np.asarray([r[2] for r in values], dtype=float)
        x_train, y_train = xy("train")
        x_val, y_val = xy("validation")
        x_test, _ = xy("test")
        require(y_train.sum() > 0, "No positive observed departures in training")
        def pipeline(regularization, loss="poisson", quantile=None):
            transformer = ColumnTransformer([("category", OneHotEncoder(handle_unknown="ignore", sparse_output=False), categorical), ("numeric", "passthrough", numeric)])
            return Pipeline([("features", transformer), ("model", HistGradientBoostingRegressor(loss=loss, quantile=quantile, max_iter=100, max_leaf_nodes=15, min_samples_leaf=10, l2_regularization=regularization, early_stopping=False, random_state=manifest["seed"]))])
        choices = []
        for regularization in (1.0, 10.0):
            model = pipeline(regularization).fit(x_train, y_train)
            choices.append((float(np.mean(np.abs(y_val - np.maximum(0, model.predict(x_val))))), regularization, model))
        _, regularization, selected = min(choices, key=lambda choice: (choice[0], choice[1]))
        selection[str(horizon)] = {"regularization": regularization, "validation_mae": min(c[0] for c in choices)}
        trained["point_models"][f"point_h{horizon}"] = selected
        for quantile in runtime.QUANTILES:
            trained["quantile_models"][f"q{round(quantile * 100):02d}_h{horizon}"] = pipeline(regularization, "quantile", quantile).fit(x_train, y_train)
        reference = baseline["point_models"][f"point_h{horizon}"]
        for row, b, c in zip(partitions["test"], reference.predict(x_test), selected.predict(x_test)):
            before[row[0]][horizon - 1] = float(max(0, b))
            after[row[0]][horizon - 1] = float(max(0, c))
    artifact = output / "candidate.joblib"
    joblib.dump(trained, artifact)
    write_json(output / "validation-selection.json", selection)
    report(manifest, before, after, artifact, version, output / "report.json")
    return artifact


def train_anomaly(manifest, output, version):
    import joblib
    import numpy as np
    from sklearn.ensemble import IsolationForest
    from sklearn.metrics import f1_score
    features = ["late_hours", "km_per_day", "fuel_drop_pct"]
    rows = {split: [r for r in manifest["rows"] if r["split"] == split] for split in ("train", "validation", "test")}
    matrix = {split: np.asarray([[float(r[f]) for f in features] for r in values]) for split, values in rows.items()}
    require(all(np.isfinite(v).all() and (v >= 0).all() for v in matrix.values()), "Invalid numeric features")
    # Recalibrate on train only. Operational MAD ranks each batch; this experiment freezes train statistics.
    center = np.median(matrix["train"], axis=0)
    mad = np.median(np.abs(matrix["train"] - center), axis=0)
    q1, q3 = np.percentile(matrix["train"], [25, 75], axis=0)
    scale = np.maximum(np.maximum(1.4826 * mad, (q3 - q1) / 1.349), np.maximum(np.abs(center), 1) * 1e-6)
    def mad_score(x):
        return np.sort(np.maximum((x - center) / scale, 0), axis=1)[:, -2:].mean(axis=1)
    challenger = IsolationForest(n_estimators=300, n_jobs=1, random_state=manifest["seed"]).fit(np.log1p(matrix["train"]))
    scores = {"baseline": {s: mad_score(x) for s, x in matrix.items()}, "candidate": {s: -challenger.score_samples(np.log1p(x)) for s, x in matrix.items()}}
    val_labels = [r["label"] for r in rows["validation"]]
    require(set(val_labels) == {0, 1}, "Validation requires reviewed anomalies and normal examples")
    thresholds = {}
    for side in scores:
        # Both models get the same validation-only threshold selection budget.
        choices = np.quantile(scores[side]["train"], [.80, .85, .90, .95, .975, .99])
        thresholds[side] = float(max(choices, key=lambda threshold: f1_score(val_labels, scores[side]["validation"] >= threshold, zero_division=0)))
    predictions = {side: {r["key"]: int(v >= thresholds[side]) for r, v in zip(rows["test"], scores[side]["test"])} for side in scores}
    output = Path(output)
    output.mkdir(parents=True, exist_ok=False, mode=0o700)
    artifact = output / "candidate.joblib"
    joblib.dump({"version": version, "model": challenger, "features": features, "threshold": thresholds["candidate"], "ready_for_saas": False}, artifact)
    write_json(output / "recalibration.json", {"reference_semantics": "MAD statistics frozen on training data; not a replay of the production batch ranking", "center": center.tolist(), "scale": scale.tolist(), "thresholds": thresholds})
    report(manifest, predictions["baseline"], predictions["candidate"], artifact, version, output / "report.json")
    return artifact
