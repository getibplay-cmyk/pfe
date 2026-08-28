# Moroccan vehicle plate ANPR v2

This directory contains the scientific, consultative-only path for detecting a
Moroccan vehicle plate and reading its registration number. The detector and
all real images remain private; GitHub stores only auditable code, protocol,
tests, and an output-free Colab notebook.

## Current status

- detector incumbent: private, hash-gated plate-localization model;
- OCR baseline: official `arabic_PP-OCRv5_mobile_rec`;
- qualification status: **not qualified**;
- historic independent holdout: consumed once and permanently retired;
- replacement independent holdout: required and not opened by E3.2;
- SaaS integration: blocked until the release gate passes.

An additional code-only review fallback now reuses the same official PP-OCRv5
recognizer on bounded serial, Arabic-series and territorial-code zones when the
full-crop reading is empty or rejected. It produces a human-review suggestion,
not an accepted registration. The Laravel result contract and disabled feature
flag are prepared, but no route, table or production activation is part of this
scientific branch. See
[`moroccan-anpr-hybrid-feedback.md`](../../../docs/intelligence/moroccan-anpr-hybrid-feedback.md).

The fallback deliberately excludes every code or weight from the unlicensed
`essanhaji/moroccan-lpr-ocr` repository.

The detector's historical development sources were already consumed. They may
guide development and can never be reused as independent ANPR evidence.

## Colab smoke

The legacy smoke notebook verifies the private detector by hash, crops only the
detected plate region, then runs the OCR worker in an isolated PaddlePaddle
process. Private paths, checkpoint identifiers and input manifests belong to
the private runbook and are deliberately omitted here.

The default source has no OCR transcript labels, so the first run validates the
pipeline but does not estimate accuracy. It never opens the replacement final
test.

## Synthetic OCR development set

`generate_synthetic_dataset.py` builds a deterministic, group-safe PaddleOCR
development bundle without reading a real vehicle image. Each split contains
both the historical one-line Arabic format and the unified 2026 format with an
Arabic series, its official Latin equivalent and `MA`. The 15 mappings come
from Moroccan arrêté n° 640.26, published in Bulletin officiel n° 7531 on
3 August 2026. Unified recognition labels follow the published visual order:
`MA`, serial number, Arabic/Latin series cell, then territorial code.

The pinned Arabic font input is the official Noto Sans Arabic `v2.013`
release. Its GitHub release asset reports this SHA-256:

```text
1301aceaea84c501cf2e6dcfb3182e2328c8eae5725817fcb239672bda7154f1
```

The unified layout renders `MA` and the official Latin equivalent with Noto
Sans from Google Fonts commit
`6a003b5eb672dc8bf5bff5937cf5863f8b175445`. The pinned TTF and OFL proof have
SHA-256 `bfb7bb691513f12e734dc346c03a03f784912432d7e3fa8e56efcf906fe86b3d`
and `cee9892f9f0cc8fe882c9e9537ee6a89621d86ee7ceaf70b02e2b2b1c25c061a`.
Two fonts are intentional: the Arabic release does not reliably render the
Latin glyphs in the generated plate image.

The generated Colab notebook downloads and verifies the archive before calling
the generator with its isolated OCR Python. Pillow, FreeType, the official
mapping, seeds and format proportions are captured in the report.

The output contains `manifest.csv`, per-split PaddleOCR labels, a character
dictionary, the exact Arabic and Latin fonts and both OFL proofs,
`generation_report.json`, and
`SHA256SUMS`. Variants of one canonical registration stay in the same group and
split. The generator refuses an existing output directory and has no `test`
option, so it cannot overwrite a previous run or open the replacement
independent holdout.

Wrong or missing Latin equivalents count as full-plate OCR errors. Synthetic
results must be reported separately. They validate the E2 training
mechanics but are not evidence of real-photo accuracy and never qualify the
SaaS integration.

E2.1 corrects an evaluator-order defect discovered after the first immutable
run. With an Arabic dictionary, PaddleOCR v3.7.0 reverses contiguous ASCII
groups and individual non-ASCII characters in its decoder. The pipeline keeps
that raw output for audit and applies the same deterministic involution before
grammar parsing. Re-scoring the unchanged run-01 challenger predictions gives
100% synthetic exact-match for both formats on validation clean (128/128 each),
calibration clean (128/128 each), and validation with all variants (384/384
each). This is a post-processing correction, not a retraining or a real-photo
claim. Future E2 selection also requires at least 90% exact-match in every
synthetic format segment. The machine-readable audit is
`docs/intelligence/evidence/moroccan-anpr-e2.1-decoder-order-rescore.json`.

## Colab E2 synthetic-only

Open
`notebooks/colab/moroccan_vehicle_plate_anpr_v2_e2_synthetic.ipynb`, select a
GPU, and run all cells. The frozen pilot uses seed `20260825`, 1,024 training
groups, 256 validation groups, 256 calibration groups, three variants per
group, 20 epochs and a T4-safe batch size of 64. It:

1. checks out this scientific branch and PaddleOCR `v3.7.0` at commit
   `b03f46425e8ff4442b268ce449e3eef758146cd4`;
2. verifies Noto Sans Arabic `v2.013`, the commit-pinned Noto Sans Latin font,
   both OFL proofs, and the official Arabic PP-OCRv5 pretrained weights,
   configuration and 747-character dictionary;
3. generates all images in ephemeral Colab storage, without any real image or
   `test` split, with a 50/50 legacy/unified format balance;
4. measures the official baseline, fine-tunes a synthetic challenger, requires
   at least 90% exact-match per format and rejects any per-format regression
   before applying validation exact-match and the CER tie-break;
