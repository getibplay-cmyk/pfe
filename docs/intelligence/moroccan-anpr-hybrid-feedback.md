# ANPR marocain — fallback hybride et boucle de correction

## Décision

Le chemin SaaS accepte désormais soit une photo complète du véhicule, soit un
crop manuel. Sur une photo complète, le détecteur Faster R-CNN E3.2 privé et
vérifié par SHA-256 doit d'abord produire un unique crop non ambigu. Le
recognizer principal reste l'officiel `arabic_PP-OCRv5_mobile_rec`. Lorsqu'une
lecture du crop est vide ou rejetée par la grammaire marocaine, le même
recognizer relit des zones bornées :

1. numéro de série ;
2. série arabe, avec vérification de l'équivalent latin lorsqu'il existe ;
3. code territorial ;
4. fusion déterministe par la grammaire marocaine.

Il n'y a ni OCR plein cadre, ni deuxième modèle OCR, ni appel cloud, ni caractère inventé. Une
composante manquante produit une suggestion partielle avec `?`. Une lecture
complète ou partielle exige toujours une validation humaine et ne modifie jamais
automatiquement l'immatriculation d'un véhicule.

## Licences admises

| Composant | Usage | Licence | Décision |
|---|---|---|---|
| PaddleOCR et le recognizer arabe officiel | OCR complet et segmenté | Apache-2.0 | admis avec notices |
| PyTorch / TorchVision | détection Faster R-CNN locale | BSD-3-Clause | admis avec notices |
| OpenCV | contraste, morphologie et crops | Apache-2.0 | admis avec notices |
| Noto Sans Arabic / Noto Sans | génération synthétique seulement | OFL-1.1 | admis avec preuves OFL |
| `essanhaji/moroccan-lpr-ocr` | ancien pilote comparatif | aucune licence publiée | exclu du SaaS, des poids et du code distribué |

Le pipeline de segmentation, la fusion, la grammaire, le contrat de correction,
les tests et le modèle ultérieurement ajusté sur les données RentFleet sont les
contributions propres du PFE. Les composants amont restent attribués à leurs
auteurs ; une licence permissive ne transforme pas leur code en travail original
RentFleet.

Sources officielles :

- <https://github.com/PaddlePaddle/PaddleOCR/blob/main/LICENSE>
- <https://www.paddleocr.ai/v3.6.0/en/version3.x/module_usage/text_recognition.html>
- <https://docs.pytorch.org/vision/0.26/models/generated/torchvision.models.detection.fasterrcnn_resnet50_fpn_v2.html>
- <https://github.com/pytorch/vision/blob/main/LICENSE>
- <https://github.com/opencv/opencv/blob/4.x/LICENSE>
- <https://github.com/notofonts/arabic>

## Chemin d'inférence

```text
photo complète privée
  -> Faster R-CNN privé vérifié par empreinte
  -> un crop borné, ou abstention si absent/ambigu
  -> jamais d'OCR sur la photo complète
crop privé détecté ou fourni manuellement
  -> PP-OCRv5 complet : original + CLAHE
  -> si lecture grammaticale complète : suggestion primaire
  -> sinon : 3 layouts fixes + séparateurs verticaux détectés si présents
  -> PP-OCRv5 sur serial / series / region, original + CLAHE
  -> fusion dans un seul layout
  -> suggestion complète, ambiguë, partielle ou vide
  -> validation/correction humaine obligatoire
```

Le chemin rapide exécute deux lectures. Le fallback ajoute 18 lectures de zones
fixes et, lorsqu'une paire de séparateurs fiable est détectée, six lectures
supplémentaires. Il ne s'exécute que pour les crops dont la lecture complète a
échoué.

## Exécution privée sur le lot de 1 819 crops

Le manifeste utilise le même contrat fermé que le worker OCR existant :

```json
{
  "schema_version": "1.0.0",
  "model_name": "arabic_PP-OCRv5_mobile_rec",
  "batch_size": 16,
  "crops": [
    {"crop_id": "1.png", "image_path": "1.png"}
  ]
}
```

Exécution dans l'environnement PaddleOCR privé :

```bash
python scripts/intelligence/vehicle_plate/hybrid_ocr_worker.py \
  --manifest PRIVATE_MANIFEST.json \
  --crop-root PRIVATE_CROP_ROOT \
  --output PRIVATE_HYBRID_OUTPUT.json \
  --device gpu:0
```

Création d'une copie du CSV de revue sans modifier `labels.csv` :

```bash
python scripts/intelligence/vehicle_plate/build_hybrid_review_csv.py \
  --labels PRIVATE_LABELS.csv \
  --hybrid-output PRIVATE_HYBRID_OUTPUT.json \
  --output PRIVATE_LABELS_HYBRID_REVIEW.csv
```

