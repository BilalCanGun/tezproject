import json

import numpy as np
import pandas as pd
import pytest

from tezanaliz import session

RNG = np.random.default_rng(2)
LSL0 = 1000.0
UTC0 = 1_790_000_000.0
LABELS = ["Fz", "Cz", "Pz", "PO7", "Oz", "PO8"]


def events() -> pd.DataFrame:
    layout = {"screen": {"w": 1000, "h": 800}, "fullscreen": True,
              "regions": {"problem": {"x": 0, "y": 0, "w": 400, "h": 800}, "editor": {"x": 500, "y": 0, "w": 500, "h": 800}}}
    rows = [
        ("eyes_open_start", 0, None), ("eyes_open_end", 60, None),
        ("task_start", 100, None), ("aoi_layout", 101, json.dumps(layout)),
        ("planning_start", 110, None), ("planning_end", 170, None),
        ("task_submit", 400, None),
    ]
    return pd.DataFrame([
        {"sequence": i, "type": kind, "lsl_time": LSL0 + t, "client_epoch_ms": (UTC0 + t) * 1000, "payload_json": payload}
        for i, (kind, t, payload) in enumerate(rows)
    ])


def eeg_stream(fs: float = 250) -> session.Stream:
    t = np.arange(0, 420, 1 / fs)
    data = RNG.normal(0, 5, (len(t), len(LABELS)))
    resting = t < 60
    for i, label in enumerate(LABELS):
        if label in ("Pz", "PO7", "Oz", "PO8"):
            data[:, i] += np.where(resting, 15, 5) * np.sin(2 * np.pi * 10 * t)
    return session.Stream(data, LSL0 + t, LABELS, fs)


def gaze_stream(fs: float = 120) -> session.Stream:
    t = np.arange(0, 420, 1 / fs)
    on_problem = ((t - 100) % 20) < 10
    x = np.where(on_problem, 0.2, 0.75) + RNG.normal(0, 0.001, len(t))
    y = np.full(len(t), 0.5)
    pupil = np.where(t < 60, 3.0, 3.4)
    x[(t > 200) & (t < 201)] = np.nan
    return session.Stream(np.column_stack([x, y, pupil]), LSL0 + t, ["x", "y", "pupil"], fs)


def wearable_signals():
    t_eda = np.arange(0, 420, 0.25)
    eda = np.where(t_eda < 60, 1.0, 1.6) + 0.001 * t_eda
    keep = ~((t_eda > 300) & (t_eda < 330))
    t_bvp = np.arange(0, 420, 1 / 64)
    bvp = np.sin(2 * np.pi * np.where(t_bvp < 60, 1.0, 1.25) * t_bvp)
    return {"eda": (UTC0 + t_eda[keep], eda[keep]), "bvp": (UTC0 + t_bvp, bvp)}


def test_windows_pair_start_and_end_events():
    assert session.windows(events(), "planning_start", ("planning_end",)) == [(LSL0 + 110, LSL0 + 170)]
    assert session.windows(events(), "task_start", ("task_submit",), clock="client_epoch_ms") == [(UTC0 + 100, UTC0 + 400)]


def test_eeg_features_use_planning_windows_against_rest():
    features = session.eeg_features(eeg_stream(), events())

    assert features["eeg_planning_seconds"] == 60
    assert features["eeg_usable_epoch_ratio"] > 0.9
    assert features["eeg_alpha_erd"] < -50


def test_gaze_features_map_aois_and_pupil():
    features = session.gaze_features(gaze_stream(), events())

    assert features["gaze_valid_ratio"] > 0.99
    assert features["gaze_problem_dwell_share"] == pytest.approx(0.5, abs=0.05)
    assert features["gaze_transitions_per_min"] == pytest.approx(5.8, abs=0.5)
    assert features["gaze_pupil_change"] == pytest.approx(0.4, abs=0.01)


def test_autonomic_features_are_aligned_by_utc():
    features = session.autonomic_features(wearable_signals(), events())

    assert features["auto_loss_ratio"] == pytest.approx(0.10, abs=0.01)
    assert features["auto_eda_tonic_change"] > 0.5
    assert features["auto_hr"] == pytest.approx(75, abs=2)
    assert features["auto_hr_change"] == pytest.approx(15, abs=3)


def test_extract_combines_all_modalities_with_study_prefixes():
    features = session.extract(session.SessionData(events(), eeg_stream(), gaze_stream(), wearable_signals()))

    assert {key.split("_")[0] for key in features} == {"eeg", "gaze", "auto"}
