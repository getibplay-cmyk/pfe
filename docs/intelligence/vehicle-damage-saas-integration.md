# S7 dommages RT-DETRv2-S — pilote SaaS consultatif

> **Périmètre de lot :** cette tranche appartient au **Lot 08 Intelligence**,
> postérieur et distinct du Lot 06 « release candidate sans IA ». Elle ne
> modifie pas le périmètre gelé de cette release candidate et ne conditionne
> aucun flux métier.

## Décision livrée

Le checkpoint RT-DETRv2-S `soup_19_24_29_centered_nms_0.72` remplace le backend
EfficientNetV2-S pour le pilote. Laravel assainit et conserve la photo sur le
stockage privé, crée une exécution PostgreSQL, puis délègue l'inférence ONNX à
un job de la queue `intelligence`. Le résultat est revalidé par Laravel avant
d'être affiché sous forme de boîtes candidates. L'ancien backend reste
sélectionnable pour un rollback sans supprimer l'historique.

Le module est **consultatif uniquement** : confirmer ou rejeter une suggestion
ne crée jamais de `damage_report`, de frais, de responsabilité, de retenue ou de
changement sur le véhicule ou l'inspection. Une action métier éventuelle reste
un flux humain séparé.

Le flag reste fermé par défaut :

```dotenv
RENTFLEET_DAMAGE_V1_ENABLED=false
DAMAGE_V1_BACKEND=rtdetrv2_s
```

## Contrat scientifique gelé

| Élément | Valeur publique gelée |
|---|---:|
| Modèle | RT-DETRv2-S R18vd, entrée 640 |
| Version SaaS | `s7-damage-rtdetrv2-s-soup192429-v1.0` |
| Checkpoints moyennés | époques 19/24/29, poids 0,25/0,50/0,25 |
| AP validation | 0,296775 |
| AP50 validation | 0,477584 |
| AP75 validation | 0,286214 |
| NMS | hard, class-agnostic, IoU 0,72 |
| Profil | `precision_90` |
| Seuil de décision | 0,8236151338 |
| Précision IoU50 au seuil | 0,900901 |
| Rappel IoU50 au seuil | 0,225861 |
| Gate exigé | AP ≥ 0,40 et AP50 ≥ 0,65 — **échoué** |

Ces mesures et le seuil ont été choisis sur la même validation de
développement : ils sont optimistes et ne constituent pas une qualification
finale. Le profil haute précision diminue les faux positifs mais manque une
part importante des dommages. Ni calibration ni test final n'ont été utilisés;
le test final reste scellé.

Le SaaS exige les SHA-256 locaux de `model.onnx` et `model_card.json`, recalcule
les empreintes et refuse une carte dont le checkpoint, le prétraitement, le
seuil, le NMS, les preuves de validation ou les garde-fous ne correspondent pas
au contrat fermé.

## Périmètre et limites

- seules les inspections de type `return` au statut `completed` sont éligibles;
- l'image entière est redimensionnée en RGB 640 × 640 pour une passe RT-DETR;
- les cadres indiquent des boîtes candidates, pas les contours exacts d'un
  dommage;
- une photo trop petite, sombre, surexposée, peu contrastée ou potentiellement
  floue produit une abstention et aucune inférence;
- une absence de boîte positive n'exclut pas un dommage hors champ, minuscule ou
  hors domaine;
- la qualification publique ne valide pas encore les photos réelles RentFleet;
  un pilote local consenti reste obligatoire.

## Architecture de sécurité

1. Le navigateur envoie uniquement l'identifiant d'une inspection autorisée et
   une image JPEG, PNG ou WebP de 8 Mo maximum.
2. Le serveur déduit le tenant et l'agence du contexte authentifié; ces champs
   sont interdits dans la requête cliente.
3. Un processus Pillow isolé applique l'orientation EXIF et produit un JPEG RGB
   de 2 048 px maximum sans EXIF, GPS, XMP, ICC ni commentaire.
