"""What the eye tracker has to deliver, tested on VREED (60 Hz, gaze in degrees).

VREED records at 60 Hz and has no pupil channel. The 120 Hz requirement in the
application therefore cannot be confirmed from this data. Instead, every
measure is computed at the native 60 Hz and again after decimation to 30 Hz.
This shows which measures stay stable when the rate drops and which degrade.
Participant-independent models predict arousal and valence from per-video
gaze features.
"""

from __future__ import annotations

import numpy as np
import pandas as pd
from scipy import stats

from . import datasets, gaze, models
from .config import EYE_MIN_SAMPLING_HZ, QUALITY, DeviceSpec

NATIVE_HZ = 60.0
VELOCITY_DEG_S = 30.0


def load_participant(path) -> tuple[list[np.ndarray], list[int]]:
    with open(path, "rb") as handle:
        content = datasets._NumpyOnlyUnpickler(handle, encoding="latin1").load()
    labels = [int(v) for v in np.asarray(content["Labels"]).ravel()]
    return [np.asarray(x, dtype=float) for x in content["Data"]], labels


def video_features(recording: np.ndarray, step: int = 1) -> dict[str, float]:
    """Gaze measures for one 360° video. Uses the right eye, falling back to the left."""
    x = recording[:, ::step]
    t = (x[0] - x[0, 0]) / 1000.0
    blink = (x[3] > 0) | (x[6] > 0)
    horizontal = np.where(np.isfinite(x[4]), x[4], x[1])
    vertical = np.where(np.isfinite(x[5]), x[5], x[2])
    horizontal = np.degrees(np.unwrap(np.radians(horizontal)))
    horizontal[blink] = np.nan
    vertical[blink] = np.nan

    fixations = gaze.detect_fixations(t, horizontal, vertical, VELOCITY_DEG_S, min_duration_s=0.1)
    summary = gaze.fixation_summary(fixations)
    minutes = max(t[-1] / 60.0, 1e-6)
    blink_onsets = np.sum(np.diff(blink.astype(int)) == 1)
    amplitudes = [np.hypot(b.x - a.x, b.y - a.y) for a, b in zip(fixations, fixations[1:])]

    return {
        "valid_ratio": gaze.valid_ratio(horizontal, vertical),
        "fixations_per_min": summary["fixation_count"] / minutes,
        "mean_fixation_ms": summary["mean_fixation_ms"],
        "fixation_time_ratio": sum(f.duration for f in fixations) / max(t[-1], 1e-6),
        "saccade_amp_deg": float(np.mean(amplitudes)) if amplitudes else np.nan,
        "blinks_per_min": blink_onsets / minutes,
        "gaze_sd_h": float(np.degrees(stats.circstd(np.radians(horizontal[np.isfinite(horizontal)])))) if np.isfinite(horizontal).any() else np.nan,
        "gaze_sd_v": float(np.nanstd(vertical)),
    }


def build_table(rates: tuple[float, ...] = (60.0, 30.0)) -> pd.DataFrame:
    rows = []
    for path in datasets.vreed_files():
        participant = path.name.split("_")[0]
        recordings, labels = load_participant(path)
        for video, (recording, label) in enumerate(zip(recordings, labels)):
            for rate in rates:
                step = int(round(NATIVE_HZ / rate))
                rows.append({"participant": participant, "video": video, "quadrant": label, "rate": rate, **video_features(recording, step)})
    return pd.DataFrame(rows)


MEASURES = {
    "fixations_per_min": "Fiksasyon sayısı (/dk)",
    "mean_fixation_ms": "Ortalama fiksasyon süresi",
    "saccade_amp_deg": "Sakkad genliği",
    "blinks_per_min": "Göz kırpma hızı",
}


