# Rapport public — candidat RT-DETRv2-S dommages v2

Date d'exécution : 24 août 2026

## Décision

Le candidat complet est **techniquement valide mais non qualifié**. Il ne
remplace pas EfficientNetV2-S v1.1 dans le SaaS. La PR reste en brouillon et
aucun seuil de production ne doit être dérivé de cette exécution.

## Protocole exécuté

- RT-DETRv2-S officiel, source publique épinglée ;
- 60 époques sur GPU NVIDIA T4 ;
- 569 images source d'entraînement et 81 images source de validation ;
- 6 355 boîtes d'entraînement et 905 boîtes de validation ;
- sélection de `best.pth` par AP COCO sur validation ;
- aucune lecture du split de test v1.1 ;
- export ONNX autonome et inférence finie avant puis après copie privée dans
  Drive ;
- seed `20260824`.

## Résultats agrégés

| Mesure | Meilleure époque | Dernière époque |
|---|---:|---:|
| Époque | 49 | 60 |
| AP boîtes (IoU 0,50:0,95) | 11,69 % | 10,71 % |
| AP50 boîtes | 24,70 % | 23,98 % |

Porte détecteur prévue : AP au moins 40 % et AP50 au moins 65 %. Les deux
conditions échouent. Les métriques photo, la précision-couverture, la
calibration, les intervalles groupés et le test final n'ont pas été évalués.

## Interprétation

L'exécution confirme la conversion COCO, le fine-tuning GPU, la sélection du
meilleur checkpoint et l'export ONNX. Elle confirme aussi qu'un entraînement
plus long sur la seule source HITL ne suffit pas à produire un détecteur fiable
pour le SaaS.

Les données actuelles contiennent des images annotées de dommages, mais pas le
minimum de 200 photos propres vérifiées ni deux domaines sources exigés par le
protocole. Sans ces preuves, une précision acceptée à 95 % ne peut pas être
mesurée honnêtement et encore moins revendiquée.

## Prochain jalon autorisé

1. obtenir et documenter un second domaine sous licence compatible ;
2. constituer au moins 200 photos propres vérifiées, séparées par véhicule ou
   inspection avant le split ;
3. entraîner un vérificateur de présence sur les régions candidates et les
   négatifs difficiles ;
4. choisir seuils et abstention sur calibration, avec courbe
   précision-couverture ;
5. n'ouvrir le test final qu'une seule fois après gel complet du pipeline.

Les photos, annotations, checkpoints, modèles, prédictions, chemins Drive et
empreintes cryptographiques restent privés.
