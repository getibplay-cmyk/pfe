# Protocole préenregistré — ANPR marocain v2

Statut au 26 août 2026 : **développement, non qualifié**. Un holdout
indépendant historique a déjà été ouvert exactement une fois, a échoué aux
gates et est définitivement retiré. E3.2 n'a ouvert aucun holdout de
remplacement; celui-ci reste obligatoire et l'intégration SaaS reste interdite.

## Question et périmètre

Le système doit localiser une plaque sur une photo de véhicule, lire le numéro
d'immatriculation marocain, puis soit proposer une valeur à confirmer, soit
s'abstenir. Il ne crée ni ne modifie jamais automatiquement un véhicule, une
réservation, un contrat, une sanction, un paiement ou une preuve d'identité.

Les plaques historiques à série arabe et le nouveau format bilingue arabe/latin
doivent coexister pendant la transition. La correspondance arabe/latin suit les
15 paires de l'arrêté n° 640.26 publié au Bulletin officiel n° 7531 le 3 août
2026. Toute autre correspondance est rejetée.

## Baseline gelée

Le détecteur de départ est un modèle privé de localisation de plaques dont
l'architecture et les paramètres opérationnels restent dans le registre privé.
Son checkpoint est accepté seulement si son SHA-256 correspond à la sélection
gelée.

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

Sur Colab, PyTorch et PaddlePaddle GPU sont installés dans deux environnements
Python distincts et exécutés dans deux processus successifs. Ce cloisonnement
est bloquant : les distributions GPU observées le 25 août 2026 imposent des
versions différentes de cuDNN, cuSPARSELt et NCCL. Les crops intermédiaires sont
des fichiers temporaires locaux, supprimés à la fin du worker OCR; aucun
matricule n'est envoyé sur la console.

## Sources et gouvernance

| Source | Usage autorisé | Statut |
|---|---|---|
| Moroccan Vehicle Registration Plates v2 | détection, développement | CC0-1.0, admise par l'audit S7 |
| Ayoub Alaoui Moroccan Plates v2 | détection, développement | CC-BY-SA-4.0, admise |
| Keremberke license-plate detection, révision `a51194c7…` | détection, développement | CC-BY-4.0, admise |
| Synthèse déterministe avec Noto Sans Arabic + Noto Sans | OCR, développement | deux polices sous SIL OFL-1.1; révisions, empreintes, paramètres et graines gelés |
| UC3M-LP | recherche détection/OCR latin inter-domaines, optionnelle | ODbL-1.0; non marocaine, obligations ODbL à préserver; jamais preuve de qualification |
| CCPD | détection de plaque inter-domaines, optionnelle | MIT; labels OCR chinois interdits comme vérité marocaine |
| Open Images V7 | détection de plaque inter-domaines, optionnelle | annotations CC-BY-4.0; licence et attribution de chaque image à vérifier; jamais vérité OCR |
| UFPR-ALPR | benchmark scientifique seulement | licence académique/non commerciale; exclue de tout entraînement SaaS |
| RodoSol-ALPR | benchmark scientifique seulement | licence académique/non commerciale; exclue de tout entraînement SaaS |
| Photos RentFleet | pilote privé seulement | consentement, minimisation et manifeste SHA-256 obligatoires |
| Corpus UM6P 705 images | aucun entraînement ni preuve | quarantaine jusqu'à preuve de licence exacte |
| Holdout marocain de remplacement | test final unique | source-disjointe, non téléchargée et labels fermés |

Une image ne reçoit jamais une transcription inventée. Une annotation de boîte
ne vaut pas vérité OCR. Les variantes synthétiques restent reportées séparément
des images réelles et ne peuvent pas constituer le holdout final de remplacement.

