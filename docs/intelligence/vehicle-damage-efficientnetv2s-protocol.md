# Protocole v1.0.0 — assistant de dommages véhicule EfficientNetV2-S

Statut : préenregistré, entraînement réel bloqué tant qu'une source officielle
et sa preuve d'autorisation ne sont pas présentes dans le Drive privé.

## Question PFE et valeur ajoutée

Le modèle répond à une seule question : « cette photo de retour contient-elle un
dommage extérieur visible ? ». La réponse sert à prioriser la revue humaine de
l'inspection. Elle ne crée jamais automatiquement un dommage, une responsabilité,
un coût, une retenue ou un changement de statut métier.

EfficientNetV2-S est retenu comme modèle principal : l'article officiel présente
la famille EfficientNetV2 comme plus rapide à entraîner et plus efficace en
paramètres, et TorchVision fournit un poids ImageNet versionné de 21 458 488
paramètres avec un prétraitement 384 x 384. Le modèle binaire est volontairement
le premier jalon : une extension de localisation multi-classe reste secondaire.

## Sources de données autorisées

| `source_id` | Source officielle | Statut avant entraînement | Usage prévu |
|---|---|---|---|
| `hitl_car_parts_damage` | Humans in the Loop, Car Parts and Car Damages | CC0 1.0, formulaire officiel à compléter | source réelle initiale, positifs et candidats négatifs audités |
| `cardd` | site officiel CarDD USTC | accord académique signé préalable | extension positive/localisation; insuffisant seul pour le binaire |
| `tqvcd` | dépôt officiel des auteurs | données disponibles sur demande | extension binaire avec classes normal/cassé/écrasé |

Un miroir Kaggle, Hugging Face, Roboflow, Zenodo ou autre n'est jamais accepté
comme substitution à la preuve officielle. Chaque image doit avoir une URL de
source, un identifiant de licence, une preuve privée et un SHA-256.

## Données et séparation gelée

Le manifeste CSV contient : `image_path`, `label`, `group_id`, `source_id`,
`source_url`, `license_id`, `license_status`, `license_proof`, `sha256`, `split`.

- labels : `0 = aucun_dommage_visible`, `1 = dommage_visible`;
- split par véhicule ou groupe d'origine, jamais par image isolée;
- aucune augmentation avant le split;
- dédoublonnage exact SHA-256, puis revue des quasi-doublons perceptuels;
- train 70 %, validation 10 %, calibration 10 %, test final 10 %;
- si plusieurs sources sont admises, chaque source est représentée dans les
  quatre splits et un résultat par source est ajouté au rapport;
- le test final n'est évalué qu'après choix du checkpoint, de la température et
  du seuil sur les autres splits.

## Entraînement préenregistré

- Colab GPU CUDA obligatoire, graine `20260823`;
- EfficientNetV2-S avec `IMAGENET1K_V1`, entrée RGB 384 x 384;
- 3 époques tête gelée puis au plus 12 époques de fine-tuning;
- AdamW, pondération de classes, scheduler cosinus, mixed precision;
- sélection du checkpoint sur macro-F1 de validation;
- arrêt anticipé après 4 époques de fine-tuning sans progrès;
- calibration scalaire de température sur le split calibration;
- seuil choisi sur calibration pour maximiser la balanced accuracy sous la
  contrainte rappel dommage >= 0,75;
- intervalles de confiance bootstrap 95 % sur le test.

Les augmentations restent plausibles pour une inspection : crop modéré, miroir
horizontal, rotation de 5 degrés, variation légère de lumière/couleur et petit
effacement. Aucun miroir vertical ni transformation géométrique extrême.

## Métriques et décision

Le lot est qualifié seulement si, sur le test gelé :

| Métrique | Seuil bloquant | Cible PFE |
|---|---:|---:|
| Balanced accuracy | >= 0,75 | >= 0,90 |
| Macro-F1 | >= 0,75 | >= 0,90 |
| Rappel dommage | >= 0,75 | >= 0,90 |
| ECE (15 bins) | <= 0,08 | <= 0,05 |

AUROC, PR-AUC, précision dommage, spécificité et Brier sont également publiés.
Un échec produit un STOP explicite et aucun ONNX. Un succès produit un ONNX
calibré, un seuil versionné, la carte du modèle, les métriques, les prédictions
de test pseudonymisées et les SHA-256.

## Limites et intégration SaaS

La classification d'une photo entière ne localise pas le dommage et peut apprendre
des biais de cadrage, d'arrière-plan ou de source. Les photos floues, trop sombres,
tronquées ou hors domaine doivent être rejetées ou envoyées à la revue humaine.
Un jeu pilote RentFleet consenti et séparé est nécessaire avant une affirmation de
généralisation locale.

L'intégration Laravel est une seconde PR, conditionnée par le succès scientifique.
Les photos restent privées, l'inférence passe par la queue, le résultat respecte un
contrat JSON fermé, et toute prédiction exige une validation humaine traçable.

## Références primaires

- EfficientNetV2 : https://arxiv.org/abs/2104.00298
- TorchVision EfficientNetV2-S : https://docs.pytorch.org/vision/stable/models/generated/torchvision.models.efficientnet_v2_s.html
- CarDD officiel : https://cardd-ustc.github.io/
- Licence CarDD : https://cardd-ustc.github.io/docs/CarDD_license.pdf
- HITL officiel : https://humansintheloop.org/resources/datasets/car-parts-and-car-damages-dataset/
- TQVCD officiel : https://github.com/dxlabskku/TQVCD
- Article TQVCD : https://doi.org/10.1016/j.heliyon.2024.e34016
- Calibration par température : https://arxiv.org/abs/1706.04599
