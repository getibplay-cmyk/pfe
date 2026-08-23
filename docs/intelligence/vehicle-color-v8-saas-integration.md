# S7 couleur v8 — intégration SaaS consultative

## Décision livrée

Le modèle couleur gelé est intégré comme un module **consultatif uniquement**.
Une photo privée d’un véhicule déclenche un job Laravel sur la queue
`intelligence`; un processus Python isolé vérifie et exécute l’ONNX; Laravel
revalide ensuite la totalité du résultat avant de l’enregistrer. Une revue
humaine append-only peut accepter, rejeter ou ignorer la suggestion, mais
aucune de ces décisions ne modifie `vehicles.color` ni une autre table métier.

Le flag reste fermé par défaut :

```dotenv
RENTFLEET_COLOR_V8_ENABLED=false
```

## Provenance et gate gelés

L’entraînement, la sélection, la calibration et l’unique évaluation externe
ont été exécutés dans le notebook Colab GPU. Le bundle de déploiement final a
ensuite été conservé sur Google Drive et vérifié avant l’intégration.

| Élément | Valeur gelée |
|---|---:|
| Bundle ZIP SHA-256 | `2f0dbb5a44cc4a16c9f0498e314deaba4e63fdef92cde26a20a68f60721076e2` |
| `S7_COLOR_V8_FINAL.onnx` | 16 848 914 octets |
| ONNX SHA-256 | `5ec7757a7bafda0abd45685dd8e1178e5b6b79220ff61b6018398d00f2e86a76` |
| `S7_COLOR_V8_FINAL_METADATA.json` | 1 987 octets |
| Métadonnées SHA-256 | `661b0dcaa9b66fc69a2d8ba55eb21ec806e66c05d86c06ef4b2c5e7ff71901e6` |
| Macro-F1 externe finale | 0,914989 |
| Balanced accuracy | 0,90625 |
| Rappel minimal | 0,80 |
| ECE | 0,03346 |
| Précision acceptée | 1,00 |
| Couverture acceptée | 0,59375 |
| Fausse acceptation du rejet | 0,05 |
| Seuil d’acceptation | 0,977 |

Ontologie fermée : `black`, `blue`, `gray`, `green`, `orange`, `red`,
`white`, `yellow`, `__reject__`.

Ces métriques qualifient l’artefact sur le protocole externe gelé. Elles ne
constituent pas une garantie universelle sur les futures photos RentFleet.
L’abstention et la validation humaine restent obligatoires.

## Architecture de sécurité

1. Le navigateur envoie seulement `vehicle_id` et une image JPEG, PNG ou WebP.
2. Le serveur déduit le tenant et l’agence depuis le contexte authentifié.
3. Le runtime Pillow applique l’orientation EXIF puis réencode la photo sans
   EXIF, GPS, XMP, profil ICC ni commentaire. Seule cette copie assainie est
   stockée sur le disque Laravel privé, jamais sous `public/`.
4. La taille, le MIME et le SHA-256 sont recalculés sur la copie assainie. Son
   chemin exact est lié au `tenant_id`, au `run_id` et à l’extension par
   la validation PHP et une contrainte PostgreSQL; une analyse ne peut pas
   pointer vers la photo privée d’un autre tenant ou d’une autre exécution.
5. Le registre garde la taille et le SHA-256 assainis; ni le chemin ni les empreintes ne sont
   rendus dans l’interface ou l’audit.
6. Le job revalide l’acteur, le véhicule, la photo, l’ONNX et les métadonnées.
7. Le processus Python reçoit une liste d’arguments, un environnement privé de
   secrets et un délai maximum de 30 secondes.
8. Python reproduit `Resize((256,256), BICUBIC)`, `CenterCrop(224)`, le passage
   RGB, l’échelle 0–1 et la normalisation ImageNet.
9. Python et PHP recalculent indépendamment le top-1 supporté, la confiance et
   la règle d’abstention.
10. Le résultat et la revue portent toujours
   `NO_OPERATIONAL_ACTION`; les triggers PostgreSQL rendent les résultats
   terminaux immuables et les revues append-only.