Le générateur synthétique de caractères v1.1 rend à parts égales les plaques historiques
arabes et le nouveau format unifié arabe/latin avec `MA`. Pour la détection,
`MA` est une seule classe sémantique de signe distinctif; `M` et `A` restent
des classes séparées lorsqu'ils représentent une série latine. Il exige Noto Sans
Arabic v2.013 pour l'arabe et les chiffres, ainsi qu'une Noto Sans latine gelée
sur un commit Google Fonts pour `MA` et l'équivalent latin. Les deux preuves SIL
OFL 1.1 et leurs empreintes exactes sont vérifiées. Le run archive les
empreintes des bundles sources, des polices, des licences, de chaque image et
du manifeste. Il produit seulement `train`, `validation` et `calibration`; son
CLI n'expose aucun split `test` et refuse d'écraser un dossier existant.

Le mapping gelé est : `أ/A`, `ب/B`, `د/D`, `ه/H`, `و/E`, `ط/T`, `ي/Y`, `ك/K`,
`ل/L`, `م/M`, `ن/N`, `ص/C`, `ف/F`, `ر/R`, `س/S`. Une erreur sur le caractère
latin redondant compte comme erreur de plaque complète. Le rendu synthétique
approxime les composants réglementaires; il ne remplace pas des photos réelles.
La cible CTC suit l'ordre visuel publié `MA | numéro | arabe/latin | territoire`,
tandis que la métrique compare la forme canonique structurée.

Erratum E2.1 : avec un chemin de dictionnaire contenant `arabic`, le décodeur
PaddleOCR v3.7.0 active son inversion RTL puis renverse les groupes ASCII
contigus et les caractères non ASCII. Les prédictions brutes restent archivées;
le post-traitement applique exactement la même transformation involutive pour
retrouver l'ordre visuel avant la grammaire. Le re-score sans réentraînement des
prédictions immuables du run 01 atteint 128/128 sur chacun des deux formats en
validation propre, 128/128 en calibration propre et 384/384 avec les trois
variantes de validation. Ces scores restent exclusivement synthétiques.
Le constat, le modèle source et les interdictions de qualification sont scellés
dans `docs/intelligence/evidence/moroccan-anpr-e2.1-decoder-order-rescore.json`.

Diagnostic E2.2 run 01 : l'historique immuable montre qu'aux époques 6 à 12
le détecteur atteignait 100 % d'exact-match sur les 128 plaques historiques,
100 % de précision caractère IoU50 et 94,617 % de rappel. Les 128 plaques
unifiées étaient néanmoins toutes rejetées par
`unified_ma_marker_missing_or_ambiguous`. Les 128 faux négatifs sur 2 378
tokens cibles correspondent exactement à un petit glyphe du marqueur par
plaque unifiée. La sélection prudente du pire format a donc conservé l'époque
1, seule époque ayant encore un exact-match unifié non nul. Le protocole
caractère v1.1 corrige l'unité d'annotation : le signe réglementaire `MA` est
détecté comme un token unique, plus grand et non ambigu, sans abaisser le seuil
de score `0,45`, sans inventer de caractère et sans ouvrir le holdout final de
remplacement.

Résultat E2.3 run 01 : le changement d'unité d'annotation est confirmé sans
autre modification d'architecture, de seuil ou de grammaire. L'époque 3 atteint
100 % d'exact-match sur les 128 plaques historiques et les 128 plaques unifiées
de validation propre, avec 100 % de précision et de rappel caractère IoU50. La
calibration propre, exclue de la sélection, atteint 127/128 sur chacun des deux
formats, soit 254/256 (99,21875 %), avec deux abstentions
`expected_exactly_two_digit_clusters`. Les quatre artefacts du run sont scellés
par SHA-256 dans
`docs/intelligence/evidence/moroccan-anpr-e2.3-ma-token-run01.json`. Ces scores
restent synthétiques : le détecteur de plaque pleine image, les photos réelles et
le holdout final de remplacement n'ont pas été évalués, et l'intégration SaaS
reste interdite.

Résultat E3.2 run 01 : le transfert de détection équilibré a exécuté les trois
époques préenregistrées avec 1 536 exemples de chacun des quatre domaines par
époque. Les trois epochs respectent les marges de non-infériorité marocaines,
mais l'époque 1 conserve la meilleure clé lexicographique. Face à l'incumbent,
le pire mAP50 de domaine passe de 0,570088 à 0,910674, le pire rappel de
0,583538 à 0,918605 et le mAP50 macro-domaines de 0,815791 à 0,962114.