4. Seule la copie assainie est conservée sous le disque Laravel privé. Le chemin
   est lié par contrat au tenant et à l'UUID de l'exécution.
5. La base enregistre la taille, les dimensions et les empreintes, mais l'UI et
   l'audit n'affichent ni chemin privé ni SHA-256.
6. Le job revalide l'acteur, l'inspection, la photo, l'ONNX et la carte du
   modèle avant de démarrer le processus Python.
7. Python ne reçoit aucun accès à PostgreSQL ni secret applicatif. Il écrit un
   JSON fermé sur stdout, limité en taille et sans chemin.
8. Laravel vérifie indépendamment chaque clé, valeur, score et rectangle. Toute
   sortie supplémentaire ou incohérente ferme l'exécution en échec contrôlé.
9. Les triggers PostgreSQL rendent les exécutions terminales immuables, les
   revues append-only et interdisent la confirmation d'une abstention ou d'un
   résultat négatif.
10. Tous les résultats et toutes les revues portent
    `NO_OPERATIONAL_ACTION`.

## Export et bundle privés

L'export suit le script officiel, épinglé au commit enregistré par le run. Il
ne charge aucun dataloader et ne reçoit aucun chemin de calibration ou de test.
Dans un environnement disposant de PyTorch, TorchVision et `onnx` :

```bash
git clone https://github.com/lyuwenyu/RT-DETR.git /chemin/RT-DETR-pinned
git -C /chemin/RT-DETR-pinned checkout 068dfde65f2667ad6555883c69d73de886518cad
python -m pip install \
  --requirement scripts/intelligence/requirements-vehicle-damage-colab.txt
python scripts/intelligence/vehicle_damage/export_rtdetrv2_s_onnx.py \
  --upstream /chemin/RT-DETR-pinned \
  --checkpoint /chemin/prive/selected_checkpoint_soup_19_24_29_inference_only.pth \
  --output /chemin/prive/export/model.onnx
python scripts/intelligence/vehicle_damage/build_rtdetrv2_s_bundle.py \
  --checkpoint /chemin/prive/selected_checkpoint_soup_19_24_29_inference_only.pth \
  --policy /chemin/prive/selected_inference_policy.json \
  --onnx /chemin/prive/export/model.onnx \
  --output /chemin/prive/rentfleet-rtdetrv2-s-bundle
```

L'exporteur vérifie le checkpoint avant d'autoriser le chargeur historique de
PyTorch et incorpore les éventuels poids ONNX externalisés dans un fichier
autonome. Le constructeur vérifie le checkpoint public exact (80 772 267
octets et SHA-256
`3544b693d9014392b5a9a0d87e6951646455ed268ca1825ee5aa4fe07cd7b92e`), la
politique, la structure ONNX, l'absence de données externes et les scellés avant
d'écrire atomiquement le bundle.

## Installation CPU recommandée

Après le pull, préparer un environnement Python 3.12 privé :

```bash
python3.12 -m venv .venv-damage-v1
.venv-damage-v1/bin/python -m pip install \
  --requirement scripts/intelligence/requirements-vehicle-damage-runtime.txt
.venv-damage-v1/bin/python -m pip check
```

Dans le dossier privé du run Colab qualifié, recalculer localement les deux
empreintes sans les publier :

```bash
sha256sum model.onnx model_card.json
```

Configurer les chemins et empreintes privés sans ouvrir le module :

```dotenv
RENTFLEET_DAMAGE_V1_ENABLED=false
DAMAGE_V1_BACKEND=rtdetrv2_s
DAMAGE_V1_PYTHON_BINARY=/chemin/.venv-damage-v1/bin/python
DAMAGE_V1_EXECUTION_PROVIDER=CPUExecutionProvider
DAMAGE_V1_MODEL_PATH=/chemin/prive/rentfleet-rtdetrv2-s-bundle/model.onnx
DAMAGE_V1_MODEL_CARD_PATH=/chemin/prive/rentfleet-rtdetrv2-s-bundle/model_card.json
DAMAGE_V1_MODEL_SHA256=<sha256-prive-model.onnx>
DAMAGE_V1_MODEL_CARD_SHA256=<sha256-prive-model_card.json>
```

