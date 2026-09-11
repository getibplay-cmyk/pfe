"""Generate the small guided notebook. No dataset, output, token or private ID in Git."""
import json
from pathlib import Path


def build():
    cells = []
    def markdown(text):
        cells.append({"cell_type": "markdown", "metadata": {}, "source": text.splitlines(keepends=True)})
    def code(text):
        cells.append({"cell_type": "code", "execution_count": None, "metadata": {}, "outputs": [], "source": text.splitlines(keepends=True)})
    markdown("""# RentFleet — Réentraînement piloté par le SaaS

**Parcours :** contributions autorisées → campagne figée → entraînement privé → comparaison → revue dans le SaaS → qualification technique.

Ce notebook consomme le manifeste téléchargé dans **Administration → Réentraînement des modèles**. Il ne demande aucun accès à la base de production. Les poids restent dans un Drive privé ; seul le rapport JSON revient au SaaS.

Pour les images : préparer les annotations vérifiées et les photos/crops autorisés. Les groupes représentent le **même véhicule, dossier ou source indépendante**, même si plusieurs photos sont prises. Une prédiction du modèle n’est pas une annotation humaine.

L’apprentissage peut échouer faute de données, de ressources GPU ou de poids sources. Une meilleure performance n’est jamais présumée. Les modèles en service restent gérés par leur déploiement versionné.
""")
    markdown("## 1. Ouvrir votre Drive privé et le code du projet")
    code('''from google.colab import drive
drive.mount('/content/drive')
from pathlib import Path
import json, subprocess, sys, datetime, shutil

CODE_REF = "main"  # Pour examiner la PR avant fusion : "feature/model-retraining-workbench".
SOURCE = Path('/content/rentfleet-training-source')
if not SOURCE.exists():
    subprocess.run(['git', 'clone', 'https://github.com/getibplay-cmyk/pfe.git', str(SOURCE)], check=True)
subprocess.run(['git', 'fetch', 'origin', CODE_REF], cwd=SOURCE, check=True)
subprocess.run(['git', 'checkout', '--detach', 'FETCH_HEAD'], cwd=SOURCE, check=True)
SOURCE_COMMIT = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=SOURCE, text=True).strip()
print('Révision utilisée :', SOURCE_COMMIT)
assert (SOURCE / 'scripts/intelligence/training/run_session.py').is_file(), 'Choisir la branche de la PR tant qu’elle n’est pas fusionnée.'
''')
    markdown("## 2. Charger le manifeste téléchargé dans le SaaS\nLe fichier ne doit être placé que dans un espace privé. Les images ne sont jamais intégrées au dépôt Git.")
    code('''from google.colab import files
uploaded = files.upload()
assert len(uploaded) == 1, 'Choisir un seul manifeste de campagne.'
manifest_name = next(iter(uploaded))
MANIFEST = Path('/content') / Path(manifest_name).name
MANIFEST.write_bytes(uploaded[manifest_name])
sys.path.insert(0, str(SOURCE / 'scripts/intelligence/training'))
from workbench import load_campaign
campaign = load_campaign(MANIFEST)
print('Modèle :', campaign['family'], '— partitions :', campaign['counts'])
''')
    markdown("""## 3. Choisir les fichiers privés et le dossier de la tentative

Les images sources portent le nom **SHA256.jpg**, **SHA256.png** ou **SHA256.webp** correspondant à l’index annoté. Utiliser les fichiers originaux de l’index ; le préparateur retire ensuite les métadonnées.

- **Demande** : bundle J5 de référence déjà qualifié ; son empreinte est vérifiée avant chargement.
- **Anomalies** : aucune source binaire requise. La référence MAD est recalibrée sur l’apprentissage ; ce n’est pas un rejeu du classement opérationnel par lot.
- **Couleur** : ONNX V8 qualifié. Le candidat est réappris depuis MobileNet/ImageNet et conserve les neuf classes.
- **Dommages** : checkout RT-DETR approuvé, checkpoint d’apprentissage approuvé et ONNX de référence. Le guide précise les empreintes et fichiers à choisir.
- **Plaque** : checkout PaddleOCR approuvé, configuration arabe et dictionnaire compatibles, poids sources et export OCR de référence. L’évaluation porte sur les recadrages, pas sur la chaîne ANPR complète.
""")
    code('''WORK = Path('/content/drive/MyDrive/RentFleet_PFE/reentrainement')
VERSION = 'candidate-' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S')
SESSION = WORK / campaign['campaign_id'] / VERSION
config = {
    'manifest_path': str(MANIFEST), 'session_root': str(SESSION), 'version': VERSION,
    'image_root': str(WORK / 'images_verifiees'),
    'baseline_path': str(WORK / 'references' / 'MODELE_A_SELECTIONNER'),
    'baseline_sha256': '',  # Dommages : ONNX approuvé ; plaque : empreinte de baseline_inventory.
    'baseline_inventory': str(WORK / 'references' / 'ocr-sha256.json'),
    'checkpoint': str(WORK / 'sources' / 'POIDS_APPRENTISSAGE_A_SELECTIONNER'),
    'checkpoint_sha256': '',
    'fine_tune_color': False,  # True pour réutiliser un checkpoint .pth approuvé avec classes et state_dict.
    'upstream': '/content/RT-DETR',  # Ou checkout PaddleOCR pour la reconnaissance.
    'source_commit': '',  # Révision complète approuvée du projet PaddleOCR.
    'approved_config': str(WORK / 'sources' / 'ocr_arabe_approuve.yml'),
    'epochs': 10 if campaign['family'] == 'color' else 30,
}
CONFIG_FILE = Path('/content/rentfleet-training-config.json')
CONFIG_FILE.write_text(json.dumps(config, ensure_ascii=False), encoding='utf-8')
print('Nouvelle tentative privée :', SESSION)
''')
    markdown("""## 4. Préparer l’environnement d’exécution

Les modèles tabulaires utilisent un environnement **Python 3.12 séparé**, avec les dépendances déjà figées du projet. Le petit utilitaire `uv` sert uniquement à créer cet environnement dans Colab.

La vision réutilise l’environnement GPU qualifié des notebooks S7. **Couleur et dommages** : PyTorch/TorchVision du runtime Colab, Pillow, ONNX et ONNX Runtime. **Plaque** : environnement Paddle séparé de PyTorch, comme dans le notebook ANPR existant. Indiquer son exécutable dans `EXECUTION_PYTHON`. Les versions effectives sont enregistrées dans le dossier de la tentative.
""")
    code('''EXECUTION_PYTHON = sys.executable  # Plaque : ex. /content/venvs/rentfleet-paddleocr-v2/bin/python
if campaign['family'] in {'demand', 'anomaly'}:
    subprocess.run([sys.executable, '-m', 'pip', 'install', '--quiet', 'uv==0.8.22'], check=True)
    UV = shutil.which('uv')
    VENV = Path('/content/rentfleet-training-tabular')
    if not VENV.exists():
        subprocess.run([UV, 'venv', '--python', '3.12', str(VENV)], check=True)
    EXECUTION_PYTHON = str(VENV / 'bin/python')
    requirements = SOURCE / 'scripts/intelligence/requirements-demand-forecast.txt'
    subprocess.run([UV, 'pip', 'install', '--python', EXECUTION_PYTHON, '-r', str(requirements)], check=True)
elif campaign['family'] in {'color', 'damage'}:
    subprocess.run([EXECUTION_PYTHON, '-m', 'pip', 'install', '-r', str(SOURCE / 'scripts/intelligence/requirements-vehicle-damage-colab.txt'), 'onnxruntime==1.29.0'], check=True)
    subprocess.run([EXECUTION_PYTHON, '-c', 'import torch, torchvision, onnx, onnxruntime; print("GPU disponible :", torch.cuda.is_available())'], check=True)
else:
    subprocess.run([EXECUTION_PYTHON, '-c', 'import paddle, paddleocr, yaml; print("Paddle prêt, GPU :", paddle.device.cuda.device_count())'], check=True)
RUNNER = SOURCE / 'scripts/intelligence/training/run_session.py'
def execute(phase):
    subprocess.run([EXECUTION_PYTHON, str(RUNNER), '--config', str(CONFIG_FILE), '--phase', phase], check=True)
execute('prepare')
''')
    markdown("""## 5. Entraîner

Le code apprend sur `train` et sélectionne les réglages sur `validation`. Une relance utilise une nouvelle tentative. Le SaaS conserve les précédentes.

La demande entraîne sept horizons et leurs modèles de quantiles. Couleur et demande produisent directement leur rapport de test ; les anomalies comparent MAD et Isolation Forest. Les détecteurs et l’OCR nécessitent ensuite l’export du checkpoint sélectionné sur la validation.
""")
    code("execute('train')\n")
    markdown("""## 6. Exporter et comparer les détecteurs / l’OCR

Pour les dommages ou la plaque seulement, sélectionner le meilleur checkpoint d’après **la validation**. Pour RT-DETR, fixer aussi le seuil choisi sur la validation. Le test final n’est jamais utilisé pour ces choix. Pour PaddleOCR, `best_checkpoint` est le préfixe du fichier `.pdparams` (sans extension).

Pour les autres familles, exécuter directement la cellule : le rapport est déjà prêt.
""")
    code('''if campaign['family'] in {'damage', 'plate'}:
    config['best_checkpoint'] = str(SESSION / 'artifacts' / 'CHECKPOINT_SELECTIONNE_SUR_VALIDATION')
    config['candidate_threshold'] = 0.8236151338  # Remplacer par le seuil fixé sur validation.
    CONFIG_FILE.write_text(json.dumps(config, ensure_ascii=False), encoding='utf-8')
execute('compare')
''')
    markdown("""## 7. Rapporter la comparaison au SaaS

Importer **report.json** sur la page de la campagne. Les indicateurs sont recalculés à partir des prédictions. Un candidat peut être rejeté ou retenu pour qualification technique ; cette décision ne l’active pas en production.

Avant une intégration : provenance des données/poids, compatibilité des entrées et sorties, calibration, mesure des intervalles de prévision, qualité par classe, latence, mémoire, test complet et procédure de retour à la version précédente. La référence CatBoost rejetée reste exclue. OR-Tools se teste sur des scénarios, il ne se réentraîne pas.

Si une contribution est révoquée dans le SaaS, arrêter les sessions concernées et supprimer leurs copies et artefacts dérivés de Drive selon la décision de conservation. Le SaaS ne peut pas effacer une copie déjà téléchargée.
""")
    code('''REPORT = SESSION / 'artifacts' / 'report.json'
assert REPORT.is_file(), 'Terminer l’entraînement et la comparaison avant l’import.'
files.download(str(REPORT))
print('Poids, configuration et résultats conservés dans le dossier privé de cette tentative.')
''')
    for index, cell in enumerate(cells):
        cell["id"] = f"training-{index:02d}"
    return {"nbformat": 4, "nbformat_minor": 5, "metadata": {"kernelspec": {"display_name": "Python 3", "language": "python", "name": "python3"}, "colab": {"name": "model_retraining_workbench.ipynb", "provenance": []}}, "cells": cells}


if __name__ == '__main__':
    target = Path(__file__).resolve().parents[3] / 'notebooks/model_retraining_workbench.ipynb'
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(json.dumps(build(), ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
