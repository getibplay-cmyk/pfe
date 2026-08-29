# Dossier de soutenance — ANPR marocain hybride

## Résultat défendable au 28 août 2026

RentFleet intègre un pilote ANPR local de bout en bout : une **photo complète du
véhicule** passe dans un détecteur privé, seul le crop borné obtenu passe dans
l'OCR hybride, puis un humain confirme ou corrige. Un crop manuel reste le
secours si le détecteur s'abstient. Le résultat ne
modifie jamais l'immatriculation du véhicule et ne bloque aucun cycle de
location.

La contribution du PFE n'est pas présentée comme « un OCR entièrement inventé ».
Elle comprend l'adaptation au format marocain, la segmentation déterministe, la
fusion des composantes, la grammaire fermée, les contrats PHP/Python, la sécurité
tenant/agence, le stockage privé, la queue, l'interface de correction, l'audit
sans texte de plaque et la boucle de feedback prête pour un futur fine-tuning.

## Composants et licences

| Composant | Rôle | Licence constatée | Décision RentFleet |
|---|---|---|---|
| PaddleOCR / `arabic_PP-OCRv5_mobile_rec` | recognizer local | Apache-2.0 | intégré avec attribution |
| PyTorch / TorchVision Faster R-CNN | localisation locale de la plaque | BSD-3-Clause | intégré avec attribution |
| OpenCV | CLAHE, morphologie, crops de zones | Apache-2.0 | intégré avec attribution |
| Code RentFleet | grammaire, fallback, fusion, contrat SaaS et revue | code du projet | contribution du PFE |
| `essanhaji/moroccan-lpr-ocr` | référence comparative ancienne | aucun fichier de licence publié dans la racine consultée | code, dataset et poids exclus |
| Plate Recognizer Snapshot | pilote cloud ponctuel | service commercial, traitement cloud | non intégré au SaaS |

Sources vérifiées :