Règles du CSV :

- une correction humaine existante est préservée ;
- une suggestion complète préremplit `correction`, mais reste `pending` ;
- une suggestion partielle reste dans `tool_suggestion` et ne préremplit pas
  `correction` ;
- `review_status` doit devenir `confirmed` si la suggestion est correcte ou
  `corrected` si elle a été modifiée ;
- `tool_suggestion` conserve la proposition initiale pour mesurer les erreurs ;
- `model_version` et `fallback_executed` rendent chaque ligne traçable.

L'option `--no-prefill-correction` garde toutes les nouvelles corrections vides.

## Tranche SaaS intégrée, activation fermée par défaut

`VehiclePlateDetectorResultValidator` ferme d'abord le contrat de localisation :
checkpoint attendu, seuil figé, boîte bornée, crop JPEG intègre, processus isolé,
OCR plein cadre interdit et aucune mise à jour automatique. `VehiclePlateHybridResultValidator`
ferme ensuite le contrat JSON OCR côté Laravel. Il
refuse notamment :

- un résultat sans validation humaine obligatoire ;
- un effet autre que `NO_OPERATIONAL_ACTION` ;
- une plaque hors grammaire ;
- des composantes incompatibles avec la plaque fusionnée ;
- un autre modèle, une autre version ou un autre crop ;
- plus d'un résultat par exécution SaaS.

La configuration `intelligence.vehicle_plate_hybrid_review` est désactivée par
défaut. La tranche verticale ajoute stockage privé de la photo réencodée et du
crop détecté, run tenant/agence-scopé, queue, écran, policy, deux contrats fermés
et revue humaine append-only. Si aucune plaque n'est détectée ou si plusieurs
candidates sont proches, le run s'abstient et l'interface demande un crop manuel.
La correction validée devient un feedback disponible pour un futur export
d'entraînement, mais n'écrit jamais dans la fiche véhicule.

Détection et OCR utilisent deux environnements Python séparés. Le checkpoint,
son chemin et son empreinte restent privés ; seuls le nom d'architecture, le
seuil de développement `0.075` et les garde-fous sont publics. Cette intégration
technique n'est pas une qualification : le jeu indépendant de remplacement
reste requis avant toute activation production.

Les contraintes et triggers PostgreSQL ferment les états, l'immuabilité et la
portée des revues. Les audits enregistrent le statut et la décision, jamais le
texte de la plaque. Le runtime reste derrière le feature flag jusqu'à la revue
du pilote et à la qualification indépendante.

## Boucle d'amélioration

La collecte peut être quotidienne ; l'entraînement et le déploiement ne le sont
pas automatiquement.

1. L'opérateur confirme ou corrige chaque suggestion.
2. Seules les lignes `confirmed`, `corrected` ou les corrections historiques
   déjà vérifiées entrent dans un export d'entraînement.
3. Les vues d'un même véhicule/événement restent dans le même groupe pour éviter
   la fuite entre train et validation.
4. Un challenger est ajusté à partir du modèle préentraîné avec un mélange de
   données réelles vérifiées, anciennes données admises et données synthétiques.
5. Le challenger est comparé à l'incumbent par exact-match plaque complète, exact
   série arabe, exact chiffres et CER, séparément par format et série.
6. Aucun déploiement si une série régresse, si le jeu gelé est ouvert pour le
   réglage, ou si les portes du protocole ANPR v2 échouent.
7. La promotion est versionnée et réversible ; l'incumbent reste disponible.

Déclencheur proposé : lancer un candidat après la revue des 1 819 crops actuels,
puis au plus une fois par semaine lorsque 300 nouvelles corrections vérifiées
ont été accumulées et que les 15 séries sont suffisamment représentées. Ce seuil
est un déclencheur opérationnel de candidat, pas une preuve de suffisance
statistique. La documentation PaddleOCR recommande davantage de données réelles
pour un fine-tuning de reconnaissance général ; le lot actuel doit donc rester
présenté comme adaptation de domaine évaluée, pas comme garantie de production.

## Ordre de livraison

1. exécuter le worker sur les crops privés et produire le CSV de revue ;
2. corriger manuellement et figer une version vérifiée ;
3. mesurer le gain du fallback sur les 36 lignes déjà corrigées, sans les
   utiliser pour régler le fallback après lecture des résultats ;
4. vérifier la vertical slice SaaS complète, déjà intégrée et désactivée par défaut ;
5. si le délai le permet, entraîner un challenger ; sinon conserver le fallback
   et la boucle de correction comme livrable démontrable ;
6. n'activer qu'après les tests d'intégration, le jeu indépendant et la porte de
   qualification.
