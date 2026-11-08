"""Thresholds and candidate device specifications used by the analysis pipeline.

The quality thresholds mirror ``config/experiment.php`` (``protocol_defaults.quality``)
and Table 1 of the BAP application. The device list mirrors
``database/seeders/DeviceProfileSeeder.php``; when the Laravel side has exported
the live profiles (``php artisan devices:export-profiles``) those are used instead.
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parents[2]
ANALYSIS_ROOT = PROJECT_ROOT / "analysis"
DATA_ROOT = ANALYSIS_ROOT / "data"
RESULTS_ROOT = PROJECT_ROOT / "storage" / "app" / "private" / "analysis"
DEVICE_EXPORT = RESULTS_ROOT / "device_profiles.json"

QUALITY = {
    "min_eeg_usable_epoch_ratio": 0.70,
    "min_gaze_valid_ratio": 0.80,
    "max_wearable_loss_ratio": 0.10,
    "min_marker_integrity": 1.0,
    "max_calibration_error_deg": 1.0,
}

EEG_MIN_SAMPLING_HZ = 250
EYE_MIN_SAMPLING_HZ = 120
LINE_FREQ_HZ = 50

THETA = (4.0, 8.0)
ALPHA = (8.0, 13.0)

FRONTAL_MIDLINE = ("Fz", "FCz", "Cz")
PARIETO_OCCIPITAL_PREFIXES = ("P", "PO", "O")


@dataclass
class DeviceSpec:
    """The subset of a device profile the emulation needs."""

    name: str
    modality: str
    sampling_rate_hz: float | None
    channel_labels: list[str] = field(default_factory=list)
    signals: list[str] = field(default_factory=list)
    signal_rates_hz: dict[str, float] = field(default_factory=dict)
    sync_method: str = "lsl"
    source: str = "varsayılan"


# Per-signal rates for the wrist sensor come from Empatica's EmbracePlus
# documentation (EDA 4 Hz, BVP 64 Hz, ACC 64 Hz, temperature 1 Hz). They are
# marked unverified in the seeder until the pro forma confirms them.
DEFAULT_DEVICES: list[DeviceSpec] = [
    DeviceSpec("g.tec Unicorn Hybrid Black", "eeg", 250, ["Fz", "C3", "Cz", "C4", "Pz", "PO7", "Oz", "PO8"], ["eeg"]),
    DeviceSpec(
        "Emotiv EPOC Flex Saline",
        "eeg",
        128,
        ["Fp1", "Fp2", "AF3", "AF4", "F7", "F3", "Fz", "F4", "F8", "FC5", "FC1", "FCz", "FC2", "FC6", "T7", "C3", "Cz", "C4",
         "T8", "CP5", "CP1", "CP2", "CP6", "P7", "P3", "Pz", "P4", "P8", "PO3", "PO4", "O1", "O2"],
        ["eeg"],
    ),
    DeviceSpec("Emotiv EPOC X", "eeg", 128, ["AF3", "F7", "F3", "FC5", "T7", "P7", "O1", "O2", "P8", "T8", "FC6", "F4", "F8", "AF4"], ["eeg"]),
    DeviceSpec("Göz izleyici (genel profil, ≥120 Hz)", "eye_tracker", 120, signals=["gaze", "pupil"]),
    DeviceSpec("Düşük hızlı izleyici / web kamerası sınıfı (30 Hz)", "eye_tracker", 30, signals=["gaze"], source="karşılaştırma"),
    DeviceSpec(
        "Empatica EmbracePlus",
        "wearable",
        None,
        signals=["eda", "bvp", "acc", "temp"],
        signal_rates_hz={"eda": 4.0, "bvp": 64.0, "acc": 64.0, "temp": 1.0},
        sync_method="utc_posthoc",
    ),
    DeviceSpec(
        "Tüketici sınıfı bileklik (BVP 25 Hz, EDA yok)",
        "wearable",
        None,
        signals=["bvp", "acc"],
        signal_rates_hz={"bvp": 25.0, "acc": 25.0},
        sync_method="utc_posthoc",
        source="karşılaştırma",
    ),
]


def load_devices() -> list[DeviceSpec]:
    """Device profiles exported from the admin panel, or the built-in defaults."""
    if not DEVICE_EXPORT.exists():
        return DEFAULT_DEVICES

    rows = json.loads(DEVICE_EXPORT.read_text(encoding="utf-8"))
    defaults = {device.name: device for device in DEFAULT_DEVICES}
    devices = []

    for row in rows:
        known = defaults.get(row["name"])
        devices.append(DeviceSpec(
            name=row["name"],
            modality=row["modality"],
            sampling_rate_hz=row.get("sampling_rate_hz"),
            channel_labels=row.get("channel_labels") or [],
            signals=row.get("signals") or [],
            signal_rates_hz=known.signal_rates_hz if known else {},
            sync_method=row.get("sync_method") or "lsl",
            source="panel",
        ))

    exported = {device.name for device in devices}
    comparison = [device for device in DEFAULT_DEVICES if device.name not in exported and device.source == "karşılaştırma"]

    return (devices + comparison) if devices else DEFAULT_DEVICES
