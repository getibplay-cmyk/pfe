# S7 Colour v8 — Colab GPU pipeline

This directory implements the replacement colour-model protocol without
re-opening or tuning against the failed v7.2.1 final.  v7.2.1 is development
data in v8 and is permanently ineligible for the new external final.

## Why this route

Existing vehicle-colour models are useful references, but none can be dropped
into the SaaS unchanged:

- PaddleDetection PP-Vehicle reports a PPLCNet vehicle-attribute model with
  90.81 mA and ten colours.  Its documented train/test set is VeRi.  VeRi's
  official download page restricts the dataset to non-commercial use, so the
  pretrained model is a research reference, not the SaaS deliverable.
- Open Model Zoo vehicle-attributes-recognition-barrier-0039/0042 are
  Apache-2.0 reference implementations, but expose only seven colours, assume
  front-facing vehicles of at least 72 px, and report about 81–83% average
  colour accuracy.  They do not cover orange or the explicit reject class.
- Torchvision provides maintained ImageNet-pretrained ConvNeXt, EfficientNetV2
  and MobileNetV3 implementations.  The pipeline compares them on the frozen
  v8 validation split, calibrates only the selected candidate, and refuses CPU
  production training.

Primary references:

- <https://github.com/PaddlePaddle/PaddleDetection/blob/release/2.9/deploy/pipeline/docs/tutorials/ppvehicle_attribute_en.md>
- <https://github.com/PaddlePaddle/PaddleClas/blob/release/2.4/docs/en/PULC/PULC_vehicle_attribute_en.md>
- <https://vehiclereid.github.io/VeRi/>
- <https://github.com/openvinotoolkit/open_model_zoo/blob/master/models/intel/vehicle-attributes-recognition-barrier-0039/README.md>
- <https://github.com/openvinotoolkit/open_model_zoo/blob/master/models/intel/vehicle-attributes-recognition-barrier-0042/README.md>
- <https://docs.pytorch.org/vision/stable/models.html>
- <https://research.google.com/colaboratory/faq.html>

## Non-negotiable state machine

1. `prepare_color_v8_dataset.py` creates only development data and excludes
   unverified/restricted sources.
2. `train_color_v8.py` trains each architecture and reads validation only for
   checkpoint/candidate selection.  It does not load calibration images.
3. `select_color_v8_candidate.py` freezes one candidate using a deterministic
   validation-only ranking.
4. `qualify_color_v8_development.py` may then load calibration once to fit a
   scalar temperature and evaluate the stricter development gates.
5. `freeze_color_v8_external_final.py` freezes a new, prediction-blind final
   with per-image licences and exact/pHash independence from development.
6. `evaluate_color_v8_external_final_once.py` creates an exclusive start token
   immediately before inference.  A second execution is refused, including if
   the first GPU run failed after inference started.
7. `export_color_v8_onnx.py` refuses export unless the one-shot external gate
   passed.  Its integration metadata keeps `RENTFLEET_COLOR_V8_ENABLED=false`,
   consultative output only, and mandatory human validation.

No command in this directory edits Laravel or enables a SaaS feature flag.

## Fixed ontology

`black`, `blue`, `gray`, `green`, `orange`, `red`, `white`, `yellow`,
`__reject__`.

Confidence acceptance is fixed at 0.90.  Hue and saturation augmentation are
forbidden; only geometric, brightness and contrast perturbations are used.

## Development gates

| Gate | Threshold |
|---|---:|
| Macro-F1 | >= 0.90 |
| Balanced accuracy | >= 0.90 |
| Minimum per-class recall | >= 0.85 |
| ECE | <= 0.05 |
| Support | >= 20/class |
| Accepted precision at 0.90 | >= 0.95 |
| Coverage at 0.90 | >= 0.50 |
| Reject false-acceptance at 0.90 | <= 0.05 |

The independent external final retains the registered v7 supported-class gates
(0.85 macro-F1/balanced accuracy, 0.80 minimum recall, ECE <= 0.05, support
>= 20, accepted precision >= 0.95, coverage >= 0.50) and additionally requires
reject false-acceptance <= 0.05.

## Execution

Use `S7_COLOR_V8_COLAB_GPU.ipynb`.  It mounts the private Drive, copies the ZIP
(or reassembles its SHA-256-verified multipart transport) to the Colab VM before
extraction, verifies CUDA, clones the dedicated GitHub branch, trains
candidates, selects one, then performs development calibration.  It deliberately
stops before creating or executing a new final.

The external final must be assembled later from a separate per-image licensed
ledger.  Never choose final images with candidate predictions or inspect final
metrics before the one allowed execution.
