"""Eye-tracking quality and measures named in Section 3.2 of the application.

Fixations are detected with a velocity threshold (I-VT). AOI measures are
computed from the rectangles the kiosk logs in ``aoi_layout`` events. Pupil
diameter is blink-interpolated and baseline-corrected (Mathôt et al., 2018);
it is reported, not interpreted as cognitive load on its own.
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np
import pandas as pd


@dataclass
class Fixation:
    start: float
    end: float
    x: float
    y: float

    @property
    def duration(self) -> float:
        return self.end - self.start


def valid_ratio(x: np.ndarray, y: np.ndarray) -> float:
    valid = np.isfinite(x) & np.isfinite(y)

    return float(valid.mean()) if len(valid) else 0.0


def detect_fixations(t: np.ndarray, x: np.ndarray, y: np.ndarray, velocity_threshold: float, min_duration_s: float = 0.06) -> list[Fixation]:
    """I-VT. ``velocity_threshold`` is in coordinate units per second."""
    if len(t) < 2:
        return []

    dt = np.diff(t)
    dt[dt <= 0] = np.nan
    velocity = np.hypot(np.diff(x), np.diff(y)) / dt
    slow = np.concatenate([[False], velocity < velocity_threshold])
    slow &= np.isfinite(x) & np.isfinite(y)

    fixations: list[Fixation] = []
    start = None

    for i, is_slow in enumerate(np.append(slow, False)):
        if is_slow and start is None:
            start = i
        elif not is_slow and start is not None:
            end = i - 1
            if t[end] - t[start] >= min_duration_s:
                fixations.append(Fixation(float(t[start]), float(t[end]), float(np.nanmean(x[start:end + 1])), float(np.nanmean(y[start:end + 1]))))
            start = None

    return fixations


def fixation_summary(fixations: list[Fixation]) -> dict[str, float]:
    durations = np.array([f.duration for f in fixations])

    return {
        "fixation_count": float(len(fixations)),
        "mean_fixation_ms": float(durations.mean() * 1000) if len(durations) else float("nan"),
    }


def aoi_metrics(fixations: list[Fixation], aois: dict[str, tuple[float, float, float, float]], start_time: float = 0.0) -> dict[str, float]:
    """Dwell time, fixation count, time to first fixation and transitions.

    ``aois`` maps a name to ``(left, top, width, height)`` in the gaze
    coordinate system, as stored in the kiosk's ``aoi_layout`` payload.
    """
    def hit(fix: Fixation) -> str | None:
        for name, (left, top, width, height) in aois.items():
            if left <= fix.x <= left + width and top <= fix.y <= top + height:
                return name
        return None

    sequence = [hit(f) for f in fixations]
    metrics: dict[str, float] = {}

    for name in aois:
        own = [f for f, label in zip(fixations, sequence) if label == name]
        metrics[f"{name}_dwell_s"] = float(sum(f.duration for f in own))
        metrics[f"{name}_fixations"] = float(len(own))
        metrics[f"{name}_ttff_s"] = float(own[0].start - start_time) if own else float("nan")

    labelled = [label for label in sequence if label is not None]
    metrics["aoi_transitions"] = float(sum(1 for a, b in zip(labelled, labelled[1:]) if a != b))

    return metrics


def clean_pupil(t: np.ndarray, pupil: np.ndarray, margin_s: float = 0.1) -> np.ndarray:
    """Drop samples around blinks (missing or zero) and interpolate linearly."""
    pupil = pupil.astype(float).copy()
    missing = ~np.isfinite(pupil) | (pupil <= 0)

    if missing.all():
        return pupil

    padded = missing.copy()
    for i in np.flatnonzero(missing):
        padded |= np.abs(t - t[i]) <= margin_s

    pupil[padded] = np.nan
    good = np.isfinite(pupil)
    pupil[~good] = np.interp(t[~good], t[good], pupil[good])

    return pupil


def baseline_correct(pupil: np.ndarray, baseline: np.ndarray) -> np.ndarray:
    """Subtractive baseline correction, recommended over divisive by Mathôt et al."""
    return pupil - np.nanmedian(baseline)


def downsample(frame: pd.DataFrame, source_hz: float, target_hz: float) -> pd.DataFrame:
    """Keep every n-th sample to emulate a slower tracker."""
    step = max(1, int(round(source_hz / target_hz)))

    return frame.iloc[::step].reset_index(drop=True)