- [licence Apache-2.0 de PaddleOCR](https://github.com/PaddlePaddle/PaddleOCR/blob/main/LICENSE) ;
- [documentation officielle du pipeline OCR PaddleOCR](https://www.paddleocr.ai/main/en/version3.x/pipeline_usage/OCR.html) ;
- [documentation officielle Faster R-CNN V2](https://docs.pytorch.org/vision/0.26/models/generated/torchvision.models.detection.fasterrcnn_resnet50_fpn_v2.html) ;
- [licence BSD-3-Clause de TorchVision](https://github.com/pytorch/vision/blob/main/LICENSE) ;
- [dépôt `essanhaji/moroccan-lpr-ocr`](https://github.com/essanhaji/moroccan-lpr-ocr/tree/main), dont la racine affichée ne contient pas de fichier `LICENSE`.

Un dépôt public sans licence explicite ne donne pas, à lui seul, une autorisation
de réutiliser ou distribuer son code et ses poids. Il reste donc une source
d'inspiration citée, pas une dépendance de RentFleet.

## Architecture démontrable

```text
photo complète privée téléversée
  -> réencodage local sans EXIF
  -> stockage privé tenant-scopé
  -> queue Intelligence
  -> checkpoint Faster R-CNN privé vérifié par SHA-256
  -> crop unique borné, ou abstention absent/ambigu
  -> jamais d'OCR sur la photo complète
  -> PP-OCRv5 complet (original + CLAHE)
  -> fallback local par zones si nécessaire
  -> contrat JSON fermé et validation Laravel
  -> suggestion consultative
  -> confirmation / correction humaine append-only
  -> feedback disponible pour un export d'entraînement futur
```

Les tables `vehicle_plate_prediction_runs` et
`vehicle_plate_prediction_reviews` sont séparées des tables opérationnelles.
Des contraintes et triggers PostgreSQL rendent l'identité du run immuable,
interdisent les transitions illégales et rendent la revue append-only. Une
correction valide le format marocain `serial|série_arabe|région`, mais ne met
jamais à jour `vehicles.registration_number`.

Le feature flag `RENTFLEET_PLATE_HYBRID_REVIEW_ENABLED` reste à `false` par
défaut. L'activation du pilote exige deux environnements Python séparés, le
checkpoint privé contrôlé par empreinte et un worker de queue `intelligence` ;
aucune image n'est envoyée à un service cloud.

Environnement détecteur CPU compatible avec le checkpoint E3.2 :

```bash
python -m venv .venv-plate-detector
.venv-plate-detector/bin/python -m pip install \
  torch==2.11.0 torchvision==0.26.0 \
  --index-url https://download.pytorch.org/whl/cpu
.venv-plate-detector/bin/python -m pip install \
  -r scripts/intelligence/requirements-vehicle-plate-detector-runtime.txt
```

Renseigner localement `PLATE_DETECTOR_PYTHON_BINARY`,
`PLATE_DETECTOR_MODEL_PATH` et `PLATE_DETECTOR_MODEL_SHA256`. Le chemin,
l'empreinte et les poids ne sont jamais publiés. Le seuil `0.075` reste un seuil
de développement calibré, pas une preuve de performance indépendante.

Exemple CPU dans un environnement isolé, en suivant l'index officiel Paddle :

```bash
python -m venv .venv-plate-ocr
.venv-plate-ocr/bin/python -m pip install \
  paddlepaddle==3.3.0 \
  -i https://www.paddlepaddle.org.cn/packages/stable/cpu/
.venv-plate-ocr/bin/python -m pip install \
  -r scripts/intelligence/requirements-vehicle-plate-runtime.txt
.venv-plate-ocr/bin/python -c \
  "from paddleocr import TextRecognition; TextRecognition(model_name='arabic_PP-OCRv5_mobile_rec', device='cpu')"
```

La dernière commande précharge le recognizer avant la démonstration. Renseigner
ensuite son interpréteur dans `PLATE_HYBRID_PYTHON_BINARY`, fixer
`DB_QUEUE_RETRY_AFTER=420`, lancer le worker de queue avec un timeout d'au moins
350 secondes et n'activer le feature flag que dans l'environnement de
démonstration.

## Preuve privée agrégée

Le pilote `run01` a traité 1 819 crops privés :

| Résultat | Nombre | Part |
|---|---:|---:|
| lecture complète du crop | 68 | 3,74 % |
| lecture complète segmentée | 522 | 28,70 % |
| lecture complète mais ambiguë | 231 | 12,70 % |
| lecture partielle | 903 | 49,64 % |
| aucune composante exploitable | 95 | 5,22 % |
| **suggestions complètes** | **821** | **45,13 %** |
| fallback exécuté | 1 656 | 91,04 % |

La preuve versionnée est
`docs/intelligence/evidence/moroccan-anpr-hybrid-private-run01.aggregate.json`.
Elle ne contient ni chemin, ni identifiant de crop, ni texte de plaque. Les 36
corrections historiques ont été préservées et 1 783 lignes restent à revoir.
Les 845 cellules `correction` non vides comprennent des préremplissages encore
`pending` : elles ne sont donc pas 845 vérités terrain.

Le générateur reproductible d'agrégats s'exécute uniquement sur la copie privée :

```bash
python scripts/intelligence/vehicle_plate/summarize_hybrid_review.py \
  --review-csv PRIVATE_LABELS_HYBRID_REVIEW.csv \
  --output PRIVATE_AGGREGATE_EVIDENCE.json
```

Il ne réécrit jamais le CSV et refuse d'écraser un rapport existant. Les lignes
`pending` contribuent aux mesures de couverture, jamais à l'exact-match.

## Démonstration de soutenance

1. Montrer que le module est désactivé par défaut et que l'effet affiché est
   « aucune action automatique ».
2. Activer le runtime local de démonstration et démarrer le worker de queue.
3. Choisir un véhicule autorisé et téléverser une photo complète synthétique ou consentie.
4. Montrer la photo privée, le crop détecté, puis la suggestion complète, partielle ou vide.
5. Confirmer une suggestion juste ou saisir une correction canonique.
6. Rafraîchir la fiche véhicule et montrer que son immatriculation n'a pas été
   modifiée.
7. Montrer la revue append-only, l'audit sans texte de plaque et l'agrégat privé
   sans donnée individuelle.

Prévoir quatre fixtures synthétiques hors données privées : détection et lecture
primaire complètes, fallback OCR complet, sortie partielle nécessitant une
correction, et abstention du détecteur avec reprise par crop manuel.

## Formulations autorisées

- « Nous avons intégré un recognizer open source sous licence permissive dans
  un pipeline marocain conçu et testé dans le PFE. »
- « La photo complète est localisée par un détecteur privé vérifié ; seul son
  crop borné entre dans l'OCR. »
- « Le fallback améliore la couverture de suggestion et n'invente jamais une
  composante absente. »
- « Toute sortie reste consultative et doit être confirmée ou corrigée. »
- « Les corrections constituent une boucle de feedback prête pour un prochain
  challenger, mais le réentraînement n'est pas automatique. »
- « Le pilote prouve l'exécution sur 1 819 crops et une couverture complète de
  45,13 %. »

## Formulations interdites à ce stade

- « Le système a 95 % / 99 % d'accuracy » : aucune mesure indépendante ne le
  démontre encore.
- « Le modèle est prêt pour la production » : le feature flag et la porte de
  qualification restent fermés.
- « Nous avons créé PaddleOCR » ou « tout le modèle est notre travail ».
- « Les 845 corrections sont toutes vérifiées » : la majorité sont des
  suggestions préremplies encore en attente.
- « L'application apprend automatiquement chaque jour » : la collecte est
  quotidienne, mais entraînement, évaluation et promotion restent contrôlés.

## Travaux différés — points 1 à 4

1. terminer la revue manuelle des 1 783 lignes restantes ;
2. figer et valider le dataset corrigé ;
3. créer un split groupé sans fuite et un jeu de test indépendant ;
4. fine-tuner un challenger, mesurer exact-match, série arabe, chiffres et CER,
   puis promouvoir uniquement en cas de non-régression.

Ces travaux améliorent la qualité scientifique, mais leur absence ne retire pas
la valeur de la tranche logicielle démontrable : OCR local, fallback, sécurité,
validation humaine et capture de feedback sont déjà séparés et testables.