| Domaine de développement déjà consommé | mAP50 sélectionné | Rappel sélectionné |
|---|---:|---:|
| CCPD public | 0,976291 | 0,981572 |
| marocain primaire | 0,999376 | 1,000000 |
| marocain secondaire | 0,910674 | 0,918605 |

La calibration séparée sélectionne le seuil 0,075 par la règle de repli gelée
« maximiser le pire rappel, puis le F1 macro », car aucun seuil ne satisfait la
contrainte préférée de rappel d'au moins 0,95 sur les trois domaines. Les neuf
artefacts privés passent `sha256sum -c`, sans publier leurs chemins,
identifiants, empreintes, poids, images ou labels. Le résumé assaini est scellé
dans
`docs/intelligence/evidence/moroccan-anpr-e3.2-detection-transfer-run01.json`.
Ces métriques restent du développement sur des cohortes marocaines déjà
consommées. Le holdout historique demeure consommé et interdit de réutilisation;
E3.2 n'a pas ouvert le holdout indépendant de remplacement. L'OCR bout en bout
n'est pas évalué, aucune qualification n'est revendiquée et l'intégration SaaS
reste interdite.

## Séparation

- groupes par véhicule ou scène source, jamais par crop;
- `train`, `validation`, `calibration`, puis `test` indépendant de remplacement;
- dédoublonnage exact par SHA-256 et revue des quasi-doublons perceptuels;
- aucune source du test indépendant dans les trois splits de développement;
- toutes les photos d'un même véhicule restent dans un seul split;
- le smoke refuse toute ligne `test` et n'écrit aucun verrou de test;
- le holdout historique n'est jamais réutilisé;
- le test final de remplacement est évalué une seule fois après gel complet.

## Expériences préenregistrées

| ID | Changement unique | Décision |
|---|---|---|
| E0 | détecteur privé gelé + crop brut + Arabic PP-OCRv5 | baseline obligatoire |
| E1 | marges 3/8/15 %, autocontraste et rectification perspective | conserver seulement si exact-match validation augmente sans dégrader le rappel détecteur |
| E2 | fine-tuning du recognizer sur synthèse OFL + réel licencié | sélectionner par exact-match validation, départager par CER |
| E3 | grammaire, calibration des seuils et consensus de clichés physiques | maximiser la couverture sous contrainte d'exactitude sélective |
| E4 | évaluation finale source-disjointe, une fois | aucune itération après ouverture des labels |

Un challenger de détection (par exemple RT-DETR) n'est lancé que si l'analyse
d'erreurs E0/E1 montre que la localisation, et non l'OCR, est le goulot. Il doit
battre l'incumbent sur le pire domaine sans utiliser le test final de
remplacement.

### Sous-expérience E2 synthétique

Le lancement E2 synthétique isole uniquement la composante OFL de E2; il ne
remplace pas l'apport futur de données réelles licenciées. La graine
`20260825`, les groupes `1024/256/256`, trois variantes, un équilibre 50/50
ancien/unifié, 20 epochs et un batch de 64 compatible T4 sont gelés avant exécution. Le recognizer officiel Arabic PP-OCRv5 est
l'incumbent. Le challenger est initialisé depuis ses poids officiels et garde
la configuration et le dictionnaire officiel de 747 caractères de PaddleOCR
`v3.7.0` au commit `b03f46425e8ff4442b268ce449e3eef758146cd4`.

La décision exige d'abord au moins 90 % d'exact-match dans chacun des deux
formats synthétiques, refuse toute régression sur l'ancien format arabe ou le
format unifié arabe/latin, maximise ensuite l'exact-match agrégé,
puis minimise le CER en cas d'égalité. La calibration est mesurée mais
n'intervient pas dans la sélection. Aucun résultat synthétique ne franchit un gate réel : le statut
reste `synthetic_e2_complete_not_qualified`, le holdout final de remplacement
reste fermé et l'intégration SaaS reste interdite.

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
prédictions restent dans un artefact privé sur Drive. Le smoke exige
que `pip check` soit vert à la fois dans l'interpréteur PyTorch système et dans
le venv PaddleOCR isolé.

