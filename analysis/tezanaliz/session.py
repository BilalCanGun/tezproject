"""Per-participant sensor features and quality metrics from a real session 1.

Inputs for one participant:

* the LabRecorder XDF file (EEG stream of type ``EEG``, gaze stream of type
  ``Gaze``/``ET``/``EyeTracking``); all streams share the LSL clock,
* the panel's ``events`` export, whose ``lsl_time`` column is on the same LSL
  clock and whose ``aoi_layout`` payloads hold the interface rectangles,
* optionally the wrist-sensor export as CSV with a ``unix_time`` column (s)
  and ``eda``/``bvp``/``temp`` columns (one file per signal is fine), aligned by
  the events' ``client_epoch_ms`` (UTC) because the device has no LSL stream.

Windows follow the application: EEG only in the silent-planning windows
(Section "EEG Uyumlu Görev Fazları"), referenced to the eyes-open rest; gaze
and autonomic signals at task/block level. Output columns use the ``eeg_``,
``gaze_`` and ``auto_`` prefixes that ``study.feature_sets`` expects.
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field

import numpy as np
import pandas as pd

from . import eeg, gaze, wearable
from .config import ALPHA, THETA


@dataclass
class Stream:
    data: np.ndarray            # samples x channels
    times: np.ndarray           # LSL seconds
    labels: list[str]
    fs: float


@dataclass
class SessionData:
    events: pd.DataFrame
    eeg: Stream | None = None
    gaze: Stream | None = None
    wearable: dict[str, tuple[np.ndarray, np.ndarray]] = field(default_factory=dict)  # name -> (unix_s, values)


# ---------------------------------------------------------------- loading

def load_xdf(path) -> dict[str, Stream]:
    import pyxdf

    streams, _ = pyxdf.load_xdf(str(path))
    found: dict[str, Stream] = {}

    for stream in streams:
        info = stream["info"]
        kind = (info["type"][0] or "").lower()
        key = "eeg" if kind == "eeg" else "gaze" if kind in ("gaze", "et", "eyetracking", "eye_tracking") else None
        if key is None or key in found:
            continue

        labels = []
        try:
            for channel in info["desc"][0]["channels"][0]["channel"]:
                labels.append(channel["label"][0])
        except (KeyError, IndexError, TypeError):
            labels = [f"ch{i}" for i in range(int(info["channel_count"][0]))]

        times = np.asarray(stream["time_stamps"], dtype=float)
        nominal = float(info["nominal_srate"][0])
        fs = nominal if nominal > 0 else (1 / np.median(np.diff(times)) if len(times) > 1 else 0.0)
        found[key] = Stream(np.asarray(stream["time_series"], dtype=float), times, labels, fs)

    return found


def load_events(path, participant_code: str, session_number: int = 1) -> pd.DataFrame:
    frame = pd.read_csv(path)
    frame = frame[(frame.participant_code.astype(str) == str(participant_code)) & (frame.session_number == session_number)].copy()
    return frame.sort_values("sequence").reset_index(drop=True)


def load_wearable_csv(paths) -> dict[str, tuple[np.ndarray, np.ndarray]]:
    signals = {}
    for path in paths:
        frame = pd.read_csv(path)
        for column in ("eda", "bvp", "temp"):
            if column in frame:
                signals[column] = (frame["unix_time"].to_numpy(float), frame[column].to_numpy(float))
    return signals


# ---------------------------------------------------------------- windows

def windows(events: pd.DataFrame, start_type: str, end_types: tuple[str, ...], clock: str = "lsl_time") -> list[tuple[float, float]]:
    """Pairs each start event with the next end event on the given clock."""
    result = []
    open_start = None
    for _, row in events.iterrows():
        value = row.get(clock)
        if pd.isna(value):
            continue
        value = float(value) / (1000.0 if clock == "client_epoch_ms" else 1.0)
        if row.type == start_type:
            open_start = value
        elif row.type in end_types and open_start is not None:
            if value > open_start:
                result.append((open_start, value))
            open_start = None
    return result


def _slice(stream: Stream, spans: list[tuple[float, float]]) -> list[np.ndarray]:
    return [stream.data[(stream.times >= a) & (stream.times < b)] for a, b in spans]


# ---------------------------------------------------------------- EEG

def eeg_features(stream: Stream, events: pd.DataFrame) -> dict[str, float | None]:
    planning = windows(events, "planning_start", ("planning_end",))
    rest = windows(events, "eyes_open_start", ("eyes_open_end",))
    labels = stream.labels

    def concat(spans):
        parts = [p for p in _slice(stream, spans) if len(p) >= stream.fs * 2]
        return np.concatenate(parts).T if parts else None

    task, base = concat(planning), concat(rest)
    result: dict[str, float | None] = {"eeg_planning_seconds": float(sum(b - a for a, b in planning))}

    if task is None:
        return result | {"eeg_usable_epoch_ratio": None}

    quality = eeg.assess_quality(task, stream.fs, labels)
    roi = eeg.roi_features(task, stream.fs, labels)
    result |= {"eeg_usable_epoch_ratio": quality.usable_epoch_ratio, "eeg_fm_theta": roi["fm_theta"], "eeg_po_alpha": roi["po_alpha"]}

    if base is not None:
        base_roi = eeg.roi_features(base, stream.fs, labels)
        if roi["po_alpha"] is not None and base_roi["po_alpha"] is not None:
            result["eeg_alpha_erd"] = eeg.erd_percent(10 ** roi["po_alpha"], 10 ** base_roi["po_alpha"])
        if roi["fm_theta"] is not None and base_roi["fm_theta"] is not None:
            result["eeg_theta_ers"] = eeg.erd_percent(10 ** roi["fm_theta"], 10 ** base_roi["fm_theta"])

    return result


# ---------------------------------------------------------------- gaze

def aoi_rectangles(events: pd.DataFrame) -> dict[str, tuple[float, float, float, float]]:
    """AOIs of the last coding-phase layout in screen CSS pixels."""
    layouts = events[events.type == "aoi_layout"]
    if layouts.empty:
        return {}

    payload = json.loads(layouts.iloc[-1].payload_json)
    window = payload.get("window", {})
    if payload.get("fullscreen"):
        offset_x = offset_y = 0.0
    else:
        offset_x = window.get("screen_x", 0) + (window.get("outer_w", 0) - window.get("inner_w", 0)) / 2
        offset_y = window.get("screen_y", 0) + (window.get("outer_h", 0) - window.get("inner_h", 0))

    return {name: (r["x"] + offset_x, r["y"] + offset_y, r["w"], r["h"]) for name, r in payload.get("regions", {}).items()}


def gaze_xy_pupil(stream: Stream, screen_w: float, screen_h: float) -> tuple[np.ndarray, np.ndarray, np.ndarray | None]:
    """Gaze in screen CSS pixels; normalised (0-1) coordinates are scaled."""
    lower = [label.lower() for label in stream.labels]

    def column(*names):
        for name in names:
            if name in lower:
                return stream.data[:, lower.index(name)]
        return None

    x = column("x", "gaze_x", "screen_x", "ch0")
    y = column("y", "gaze_y", "screen_y", "ch1")
    pupil = column("pupil", "pupil_diameter", "pupil_l", "pupil_r")

    if np.nanmax(np.abs(np.concatenate([x, y]))) <= 1.5:
        x, y = x * screen_w, y * screen_h

    return x, y, pupil


def gaze_features(stream: Stream, events: pd.DataFrame, velocity_px_s: float = 1500.0) -> dict[str, float | None]:
    layouts = events[events.type == "aoi_layout"]
    screen = json.loads(layouts.iloc[-1].payload_json).get("screen", {}) if not layouts.empty else {}
    x, y, pupil = gaze_xy_pupil(stream, screen.get("w", 1920), screen.get("h", 1080))

    tasks = windows(events, "task_start", ("task_submit", "task_timeout", "task_give_up"))
    mask = np.zeros(len(stream.times), dtype=bool)
    for a, b in tasks:
        mask |= (stream.times >= a) & (stream.times < b)

    if not mask.any():
        return {"gaze_valid_ratio": None}

    t, gx, gy = stream.times[mask], x[mask], y[mask]
    fixations = gaze.detect_fixations(t, gx, gy, velocity_px_s)
    total_fix = sum(f.duration for f in fixations) or np.nan
    minutes = sum(b - a for a, b in tasks) / 60
    result: dict[str, float | None] = {
        "gaze_valid_ratio": gaze.valid_ratio(gx, gy),
        "gaze_mean_fixation_ms": gaze.fixation_summary(fixations)["mean_fixation_ms"],
    }

    aois = aoi_rectangles(events)
    if aois:
        metrics = gaze.aoi_metrics(fixations, aois, start_time=float(t[0]))
        for name in aois:
            result[f"gaze_{name}_dwell_share"] = metrics[f"{name}_dwell_s"] / total_fix if total_fix else None
        result["gaze_transitions_per_min"] = metrics["aoi_transitions"] / minutes if minutes else None

    if pupil is not None:
        rest = windows(events, "eyes_open_start", ("eyes_open_end",))
        base_mask = np.zeros(len(stream.times), dtype=bool)
        for a, b in rest:
            base_mask |= (stream.times >= a) & (stream.times < b)
        cleaned = gaze.clean_pupil(stream.times, pupil)
        if base_mask.any():
            result["gaze_pupil_change"] = float(np.nanmean(gaze.baseline_correct(cleaned[mask], cleaned[base_mask])))

    return result


# ---------------------------------------------------------------- wrist sensor

def autonomic_features(signals: dict[str, tuple[np.ndarray, np.ndarray]], events: pd.DataFrame) -> dict[str, float | None]:
    tasks = windows(events, "task_start", ("task_submit", "task_timeout", "task_give_up"), clock="client_epoch_ms")
    rest = windows(events, "eyes_open_start", ("eyes_open_end",), clock="client_epoch_ms")
    if not tasks:
        return {}

    start, end = tasks[0][0], tasks[-1][1]
    result: dict[str, float | None] = {}

    def block(name, spans):
        times, values = signals[name]
        keep = np.zeros(len(times), dtype=bool)
        for a, b in spans:
            keep |= (times >= a) & (times < b)
        return values[keep]

    def rate(name):
        times, _ = signals[name]
        return 1 / np.median(np.diff(times)) if len(times) > 1 else 0.0

    if "eda" in signals:
        fs = rate("eda")
        times = signals["eda"][0]
        result["auto_loss_ratio"] = wearable.loss_ratio(times, fs, start, end)
        task_eda = block("eda", [(start, end)])
        if len(task_eda) > 30:
            features = wearable.eda_features(task_eda, fs)
            result["auto_scr_per_min"] = features["scr_per_min"]
            base_eda = block("eda", rest)
            if len(base_eda):
                result["auto_eda_tonic_change"] = features["eda_tonic_mean"] - float(np.median(base_eda))

    if "bvp" in signals:
        fs = rate("bvp")
        task_hr = wearable.hr_hrv(wearable.bvp_beats(block("bvp", [(start, end)]), fs))
        result["auto_hr"] = task_hr["hr_bpm"]
        result["auto_rmssd"] = task_hr["rmssd_ms"]
        base_bvp = block("bvp", rest)
        if len(base_bvp) > fs * 30:
            result["auto_hr_change"] = task_hr["hr_bpm"] - wearable.hr_hrv(wearable.bvp_beats(base_bvp, fs), min_beats=20)["hr_bpm"]

    if "temp" in signals:
        values = block("temp", [(start, end)])
        if len(values) > 2:
            result["auto_temp_mean"] = float(np.mean(values))

    return result


def extract(session: SessionData) -> dict[str, float | None]:
    features: dict[str, float | None] = {}
    if session.eeg is not None:
        features |= eeg_features(session.eeg, session.events)
    if session.gaze is not None:
        features |= gaze_features(session.gaze, session.events)
    if session.wearable:
        features |= autonomic_features(session.wearable, session.events)
    return features


QUALITY_COLUMNS = {"eeg_usable_epoch_ratio": "eeg_usable_epoch_ratio", "gaze_valid_ratio": "gaze_valid_ratio", "auto_loss_ratio": "wearable_loss_ratio"}
