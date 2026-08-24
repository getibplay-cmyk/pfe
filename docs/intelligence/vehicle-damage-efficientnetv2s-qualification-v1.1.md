# Qualification v1.1 — assistant de dommages EfficientNetV2-S

Date de gel : 2026-08-24  
Statut : **QUALIFIED** (`release_gate.passed = true`)  
Révision scientifique de départ : `1ae5c3bc9faf7f6076edc0af2f40b5eeb5a06939`

Ce rapport public contient uniquement des résultats agrégés. Les photos,
annotations, patches, prédictions individuelles, checkpoints, modèles, tailles
d'artefacts et empreintes cryptographiques restent dans le Drive privé RentFleet.

## Décision

EfficientNetV2-S franchit les quatre portes préenregistrées sur le test final
gelé. Il peut donc être conservé comme modèle consultatif du PFE et passer à une
intégration SaaS séparée, avec revue humaine obligatoire. Il ne détermine jamais
la responsabilité, le coût, la retenue, la gravité contractuelle ou l'état du
véhicule.

La cible PFE de `0,90` n'est pas atteinte pour la balanced accuracy, le macro-F1
et le rappel sur le test. Elle reste un objectif d'amélioration, distinct du
seuil de qualification `0,75`.

## Données gelées

- source officielle : HITL Car Parts and Car Damages, CC0 1.0;
- 1 812 images annotées, dont 814 images avec polygones de dommage;
- 441 images identiques présentes dans les annotations pièces et dommages;
- 557 images « pièces seules » exclues des négatifs, car l'absence d'annotation
  dommage ne prouve pas l'absence de dommage;
- positifs : patches centrés sur les polygones de dommage;
- négatifs : régions de pièces échantillonnées uniquement dans les 441 images
  communes, avec couverture dommage `<= 0,0005`;
- arithmétique des masques : intégrales signées `int64`, soustractions converties
  en entiers Python;
- séparation par SHA-256 de l'image source : tous les patches d'une même image
  restent dans le même split.

| Split | Patches |
|---|---:|
| Train | 4 822 |
| Validation | 700 |
| Calibration | 691 |
| Test final | 714 |
| **Total** | **6 927** |

Répartition des labels : 4 293 positifs et 2 634 négatifs. Les archives brute et
préparée ont été vérifiées avant l'entraînement; leurs tailles et empreintes ne
sont pas publiées.

## Entraînement et sélection

- modèle : TorchVision EfficientNetV2-S, poids `IMAGENET1K_V1`;
- entrée : RGB `384 x 384`;
- GPU : Tesla T4;
- seed : `20260823`;
- 3 époques tête gelée, puis fine-tuning;
- meilleur checkpoint : époque 7;
- macro-F1 validation du meilleur checkpoint : `0,925229`;
- arrêt anticipé : époque 11, après quatre époques sans progrès;
- seuil choisi uniquement sur calibration : `0,495`;
- le test final est évalué une seule fois après le gel du checkpoint, de la
  température et du seuil;
- le protocole corrigé utilise 1 000 réplications bootstrap par image source
  (`group_id`), et non par patch.

## Résultats du test final

| Mesure | Valeur | Porte |
|---|---:|---:|
| Balanced accuracy | 0,857633 | >= 0,75 |
| Macro-F1 | 0,852923 | >= 0,75 |
| Rappel dommage | 0,867117 | >= 0,75 |
| Précision dommage | 0,903756 | information |
| Spécificité | 0,848148 | information |
| ROC-AUC | 0,940057 | information |
| PR-AUC | 0,962940 | information |
| ECE | 0,025848 | <= 0,08 |
| Brier | 0,098411 | information |

Perte test : `0,412120`. Toutes les portes passent sans exception.

Les premiers intervalles calculés par patch ont été retirés : plusieurs patches
d'une même image sont corrélés. Ils seront republiés uniquement après
rééchantillonnage groupé des prédictions déjà gelées, sans nouvelle inférence ni
nouvelle lecture des images du test. Cette correction ne change aucune valeur
ponctuelle ni aucune porte de qualification.

## Vérification privée

Le checkpoint sélectionné, les métriques, la carte du modèle, les prédictions
de test pseudonymisées et l'export ONNX ont été inventoriés dans le Drive privé.
Le contrôle d'intégrité cryptographique a réussi. Aucun fichier, nom de fichier
privé, taille ou SHA-256 de ces artefacts n'est publié dans GitHub.

## Valeur PFE et limites

Le modèle apporte une valeur démontrable au PFE : protocole anti-fuite,
provenance officielle, split groupe, calibration, seuil versionné, verrou de test
et export portable ONNX. Dans le SaaS, une photo de retour pourra être
découpée en zones chevauchantes, scorée puis affichée sous forme de régions
suspectes à vérifier par l'agent.

Cette proposition reste une localisation grossière par patch, pas une
segmentation pixel-précise. Le test provient d'une seule source publique et ne
démontre pas encore la généralisation aux agences RentFleet marocaines. Une
étude pilote consentie, séparée et jamais utilisée pour réentraîner ce test est
obligatoire avant tout usage opérationnel. Les images floues, sombres,
tronquées ou hors domaine doivent provoquer une abstention ou une revue humaine.

## Étape suivante autorisée par cette qualification

Créer une PR d'intégration consultative séparée : inférence asynchrone privée,
contrat JSON fermé, score et seuil versionnés, carte de zones candidates,
abstention qualité, validation humaine traçable et aucune décision métier
automatique.
