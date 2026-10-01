# Vehicle damage v2 — RT-DETRv2-S

This directory contains the public and reproducible portion of the second
scientific iteration of RentFleet's consultative damage assistant.

The default notebook mode is `smoke`. It converts eight train and eight
validation source images, trains for one epoch on Colab GPU, exports ONNX and
runs one finite-output inference. It never reads the frozen legacy test split
and it never qualifies the smoke model.

Generate and verify the notebook locally:

```bash
python scripts/intelligence/vehicle_damage_v2/build_colab_notebook.py
python -m json.tool notebooks/colab/vehicle_damage_rtdetrv2.ipynb >/dev/null
python -m unittest -v tests/Python/test_vehicle_damage_v2_protocol.py
```

Private inputs expected in Drive:

```text
RentFleet_PFE/S7_vehicle_vision_assistant/
├── donnees_brutes_privees/Car_parts_and_car_damages_dataset.zip
└── splits_geles/S7_DAMAGE_manifest_v1.1.csv
```

Private smoke/candidate outputs are written below `modeles/`. Checkpoints,
ONNX, predictions, image names and private SHA-256 values must not be committed.

The complete protocol and release criteria are documented in
`docs/intelligence/vehicle-damage-v2-rtdetr-protocol.md`.
