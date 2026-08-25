# Moroccan vehicle plate ANPR v2

This directory contains the scientific, consultative-only path for detecting a
Moroccan vehicle plate and reading its registration number. The detector and
all real images remain private; GitHub stores only auditable code, protocol,
tests, and an output-free Colab notebook.

## Current status

- detector incumbent: private Faster R-CNN ResNet-50 FPN V2 v1.2;
- OCR baseline: official `arabic_PP-OCRv5_mobile_rec`;
- qualification status: **not qualified**;
- final independent holdout: not opened;
- SaaS integration: blocked until the release gate passes.

The historical detector has strong development results, but its secondary
Moroccan set was already consumed. Those numbers may guide development and can
never be reused as independent ANPR evidence.

## Colab smoke

Open `notebooks/colab/moroccan_vehicle_plate_anpr_v2.ipynb`, select a GPU, and
run the cells in order. The notebook:

1. checks out `science/moroccan-anpr-v2`;
2. creates a dedicated OCR virtual environment, then installs the official
   CUDA-specific PaddlePaddle 3.3.0 wheel and PaddleOCR 3.7.0 inside it;
3. verifies the private v1.2 detector checkpoint against its frozen selection;
4. draws at most 24 deterministic images from an admitted, already-consumed
   development archive;
5. runs detection, conservative crop variants, geometric rectification and
   the Arabic PP-OCRv5 recognizer;
6. writes private predictions and an aggregate smoke report to Drive.

PyTorch remains in Colab's system interpreter. PaddleOCR runs later in a
separate process using the isolated virtual environment. This is a correctness
guard: current Colab PyTorch and Paddle GPU wheels pin different cuDNN,
cuSPARSELt and NCCL versions. Both stages still use the T4, but the detector
model is released before the OCR process starts.

The default source has no OCR transcript labels, so the first run validates the
pipeline but does not estimate accuracy. It never opens the future final test.

## Optional consented labelled smoke

`colab_smoke.py` accepts a private CSV with these columns:

```text
image_path,group_id,split,target,plate_bbox,sha256,source_id,consent_status
```

- `split` is only `train`, `validation`, or `calibration`; `test` is rejected;
- `target` is optional and uses canonical `serial|Arabic-series|region` form;
- `plate_bbox` is optional JSON `[x1,y1,x2,y2]`;
- every image is re-hashed;
- `consent_status` must be `approved`;
- every physical photo has its own view ID; preprocessing variants do not
  manufacture multi-view agreement.

Example invocation inside the repository:

```bash
python scripts/intelligence/vehicle_plate/colab_smoke.py \
  --input-dir /content/private-development-images \
  --labels /content/drive/MyDrive/private/anpr-smoke-labels.csv \
  --checkpoint /content/anpr_detector_v1.2.0.pt \
  --selection /content/drive/MyDrive/private/model-selection.json \
  --ocr-python /content/venvs/rentfleet-paddleocr-v2/bin/python \
  --output-dir /content/drive/MyDrive/private/anpr-smoke-run-02
```

The optional bilingual series mapping is accepted only when its JSON says it
was verified against the official annex and provides an official HTTPS source.
Without it, a new bilingual plate is deliberately rejected for human review.

## Private output

- `SMOKE_COMPLETE.json`: environment, timings, counts and aggregate metrics;
- `PRIVATE_predictions.jsonl`: bounding boxes, OCR candidates and plate text.

The second file contains vehicle identifiers and must never be committed or
published. A smoke report always has `qualification_claim=false`.

## Validation

```bash
python -m unittest -v \
  tests/Python/test_vehicle_plate_protocol.py \
  tests/Python/test_vehicle_plate_smoke.py

python scripts/intelligence/vehicle_plate/build_colab_notebook.py
```

The complete preregistration, experiments and release thresholds are in
`docs/intelligence/moroccan-anpr-v2-protocol.md`.