La commande suivante vérifie la paire source puis la copie atomiquement avec
des permissions privées vers les chemins configurés :

```bash
php artisan rentfleet:damage-v1:install "/chemin/prive/rentfleet-rtdetrv2-s-bundle"
```

Appliquer et vérifier le déploiement :

```bash
php artisan migrate --force
php artisan rentfleet:doctor --production
php artisan queue:work --queue=intelligence,default --tries=1 --timeout=130
```

Une fois les migrations, la CI, le doctor et le worker validés, ouvrir seulement
le mode consultatif et reconstruire le cache de configuration :

```dotenv
RENTFLEET_DAMAGE_V1_ENABLED=true
```

```bash
php artisan config:cache
```

L'assistant est disponible à `/intelligence/vehicle-damages` pour les rôles
autorisés.

## Worker GPU facultatif

Le GPU Colab a servi à l'entraînement; le SaaS peut exécuter l'inférence sur
CPU. Pour un worker NVIDIA compatible, utiliser un environnement séparé :

```bash
python3.12 -m venv .venv-damage-v1-gpu
.venv-damage-v1-gpu/bin/python -m pip install \
  --requirement scripts/intelligence/requirements-vehicle-damage-runtime-gpu.txt
```

```dotenv
DAMAGE_V1_PYTHON_BINARY=/chemin/.venv-damage-v1-gpu/bin/python
DAMAGE_V1_EXECUTION_PROVIDER=CUDAExecutionProvider
```

`rentfleet:doctor` refuse l'activation si le fournisseur demandé n'est pas
réellement disponible. Les paquets CPU et GPU ne doivent pas être installés
ensemble dans le même environnement.

## Exploitation et rollback

- une seule exécution `queued` ou `running` est autorisée par inspection;
- une exécution active depuis plus de 15 minutes est fermée avant une nouvelle
  demande;
- les limites par défaut sont de 3 analyses par minute par utilisateur et de
  20 par heure par périmètre tenant/agence;
- les photos restent accessibles uniquement par une route privée autorisée et
  auditée;
- pour arrêter les nouvelles analyses, remettre le flag à `false`, reconstruire
  le cache de configuration et laisser les jobs présents se terminer ou
  expirer;
- pour revenir à l'ancien runtime, laisser d'abord le flag à `false`, installer
  sa paire privée puis définir `DAMAGE_V1_BACKEND=efficientnetv2s`; ne jamais
  réutiliser les SHA-256 du nouveau backend;
- un rollback ne doit pas supprimer les registres, photos ou revues existants.

## Références techniques

- RT-DETR officiel : <https://github.com/lyuwenyu/RT-DETR>
- Export ONNX officiel :
  <https://github.com/lyuwenyu/RT-DETR/blob/main/rtdetrv2_pytorch/tools/export_onnx.py>
- Checkpoint sélectionné :
  <https://drive.google.com/file/d/1FhUglF3PzS_2x4JIJfh6ct5KfWXeAGVE/view?usp=drivesdk>
- Politique d'inférence :
  <https://drive.google.com/file/d/18khLLc12cqj0y4nt7gUP-yXAipdU4Uxs/view?usp=drivesdk>
- ONNX Runtime Python : <https://onnxruntime.ai/docs/get-started/with-python.html>
- ONNX Runtime, optimisation et quantification :
  <https://onnxruntime.ai/docs/performance/model-optimizations/quantization.html>
- Queues Laravel : <https://laravel.com/docs/12.x/queues>
- Validation des fichiers Laravel : <https://laravel.com/docs/12.x/validation#validating-files>
- Processus Laravel : <https://laravel.com/docs/12.x/processes>
- Stockage Laravel : <https://laravel.com/docs/12.x/filesystem>
