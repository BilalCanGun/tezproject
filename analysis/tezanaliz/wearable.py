"""Wrist sensor processing for Section 3.3 (EDA, BVP, ACC, skin temperature).

EDA is split into tonic and phasic parts and skin-conductance responses are
counted. Heart rate comes from BVP peaks; RMSSD is only computed for windows
long and clean enough (Shaffer & Ginsberg, 2017). Analyses are block level
because the device is aligned post hoc via UTC timestamps.
"""

from __future__ import annotations

import numpy as np
from scipy import signal


def loss_ratio(timestamps: np.ndarray, fs: float, expected_start: float, expected_end: float) -> float:
    """Share of the expected samples that never arrived."""
    expected = max(1, int(round((expected_end - expected_start) * fs)))
    inside = np.sum((timestamps >= expected_start) & (timestamps <= expected_end))

    return float(max(0.0, 1.0 - inside / expected))


def eda_decompose(eda: np.ndarray, fs: float, tonic_cutoff_hz: float = 0.05) -> tuple[np.ndarray, np.ndarray]:
    """Low-pass tonic level and the residual phasic component."""
    sos = signal.butter(2, tonic_cutoff_hz, btype="low", fs=fs, output="sos")
    tonic = signal.sosfiltfilt(sos, eda) if len(eda) > 30 else np.full_like(eda, np.median(eda), dtype=float)

    return tonic, eda - tonic


def scr_peaks(eda: np.ndarray, fs: float, min_amplitude_us: float = 0.05) -> tuple[np.ndarray, np.ndarray]:
    """Skin-conductance responses as peaks of the smoothed signal.

    Prominence measures each rise from its preceding trough, so the high-pass
    undershoot of the phasic component is not counted as a response.
    """
    cutoff = min(1.0, fs / 2 * 0.8)
    smooth = signal.sosfiltfilt(signal.butter(2, cutoff, btype="low", fs=fs, output="sos"), eda) if len(eda) > 30 else eda
    peaks, props = signal.find_peaks(smooth, prominence=min_amplitude_us, distance=max(1, int(fs)))

    return peaks, props["prominences"]


def eda_features(eda: np.ndarray, fs: float) -> dict[str, float]:
    tonic, phasic = eda_decompose(eda, fs)
    peaks, amplitudes = scr_peaks(eda, fs)
    minutes = len(eda) / fs / 60

    return {
        "eda_tonic_mean": float(np.mean(tonic)),
        "eda_phasic_std": float(np.std(phasic)),
        "scr_per_min": float(len(peaks) / minutes) if minutes > 0 else float("nan"),
        "scr_mean_amp": float(np.mean(amplitudes)) if len(peaks) else 0.0,
    }


def bvp_beats(bvp: np.ndarray, fs: float) -> np.ndarray:
    """Systolic peak times in seconds."""
    sos = signal.butter(3, [0.7, 3.5], btype="bandpass", fs=fs, output="sos")
    clean = signal.sosfiltfilt(sos, bvp)
    peaks, _ = signal.find_peaks(clean, distance=int(fs * 0.33), prominence=np.std(clean) * 0.3)

    return peaks / fs


def ecg_beats(ecg: np.ndarray, fs: float) -> np.ndarray:
    """R-peak times in seconds, used as reference for the BVP estimate."""
    sos = signal.butter(3, [5, 20], btype="bandpass", fs=fs, output="sos")
    clean = signal.sosfiltfilt(sos, ecg)
    energy = clean ** 2
    peaks, _ = signal.find_peaks(energy, distance=int(fs * 0.33), height=np.percentile(energy, 98) * 0.3)

    return peaks / fs


def hr_hrv(beat_times: np.ndarray, min_beats: int = 30) -> dict[str, float]:
    """Mean heart rate and RMSSD after removing physiologically implausible intervals."""
    ibi = np.diff(beat_times)
    ibi = ibi[(ibi > 0.33) & (ibi < 1.5)]

    if len(ibi) > 3:
        median = np.median(ibi)
        ibi = ibi[np.abs(ibi - median) < 0.3 * median]

    if len(ibi) < min_beats:
        return {"hr_bpm": float(60 / np.mean(ibi)) if len(ibi) else float("nan"), "rmssd_ms": float("nan")}

    return {
        "hr_bpm": float(60 / np.mean(ibi)),
        "rmssd_ms": float(np.sqrt(np.mean(np.diff(ibi) ** 2)) * 1000),
    }


def motion_level(acc: np.ndarray) -> float:
    """Standard deviation of the acceleration magnitude (``acc`` is samples x 3)."""
    magnitude = np.linalg.norm(acc, axis=1)

    return float(np.std(magnitude))


def sync_gesture_times(acc: np.ndarray, fs: float, count: int = 3) -> np.ndarray:
    """Times of the ``count`` strongest acceleration peaks (wrist-tap markers)."""
    magnitude = np.linalg.norm(acc, axis=1)
    magnitude = magnitude - np.median(magnitude)
    peaks, props = signal.find_peaks(magnitude, distance=int(fs * 0.25), height=np.std(magnitude) * 3)
    strongest = peaks[np.argsort(props["peak_heights"])[::-1][:count]]

    return np.sort(strongest) / fs


def resample_to(x: np.ndarray, fs: float, target_fs: float) -> np.ndarray:
    """Anti-aliased decimation used to emulate the wrist sensor's native rates."""
    if abs(fs - target_fs) < 1e-6:
        return x

    from fractions import Fraction

    ratio = Fraction(target_fs / fs).limit_denominator(1000)

    return signal.resample_poly(x, ratio.numerator, ratio.denominator, axis=0)
