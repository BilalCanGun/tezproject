import importlib.util
import pickle
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

from tezanaliz import datasets, validate_eeg, validate_wearable
from tezanaliz.config import DeviceSpec

LABELS = ["Fp1", "F3", "Fz", "Cz", "Pz", "O1", "O2"]


def test_emulation_keeps_only_the_device_electrodes_and_lowers_the_rate():
    data = np.random.default_rng(0).normal(size=(len(LABELS), 500 * 10))
    device = DeviceSpec("Test", "eeg", 128, ["Fz", "Cz", "PO7", "O1"])

    reduced, fs, labels = validate_eeg.emulate(data, 500, LABELS, device)

    assert labels == ["Fz", "Cz", "O1"]
    assert fs == 128
    assert reduced.shape == (3, 1280)


def test_emulation_never_upsamples():
    data = np.zeros((len(LABELS), 1000))
    _, fs, _ = validate_eeg.emulate(data, 250, LABELS, DeviceSpec("Fast", "eeg", 1000, LABELS))

    assert fs == 250


def test_wesad_windows_stay_inside_one_condition():
    labels = np.array([0] * 40 + [1] * 4 * 150 + [2] * 4 * 90 + [4] * 40)

    windows = validate_wearable._windows(labels)

    assert {w[0] for w in windows} == {1, 2}
    assert all(end - start == validate_wearable.WINDOW_S for _, start, end in windows)
    assert sum(1 for w in windows if w[0] == 1) == 4
    assert sum(1 for w in windows if w[0] == 2) == 2


def test_baseline_normalisation_drops_its_reference_windows():
    frame = pd.DataFrame({
        "subject": ["a"] * 6,
        "label": ["baseline"] * 4 + ["stress"] * 2,
        "start": [0, 30, 60, 90, 200, 230],
        "hr_bpm": [60, 62, 64, 66, 80, 82],
    })

    result = validate_wearable._baseline_normalise(frame, ["hr_bpm"])

    assert len(result) == 4
    assert result.loc[result.label == "stress", "hr_bpm"].tolist() == [19, 21]


def test_the_unpickler_refuses_arbitrary_objects(tmp_path):
    safe = tmp_path / "safe.pkl"
    safe.write_bytes(pickle.dumps({"x": np.arange(3)}, protocol=2))
    unsafe = tmp_path / "unsafe.pkl"
    unsafe.write_bytes(pickle.dumps(Path("x")))

    with safe.open("rb") as handle:
        assert datasets._NumpyOnlyUnpickler(handle).load()["x"].tolist() == [0, 1, 2]
    with unsafe.open("rb") as handle, pytest.raises(pickle.UnpicklingError):
        datasets._NumpyOnlyUnpickler(handle).load()


def test_report_values_are_json_safe():
    spec = importlib.util.spec_from_file_location("run_validation", Path(__file__).resolve().parents[1] / "run_validation.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)

    cleaned = module._clean({"a": np.float64("nan"), "b": [np.int64(3), float("inf")], "c": "metin"})

    assert cleaned == {"a": None, "b": [3, None], "c": "metin"}
