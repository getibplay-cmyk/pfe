"""Vision recipes for private Colab sessions, using the project's existing frameworks.

Source projects/checkpoints must be explicitly selected and verified locally.
No command, repository URL or executable is accepted from a SaaS manifest.
"""
from __future__ import annotations

import copy
import json
import subprocess
import sys
from pathlib import Path

from workbench import COLORS, report, require, sha256, write_json


def train_color(manifest, paths, baseline_onnx, output, version, epochs=10):
    import numpy as np
    import onnxruntime as ort
    import torch
    from PIL import Image
    from torch.utils.data import DataLoader, Dataset
    from torchvision import models, transforms
    from torchvision.transforms import InterpolationMode
    require(1 <= epochs <= 50, "Use 1–50 epochs")
    require(sha256(baseline_onnx) == "5ec7757a7bafda0abd45685dd8e1178e5b6b79220ff61b6018398d00f2e86a76", "Colour reference is not the qualified V8 ONNX")
    torch.manual_seed(manifest["seed"])
    np.random.seed(manifest["seed"])
    device = "cuda" if torch.cuda.is_available() else "cpu"
    preprocess = transforms.Compose([transforms.Resize((256, 256), interpolation=InterpolationMode.BICUBIC), transforms.CenterCrop(224), transforms.ToTensor(), transforms.Normalize((.485, .456, .406), (.229, .224, .225))])
    class Samples(Dataset):
        def __init__(self, split):
            self.rows = [r for r in manifest["rows"] if r["split"] == split]
        def __len__(self):
            return len(self.rows)
        def __getitem__(self, index):
            row = self.rows[index]
            with Image.open(paths[row["key"]]) as image:
                tensor = preprocess(image.convert("RGB"))
            return tensor, COLORS.index(row["label"]), row["key"]
    training, validation = Samples("train"), Samples("validation")
    require({r["label"] for r in training.rows} == set(COLORS), "Training needs all eight colours and reject examples")
    network = models.mobilenet_v3_large(weights=models.MobileNet_V3_Large_Weights.IMAGENET1K_V2)
    network.classifier[3] = torch.nn.Linear(network.classifier[3].in_features, len(COLORS))
    network.to(device)
    optimizer = torch.optim.AdamW(network.parameters(), lr=3e-4, weight_decay=1e-4)
    criterion = torch.nn.CrossEntropyLoss()
    best, state, history = -1, None, []
    for epoch in range(epochs):
        network.train()
        for images, labels, _ in DataLoader(training, batch_size=32, shuffle=True, num_workers=0):
            optimizer.zero_grad()
            loss = criterion(network(images.to(device)), labels.to(device))
            loss.backward()
            optimizer.step()
        network.eval()
        correct = 0
        with torch.no_grad():
            for images, labels, _ in DataLoader(validation, batch_size=32):
                correct += int((network(images.to(device)).argmax(1).cpu() == labels).sum())
        accuracy = correct / len(validation)
        history.append({"epoch": epoch + 1, "validation_accuracy": accuracy})
        if accuracy > best:
            best, state = accuracy, copy.deepcopy(network.state_dict())
    network.load_state_dict(state)
    network.eval()
    reference = ort.InferenceSession(str(baseline_onnx), providers=["CPUExecutionProvider"])
    before, after = {}, {}
    with torch.no_grad():
        for tensor, _, key in Samples("test"):
            before[key] = COLORS[int(np.asarray(reference.run(["probabilities"], {reference.get_inputs()[0].name: tensor[None].numpy()})[0]).argmax())]
            after[key] = COLORS[int(network(tensor[None].to(device)).argmax(1))]
    output = Path(output)
    output.mkdir(parents=True, exist_ok=False, mode=0o700)
    torch.save({"state_dict": network.cpu().state_dict(), "classes": COLORS, "version": version}, output / "candidate.pth")
    class Export(torch.nn.Module):
        def __init__(self, model):
            super().__init__()
            self.model = model
        def forward(self, images):
            probabilities = self.model(images).softmax(1)
            confidence, index = probabilities[:, :-1].max(1)
            accepted = (confidence >= .977) & (probabilities.argmax(1) != 8)
            return probabilities, index, confidence, accepted
    artifact = output / "candidate.onnx"
    torch.onnx.export(Export(network.cpu()).eval(), torch.zeros(1, 3, 224, 224), str(artifact), input_names=["normalized_rgb_image"], output_names=["probabilities", "supported_class_index", "supported_confidence", "accepted"], opset_version=17, dynamo=False)
    write_json(output / "validation-history.json", history)
    write_json(output / "qualification-required.json", {"threshold": "0.977 is only a compatibility placeholder; recalibrate on validation before deployment", "comparison": "nine-class argmax, before abstention policy", "production_ready": False})
    report(manifest, before, after, artifact, version, output / "report.json")
    return artifact


