"""What the wrist sensor has to deliver, tested on WESAD (Empatica E4 + chest ECG).

Each wearable profile is emulated by resampling the E4 signals to the device's
native rates. For every 60 s window the heart rate and RMSSD from wrist BVP
are compared with the chest-ECG reference. This checks the application's
rule that HRV is only computed for windows of adequate length and quality.
The expected autonomic stress response (EDA, HR) is tested within
participants. Participant-independent models classify stress, adding
modalities step by step (ACC+TEMP → +EDA → +BVP).
"""

from __future__ import annotations

import numpy as np
import pandas as pd
from scipy import stats

from . import datasets, models, wearable
from .config import DATA_ROOT, QUALITY, DeviceSpec

RATES = {"bvp": 64.0, "eda": 4.0, "acc": 32.0, "temp": 4.0, "ecg": 350.0, "label": 4.0}
WINDOW_S = 60
STEP_S = 30
LABELS = {1: "baseline", 2: "stress", 3: "amusement"}


def _windows(labels: np.ndarray) -> list[tuple[int, int, int]]:
    """``(label, start_s, end_s)`` windows fully inside one labelled segment."""
    result = []
    change = np.flatnonzero(np.diff(labels)) + 1
    bounds = np.concatenate([[0], change, [len(labels)]])
    for a, b in zip(bounds[:-1], bounds[1:]):
        label = int(labels[a])
        if label not in LABELS:
            continue
        start, end = a / RATES["label"], b / RATES["label"]
        t = start
        while t + WINDOW_S <= end:
            result.append((label, int(t), int(t + WINDOW_S)))
            t += STEP_S
    return result


def _segment(x: np.ndarray, rate: float, start: int, end: int) -> np.ndarray:
    return x[int(start * rate):int(end * rate)]


def subject_windows(subject: str, device: DeviceSpec) -> pd.DataFrame:
    data = np.load(DATA_ROOT / "wesad" / f"{subject}.npz")
    rates = device.signal_rates_hz
    signals = {}
    for name in ("bvp", "eda", "acc", "temp"):
        if name in device.signals and name in rates:
            target = min(rates[name], RATES[name])
            signals[name] = (wearable.resample_to(np.asarray(data[name], dtype=float), RATES[name], target), target)

    ecg = np.asarray(data["ecg"], dtype=float)
    rows = []
    for label, start, end in _windows(np.asarray(data["label_4hz"])):
        row = {"subject": subject, "label": LABELS[label], "start": start}
        reference = wearable.hr_hrv(wearable.ecg_beats(_segment(ecg, RATES["ecg"], start, end), RATES["ecg"]))
        row["ref_hr"], row["ref_rmssd"] = reference["hr_bpm"], reference["rmssd_ms"]

        if "bvp" in signals:
            x, rate = signals["bvp"]
            estimate = wearable.hr_hrv(wearable.bvp_beats(_segment(x, rate, start, end), rate))
            row["hr_bpm"], row["rmssd_ms"] = estimate["hr_bpm"], estimate["rmssd_ms"]
        if "eda" in signals:
            x, rate = signals["eda"]
            row.update(wearable.eda_features(_segment(x, rate, start, end), rate))
        if "temp" in signals:
            x, rate = signals["temp"]
            segment = _segment(x, rate, start, end)
            row["temp_mean"] = float(segment.mean())
            row["temp_slope"] = float(np.polyfit(np.arange(len(segment)) / rate, segment, 1)[0]) if len(segment) > 2 else np.nan
        if "acc" in signals:
            x, rate = signals["acc"]
            row["motion"] = wearable.motion_level(_segment(x, rate, start, end))
        rows.append(row)

    return pd.DataFrame(rows)


def available_subjects() -> list[str]:
    return [s for s in datasets.WESAD_SUBJECTS if (DATA_ROOT / "wesad" / f"{s}.npz").exists()]


