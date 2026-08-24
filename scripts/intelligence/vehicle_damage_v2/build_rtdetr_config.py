#!/usr/bin/env python3
"""Generate a pinned one-class RT-DETRv2-S config for Colab."""

from __future__ import annotations

import argparse
import json
from pathlib import Path


def yaml_string(value: str | Path) -> str:
    return json.dumps(str(value), ensure_ascii=False)


def render_config(
    dataset_root: Path,
    output_dir: Path,
    epochs: int,
    train_batch_size: int,
    validation_batch_size: int,
    workers: int,
) -> str:
    if epochs < 1 or min(train_batch_size, validation_batch_size) < 1 or workers < 0:
        raise ValueError("Paramètres RT-DETRv2 invalides.")
    stop_epoch = max(1, epochs - 3)
    warmup = min(200, max(10, epochs * 5))
    train_images = dataset_root / "images"
    annotations = dataset_root / "annotations"
    return f"""__include__: ['./rtdetrv2/rtdetrv2_r18vd_120e_coco.yml']

output_dir: {yaml_string(output_dir)}
num_classes: 1
remap_mscoco_category: False
sync_bn: False
find_unused_parameters: False
print_freq: 10
checkpoint_freq: 1
epoches: {epochs}
use_amp: True

lr_warmup_scheduler:
  type: LinearWarmup
  warmup_duration: {warmup}

train_dataloader:
  dataset:
    img_folder: {yaml_string(train_images)}
    ann_file: {yaml_string(annotations / 'instances_train.json')}
    transforms:
      policy:
        epoch: {stop_epoch}
  total_batch_size: {train_batch_size}
  num_workers: {workers}
  collate_fn:
    scales: [576, 608, 640, 672, 704]
    stop_epoch: {stop_epoch}

val_dataloader:
  dataset:
    img_folder: {yaml_string(train_images)}
    ann_file: {yaml_string(annotations / 'instances_validation.json')}
  total_batch_size: {validation_batch_size}
  num_workers: {workers}
"""


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dataset-root", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--config", type=Path, required=True)
    parser.add_argument("--epochs", type=int, default=1)
    parser.add_argument("--train-batch-size", type=int, default=2)
    parser.add_argument("--validation-batch-size", type=int, default=2)
    parser.add_argument("--workers", type=int, default=2)
    args = parser.parse_args()

    content = render_config(
        args.dataset_root,
        args.output_dir,
        args.epochs,
        args.train_batch_size,
        args.validation_batch_size,
        args.workers,
    )
    args.config.parent.mkdir(parents=True, exist_ok=True)
    args.config.write_text(content, encoding="utf-8")
    print(args.config)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