def checked_source(root, expected_commit):
    root = Path(root).resolve(strict=True)
    require(len(expected_commit) == 40, "An approved full source commit is required")
    commit = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=root, text=True).strip()
    require(commit == expected_commit, "Source checkout is not the approved revision")
    require(not subprocess.check_output(["git", "status", "--porcelain", "--untracked-files=no"], cwd=root, text=True).strip(), "Tracked trainer sources are modified")
    return root


def train_damage(manifest, prepared, upstream, checkpoint, checkpoint_sha256, output, epochs=30):
    require(1 <= epochs <= 100, "Use 1–100 epochs")
    root = checked_source(upstream, "068dfde65f2667ad6555883c69d73de886518cad") / "rtdetrv2_pytorch"
    require(sha256(checkpoint) == checkpoint_sha256, "Approved training checkpoint digest mismatch")
    prepared, output = Path(prepared).resolve(), Path(output).resolve()
    output.mkdir(parents=True, exist_ok=False, mode=0o700)
    config = {
        "__include__": [str(root / "configs/rtdetrv2/rtdetrv2_r18vd_120e_coco.yml")],
        "output_dir": str(output), "epoches": epochs, "num_classes": 1,
        "remap_mscoco_category": False, "sync_bn": False,
        "PResNet": {"depth": 18, "pretrained": False},
        "train_dataloader": {"dataset": {"img_folder": str(prepared / "train/images"), "ann_file": str(prepared / "train/annotations.json")}},
        "val_dataloader": {"dataset": {"img_folder": str(prepared / "validation/images"), "ann_file": str(prepared / "validation/annotations.json")}},
    }
    # JSON is a YAML subset; these paths are serialized, never interpolated into shell code.
    config_path = output / "training.yml"
    write_json(config_path, config)
    subprocess.run([sys.executable, str(root / "tools/train.py"), "--config", str(config_path), "--tuning", str(Path(checkpoint).resolve()), "--seed", str(manifest["seed"])], cwd=root, check=True)
    return config_path


def export_damage(upstream, training_config, validation_checkpoint, output_onnx):
    root = checked_source(upstream, "068dfde65f2667ad6555883c69d73de886518cad") / "rtdetrv2_pytorch"
    output = Path(output_onnx).resolve()
    require(not output.exists(), "Refusing to overwrite a candidate export")
    subprocess.run([sys.executable, str(root / "tools/export_onnx.py"), "--config", str(Path(training_config).resolve()), "--resume", str(Path(validation_checkpoint).resolve()), "--output_file", str(output), "--input_size", "640", "--check"], cwd=root, check=True)
    return output


