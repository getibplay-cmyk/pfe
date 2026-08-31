import errno
import tempfile
import unittest
from pathlib import Path

from scripts.intelligence.vehicle_plate.plate_detector_worker import (
    DetectorWorkerError,
    box_iou,
    expand_box,
    secure_child,
    select_detection,
)


def create_symlink_or_skip(
    test_case: unittest.TestCase, link: Path, target: Path
) -> None:
    try:
        link.symlink_to(target)
    except NotImplementedError:
        test_case.skipTest("Symbolic-link creation is unsupported by this platform.")
    except OSError as exception:
        unsupported = {
            errno.EPERM,
            errno.EACCES,
            getattr(errno, "ENOTSUP", -1),
            getattr(errno, "EOPNOTSUPP", -1),
        }
        if getattr(exception, "winerror", None) == 1314 or exception.errno in unsupported:
            test_case.skipTest(
                "The current process lacks symbolic-link creation capability."
            )
        raise


class VehiclePlateDetectorWorkerTest(unittest.TestCase):
    def test_selects_highest_plate_above_threshold(self) -> None:
        result = select_detection(
            boxes=[[10, 20, 110, 60], [12, 21, 108, 59], [0, 0, 20, 20]],
            labels=[1, 1, 2],
            scores=[0.91, 0.84, 0.99],
            threshold=0.075,
        )

        self.assertEqual("detected", result["status"])
        self.assertEqual([10.0, 20.0, 110.0, 60.0], result["bbox"])
        self.assertEqual(2, result["eligible_count"])
        self.assertFalse(result["ambiguous"])

    def test_abstains_for_two_distinct_near_score_plates(self) -> None:
        result = select_detection(
            boxes=[[10, 20, 110, 60], [300, 200, 420, 250]],
            labels=[1, 1],
            scores=[0.91, 0.88],
            threshold=0.075,
        )

        self.assertEqual("ambiguous", result["status"])
        self.assertTrue(result["ambiguous"])
        self.assertEqual(2, result["eligible_count"])

    def test_abstains_when_no_plate_reaches_threshold(self) -> None:
        result = select_detection(
            boxes=[[10, 20, 110, 60]],
            labels=[1],
            scores=[0.07],
            threshold=0.075,
        )

        self.assertEqual(
            {
                "status": "no_detection",
                "score": None,
                "bbox": None,
                "eligible_count": 0,
                "ambiguous": False,
            },
            result,
        )

    def test_padding_is_bounded_to_the_image(self) -> None:
        self.assertEqual((0, 0, 105, 53), expand_box([0, 0, 100, 50], 200, 100, 0.05))
        self.assertAlmostEqual(1.0, box_iou([0, 0, 10, 10], [0, 0, 10, 10]))

    def test_secure_child_refuses_traversal_on_every_platform(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            image = root / "vehicle.jpg"
            image.write_bytes(b"fixture")
            self.assertEqual(image, secure_child(root, "vehicle.jpg", must_exist=True))
            self.assertEqual(
                root / "future.jpg",
                secure_child(root, "future.jpg", must_exist=False),
            )

            for unsafe in (
                "",
                ".",
                "..",
                "../vehicle.jpg",
                "nested/vehicle.jpg",
                "nested\\vehicle.jpg",
                str(image.resolve()),
            ):
                with self.subTest(unsafe=unsafe), self.assertRaisesRegex(
                    DetectorWorkerError, "PATH_OUTSIDE_BOUNDARY"
                ):
                    secure_child(root, unsafe, must_exist=False)

            with self.assertRaisesRegex(DetectorWorkerError, "ROOT_INVALID"):
                secure_child(root / "missing", "vehicle.jpg", must_exist=False)

    def test_secure_child_refuses_symlink_when_capability_is_available(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            target = root / "target.jpg"
            target.write_bytes(b"target")
            link = root / "link.jpg"
            create_symlink_or_skip(self, link, target)
            with self.assertRaisesRegex(DetectorWorkerError, "SYMLINK_FORBIDDEN"):
                secure_child(root, "link.jpg", must_exist=True)

    def test_invalid_model_output_fails_closed(self) -> None:
        with self.assertRaisesRegex(DetectorWorkerError, "DETECTION_OUTPUT_INVALID"):
            select_detection([[0, 0, 1, 1]], [1, 1], [0.9], 0.075)
        with self.assertRaisesRegex(DetectorWorkerError, "DETECTION_OUTPUT_INVALID"):
            select_detection([[0, 0, float("nan"), 1]], [1], [0.9], 0.075)


if __name__ == "__main__":
    unittest.main()