Le notebook E2 synthétique est généré séparément depuis
`e2_synthetic_cells.json`. Les images sont créées dans le stockage éphémère
Colab. Seul le bundle final de modèle, métriques, logs et provenance OFL est
copié dans le Drive privé après vérification de `SHA256SUMS`; aucun artefact
réel ou label du holdout de remplacement n'est lu.

E2 n'entraîne pas le détecteur. Le contrat d'inférence complet impose
`photo véhicule -> détecteur de plaque -> crop borné -> recognizer`. Appliquer
l'OCR directement à toute la photo est interdit afin d'éviter l'extraction de
texte sans rapport avec l'immatriculation.

## Conditions d'intégration SaaS

Une PR Laravel séparée est autorisée seulement après passage du gate final. Le
runtime devra être asynchrone, privé, multitenant, à contrat JSON fermé et avec
revue humaine append-only. La valeur proposée n'est jamais copiée dans la fiche
véhicule sans confirmation explicite et traçable. Le rollback désactive le
feature flag et laisse la saisie manuelle disponible.

## Références primaires

- PaddleOCR officiel : https://github.com/PaddlePaddle/PaddleOCR
- Module officiel de reconnaissance : https://www.paddleocr.ai/main/en/version3.x/module_usage/text_recognition.html
- configuration Arabic PP-OCRv5 officielle : https://github.com/PaddlePaddle/PaddleOCR/blob/v3.7.0/configs/rec/PP-OCRv5/multi_language/arabic_PP-OCRv5_mobile_rec.yaml
- post-traitement RTL officiel figé : https://github.com/PaddlePaddle/PaddleOCR/blob/v3.7.0/ppocr/postprocess/rec_postprocess.py
- dictionnaire PP-OCRv5 arabe officiel : https://github.com/PaddlePaddle/PaddleOCR/blob/v3.7.0/ppocr/utils/dict/ppocrv5_arabic_dict.txt
- installation PaddlePaddle : https://www.paddlepaddle.org.cn/documentation/docs/en/install/index_en.html
- TorchVision Faster R-CNN V2 : https://pytorch.org/vision/stable/models/generated/torchvision.models.detection.fasterrcnn_resnet50_fpn_v2.html
- article UM6P pour comparaison scientifique, données non admises : https://arxiv.org/abs/2104.08244
- Noto Arabic et licence OFL : https://github.com/notofonts/arabic
- release Noto Sans Arabic v2.013 : https://github.com/notofonts/arabic/releases/tag/NotoSansArabic-v2.013
- Noto Sans latin gelé sur Google Fonts : https://github.com/google/fonts/tree/6a003b5eb672dc8bf5bff5937cf5863f8b175445/ofl/notosans
- UC3M-LP (ODbL-1.0) : https://github.com/ramajoballester/UC3M-LP
- CCPD (MIT) : https://github.com/detectRecog/CCPD
- Open Images V7, téléchargement et métadonnées : https://storage.googleapis.com/openimages/web/download_v7.html
- Open Images V7, licences : https://storage.googleapis.com/openimages/web/factsfigures_v7.html#licenses
- UFPR-ALPR (usage académique/non commercial) : https://github.com/raysonlaroca/ufpr-alpr-dataset
- RodoSol-ALPR (usage académique/non commercial) : https://github.com/raysonlaroca/rodosol-alpr-dataset
- format des labels PaddleOCR : https://www.paddleocr.ai/v3.3.2/en/version2.x/ppocr/model_train/recognition.html
- annonce NARSA/MAP sur l'arrêté n° 640.26 et le modèle unifié : https://snrtnews.com/fr/article/plaques-dimmatriculation-la-narsa-annonce-lharmonisation-du-modele-utilise-au-maroc-et-a
