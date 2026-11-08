"""EEG preprocessing and the pre-registered features of the application (Section 3.1).

Only the measures the method section commits to are implemented: frontal
midline theta power, parieto-occipital alpha power and alpha ERD/ERS against a
baseline. Preprocessing follows the stated order: band-pass, 50 Hz line-noise
control, bad-channel detection, high-amplitude artefact rejection, epoching.
All functions take ``data`` shaped ``(channels, samples)`` in microvolts.
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np
from scipy import signal

from .config import ALPHA, FRONTAL_MIDLINE, LINE_FREQ_HZ, PARIETO_OCCIPITAL_PREFIXES, THETA


@dataclass
class EegQuality:
    bad_channels: list[str]
    usable_epoch_ratio: float
    epochs_total: int
    epochs_usable: int


def preprocess(data: np.ndarray, fs: float, low: float = 1.0, high: float = 40.0, line_freq: float = LINE_FREQ_HZ) -> np.ndarray:
    """Zero-phase band-pass and notch filtering."""
    nyquist = fs / 2
    high = min(high, nyquist * 0.9)
    sos = signal.butter(4, [low, high], btype="bandpass", fs=fs, output="sos")
    filtered = signal.sosfiltfilt(sos, data, axis=-1)

    if line_freq < nyquist:
        b, a = signal.iirnotch(line_freq, Q=30, fs=fs)
        filtered = signal.filtfilt(b, a, filtered, axis=-1)

    return filtered


def detect_bad_channels(data: np.ndarray, labels: list[str], flat_uv: float = 0.5, z_threshold: float = 5.0) -> list[str]:
    """Flat channels and channels whose variance is a robust outlier."""
    std = data.std(axis=-1)
    median = np.median(std)
    mad = np.median(np.abs(std - median)) or 1e-9
    robust_z = 0.6745 * (std - median) / mad

    return [label for label, s, z in zip(labels, std, robust_z) if s < flat_uv or z > z_threshold]


def make_epochs(data: np.ndarray, fs: float, length_s: float = 2.0, step_s: float = 1.0) -> np.ndarray:
    """Sliding-window epochs shaped ``(epochs, channels, samples)``."""
    size = int(round(length_s * fs))
    step = int(round(step_s * fs))

    if data.shape[-1] < size:
        return np.empty((0, data.shape[0], size))

    starts = range(0, data.shape[-1] - size + 1, step)

    return np.stack([data[:, start:start + size] for start in starts])


def reject_epochs(epochs: np.ndarray, peak_to_peak_uv: float = 150.0) -> np.ndarray:
    """Boolean mask of epochs where no channel exceeds the amplitude limit."""
    if len(epochs) == 0:
        return np.zeros(0, dtype=bool)

    ptp = epochs.max(axis=-1) - epochs.min(axis=-1)

    return (ptp < peak_to_peak_uv).all(axis=-1)


def assess_quality(data: np.ndarray, fs: float, labels: list[str], peak_to_peak_uv: float = 150.0) -> EegQuality:
    filtered = preprocess(data, fs)
    bad = detect_bad_channels(filtered, labels)
    keep = [i for i, label in enumerate(labels) if label not in bad]
    epochs = make_epochs(filtered[keep], fs)
    usable = reject_epochs(epochs, peak_to_peak_uv)

    return EegQuality(
        bad_channels=bad,
        usable_epoch_ratio=float(usable.mean()) if len(usable) else 0.0,
        epochs_total=int(len(usable)),
        epochs_usable=int(usable.sum()),
    )


def band_power(epochs: np.ndarray, fs: float, band: tuple[float, float]) -> np.ndarray:
    """Absolute band power per epoch and channel (Welch, trapezoidal integration)."""
    nperseg = min(epochs.shape[-1], int(fs * 2))
    freqs, psd = signal.welch(epochs, fs=fs, nperseg=nperseg, axis=-1)
    mask = (freqs >= band[0]) & (freqs < band[1])

    return np.trapezoid(psd[..., mask], freqs[mask], axis=-1)


def frontal_midline_channels(labels: list[str]) -> list[str]:
    return [label for label in FRONTAL_MIDLINE if label in labels]


def parieto_occipital_channels(labels: list[str]) -> list[str]:
    def is_po(label: str) -> bool:
        upper = label.upper()
        return any(upper.startswith(prefix) and (upper[len(prefix):].isdigit() or upper[len(prefix):] == "Z")
                   for prefix in PARIETO_OCCIPITAL_PREFIXES)

    return [label for label in labels if is_po(label)]


def roi_features(data: np.ndarray, fs: float, labels: list[str], peak_to_peak_uv: float = 150.0) -> dict[str, float | None]:
    """Log band power in the two pre-registered regions over clean epochs.

    Returns ``None`` for a region the montage does not cover, which is how a
    device that cannot support a measure shows up in the comparison.
    """
    filtered = preprocess(data, fs)
    epochs = make_epochs(filtered, fs)
    clean = epochs[reject_epochs(epochs, peak_to_peak_uv)] if len(epochs) else epochs

    def region(channels: list[str], band: tuple[float, float]) -> float | None:
        if not channels or len(clean) == 0:
            return None
        idx = [labels.index(c) for c in channels]
        return float(np.log10(band_power(clean[:, idx], fs, band).mean()))

    return {
        "fm_theta": region(frontal_midline_channels(labels), THETA),
        "po_alpha": region(parieto_occipital_channels(labels), ALPHA),
    }


def erd_percent(task_power: float, baseline_power: float) -> float:
    """Pfurtscheller & Lopes da Silva (1999): negative values are desynchronisation."""
    return (task_power - baseline_power) / baseline_power * 100.0


def resample(data: np.ndarray, fs: float, target_fs: float) -> np.ndarray:
    """Polyphase resampling used to emulate a lower-rate amplifier."""
    if abs(fs - target_fs) < 1e-6:
        return data

    from fractions import Fraction

    ratio = Fraction(target_fs / fs).limit_denominator(1000)

    return signal.resample_poly(data, ratio.numerator, ratio.denominator, axis=-1)
