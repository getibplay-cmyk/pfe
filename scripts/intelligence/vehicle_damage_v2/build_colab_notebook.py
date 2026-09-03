#!/usr/bin/env python3
"""Generate the output-free RT-DETRv2 Colab notebook."""

from __future__ import annotations

import json
import textwrap
from pathlib import Path


ROOT = Path(__file__).resolve().parents[3]
OUTPUT = ROOT / "notebooks/colab/vehicle_damage_rtdetrv2.ipynb"


def lines(source: str) -> list[str]:
    text = textwrap.dedent(source).strip("\n") + "\n"
    return text.splitlines(keepends=True)


def markdown(source: str) -> dict[str, object]:
    return {"cell_type": "markdown", "metadata": {}, "source": lines(source)}


def code(source: str) -> dict[str, object]:
    return {
        "cell_type": "code",
        "execution_count": None,
        "metadata": {},
        "outputs": [],
        "source": lines(source),
    }


CELLS = [
    markdown(
        """
        # RentFleet — dommages v2 avec RT-DETRv2-S

        Ce notebook entraîne un **détecteur de régions candidates**, pas un
        décideur métier. Il ne crée jamais de dommage, frais, responsabilité ou
        décision contractuelle. Les sorties restent consultatives et doivent
        être revues par une personne autorisée.

        `smoke` vérifie la chaîne GPU sur un petit sous-ensemble. Le mode
        `detector_candidate` entraîne le détecteur sur les splits train et
        validation. Aucun des deux modes ne lit le test final v1.1.
        """
    ),
    markdown(
        """
        ## Verrous scientifiques

        - RT-DETRv2 officiel, dépôt et commit épinglés dans le code public.
        - Images et annotations privées uniquement dans Drive/Colab.
        - Mapping des images sources repris du manifeste v1.1 gelé.
        - SHA-256 de chaque image brute revérifié avant conversion COCO.
        - Split `test` explicitement refusé pendant l'entraînement.
        - Un smoke réussi prouve l'exécution et l'export ONNX, **pas la précision**.
        - La qualification à 95 % exige ensuite de vrais négatifs propres,
          au moins deux domaines, calibration et test final consulté une fois.
        """
    ),
    code(
        """
        #@title 1. Monter Drive et vérifier le GPU
        from google.colab import drive
        drive.mount('/content/drive')

        from pathlib import Path
        import hashlib, json, os, platform, shutil, subprocess, sys, urllib.request
        import torch

        assert torch.cuda.is_available(), "Sélectionnez Exécution > Modifier le type d'exécution > GPU."
        ENVIRONMENT = {
            'python': sys.version,
            'platform': platform.platform(),
            'torch': torch.__version__,
            'cuda': torch.version.cuda,
            'gpu': torch.cuda.get_device_name(0),
            'cuda_available': True,
        }
        print(json.dumps(ENVIRONMENT, ensure_ascii=False, indent=2, sort_keys=True))
        """
    ),
    code(
        """
        #@title 2. Paramètres v2 — aucun test final
        MODE = 'smoke' #@param ['smoke', 'detector_candidate']
        SEED = 20260824
        REPOSITORY = 'https://github.com/getibplay-cmyk/pfe.git'
        GIT_REF = 'science/vehicle-damage-v2-rtdetr'
        UPSTREAM_REPOSITORY = 'https://github.com/lyuwenyu/RT-DETR.git'
        UPSTREAM_COMMIT = '068dfde65f2667ad6555883c69d73de886518cad'
        PRETRAINED_URL = 'https://github.com/lyuwenyu/storage/releases/download/v0.2/rtdetrv2_r18vd_120e_coco_rerun_48.1.pth'

        DRIVE_ROOT = Path('/content/drive/MyDrive/RentFleet_PFE/S7_vehicle_vision_assistant')
        RAW_ARCHIVE = DRIVE_ROOT / 'donnees_brutes_privees/Car_parts_and_car_damages_dataset.zip'
        LEGACY_MANIFEST = DRIVE_ROOT / 'splits_geles/S7_DAMAGE_manifest_v1.1.csv'
        REPO_DIR = Path('/content/pfe-damage-v2')
        UPSTREAM_DIR = Path('/content/RT-DETR-pinned')
        HITL_ROOT = Path('/content/hitl_raw')
        LOCAL_ARCHIVE = Path('/content/Car_parts_and_car_damages_dataset.zip')
        COCO_ROOT = Path(f'/content/rentfleet-damage-v2-coco-{MODE}')
        LOCAL_RUN = Path(f'/content/rentfleet-damage-v2-{MODE}')
        RUN_ID = f'rtdetrv2_s_damage_v2_{MODE}_seed{SEED}'
        DRIVE_RUN = DRIVE_ROOT / 'modeles' / RUN_ID
        EPOCHS = 1 if MODE == 'smoke' else 60
        TRAIN_BATCH = 2 if MODE == 'smoke' else 4
        VALIDATION_BATCH = 2 if MODE == 'smoke' else 4
        SMOKE_IMAGES = 8 if MODE == 'smoke' else 0

        assert MODE in {'smoke', 'detector_candidate'}
        assert RAW_ARCHIVE.is_file(), f'Archive HITL absente: {RAW_ARCHIVE}'
        assert LEGACY_MANIFEST.is_file(), f'Manifeste v1.1 absent: {LEGACY_MANIFEST}'
        print({'mode': MODE, 'epochs': EPOCHS, 'run_id': RUN_ID, 'legacy_test_read': False})
        """
    ),
    code(
        """
        #@title 3. Récupérer les sources publiques épinglées
        def run(command, *, cwd=None):
            print('+', ' '.join(map(str, command)))
            return subprocess.run(command, cwd=cwd, check=True)

        def clone_or_fast_forward(url, ref, destination):
            if not destination.exists():
                run(['git', 'clone', '--filter=blob:none', '--branch', ref, '--single-branch', url, str(destination)])
            else:
                run(['git', '-C', str(destination), 'fetch', 'origin', ref])
                run(['git', '-C', str(destination), 'checkout', ref])
                run(['git', '-C', str(destination), 'merge', '--ff-only', f'origin/{ref}'])

        clone_or_fast_forward(REPOSITORY, GIT_REF, REPO_DIR)
        if not UPSTREAM_DIR.exists():
            run(['git', 'clone', '--filter=blob:none', UPSTREAM_REPOSITORY, str(UPSTREAM_DIR)])
        run(['git', '-C', str(UPSTREAM_DIR), 'fetch', 'origin', UPSTREAM_COMMIT])
        run(['git', '-C', str(UPSTREAM_DIR), 'checkout', '--detach', UPSTREAM_COMMIT])

        rentfleet_head = subprocess.check_output(['git', '-C', str(REPO_DIR), 'rev-parse', 'HEAD'], text=True).strip()
        upstream_head = subprocess.check_output(['git', '-C', str(UPSTREAM_DIR), 'rev-parse', 'HEAD'], text=True).strip()
        assert upstream_head == UPSTREAM_COMMIT
        print({'rentfleet_head': rentfleet_head, 'rtdetr_head': upstream_head})
        """
    ),
    code(
        """
        #@title 4. Installer les dépendances scientifiques
        run([
            sys.executable, '-m', 'pip', 'install', '--disable-pip-version-check', '--quiet',
            '--requirement', str(UPSTREAM_DIR / 'rtdetrv2_pytorch/requirements.txt'),
        ])
        run([
            sys.executable, '-m', 'pip', 'install', '--disable-pip-version-check', '--quiet',
            '--requirement', str(REPO_DIR / 'scripts/intelligence/requirements-vehicle-damage-v2-colab.txt'),
        ])
        run([sys.executable, '-m', 'pip', 'check'])
        """
    ),
    code(
        """
        #@title 5. Copier, vérifier et extraire HITL sur le disque éphémère
        run([
            sys.executable, '-m', 'scripts.intelligence.vehicle_damage_v2.stage_hitl_archive',
            '--source', str(RAW_ARCHIVE),
            '--local-archive', str(LOCAL_ARCHIVE),
            '--extract-root', str(HITL_ROOT),
        ], cwd=REPO_DIR)
        """
    ),
    code(
        """
        #@title 6. Convertir les polygones en COCO sans lire le test
        prepare_command = [
            sys.executable, '-m', 'scripts.intelligence.vehicle_damage_v2.prepare_hitl_coco',
            '--manifest', str(LEGACY_MANIFEST),
            '--hitl-root', str(HITL_ROOT),
            '--output-root', str(COCO_ROOT),
            '--splits', 'train', 'validation', 'calibration',
        ]
        if SMOKE_IMAGES:
            prepare_command.extend(['--smoke-images-per-split', str(SMOKE_IMAGES)])
        run(prepare_command, cwd=REPO_DIR)
        preparation = json.loads((COCO_ROOT / 'preparation_report.json').read_text(encoding='utf-8'))
        assert preparation['legacy_test_read'] is False
        assert 'test' not in preparation['splits']
        print(json.dumps(preparation, ensure_ascii=False, indent=2, sort_keys=True))
        """
    ),
    code(
        """
        #@title 7. Générer la configuration RT-DETRv2-S pour le T4
        RTDETR_ROOT = UPSTREAM_DIR / 'rtdetrv2_pytorch'
        CONFIG = RTDETR_ROOT / 'configs/rentfleet_vehicle_damage_v2.yml'
        run([
            sys.executable, '-m', 'scripts.intelligence.vehicle_damage_v2.build_rtdetr_config',
            '--dataset-root', str(COCO_ROOT),
            '--output-dir', str(LOCAL_RUN),
            '--config', str(CONFIG),
            '--epochs', str(EPOCHS),
            '--train-batch-size', str(TRAIN_BATCH),
            '--validation-batch-size', str(VALIDATION_BATCH),
            '--workers', '2',
        ], cwd=REPO_DIR)
        print(CONFIG.read_text(encoding='utf-8'))
        """
    ),
    code(
        """
        #@title 8. Télécharger le checkpoint officiel et inventorier son empreinte
        PRETRAINED = Path('/content/rtdetrv2_r18vd_120e_coco_rerun_48.1.pth')
        if not PRETRAINED.is_file():
            urllib.request.urlretrieve(PRETRAINED_URL, PRETRAINED)

        def sha256(path):
            digest = hashlib.sha256()
            with path.open('rb') as handle:
                while chunk := handle.read(8 * 1024 * 1024):
                    digest.update(chunk)
            return digest.hexdigest()

        LOCAL_RUN.mkdir(parents=True, exist_ok=True)
        upstream_source = {
            'repository': UPSTREAM_REPOSITORY,
            'commit': UPSTREAM_COMMIT,
            'checkpoint_url': PRETRAINED_URL,
            'checkpoint_sha256': sha256(PRETRAINED),
            'checkpoint_bytes': PRETRAINED.stat().st_size,
        }
        (LOCAL_RUN / 'upstream_source.json').write_text(
            json.dumps(upstream_source, indent=2, sort_keys=True) + '\\n', encoding='utf-8'
        )
        print(json.dumps(upstream_source, indent=2, sort_keys=True))
        """
    ),
    code(
        """
        #@title 9. Entraîner le détecteur sur GPU — test final interdit
        run([
            sys.executable, 'tools/train.py',
            '--config', str(CONFIG),
            '--tuning', str(PRETRAINED),
            '--use-amp',
            '--seed', str(SEED),
            '--device', 'cuda',
        ], cwd=RTDETR_ROOT)
        assert (LOCAL_RUN / 'best.pth').is_file()
        assert (LOCAL_RUN / 'log.txt').is_file()
        """
    ),
    code(
        """
        #@title 10. Exporter en ONNX et vérifier une inférence finie
        MODEL_ONNX = LOCAL_RUN / 'model.onnx'
        run([
            sys.executable, 'tools/export_onnx.py',
            '--config', str(CONFIG),
            '--resume', str(LOCAL_RUN / 'best.pth'),
            '--output_file', str(MODEL_ONNX),
            '--input_size', '640',
            '--check',
        ], cwd=RTDETR_ROOT)

        # PyTorch 2.11 may export an ONNX protobuf plus model.onnx.data even
        # below 2 GB. Embed those tensors so the private SaaS artifact remains
        # a single portable model.onnx file.
        import onnx
        from onnx.external_data_helper import convert_model_from_external_data, uses_external_data

        exported_model = onnx.load(str(MODEL_ONNX), load_external_data=True)
        convert_model_from_external_data(exported_model)
        assert not any(uses_external_data(tensor) for tensor in exported_model.graph.initializer)
        embedded_onnx = LOCAL_RUN / 'model.embedded.onnx'
        onnx.save_model(exported_model, str(embedded_onnx), save_as_external_data=False)
        onnx.checker.check_model(str(embedded_onnx))
        MODEL_ONNX.unlink()
        embedded_onnx.replace(MODEL_ONNX)
        for external_data in LOCAL_RUN.glob(f'{MODEL_ONNX.name}.*'):
            external_data.unlink()

        validation_image = next((COCO_ROOT / 'images/validation').iterdir())
        run([
            sys.executable, '-m', 'scripts.intelligence.vehicle_damage_v2.verify_onnx_smoke',
            '--model', str(MODEL_ONNX),
            '--image', str(validation_image),
            '--report', str(LOCAL_RUN / 'onnx_smoke_report.json'),
            '--provider', 'CUDAExecutionProvider',
        ], cwd=REPO_DIR)
        """
    ),
    code(
        """
        #@title 11. Enregistrer les artefacts privés et la preuve agrégée dans Drive
        log_rows = [json.loads(line) for line in (LOCAL_RUN / 'log.txt').read_text(encoding='utf-8').splitlines() if line.strip()]
        last = log_rows[-1]
        validation_rows = [
            row for row in log_rows
            if len(row.get('test_coco_eval_bbox') or []) >= 2
        ]
        assert validation_rows, 'Aucune métrique COCO de validation dans log.txt.'
        best = max(
            validation_rows,
            key=lambda row: float(row['test_coco_eval_bbox'][0]),
        )
        coco_stats = best['test_coco_eval_bbox']
        final_coco_stats = last.get('test_coco_eval_bbox') or []
        summary = {
            'protocol_version': '2.0.0',
            'mode': MODE,
            'seed': SEED,
            'epochs_completed': int(last.get('epoch', -1)) + 1,
            'best_validation_epoch': int(best.get('epoch', -1)) + 1,
            'validation_bbox_ap': float(coco_stats[0]) if len(coco_stats) > 0 else None,
            'validation_bbox_ap50': float(coco_stats[1]) if len(coco_stats) > 1 else None,
            'final_validation_bbox_ap': float(final_coco_stats[0]) if len(final_coco_stats) > 0 else None,
            'final_validation_bbox_ap50': float(final_coco_stats[1]) if len(final_coco_stats) > 1 else None,
            'legacy_test_read': False,
            'qualification': False,
            'release_gate_passed': False,
            'reason': 'Détecteur expérimental; présence sur photos propres, calibration, couverture et test final non évalués.',
            'rentfleet_head': rentfleet_head,
            'rtdetr_head': upstream_head,
        }
        (LOCAL_RUN / 'environment.json').write_text(
            json.dumps(ENVIRONMENT, ensure_ascii=False, indent=2, sort_keys=True) + '\\n', encoding='utf-8'
        )
        (LOCAL_RUN / 'candidate_summary.json').write_text(
            json.dumps(summary, ensure_ascii=False, indent=2, sort_keys=True) + '\\n', encoding='utf-8'
        )
        artifacts = [
            LOCAL_RUN / 'best.pth',
            LOCAL_RUN / 'model.onnx',
            LOCAL_RUN / 'log.txt',
            LOCAL_RUN / 'environment.json',
            LOCAL_RUN / 'candidate_summary.json',
            LOCAL_RUN / 'onnx_smoke_report.json',
            LOCAL_RUN / 'upstream_source.json',
            COCO_ROOT / 'preparation_report.json',
            CONFIG,
        ]
        DRIVE_RUN.mkdir(parents=True, exist_ok=True)
        manifest = []
        for artifact in artifacts:
            destination = DRIVE_RUN / artifact.name
            shutil.copy2(artifact, destination)
            manifest.append({'name': destination.name, 'bytes': destination.stat().st_size, 'sha256': sha256(destination)})
        drive_smoke_report = DRIVE_RUN / 'onnx_drive_smoke_report.json'
        run([
            sys.executable, '-m', 'scripts.intelligence.vehicle_damage_v2.verify_onnx_smoke',
            '--model', str(DRIVE_RUN / MODEL_ONNX.name),
            '--image', str(validation_image),
            '--report', str(drive_smoke_report),
            '--provider', 'CUDAExecutionProvider',
        ], cwd=REPO_DIR)
        manifest.append({
            'name': drive_smoke_report.name,
            'bytes': drive_smoke_report.stat().st_size,
            'sha256': sha256(drive_smoke_report),
        })
        (DRIVE_RUN / 'SHA256SUMS.json').write_text(
            json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True) + '\\n', encoding='utf-8'
        )
        print(json.dumps(summary, ensure_ascii=False, indent=2, sort_keys=True))
        print('PRIVATE_RUN_SAVED', DRIVE_RUN)
        """
    ),
    markdown(
        """
        ## Interprétation obligatoire

        Une exécution verte confirme la conversion, l'entraînement GPU, l'export
        ONNX et l'inférence. Elle ne justifie pas l'installation dans le SaaS.
        Le modèle ne devient remplaçant de v1.1 qu'après vrais négatifs propres,
        calibration précision-couverture et qualification finale à passage unique.
        """
    ),
]


def main() -> int:
    notebook = {
        "cells": CELLS,
        "metadata": {
            "accelerator": "GPU",
            "colab": {"name": OUTPUT.name, "provenance": []},
            "kernelspec": {"display_name": "Python 3", "language": "python", "name": "python3"},
            "language_info": {"name": "python", "version": "3.x"},
        },
        "nbformat": 4,
        "nbformat_minor": 5,
    }
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(json.dumps(notebook, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    print(OUTPUT)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
