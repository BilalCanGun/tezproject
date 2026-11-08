import numpy as np
import pandas as pd
import pytest

from tezanaliz import eeg, gaze, models, wearable

RNG = np.random.default_rng(0)


def synthetic_eeg(fs: float, seconds: float, labels: list[str], theta_uv: float = 0.0, alpha_uv: float = 0.0) -> np.ndarray:
    t = np.arange(int(fs * seconds)) / fs
    data = RNG.normal(0, 5, (len(labels), len(t)))
    for i, label in enumerate(labels):
        if label in eeg.frontal_midline_channels(labels):
            data[i] += theta_uv * np.sin(2 * np.pi * 6 * t)
        if label in eeg.parieto_occipital_channels(labels):
            data[i] += alpha_uv * np.sin(2 * np.pi * 10 * t)
    return data


LABELS = ["Fz", "Cz", "C3", "C4", "Pz", "PO7", "Oz", "PO8"]


def test_region_selection_follows_the_montage():
    assert eeg.frontal_midline_channels(LABELS) == ["Fz", "Cz"]
    assert eeg.parieto_occipital_channels(LABELS) == ["Pz", "PO7", "Oz", "PO8"]
    assert eeg.frontal_midline_channels(["AF3", "F7", "F3", "O1"]) == []


def test_theta_and_alpha_increase_with_injected_rhythms():
    fs = 250
    rest = eeg.roi_features(synthetic_eeg(fs, 60, LABELS), fs, LABELS)
    active = eeg.roi_features(synthetic_eeg(fs, 60, LABELS, theta_uv=10, alpha_uv=10), fs, LABELS)

    assert active["fm_theta"] > rest["fm_theta"] + 0.3
    assert active["po_alpha"] > rest["po_alpha"] + 0.3


def test_missing_midline_coverage_is_reported_as_none():
    labels = ["AF3", "F7", "F3", "P7", "O1"]
    features = eeg.roi_features(synthetic_eeg(128, 30, labels), 128, labels)

    assert features["fm_theta"] is None
    assert features["po_alpha"] is not None


def test_artefacts_lower_the_usable_epoch_ratio():
    fs = 250
    clean = synthetic_eeg(fs, 60, LABELS)
    noisy = clean.copy()
    noisy[:, int(fs * 10):int(fs * 30)] += 400 * np.sign(RNG.normal(size=(len(LABELS), int(fs * 20))))

    assert eeg.assess_quality(clean, fs, LABELS).usable_epoch_ratio > 0.95
    assert eeg.assess_quality(noisy, fs, LABELS).usable_epoch_ratio < 0.70


def test_flat_channel_is_marked_bad():
    data = synthetic_eeg(250, 20, LABELS)
    data[2] = 0.0

    assert eeg.assess_quality(data, 250, LABELS).bad_channels == ["C3"]


def test_resampling_keeps_alpha_power():
    fs = 500
    data = synthetic_eeg(fs, 60, LABELS, alpha_uv=10)
    original = eeg.roi_features(data, fs, LABELS)["po_alpha"]
    emulated = eeg.roi_features(eeg.resample(data, fs, 128), 128, LABELS)["po_alpha"]

    assert emulated == pytest.approx(original, abs=0.1)


def test_erd_is_negative_when_power_drops():
    assert eeg.erd_percent(5.0, 10.0) == pytest.approx(-50.0)


def test_heart_rate_from_bvp_matches_the_generator():
    fs = 64
    t = np.arange(fs * 120) / fs
    bvp = np.sin(2 * np.pi * 1.2 * t) + RNG.normal(0, 0.05, len(t))

    result = wearable.hr_hrv(wearable.bvp_beats(bvp, fs))

    assert result["hr_bpm"] == pytest.approx(72, abs=1.5)
    assert result["rmssd_ms"] < 40


def test_scr_peaks_are_counted():
    fs = 4
    t = np.arange(fs * 120) / fs
    eda = 2 + 0.002 * t
    for onset in (20, 50, 80, 110):
        rise = t >= onset
        eda = eda + rise * 0.3 * np.exp(-(t - onset) / 4) * (1 - np.exp(-(t - onset) / 0.75))

    features = wearable.eda_features(eda, fs)

    assert features["scr_per_min"] == pytest.approx(2.0, abs=0.6)


def test_loss_ratio_counts_missing_samples():
    fs = 4
    timestamps = np.arange(0, 100, 1 / fs)
    timestamps = timestamps[(timestamps < 40) | (timestamps >= 50)]

    assert wearable.loss_ratio(timestamps, fs, 0, 100) == pytest.approx(0.10, abs=0.01)


def test_sync_gesture_taps_are_found():
    fs = 64
    acc = RNG.normal(0, 0.02, (fs * 30, 3)) + [0, 0, 1]
    for tap in (5.0, 6.0, 7.0):
        acc[int(tap * fs), 2] += 3

    assert np.allclose(wearable.sync_gesture_times(acc, fs), [5, 6, 7], atol=0.05)


def test_fixations_and_aoi_dwell():
    fs = 120
    t = np.arange(fs * 2) / fs
    x = np.where(t < 1, 100.0, 600.0) + RNG.normal(0, 1, len(t))
    y = np.full(len(t), 100.0)

    fixations = gaze.detect_fixations(t, x, y, velocity_threshold=1000)
    metrics = gaze.aoi_metrics(fixations, {"problem": (0, 0, 300, 300), "editor": (400, 0, 400, 300)})

    assert len(fixations) == 2
    assert metrics["problem_dwell_s"] == pytest.approx(1.0, abs=0.05)
    assert metrics["aoi_transitions"] == 1


def test_pupil_blinks_are_interpolated():
    t = np.arange(0, 2, 0.01)
    pupil = np.full(len(t), 4.0)
    pupil[80:90] = 0.0

    cleaned = gaze.clean_pupil(t, pupil)

    assert np.all(cleaned > 3.9)


def test_nested_cv_never_mixes_participants_and_beats_dummy():
    rows = []
    for participant in range(12):
        offset = RNG.normal()
        for _ in range(20):
            x = RNG.normal(size=3)
            rows.append({"pid": participant, "a": x[0], "b": x[1], "c": x[2], "y": 2 * x[0] + offset + RNG.normal(0, 0.3)})
    frame = pd.DataFrame(rows)

    results = {r.model: r for r in models.nested_cv(frame[["a", "b", "c"]], frame.y.to_numpy(), frame.pid.to_numpy(), outer_splits=6)}

    assert results["ElasticNet"].metrics["mae"] < results["Dummy (ortalama)"].metrics["mae"]
    assert len(results["ElasticNet"].fold_metrics) == 6


def test_participant_identity_leaks_only_in_record_wise_cv():
    rows = []
    for participant in range(10):
        label = participant % 2
        fingerprint = RNG.normal(size=4) * 3
        for _ in range(30):
            rows.append({"pid": participant, **{f"f{i}": fingerprint[i] + RNG.normal(0, 0.2) for i in range(4)}, "y": label})
    frame = pd.DataFrame(rows)
    features = frame[[f"f{i}" for i in range(4)]]
    spec = models.ModelSpec("RF", models.RandomForestClassifier(n_estimators=100, random_state=0), {})

    leaky = models.record_wise_cv(features, frame.y.to_numpy(), "classification", spec)
    honest = models.nested_cv(features, frame.y.to_numpy(), frame.pid.to_numpy(), task="classification", models=[spec])[0]

    assert leaky["balanced_accuracy"] > 0.95
    assert honest.metrics["balanced_accuracy"] < 0.8