def damage_predictions(manifest, paths, onnx_path, threshold):
    import numpy as np
    import onnxruntime as ort
    from PIL import Image
    require(0 < threshold <= 1, "Threshold must be chosen on validation")
    session = ort.InferenceSession(str(onnx_path), providers=["CPUExecutionProvider"])
    output = {}
    for row in manifest["rows"]:
        if row["split"] != "test":
            continue
        with Image.open(paths[row["key"]]) as image:
            width, height = image.size
            tensor = np.asarray(image.convert("RGB").resize((640, 640), Image.Resampling.BILINEAR), dtype=np.float32).transpose(2, 0, 1)[None] / np.float32(255)
        labels, boxes, scores = session.run(["labels", "boxes", "scores"], {"images": tensor, "orig_target_sizes": np.asarray([[width, height]], dtype=np.int64)})
        predictions = []
        for label, box, score in zip(np.asarray(labels).reshape(-1), np.asarray(boxes).reshape(-1, 4), np.asarray(scores).reshape(-1)):
            require(np.isfinite(box).all() and np.isfinite(score), "Non-finite detection")
            if score < threshold or label != 0:
                continue
            x1, y1, x2, y2 = float(np.clip(box[0] / width, 0, 1)), float(np.clip(box[1] / height, 0, 1)), float(np.clip(box[2] / width, 0, 1)), float(np.clip(box[3] / height, 0, 1))
            if x2 > x1 and y2 > y1:
                predictions.append({"x": x1, "y": y1, "w": x2 - x1, "h": y2 - y1})
        require(len(predictions) <= 100, "Too many detections; qualify NMS/threshold on validation")
        output[row["key"]] = predictions
    return output


def train_plate(manifest, prepared, upstream, approved_commit, approved_config, checkpoint, checkpoint_sha256, output):
    """Reuse the approved Arabic recognition architecture and dictionary, not a new OCR alphabet."""
    import yaml
    root = checked_source(upstream, approved_commit)
    require(sha256(checkpoint) == checkpoint_sha256, "Approved OCR checkpoint digest mismatch")
    config = yaml.safe_load(Path(approved_config).read_text(encoding="utf-8"))
    require(config.get("Architecture", {}).get("model_type") == "rec", "A vetted recognition training config is required")
    prepared, output = Path(prepared).resolve(), Path(output).resolve()
    output.mkdir(parents=True, exist_ok=False, mode=0o700)
    config["Global"].update({"pretrained_model": str(Path(checkpoint).resolve().with_suffix("")), "save_model_dir": str(output), "seed": manifest["seed"]})
    dictionary = Path(config["Global"]["character_dict_path"])
    if not dictionary.is_absolute():
        dictionary = root / dictionary
    characters = set(dictionary.read_text(encoding="utf-8").splitlines())
    require(all(set(row["label"]) <= characters for row in manifest["rows"]), "Approved dictionary does not cover canonical labels; qualify the dictionary/head before retraining")
    for key, split in (("Train", "train"), ("Eval", "validation")):
        config[key]["dataset"].update({"data_dir": str(prepared), "label_file_list": [str(prepared / f"{split}.txt")], "ratio_list": [1.0]})
    config_path = output / "training.yml"
    write_json(config_path, config)
    subprocess.run([sys.executable, str(root / "tools/train.py"), "-c", str(config_path)], cwd=root, check=True)
    return config_path


def export_plate(upstream, approved_commit, config, best_checkpoint_prefix, output):
    root = checked_source(upstream, approved_commit)
    require(not Path(output).exists(), "Refusing to overwrite an OCR export")
    subprocess.run([sys.executable, str(root / "tools/export_model.py"), "-c", str(Path(config).resolve()), "-o", "Global.pretrained_model=" + str(Path(best_checkpoint_prefix).resolve()), "Global.save_inference_dir=" + str(Path(output).resolve())], cwd=root, check=True)


def plate_predictions(manifest, paths, model_directory):
    from paddleocr import TextRecognition
    from PIL import Image
    import numpy as np
    sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'vehicle_plate'))
    from protocol import parse_plate_text
    recognizer = TextRecognition(model_name="arabic_PP-OCRv5_mobile_rec", model_dir=str(model_directory))
    output = {}
    for row in manifest["rows"]:
        if row["split"] != "test":
            continue
        with Image.open(paths[row["key"]]) as image:
            # Paddle receives BGR, as in the existing OpenCV-based worker.
            bgr = np.asarray(image.convert("RGB"))[:, :, ::-1].copy()
        result = list(recognizer.predict(input=[bgr], batch_size=1))[0]
        payload = result.json if hasattr(result, "json") else result
        if isinstance(payload, str):
            payload = json.loads(payload)
        text = str(payload.get("res", payload).get("rec_text", ""))
        output[row["key"]] = parse_plate_text(text).canonical or ""
    return output
