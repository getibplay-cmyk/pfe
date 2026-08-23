#!/usr/bin/env python3
"""Generate the committed Colab notebook from auditable source cells."""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[3]
OUTPUT = ROOT / "notebooks/colab/vehicle_damage_efficientnetv2s.ipynb"


def markdown(source: str) -> dict[str, object]:
    return {
        "cell_type": "markdown",
        "metadata": {},
        "source": source.splitlines(keepends=True),
    }


def code(source: str) -> dict[str, object]:
    return {
        "cell_type": "code",
        "execution_count": None,
        "metadata": {},
        "outputs": [],
        "source": source.splitlines(keepends=True),
    }


CELLS = [
    markdown(
        """# RentFleet — détection consultative de dommages (EfficientNetV2-S)\n

Ce notebook entraîne un classifieur binaire `aucun dommage visible` / `dommage visible` sur GPU Colab. Il ne détermine jamais la responsabilité, le coût, la retenue ou une décision contractuelle. Toute sortie exige une revue humaine.\n

Le mode par défaut est `smoke` : il vérifie uniquement le GPU et l'architecture. Le mode `train` refuse de démarrer sans manifeste gelé et preuve de licence officielle. Les données et modèles restent dans le Drive privé; GitHub conserve le code et le protocole.\n"""
    ),
    markdown(
        """## 0. Verrou de provenance\n

Sources préenregistrées :\n

- [HITL Car Parts and Car Damages](https://humansintheloop.org/resources/datasets/car-parts-and-car-damages-dataset/) — CC0 1.0, formulaire officiel requis;\n
- [CarDD officiel](https://cardd-ustc.github.io/) — accord académique signé préalable;\n
- [TQVCD officiel](https://github.com/dxlabskku/TQVCD) — données disponibles sur demande aux auteurs.\n

Ne jamais utiliser un miroir non attesté. CarDD seul ne fournit pas les négatifs nécessaires au classifieur binaire.\n"""
    ),
    code(
        """#@title 1. Monter Drive et vérifier le GPU
from google.colab import drive
drive.mount('/content/drive')

from pathlib import Path
import json, os, subprocess, sys
import torch

assert torch.cuda.is_available(), "Sélectionnez Exécution > Modifier le type d'exécution > GPU."
print({
    'python': sys.version.split()[0],
    'torch': torch.__version__,
    'cuda': torch.version.cuda,
    'gpu': torch.cuda.get_device_name(0),
})
"""
    ),
    code(
        """#@title 2. Récupérer la branche scientifique GitHub
from google.colab import userdata
import base64

REPOSITORY = 'https://github.com/getibplay-cmyk/pfe.git'
GIT_REF = 'science/vehicle-damage-efficientnetv2s'
REPO_DIR = Path('/content/pfe')

def git_environment():
    env = os.environ.copy()
    try:
        token = userdata.get('GITHUB_TOKEN')
    except Exception:
        token = None
    if token:
        basic = base64.b64encode(f'x-access-token:{token}'.encode()).decode()
        env.update({
            'GIT_CONFIG_COUNT': '1',
            'GIT_CONFIG_KEY_0': 'http.https://github.com/.extraheader',
            'GIT_CONFIG_VALUE_0': f'AUTHORIZATION: basic {basic}',
        })
    return env

if not REPO_DIR.exists():
    subprocess.run(['git', 'clone', '--filter=blob:none', REPOSITORY, str(REPO_DIR)], check=True, env=git_environment())
subprocess.run(['git', '-C', str(REPO_DIR), 'fetch', 'origin', GIT_REF], check=True, env=git_environment())
subprocess.run(['git', '-C', str(REPO_DIR), 'checkout', '--force', GIT_REF], check=True)
print(subprocess.check_output(['git', '-C', str(REPO_DIR), 'rev-parse', 'HEAD'], text=True).strip())
"""
    ),
    code(
        """#@title 3. Installer les dépendances scientifiques figées
requirements = REPO_DIR / 'scripts/intelligence/requirements-vehicle-damage-colab.txt'
subprocess.run([
    sys.executable, '-m', 'pip', 'install', '--disable-pip-version-check',
    '--quiet', '--requirement', str(requirements)
], check=True)
subprocess.run([sys.executable, '-m', 'pip', 'check'], check=True)
"""
    ),
    code(
        """#@title 4. Paramètres privés Drive
MODE = 'smoke' #@param ['smoke', 'train']
DRIVE_ROOT = Path('/content/drive/MyDrive/RentFleet_PFE/S7_vehicle_vision_assistant')
MANIFEST = DRIVE_ROOT / 'splits_geles/S7_DAMAGE_manifest_v1.csv'
DATA_ROOT = DRIVE_ROOT / 'donnees_preparees/S7_DAMAGE_v1'
LICENSE_ROOT = DRIVE_ROOT / 'registre_sources_licences'
RUN_ID = 'efficientnetv2s_v1_seed20260823'
OUTPUT = DRIVE_ROOT / 'modeles_prives/vehicle_damage' / RUN_ID

print({
    'mode': MODE,
    'manifest_exists': MANIFEST.is_file(),
    'data_root_exists': DATA_ROOT.is_dir(),
    'output': str(OUTPUT),
})
"""
    ),
    code(
        """#@title 5. Smoke test GPU EfficientNetV2-S (ce résultat n'est pas une métrique PFE)
from torchvision.models import EfficientNet_V2_S_Weights, efficientnet_v2_s

weights = EfficientNet_V2_S_Weights.IMAGENET1K_V1
smoke_model = efficientnet_v2_s(weights=weights).cuda().eval()
with torch.inference_mode(), torch.autocast(device_type='cuda', dtype=torch.float16):
    smoke_output = smoke_model(torch.zeros(1, 3, 384, 384, device='cuda'))
assert tuple(smoke_output.shape) == (1, 1000)
print('GPU_SMOKE_OK', tuple(smoke_output.shape))
del smoke_model, smoke_output
torch.cuda.empty_cache()
"""
    ),
    code(
        """#@title 6. Valider le manifeste avant tout entraînement
validator = REPO_DIR / 'scripts/intelligence/vehicle_damage/validate_manifest.py'
if MODE == 'train':
    if not MANIFEST.is_file():
        raise FileNotFoundError(
            'STOP: manifeste gelé absent. Complétez d’abord le formulaire officiel, '
            'déposez la preuve de licence et construisez S7_DAMAGE_manifest_v1.csv.'
        )
    subprocess.run([sys.executable, str(validator), str(MANIFEST), '--json'], check=True)
else:
    print('SMOKE uniquement — aucun accès au test final, aucune métrique scientifique.')
"""
    ),
    code(
        """#@title 7. Entraîner, calibrer, tester une fois et qualifier
trainer = REPO_DIR / 'scripts/intelligence/vehicle_damage/train_efficientnetv2s.py'
if MODE == 'train':
    command = [
        sys.executable, str(trainer),
        '--manifest', str(MANIFEST),
        '--data-root', str(DATA_ROOT),
        '--license-root', str(LICENSE_ROOT),
        '--output', str(OUTPUT),
        '--epochs', '15',
        '--head-epochs', '3',
        '--patience', '4',
        '--batch-size', '16',
        '--workers', '2',
        '--bootstrap', '1000',
        '--resume',
    ]
    completed = subprocess.run(command)
    if completed.returncode not in (0, 2):
        raise RuntimeError(f'Échec technique de l’entraînement: code {completed.returncode}')
    print('QUALIFIED' if completed.returncode == 0 else 'STOP_NOT_QUALIFIED')
else:
    print('Passez MODE à train seulement après validation officielle des données.')
"""
    ),
    code(
        """#@title 8. Lire les preuves et vérifier les SHA-256
if MODE == 'train':
    metrics = json.loads((OUTPUT / 'metrics.json').read_text(encoding='utf-8'))
    print(json.dumps(metrics['test'], ensure_ascii=False, indent=2))
    print(json.dumps(metrics['release_gate'], ensure_ascii=False, indent=2))
    subprocess.run(['sha256sum', '--check', 'SHA256SUMS'], cwd=OUTPUT, check=True)
    print('ONNX exporté:', (OUTPUT / 'model.onnx').is_file())
else:
    print('Aucune preuve scientifique produite en mode smoke.')
"""
    ),
    markdown(
        """## Lecture du résultat\n

Un modèle est utilisable uniquement si `release_gate.passed = true` et si les SHA-256 sont valides. Le seuil bloquant est inclusif (`>= 0,75`) pour balanced accuracy, macro-F1 et rappel dommage; ECE doit être `<= 0,08`. La cible PFE reste 0,90.\n

Même en cas de succès, l'intégration SaaS demeure consultative et fait l'objet d'une PR séparée avec revue humaine, stockage privé et exécution en queue.\n"""
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
