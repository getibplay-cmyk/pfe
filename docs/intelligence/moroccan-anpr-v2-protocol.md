# Protocole préenregistré — ANPR marocain v2

Statut au 25 août 2026 : **développement, non qualifié**. Le test final
indépendant n'a pas été ouvert et l'intégration SaaS reste interdite.

## Question et périmètre

Le système doit localiser une plaque sur une photo de véhicule, lire le numéro
d'immatriculation marocain, puis soit proposer une valeur à confirmer, soit
s'abstenir. Il ne crée ni ne modifie jamais automatiquement un véhicule, une
réservation, un contrat, une sanction, un paiement ou une preuve d'identité.

Les plaques historiques à série arabe et le nouveau format bilingue arabe/latin
doivent coexister pendant la transition. La correspondance arabe/latin n'est
pas codée en dur : elle reste bloquée tant que l'annexe officielle n'a pas été
archivée et attestée dans le Drive privé.

## Baseline gelée

Le détecteur de départ est
`fasterrcnn_resnet50_fpn_v2_multidomain_v1.2.0`, seuil `0,425`, entrée minimale
768 et maximale 1280. Son checkpoint privé est accepté seulement si son SHA-256
correspond au fichier de sélection privé v1.2.

Ses résultats de développement antérieurs sont :

| Domaine déjà consommé | mAP50 | Rappel |
|---|---:|---:|
| marocain primaire | 0,998232 | 1,000000 |
| marocain secondaire | 0,881680 | 0,918605 |

Ils motivent la conservation de ce détecteur comme incumbent. Ils ne constituent
pas une qualification indépendante et ne peuvent pas justifier un objectif de
95 % bout en bout.

L'OCR initial est le modèle officiel
`arabic_PP-OCRv5_mobile_rec`. Sa précision générique publiée n'est pas une
métrique RentFleet ni une précision de plaque complète. Le dictionnaire officiel
contient chiffres, lettres latines et caractères arabes, ce qui permet un
baseline pertinent avant spécialisation.

## Sources et gouvernance

| Source | Usage autorisé | Statut |
|---|---|---|
| Moroccan Vehicle Registration Plates v2 | détection, développement | CC0-1.0, admise par l'audit S7 |
| Ayoub Alaoui Moroccan Plates v2 | détection, développement | CC-BY-SA-4.0, admise |
| Keremberke license-plate detection, révision `a51194c7…` | détection, développement | CC-BY-4.0, admise |
| Synthèse déterministe avec Noto Arabic | OCR, développement | police sous SIL OFL-1.1; paramètres et graines gelés |
| Photos RentFleet | pilote privé seulement | consentement, minimisation et manifeste SHA-256 obligatoires |
| Corpus UM6P 705 images | aucun entraînement ni preuve | quarantaine jusqu'à preuve de licence exacte |
| Futur holdout marocain | test final unique | source-disjointe, non téléchargée et labels fermés |

Une image ne reçoit jamais une transcription inventée. Une annotation de boîte
ne vaut pas vérité OCR. Les variantes synthétiques restent reportées séparément
des images réelles et ne peuvent pas constituer le holdout final.

## Séparation

- groupes par véhicule ou scène source, jamais par crop;
- `train`, `validation`, `calibration`, puis `test` indépendant;
- dédoublonnage exact par SHA-256 et revue des quasi-doublons perceptuels;
- aucune source du test indépendant dans les trois splits de développement;
- toutes les photos d'un même véhicule restent dans un seul split;
- le smoke refuse toute ligne `test` et n'écrit aucun verrou de test;
- le test final est évalué une seule fois après gel complet.

## Expériences préenregistrées

| ID | Changement unique | Décision |
|---|---|---|
| E0 | détecteur v1.2 + crop brut + Arabic PP-OCRv5 | baseline obligatoire |
| E1 | marges 3/8/15 %, autocontraste et rectification perspective | conserver seulement si exact-match validation augmente sans dégrader le rappel détecteur |
| E2 | fine-tuning du recognizer sur synthèse OFL + réel licencié | sélectionner par exact-match validation, départager par CER |
| E3 | grammaire, calibration des seuils et consensus de clichés physiques | maximiser la couverture sous contrainte d'exactitude sélective |
| E4 | évaluation finale source-disjointe, une fois | aucune itération après ouverture des labels |