def run(devices: list[DeviceSpec], model_outer_splits: int = 10) -> dict:
    table = build_table()
    native = table[table.rate == NATIVE_HZ].set_index(["participant", "video"])

    def agreement(rate: float) -> dict[str, float]:
        other = table[table.rate == rate].set_index(["participant", "video"])
        result = {}
        for measure in MEASURES:
            joined = pd.concat([native[measure], other[measure]], axis=1, keys=["a", "b"]).dropna()
            result[measure] = float(np.corrcoef(joined.a, joined.b)[0, 1]) if len(joined) > 3 else float("nan")
            result[f"{measure}_bias"] = float((joined.b - joined.a).mean() / joined.a.abs().mean()) if len(joined) else float("nan")
        return result

    cards = []
    for device in [d for d in devices if d.modality == "eye_tracker"]:
        spec_rate = device.sampling_rate_hz or NATIVE_HZ
        rate = 30.0 if spec_rate <= 30 else NATIVE_HZ
        subset = table[table.rate == rate]
        valid = float(subset.valid_ratio.mean())
        valid_participants = subset.groupby("participant").valid_ratio.mean()
        checks = [
            _check("Örnekleme hızı (cihaz)", f"≥{EYE_MIN_SAMPLING_HZ} Hz (form)", f"{spec_rate:g} Hz", "ok" if spec_rate >= EYE_MIN_SAMPLING_HZ else "fail"),
            _check("Pupil çapı", "Ham pupil (Mathôt vd., 2018)", "Cihazda var; veri setinde yok, test edilemedi" if "pupil" in device.signals else "Yok",
                   "no_data" if "pupil" in device.signals else "fail"),
            _check("Geçerli örnek oranı", f"≥%{QUALITY['min_gaze_valid_ratio'] * 100:.0f}", f"%{valid * 100:.1f} (veri seti, {rate:g} Hz)",
                   "ok" if valid >= QUALITY["min_gaze_valid_ratio"] else "fail"),
            _check("Eşiği geçen katılımcı", "Katılımcı ortalaması eşiğin üzerinde", f"{int((valid_participants >= QUALITY['min_gaze_valid_ratio']).sum())}/{len(valid_participants)}",
                   "ok" if (valid_participants >= QUALITY["min_gaze_valid_ratio"]).mean() >= 0.9 else "warn"),
        ]
        if rate < NATIVE_HZ:
            agree = agreement(rate)
            for measure, label in MEASURES.items():
                r, bias = agree[measure], agree[f"{measure}_bias"]
                checks.append(_check(f"{label}: 60 Hz ile uyum", "r ≥ 0,90 ve sapma ≤ %10",
                                     f"r = {r:.2f}, sapma %{bias * 100:+.0f}",
                                     "ok" if r >= 0.9 and abs(bias) <= 0.1 else "warn" if r >= 0.7 else "fail"))
        summary = (f"VREED 60 Hz kaydedildiği için {spec_rate:g} Hz cihaz {rate:g} Hz veriyle değerlendirildi."
                   + (" 120 Hz ve üzerindeki kazanç bu veri setiyle doğrulanamaz; satın alınacak cihazla pilotta doğrulanmalıdır." if spec_rate > NATIVE_HZ else ""))
        cards.append({"device": device.name, "modality": "eye_tracker", "source": device.source,
                      "dataset": datasets.DATASETS["vreed"].title, "checks": checks, "summary": summary})

    experiments = []
    features = ["fixations_per_min", "mean_fixation_ms", "fixation_time_ratio", "saccade_amp_deg", "blinks_per_min", "gaze_sd_h", "gaze_sd_v"]
    for target, positive, title in (("arousal", {0, 3}, "Yüksek / düşük uyarılma"), ("valence", {0, 1}, "Olumlu / olumsuz değerlik")):
        for rate in (NATIVE_HZ, 30.0):
            subset = table[table.rate == rate].copy()
            subset["y"] = subset.quadrant.isin(positive).map({True: "yüksek", False: "düşük"})
            results = models.nested_cv(subset[features], subset["y"].to_numpy(), subset["participant"].to_numpy(),
                                       task="classification", outer_splits=model_outer_splits)
            leaky = models.record_wise_cv(subset[features], subset["y"].to_numpy(), "classification",
                                          models.ModelSpec("Random Forest", models.RandomForestClassifier(n_estimators=300, random_state=0), {}))
            experiments.append({
                "key": f"gaze_{target}_{int(rate)}",
                "title": f"Göz izleme ile {title.lower()} sınıflandırması ({rate:g} Hz)",
                "dataset": datasets.DATASETS["vreed"].title,
                "task": "classification",
                "target": title,
                "n_participants": int(subset.participant.nunique()),
                "n_samples": int(len(subset)),
                "n_features": len(features),
                "evaluation": f"İç içe katılımcı bazlı ÇD (dış {model_outer_splits} katlı GroupKFold, iç 3 katlı)",
                "primary_metric": "balanced_accuracy",
                "results": [{"model": r.model, "metrics": r.metrics} for r in results],
                "record_wise": {"model": "Random Forest", "metrics": leaky},
            })

    return {"devices": cards, "models": experiments, "participants": int(table.participant.nunique())}


def _check(label: str, requirement: str, value: str, status: str) -> dict:
    return {"label": label, "requirement": requirement, "value": value, "status": status}
