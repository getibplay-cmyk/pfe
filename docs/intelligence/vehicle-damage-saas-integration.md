# S7 dommages v1.1 — intégration SaaS consultative

## Décision livrée

Le modèle EfficientNetV2-S qualifié est intégré comme un assistant de contrôle
des photos d'inspection de retour. Laravel assainit et conserve la photo sur le
stockage privé, crée une exécution PostgreSQL, puis délègue l'inférence ONNX à
un job de la queue `intelligence`. Le résultat est revalidé par Laravel avant
d'être affiché sous forme de zones candidates grossières.

Le module est **consultatif uniquement** : confirmer ou rejeter une suggestion
ne crée jamais de `damage_report`, de frais, de responsabilité, de retenue ou de
changement sur le véhicule ou l'inspection. Une action métier éventuelle reste
un flux humain séparé.

Le flag reste fermé par défaut :

```dotenv
RENTFLEET_DAMAGE_V1_ENABLED=false
```

## Contrat scientifique gelé

| Élément | Valeur publique gelée |
|---|---:|
| Modèle | EfficientNetV2-S |
| Version SaaS | `s7-damage-efficientnetv2s-v1.1` |
| Balanced accuracy test | 0,857633 |
| Macro-F1 test | 0,852923 |
| Rappel dommage test | 0,867117 |
| ECE test | 0,025848 |
| Plancher de qualification | 0,75 |
| Seuil de décision calibré | 0,495 |

Le plancher `0,75` s'applique aux métriques de qualification du modèle. Il ne
doit pas être confondu avec le seuil de décision `0,495`, choisi uniquement sur
le split de calibration puis gelé avant le test final.

Les tailles et SHA-256 de `model.onnx` et `model_card.json` restent privés. Le
SaaS exige que ces deux empreintes soient fournies dans l'environnement local,
recalcule les empreintes et refuse une carte dont l'identité, le prétraitement,
le seuil ou la release gate ne correspondent pas au contrat gelé.

## Périmètre et limites

- seules les inspections de type `return` au statut `completed` sont éligibles;
- l'image est évaluée par patches carrés chevauchants;
- les cadres indiquent des régions candidates, pas les contours exacts d'un
  dommage;
- une photo trop petite, sombre, surexposée, peu contrastée ou potentiellement
  floue produit une abstention et aucune inférence;
- une absence de patch positif n'exclut pas un dommage hors champ, minuscule ou
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
DAMAGE_V1_PYTHON_BINARY=/chemin/.venv-damage-v1/bin/python
DAMAGE_V1_EXECUTION_PROVIDER=CPUExecutionProvider
DAMAGE_V1_MODEL_PATH=/chemin/prive/rentfleet/vehicle-damage-v1/model.onnx
DAMAGE_V1_MODEL_CARD_PATH=/chemin/prive/rentfleet/vehicle-damage-v1/model_card.json
DAMAGE_V1_MODEL_SHA256=<sha256-prive-model.onnx>
DAMAGE_V1_MODEL_CARD_SHA256=<sha256-prive-model_card.json>
```

La commande suivante vérifie la paire source puis la copie atomiquement avec
des permissions privées vers les chemins configurés :

```bash
php artisan rentfleet:damage-v1:install "/chemin/prive/du-run-qualifie"
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
- un rollback ne doit pas supprimer les registres, photos ou revues existants.

## Références techniques

- ONNX Runtime Python : <https://onnxruntime.ai/docs/get-started/with-python.html>
- ONNX Runtime, optimisation et quantification :
  <https://onnxruntime.ai/docs/performance/model-optimizations/quantization.html>
- Queues Laravel : <https://laravel.com/docs/12.x/queues>
- Validation des fichiers Laravel : <https://laravel.com/docs/12.x/validation#validating-files>
- Processus Laravel : <https://laravel.com/docs/12.x/processes>
- Stockage Laravel : <https://laravel.com/docs/12.x/filesystem>