5. copies the immutable result bundle to an access-controlled private run
   location after verifying every SHA-256.

`E2_SYNTHETIC_COMPLETE.json` always declares
`synthetic_e2_complete_not_qualified`, `qualification_claim=false`,
`final_test_opened=false` and `saas_integration_allowed=false`. The bundle also
contains both candidate checkpoints, logs, synthetic predictions, the exact
source/configuration/dictionary, font and OFL provenance, `pip-freeze.txt`, and
`SHA256SUMS`. Raw generated images remain local to the Colab runtime; their
manifest and aggregate image digest allow exact regeneration.

Here `final_test_opened=false` refers to the required replacement holdout. The
historic independent holdout was already consumed once and is never reused for
selection, calibration, or requalification.

E2 is deliberately a recognition experiment. The full ANPR chain is always
`vehicle image -> plate detector -> bounded crop -> recognizer -> grammar and
abstention`. Full-frame OCR is forbidden because it could return unrelated text.

## E3.1 detection-source preparation

`detection_sources.py` prepares only full-frame plate-localisation data. Its
CCPD mode verifies the official MIT proof, parses only the geometry fields in
the seven-part filename in annotated partitions, creates a one-class COCO
bundle, computes SHA-256 and 64-bit difference hashes, groups exact/near
duplicates before splitting, and emits only `train`, `validation`, and
`calibration`. The official `ccpd_np` negative-image partition has no encoded
box and is explicitly counted then excluded from this positive one-box bundle.
The Chinese sequence field is deliberately opaque and never appears as an OCR
target.

The Open Images mode creates a no-download candidate CSV from manual `xclick`
boxes for `/m/01jfm_`. It requires per-item CC BY 2.0 metadata and attribution,
but keeps download and training disabled until the original landing page is
reviewed for every image. This follows Open Images' own warning that its image
licence listings carry no warranty.

Neither source is a Moroccan holdout. Upstream CCPD test folders are remapped
to development, Open Images has no OCR truth here, and a future source-disjoint
Moroccan evaluation remains mandatory.

Open
`notebooks/colab/moroccan_vehicle_plate_anpr_v2_e31_detection_sources.ipynb`
to acquire the official CCPD2019 archive in ephemeral Colab storage and save
only the bounded, sealed development bundle to private Drive. The notebook is
pinned to both the RentFleet import code and the upstream CCPD repository
revision; it produces no trained model and never touches the replacement final
holdout.
The completed bounded-source run is recorded in
`docs/intelligence/evidence/moroccan-anpr-e3.1-ccpd-source-audit.json`; private
Drive identifiers and artifact hashes remain only in the sealed private run.

## E3.2 balanced detection transfer

`e32_detection_transfer.py` keeps the Faster R-CNN architecture and image
resolution fixed while balancing four development sources per epoch: the two
already-consumed Moroccan domains, the admitted generic global source, and the
bounded CCPD bundle from E3.1. Candidate epochs are eligible only when neither
Moroccan anchor regresses by more than two points in mAP50 or recall. Ranking
then maximizes worst-domain mAP50, worst-domain recall, and macro-domain mAP50.

Run 01 completed three resumable epochs and selected epoch 1. Against the
warm-start incumbent, worst-domain mAP50 increased from `0.570088` to
`0.910674`, worst-domain recall increased from `0.583538` to `0.918605`, and
macro-domain mAP50 increased from `0.815791` to `0.962114`. The selected
development metrics were:

| Consumed development domain | mAP50 | Recall |
|---|---:|---:|
| CCPD public validation | 0.976291 | 0.981572 |
| Moroccan primary validation | 0.999376 | 1.000000 |
| Moroccan secondary validation | 0.910674 | 0.918605 |

Threshold calibration used the three calibration domains and selected `0.075`
through the preregistered fallback rule, maximizing worst-domain recall before
macro F1. All nine sealed artifacts passed `sha256sum -c`; their private hashes,
paths, identifiers, model weights, images, and labels are not published.

These are development results on already-consumed Moroccan cohorts, not
independent evidence. The historic holdout remains consumed and retired; E3.2
did not open the required replacement holdout. End-to-end OCR was not evaluated,
no qualification claim is made, and SaaS integration remains blocked. The
sanitized machine-readable result is
`docs/intelligence/evidence/moroccan-anpr-e3.2-detection-transfer-run01.json`.

## Optional consented labelled smoke

`colab_smoke.py` accepts only consented development rows, verifies every input
hash and rejects `test`. Operational paths, private artifact names and the
invocation are intentionally kept in the private runbook.

The bilingual series mapping must match the official 15-pair mapping. A missing
or inconsistent Arabic/Latin pair is rejected for human review.

Private smoke outputs may contain vehicle identifiers and must never be
committed or published. A smoke report always has `qualification_claim=false`.

## Validation

```bash
python -m unittest -v \
  tests/Python/test_vehicle_plate_protocol.py \
  tests/Python/test_vehicle_plate_smoke.py \
  tests/Python/test_vehicle_plate_synthetic.py \
  tests/Python/test_vehicle_plate_e2_synthetic.py \
  tests/Python/test_vehicle_plate_detection_sources.py \
  tests/Python/test_vehicle_plate_e32_detection_transfer.py

python scripts/intelligence/vehicle_plate/build_colab_notebook.py
python scripts/intelligence/vehicle_plate/build_e2_synthetic_notebook.py
python scripts/intelligence/vehicle_plate/build_e31_detection_sources_notebook.py
```

The complete preregistration, experiments and release thresholds are in
`docs/intelligence/moroccan-anpr-v2-protocol.md`.
