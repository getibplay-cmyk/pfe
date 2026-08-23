# Vehicle damage — EfficientNetV2-S

This lot trains a binary, consultative assistant for return-inspection photos:
`no visible damage` versus `visible damage`. It does not determine liability,
repair cost, severity, or a contractual decision.

## Mandatory inputs

- a private image directory on Google Drive;
- a frozen CSV manifest with every column listed in `protocol.py`;
- a private proof of the official licence/authorisation referenced by each row;
- four group-disjoint splits: `train`, `validation`, `calibration`, and `test`.

The only preregistered sources are the official HITL, CarDD, and TQVCD sources.
Mirrors are rejected. CarDD and TQVCD require prior author consent; HITL is CC0
but its official access form must still be completed.

## Colab execution

Open `notebooks/colab/vehicle_damage_efficientnetv2s.ipynb`, choose a GPU
runtime, mount Drive, and run the cells in order. The notebook defaults to a GPU
smoke test and refuses full training while the frozen manifest is missing.

Private data, checkpoints, predictions, and model files stay in Drive. GitHub
contains only code, protocol, schema, tests, and non-sensitive attestations.

## Qualification gate

All four conditions must pass on the untouched test set:

- balanced accuracy >= 0.75;
- macro-F1 >= 0.75;
- damage recall >= 0.75;
- expected calibration error <= 0.08.

The PFE target is 0.90 for the three discrimination metrics. The target is
reported separately and does not replace the explicit 0.75 release floor.

If any release condition fails, the script writes `STOP_NOT_QUALIFIED.json` and
does not export `model.onnx`.