Un challenger de détection (par exemple RT-DETR) n'est lancé que si l'analyse
d'erreurs E0/E1 montre que la localisation, et non l'OCR, est le goulot. Il doit
battre l'incumbent sur le pire domaine sans utiliser le test final.

## Prétraitement autorisé

- extension de boîte bornée et clampée dans l'image;
- niveaux de gris et autocontraste;
- détection de quadrilatère et homographie conservatrice;
- redimensionnement requis par le recognizer;
- augmentations synthétiques plausibles : perspective modérée, flou, bruit,
  exposition, compression et occlusion partielle.

Super-résolution générative, inpainting, reconstruction de caractères, LLM ou
correction sémantique non traçable sont interdits : ils peuvent fabriquer un
matricule qui n'existe pas sur la photo.

## Métriques et seuils

Les métriques sont calculées par véhicule/groupe avec intervalles bootstrap par
groupe. Une abstention compte comme erreur dans l'exactitude bout en bout, mais
pas dans l'exactitude sélective dont la couverture est publiée séparément.

| Métrique finale indépendante | Gate bloquant |
|---|---:|
| mAP50 détection | >= 0,95 |
| rappel détection | >= 0,95 |
| exact-match plaque complète OCR | >= 0,90 |
| CER OCR | <= 0,02 |
| exactitude sélective après abstention | >= 0,97 |
| couverture sélective | >= 0,70 |
| exactitude bout en bout, abstentions incluses | >= 0,90 |

La cible ambitieuse, distincte du gate, est `end_to_end_exact >= 0,95`. Elle
n'est jamais annoncée avant l'évaluation indépendante. Les métriques sont aussi
ventilées par ancien/nouveau format, jour/nuit, distance, flou, région et source.

## Règle de consensus et abstention

Une proposition est éligible seulement si la qualité, la confiance du
détecteur, la confiance OCR et la grammaire passent. Les transformations d'une
même photo gardent le même `view_id`; seule leur meilleure variante est retenue.
Deux clichés physiques concordants peuvent établir un consensus. Une plaque
bilingue sans correspondance officielle vérifiée, plusieurs plaques candidates,
un conflit entre vues ou un écart insuffisant impose l'abstention.

## Smoke Colab

Le smoke public est généré depuis `colab_cells.json` et ne contient aucune
sortie. Il utilise un petit échantillon déterministe d'une source de
développement déjà vue. Il mesure le fonctionnement et la latence; sans CSV
annoté, les métriques d'exactitude restent nulles. Tous les matricules et
prédictions restent dans `PRIVATE_predictions.jsonl` sur Drive.

## Conditions d'intégration SaaS

Une PR Laravel séparée est autorisée seulement après passage du gate final. Le
runtime devra être asynchrone, privé, multitenant, à contrat JSON fermé et avec
revue humaine append-only. La valeur proposée n'est jamais copiée dans la fiche
véhicule sans confirmation explicite et traçable. Le rollback désactive le
feature flag et laisse la saisie manuelle disponible.

## Références primaires

- PaddleOCR officiel : https://github.com/PaddlePaddle/PaddleOCR
- Module officiel de reconnaissance : https://www.paddleocr.ai/main/en/version3.x/module_usage/text_recognition.html
- dictionnaire arabe officiel : https://github.com/PaddlePaddle/PaddleOCR/blob/v3.7.0/ppocr/utils/dict/arabic_dict.txt
- installation PaddlePaddle : https://www.paddlepaddle.org.cn/documentation/docs/en/install/index_en.html
- TorchVision Faster R-CNN V2 : https://pytorch.org/vision/stable/models/generated/torchvision.models.detection.fasterrcnn_resnet50_fpn_v2.html
- article UM6P pour comparaison scientifique, données non admises : https://arxiv.org/abs/2104.08244
- Noto Arabic et licence OFL : https://github.com/notofonts/arabic
