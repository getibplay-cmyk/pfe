# Protocole v2 — détection consultative de dommages par RT-DETRv2-S

## Décision

La v1.1 EfficientNetV2-S reste la baseline publiée. Elle n'est ni supprimée ni
réévaluée. La v2 est une nouvelle itération du même module dommages, pas un
cinquième modèle métier. Elle remplace le scan de patches arbitraires par un
détecteur à boîtes, puis réserve une seconde vérification de présence aux
candidats localisés.

Le dépôt officiel `lyuwenyu/RT-DETR` est utilisé à l'empreinte Git
`068dfde65f2667ad6555883c69d73de886518cad`, sous Apache-2.0. Le premier
candidat est RT-DETRv2-S (ResNet-18, entrée 640) afin de rester compatible avec
le GPU T4 Colab et l'export ONNX officiel.

## Pourquoi la v1.1 ne peut pas atteindre honnêtement 95 % par simple réglage

- entraînement sur 6 927 patches provenant d'une seule source ;
- seulement 814 images avec polygones de dommages ;
- négatifs extraits à l'intérieur de 441 images déjà endommagées ;
- aucune preuve de performance sur des photos complètes propres RentFleet ;
- macro-F1 test 0,8529 malgré 0,9252 en validation ;
- localisation SaaS obtenue par fenêtre glissante, non apprise par le modèle.

## Données et isolation

1. Le manifeste `S7_DAMAGE_manifest_v1.1.csv` fournit uniquement le mapping
   immuable image source → split.
2. Les images `train`, `validation` et `calibration` sont converties en COCO à
   partir des polygones HITL. Leur SHA-256 brut est revérifié avant copie.
3. Le split `test` v1.1 n'est jamais lu ni converti par le notebook
   d'entraînement v2.
4. Toute évaluation finale doit créer atomiquement
   `TEST_EVALUATION_LOCK.json` avant la première lecture du test.
5. Bootstrap et intervalles sont calculés au niveau image/inspection, jamais au
   niveau boîte ou patch.

Les photos, annotations, manifests détaillés, checkpoints, ONNX, prédictions et
empreintes privées restent dans Drive. GitHub conserve le code, le protocole et
des métriques agrégées expurgées.

## Sources supplémentaires

| Source | Apport | État avant entraînement |
|---|---|---|
| HITL Car Parts and Car Damages | 814 images et polygones de dommages | approuvée, CC0-1.0 |
| CarDD | diversité réelle, plus de 9 000 instances | bloquée jusqu'au consentement PIC Lab ; usage commercial séparément autorisé |
| TQVCD | vues véhicule et images normales | bloquée jusqu'à réception directe des auteurs |
| CrashCar101 | synthétique, masques précis et variations contrôlées | pipeline MIT autorisé, actifs ShapeNet/HDRI à auditer séparément |
| Pilote RentFleet | domaine marocain et vrais pièges optiques | bloqué jusqu'à consentement, minimisation et séparation du test |

Aucun miroir non officiel n'est permis. CarDD ne peut pas être intégré au SaaS
commercial sans autorisation écrite correspondante.

## Étapes GPU

1. `smoke` : conversion de huit images train et huit validation, une époque,
   export ONNX et inférence finie. Ce résultat ne mesure aucune précision.
2. `detector_candidate` : apprentissage RT-DETRv2-S sur train uniquement,
   sélection sur validation, sans lecture du test.
3. `verifier_candidate` : EfficientNetV2-S réentraîné sur régions candidates et
   vrais négatifs difficiles attestés (reflets, ombres, saleté, joints,
   poignées, feux, plaques et arrière-plans).
4. `calibration` : température/seuils et règle d'abstention choisis sur le split
   calibration uniquement ; courbe précision-couverture obligatoire.
5. `qualification` : test final consulté une fois, métriques photo et inspection,
   intervalles groupés, export ONNX, carte modèle et porte de release.

## Porte de release v2

Toutes les conditions sont inclusives et obligatoires :

- AP boîtes ≥ 0,40 et AP50 ≥ 0,65 ;
- macro-F1 photo et balanced accuracy photo ≥ 0,90 ;
- précision dommage sur les résultats acceptés ≥ 0,95 ;
- rappel dommage photo ≥ 0,85 ;
- couverture acceptée ≥ 0,50, publiée avec la précision ;
- ECE ≤ 0,05 ;
- au moins 200 photos propres vérifiées et deux domaines sources ;
- aucune fuite source/vehicule, aucun second passage sur le test ;
- revue humaine obligatoire et aucune écriture automatique de dommage, frais ou
  responsabilité.

Le chiffre de 95 % désigne donc la précision des prédictions que le système
accepte après abstention. Il ne constitue ni une promesse de macro-F1 à 95 %, ni
une garantie sur toutes les photos.

## Références officielles

- RT-DETR officiel : https://github.com/lyuwenyu/RT-DETR
- CarDD et licence : https://cardd-ustc.github.io/
- TQVCD officiel : https://github.com/dxlabskku/TQVCD
- CrashCar101 : https://github.com/JensPars/CrashCar_procedural_generation
- Article CrashCar101 : https://openaccess.thecvf.com/content/WACV2024/html/Parslov_CrashCar101_Procedural_Generation_for_Damage_Assessment_WACV_2024_paper.html