## Installation CPU recommandée

Préparer un environnement Python 3.12 privé :

```bash
python3.12 -m venv .venv-color-v8
.venv-color-v8/bin/python -m pip install \
  --requirement scripts/intelligence/requirements-color-v8-runtime.txt
```

Extraire localement le ZIP final téléchargé depuis Drive, puis laisser la
commande d’installation vérifier et copier atomiquement les deux fichiers :

```bash
php artisan rentfleet:color-v8:install "/chemin/S7_COLOR_V8_FINAL_DEPLOYMENT_BUNDLE"
```

Configurer l’interpréteur sans activer encore le module :

```dotenv
COLOR_V8_PYTHON_BINARY=/chemin/.venv-color-v8/bin/python
COLOR_V8_EXECUTION_PROVIDER=CPUExecutionProvider
RENTFLEET_COLOR_V8_ENABLED=false
```

Appliquer les migrations et contrôler le déploiement :

```bash
php artisan migrate --force
php artisan rentfleet:doctor --production
php artisan queue:work --queue=intelligence,default --tries=1 --timeout=40
```

Une fois la CI, `rentfleet:doctor` et le worker validés, ouvrir seulement le
mode consultatif :

```dotenv
RENTFLEET_COLOR_V8_ENABLED=true
```

Après modification du fichier d’environnement, reconstruire le cache de
configuration suivant la procédure de déploiement habituelle.

## Worker GPU facultatif

Le GPU de Colab a servi à l’entraînement; il n’est pas requis pour l’inférence
du SaaS. Sur un worker NVIDIA compatible, utiliser un environnement séparé :

```bash
python3.12 -m venv .venv-color-v8-gpu
.venv-color-v8-gpu/bin/python -m pip install \
  --requirement scripts/intelligence/requirements-color-v8-runtime-gpu.txt
```

```dotenv
COLOR_V8_PYTHON_BINARY=/chemin/.venv-color-v8-gpu/bin/python
COLOR_V8_EXECUTION_PROVIDER=CUDAExecutionProvider
```

`rentfleet:doctor` refuse l’activation si `CUDAExecutionProvider` n’est pas
réellement disponible. ONNX Runtime 1.29.x GPU publié sur PyPI vise CUDA 13 et
cuDNN 9; la pile NVIDIA du worker doit donc être compatible. Ne jamais installer
`onnxruntime` et `onnxruntime-gpu` dans le même environnement.

## Exploitation et rollback

- Les nouvelles analyses sont limitées par défaut à 5 par minute par
  utilisateur et à 30 par heure par périmètre tenant/agence. Les variables
  `COLOR_V8_USER_RATE_LIMIT_PER_MINUTE` et
  `COLOR_V8_SCOPE_RATE_LIMIT_PER_HOUR` permettent un réglage de déploiement.
- Une seule analyse `queued` ou `running` est autorisée par véhicule.
- Une exécution expirée est fermée en `failed` avant un nouveau lancement.
- Un échec ne conserve ni suggestion ni score et n’expose pas `stderr`.
- Une abstention ne peut jamais être acceptée par la revue humaine.
- La photo reste accessible uniquement par la route privée, autorisée et
  auditée.
- Pour arrêter immédiatement les nouvelles analyses, remettre
  `RENTFLEET_COLOR_V8_ENABLED=false`, reconstruire le cache de configuration et
  laisser le worker clôturer ou expirer les jobs déjà présents.
- Le rollback applicatif ne doit pas supprimer les registres d’audit ou les
  revues existantes.

## Références techniques

- ONNX Runtime Python : <https://onnxruntime.ai/docs/api/python/api_summary.html>
- Fournisseur CUDA ONNX Runtime :
  <https://onnxruntime.ai/docs/execution-providers/CUDA-ExecutionProvider.html>
- Queues Laravel : <https://laravel.com/docs/12.x/queues>
- Processus Laravel : <https://laravel.com/docs/12.x/processes>
- Stockage Laravel : <https://laravel.com/docs/12.x/filesystem>
- Google Colab : <https://research.google.com/colaboratory/faq.html>
