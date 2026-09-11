"""Entrypoint used by the guided notebook, inside an isolated Python environment."""
from __future__ import annotations

import argparse
import importlib.metadata
import json
import platform
import shutil
import subprocess
from pathlib import Path
from zipfile import ZipFile

from workbench import load_campaign, prepare_vision, report, require, sha256, train_anomaly, train_demand, write_json


def run(config, phase):
    root = Path(config["session_root"]).resolve()
    source = Path(config["manifest_path"])
    manifest = load_campaign(source if phase == "prepare" else root / "campaign.json")
    family = manifest["family"]
    if phase == "prepare":
        root.mkdir(parents=True, exist_ok=False, mode=0o700)
        shutil.copyfile(source, root / "campaign.json")
        write_json(root / "session-config.json", config)
        if family in {"color", "damage", "plate"}:
            prepare_vision(manifest, config["image_root"], root / "prepared")
        print("Campagne vérifiée et partitions préparées :", manifest["counts"])
        return
    saved_config = json.loads((root / "session-config.json").read_text())
    # Tuning/export choices may be added later; data, reference and source selection remain frozen.
    for key in ("version", "baseline_path", "baseline_sha256", "upstream", "source_commit", "checkpoint", "checkpoint_sha256", "approved_config"):
        require(saved_config.get(key) == config.get(key), f"Frozen session parameter changed: {key}")
    artifact_dir = root / "artifacts"
    if phase == "train":
        versions = {}
        for package in ("numpy", "pandas", "scikit-learn", "joblib", "torch", "torchvision", "onnx", "onnxruntime", "paddlepaddle-gpu", "paddleocr"):
            try:
                versions[package] = importlib.metadata.version(package)
            except importlib.metadata.PackageNotFoundError:
                pass
        repo = Path(__file__).resolve().parents[3]
        commit = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=repo, text=True).strip()
        write_json(root / "training-started.json", {"python": platform.python_version(), "versions": versions, "source_commit": commit, "manifest_sha256": manifest["manifest_sha256"]})
        if family == "demand":
            train_demand(manifest, config["baseline_path"], artifact_dir, config["version"])
        elif family == "anomaly":
            train_anomaly(manifest, artifact_dir, config["version"])
        else:
            from vision_recipes import train_color, train_damage, train_plate
            paths = json.loads((root / "prepared/image-index.json").read_text())
            if family == "color":
                train_color(manifest, paths, config["baseline_path"], artifact_dir, config["version"], config.get("epochs", 10))
            elif family == "damage":
                train_damage(manifest, root / "prepared", config["upstream"], config["checkpoint"], config["checkpoint_sha256"], artifact_dir, config.get("epochs", 30))
            else:
                train_plate(manifest, root / "prepared", config["upstream"], config["source_commit"], config["approved_config"], config["checkpoint"], config["checkpoint_sha256"], artifact_dir)
        write_json(root / "training-completed.json", {"family": family, "version": config["version"]})
        print("Entraînement terminé. Résultats privés :", artifact_dir)
        return
    require((root / "training-completed.json").is_file(), "Complete training first")
    if family not in {"damage", "plate"}:
        require((artifact_dir / "report.json").is_file(), "Comparison report missing")
        print("Rapport prêt :", artifact_dir / "report.json")
        return
    from vision_recipes import damage_predictions, export_damage, export_plate, plate_predictions
    paths = json.loads((root / "prepared/image-index.json").read_text())
    baseline = Path(config["baseline_path"])
    if family == "damage":
        require(sha256(baseline) == config["baseline_sha256"], "Reference ONNX digest mismatch")
        candidate = export_damage(config["upstream"], artifact_dir / "training.yml", config["best_checkpoint"], artifact_dir / "candidate.onnx")
        threshold = float(config["candidate_threshold"])
        require(0 < threshold <= 1, "Candidate threshold must come from validation")
        write_json(root / "test-started.json", {"candidate_threshold": threshold, "validation_checkpoint": sha256(config["best_checkpoint"]), "reference_threshold": .8236151338})
        before = damage_predictions(manifest, paths, baseline, .8236151338)
        after = damage_predictions(manifest, paths, candidate, threshold)
    else:
        # Bind every reference export file, not just one weight shard.
        identity = json.loads(Path(config["baseline_inventory"]).read_text())
        require(identity and all(Path(name).name == name for name in identity), "Invalid reference inventory")
        require(sha256(config["baseline_inventory"]) == config["baseline_sha256"], "Reference inventory digest mismatch")
        require(set(identity) == {p.name for p in baseline.iterdir() if p.is_file()}, "Reference export inventory differs")
        for name, digest in identity.items():
            require(sha256(baseline / name) == digest, "Reference file digest mismatch")
        exported = artifact_dir / "inference"
        export_plate(config["upstream"], config["source_commit"], artifact_dir / "training.yml", config["best_checkpoint"], exported)
        write_json(root / "test-started.json", {"manifest_sha256": manifest["manifest_sha256"]})
        before = plate_predictions(manifest, paths, baseline)
        after = plate_predictions(manifest, paths, exported)
        candidate = artifact_dir / "candidate.zip"
        with ZipFile(candidate, "x") as archive:
            for path in sorted(exported.rglob("*")):
                if path.is_file():
                    require(not path.is_symlink(), "Unsafe export file")
                    archive.write(path, path.relative_to(exported).as_posix())
    report(manifest, before, after, candidate, config["version"], artifact_dir / "report.json")
    print("Comparaison prête à importer :", artifact_dir / "report.json")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", type=Path, required=True)
    parser.add_argument("--phase", choices=("prepare", "train", "compare"), required=True)
    args = parser.parse_args()
    run(json.loads(args.config.read_text(encoding="utf-8")), args.phase)