def run(devices: list[DeviceSpec], model_outer_splits: int = 15) -> dict:
    subjects = available_subjects()
    cards, experiments = [], []

    for device in [d for d in devices if d.modality == "wearable"]:
        frame = pd.concat([subject_windows(s, device) for s in subjects], ignore_index=True)
        cards.append(_device_card(device, frame, len(subjects)))

        if device.source == "karşılaştırma":
            continue

        feature_sets = {
            "ACC + TEMP": ["motion", "temp_mean", "temp_slope"],
            "+ EDA": ["motion", "temp_mean", "temp_slope", "eda_tonic_mean", "eda_phasic_std", "scr_per_min", "scr_mean_amp"],
            "+ BVP (HR, RMSSD)": ["motion", "temp_mean", "temp_slope", "eda_tonic_mean", "eda_phasic_std", "scr_per_min", "scr_mean_amp", "hr_bpm", "rmssd_ms"],
        }
        frame["y"] = np.where(frame.label == "stress", "stres", "stres değil")

        for normalised in (False, True):
            data = _baseline_normalise(frame, feature_sets["+ BVP (HR, RMSSD)"]) if normalised else frame
            table = models.incremental_value(data, "y", "subject", feature_sets, task="classification", outer_splits=model_outer_splits)
            leaky = models.record_wise_cv(data[feature_sets["+ BVP (HR, RMSSD)"]], data["y"].to_numpy(), "classification",
                                          models.ModelSpec("Random Forest", models.RandomForestClassifier(n_estimators=300, random_state=0), {}))
            suffix = " (katılımcının dinlenim referansına göre normalize)" if normalised else " (ham özellikler)"
            experiments.append({
                "key": f"wearable_stress_{'norm' if normalised else 'raw'}",
                "title": f"Bileklik ile stres sınıflandırması, modalite ekleme{suffix}: {device.name}",
                "dataset": datasets.DATASETS["wesad"].title,
                "task": "classification",
                "target": "stres / stres değil (dinlenim + eğlence)",
                "n_participants": int(data.subject.nunique()),
                "n_samples": int(len(data)),
                "evaluation": "İç içe katılımcı bazlı ÇD (dışta her seferinde bir katılımcı dışarıda, içte 3 katlı GroupKFold)",
                "primary_metric": "balanced_accuracy",
                "results": [
                    {"feature_set": row.feature_set, "model": row.model,
                     "metrics": {k: float(row[k]) for k in ("balanced_accuracy", "f1_macro", "roc_auc", "delta_balanced_accuracy") if k in row and pd.notna(row[k])}}
                    for _, row in table.iterrows()
                ],
                "record_wise": {"model": "Random Forest", "metrics": leaky},
                "note": ("Normalizasyonda her katılımcının dinlenim bölümünün ilk yarısı referans alınır ve bu pencereler sınıflandırmadan çıkarılır; "
                         "tezdeki oturum başı dinlenim kaydının karşılığıdır.") if normalised else None,
            })

    return {"devices": cards, "models": experiments, "participants": len(subjects)}


