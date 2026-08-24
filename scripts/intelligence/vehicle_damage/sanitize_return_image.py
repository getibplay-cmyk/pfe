#!/usr/bin/env python3
"""Re-encode one private return-inspection image without source metadata."""

from __future__ import annotations

import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from color_v8 import sanitize_vehicle_image as core  # noqa: E402
from PIL import Image  # noqa: E402


def main() -> int:
    args = core.parse_args()
    manifest = core.sanitize(
        args.input,
        args.output,
        args.expected_mime,
        args.max_bytes,
        args.max_dimension,
        args.output_max_dimension,
    )
    sys.stdout.write(json.dumps(manifest, separators=(",", ":")) + "\n")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (
        core.SanitizationContractError,
        OSError,
        ValueError,
        Image.DecompressionBombError,
        Image.DecompressionBombWarning,
    ):
        sys.stderr.write('{"error":"DAMAGE_IMAGE_SANITIZATION_FAILED"}\n')
        raise SystemExit(2)
    except Exception:
        sys.stderr.write('{"error":"DAMAGE_IMAGE_SANITIZER_UNAVAILABLE"}\n')
        raise SystemExit(3)
