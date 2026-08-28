# Dossier de soutenance — ANPR marocain hybride

## Résultat défendable au 28 août 2026

RentFleet intègre un assistant OCR local pour des **crops de plaques déjà
détectés**. Il propose une lecture, applique si nécessaire un fallback par
zones, puis exige une confirmation ou une correction humaine. Le résultat ne
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
| OpenCV | CLAHE, morphologie, crops de zones | Apache-2.0 | intégré avec attribution |
| Code RentFleet | grammaire, fallback, fusion, contrat SaaS et revue | code du projet | contribution du PFE |
| `essanhaji/moroccan-lpr-ocr` | référence comparative ancienne | aucun fichier de licence publié dans la racine consultée | code, dataset et poids exclus |
| Plate Recognizer Snapshot | pilote cloud ponctuel | service commercial, traitement cloud | non intégré au SaaS |

Sources vérifiées :

- [licence Apache-2.0 de PaddleOCR](https://github.com/PaddlePaddle/PaddleOCR/blob/main/LICENSE) ;
- [documentation officielle du pipeline OCR PaddleOCR](https://www.paddleocr.ai/main/en/version3.x/pipeline_usage/OCR.html) ;
- [dépôt `essanhaji/moroccan-lpr-ocr`](https://github.com/essanhaji/moroccan-lpr-ocr/tree/main), dont la racine affichée ne contient pas de fichier `LICENSE`.

Un dépôt public sans licence explicite ne donne pas, à lui seul, une autorisation
de réutiliser ou distribuer son code et ses poids. Il reste donc une source
d'inspiration citée, pas une dépendance de RentFleet.

## Architecture démontrable

```text
crop privé téléversé
  -> réencodage local sans EXIF
  -> stockage privé tenant-scopé
  -> queue Intelligence
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
défaut. L'activation exige un environnement PaddleOCR local préchargé et un
worker de queue `intelligence` ; aucun crop n'est envoyé à un service cloud.

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
ensuite son interpréteur dans `PLATE_HYBRID_PYTHON_BINARY`, lancer le worker de
queue et n'activer le feature flag que dans l'environnement de démonstration.

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
3. Choisir un véhicule autorisé et téléverser un crop synthétique ou consenti.
4. Montrer le run `queued`, puis la suggestion complète, partielle ou vide.
5. Confirmer une suggestion juste ou saisir une correction canonique.
6. Rafraîchir la fiche véhicule et montrer que son immatriculation n'a pas été
   modifiée.
7. Montrer la revue append-only, l'audit sans texte de plaque et l'agrégat privé
   sans donnée individuelle.

Prévoir trois fixtures synthétiques hors données privées : lecture primaire
complète, fallback complet, et sortie partielle nécessitant une correction.

## Formulations autorisées

- « Nous avons intégré un recognizer open source sous licence permissive dans
  un pipeline marocain conçu et testé dans le PFE. »
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