def _baseline_normalise(frame: pd.DataFrame, columns: list[str]) -> pd.DataFrame:
    parts = []
    for _, group in frame.groupby("subject"):
        baseline = group[group.label == "baseline"].sort_values("start")
        reference = baseline.iloc[: max(1, len(baseline) // 2)]
        rest = group.drop(reference.index)
        rest = rest.copy()
        rest[columns] = rest[columns] - reference[columns].mean()
        parts.append(rest)
    return pd.concat(parts, ignore_index=True)


def _device_card(device: DeviceSpec, frame: pd.DataFrame, n_subjects: int) -> dict:
    required = ["eda", "bvp", "acc", "temp"]
    missing = [s for s in required if s not in device.signals]
    rates = ", ".join(f"{k.upper()} {min(v, RATES[k]):g} Hz" + (f" (cihaz {v:g} Hz; veri seti sınırı)" if v > RATES[k] else "")
                      for k, v in device.signal_rates_hz.items())
    checks = [
        _check("Sinyaller", "EDA, BVP/PPG, ivme, cilt sıcaklığı (form 3.3)", (", ".join(s.upper() for s in device.signals) + (f" — eksik: {', '.join(m.upper() for m in missing)}" if missing else "")),
               "fail" if missing else "ok"),
        _check("Örnekleme (öykünme)", "Üretici değerleri", rates or "—", "ok" if rates else "warn"),
    ]

    if "hr_bpm" in frame:
        valid = frame.dropna(subset=["hr_bpm", "ref_hr"])
        mae = float((valid.hr_bpm - valid.ref_hr).abs().mean())
        r = float(np.corrcoef(valid.hr_bpm, valid.ref_hr)[0, 1])
        checks.append(_check("Kalp hızı: göğüs EKG'sine göre", "MAE ≤ 5 atım/dk", f"MAE = {mae:.1f} atım/dk, r = {r:.2f}, {len(valid)} pencere",
                             "ok" if mae <= 5 else "warn" if mae <= 10 else "fail"))

        rmssd = frame.dropna(subset=["rmssd_ms", "ref_rmssd"])
        coverage = len(rmssd) / max(1, len(frame))
        if len(rmssd) > 3:
            mae_rmssd = float((rmssd.rmssd_ms - rmssd.ref_rmssd).abs().mean())
            r_rmssd = float(np.corrcoef(rmssd.rmssd_ms, rmssd.ref_rmssd)[0, 1])
            calm = rmssd[rmssd.motion <= rmssd.motion.median()] if "motion" in rmssd else rmssd
            r_calm = float(np.corrcoef(calm.rmssd_ms, calm.ref_rmssd)[0, 1]) if len(calm) > 3 else float("nan")
            checks.append(_check("RMSSD: göğüs EKG'sine göre", "r ≥ 0,70 (yalnızca yeterli kalitedeki pencereler)",
                                 f"MAE = {mae_rmssd:.0f} ms, r = {r_rmssd:.2f}; düşük hareketli yarıda r = {r_calm:.2f}",
                                 "ok" if r_rmssd >= 0.7 else "warn" if r_rmssd >= 0.4 else "fail"))
        checks.append(_check("RMSSD hesaplanabilen pencere", "Kalite kuralını geçen 60 sn pencere oranı", f"%{coverage * 100:.0f}",
                             "ok" if coverage >= 0.8 else "warn" if coverage >= 0.5 else "fail"))
    else:
        checks.append(_check("Kalp hızı / RMSSD", "BVP gerekli", "BVP yok", "fail"))

    for column, label, positive in (("scr_per_min", "Stresle deri iletkenlik yanıtı artışı", True),
                                    ("eda_tonic_mean", "Stresle tonik EDA artışı", True),
                                    ("hr_bpm", "Stresle kalp hızı artışı (bileklik)", True)):
        if column not in frame:
            checks.append(_check(label, "Kişi içi stres > dinlenim", "Sinyal yok", "fail"))
            continue
        means = frame[frame.label.isin(["baseline", "stress"])].groupby(["subject", "label"])[column].mean().unstack().dropna()
        diff = means["stress"] - means["baseline"]
        test = stats.ttest_rel(means["stress"], means["baseline"])
        dz = float(diff.mean() / diff.std(ddof=1)) if len(diff) > 2 else float("nan")
        checks.append(_check(label, "Kişi içi stres > dinlenim (eşleştirilmiş t)", f"dz = {dz:.2f}, p = {test.pvalue:.3g}, n = {len(diff)}",
                             "ok" if dz > 0 and test.pvalue < 0.05 else ("warn" if dz > 0 else "fail")))

    checks.append(_check("Veri kaybı", f"≤%{QUALITY['max_wearable_loss_ratio'] * 100:.0f}", "%0 (WESAD kayıtları eksiksiz; oturum içi kayıp pilotta ölçülmeli)", "no_data"))
    checks.append(_check("Senkronizasyon", "UTC + bilek vuruşu (sync_gesture)", "Canlı LSL yok; blok düzeyi analiz" if device.sync_method == "utc_posthoc" else device.sync_method, "warn"))

    return {"device": device.name, "modality": "wearable", "source": device.source,
            "dataset": datasets.DATASETS["wesad"].title + f" ({n_subjects} katılımcı)", "checks": checks,
            "summary": "Referans kalp hızı ve RMSSD, aynı anda kaydedilen göğüs EKG'sinin R tepelerinden hesaplandı."}


def _check(label: str, requirement: str, value: str, status: str) -> dict:
    return {"label": label, "requirement": requirement, "value": value, "status": status}
