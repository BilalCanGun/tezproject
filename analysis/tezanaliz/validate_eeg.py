"""What each EEG device has to deliver, tested by emulating it on public data.

The 19-channel, 500 Hz mental-arithmetic recordings are reduced to each
candidate's montage and sampling rate. For every device the pipeline reports
whether the pre-registered measures exist on that montage, the usable-epoch
ratio, whether the expected workload effects (frontal theta up, alpha down)
are detected, how well the device's measure agrees with the full-montage
reference, and how well participant-independent models separate rest from
task. STEW adds a real Emotiv EPOC recording for the 128 Hz saline class.
"""

from __future__ import annotations

import numpy as np
import pandas as pd
from scipy import stats

from . import datasets, eeg, models
from .config import ALPHA, EEG_MIN_SAMPLING_HZ, QUALITY, THETA, DeviceSpec

BETA = (13.0, 30.0)
WINDOW_S = 4.0
REST_TAIL_S = 60.0


def emulate(data: np.ndarray, fs: float, labels: list[str], device: DeviceSpec | None) -> tuple[np.ndarray, float, list[str]]:
    """Keeps the device's electrodes that exist in the recording and resamples."""
    if device is None:
        return data, fs, labels

    keep = [label for label in device.channel_labels if label in labels]
    reduced = data[[labels.index(label) for label in keep]]
    target = min(fs, device.sampling_rate_hz or fs)

    return eeg.resample(reduced, fs, target), target, keep


def window_features(data: np.ndarray, fs: float, labels: list[str]) -> pd.DataFrame:
    """Log theta, alpha and beta power per channel for non-overlapping windows."""
    filtered = eeg.preprocess(data, fs)
    epochs = eeg.make_epochs(filtered, fs, WINDOW_S, WINDOW_S)
    clean = eeg.reject_epochs(epochs)
    rows = []

    for band_name, band in (("theta", THETA), ("alpha", ALPHA), ("beta", BETA)):
        power = np.log10(eeg.band_power(epochs, fs, band) + 1e-12)
        rows.append(pd.DataFrame(power, columns=[f"{label}_{band_name}" for label in labels]))

    frame = pd.concat(rows, axis=1)
    frame["clean"] = clean

    return frame


def _effect(rest: list[float | None], task: list[float | None]) -> dict[str, float | None]:
    pairs = [(r, t) for r, t in zip(rest, task) if r is not None and t is not None]
    if len(pairs) < 3:
        return {"dz": None, "p": None, "n": len(pairs)}

    diff = np.array([t - r for r, t in pairs])
    test = stats.ttest_rel([t for _, t in pairs], [r for r, _ in pairs])

    return {"dz": float(diff.mean() / diff.std(ddof=1)), "p": float(test.pvalue), "n": len(pairs), "diff": diff}


def run_arithmetic(devices: list[DeviceSpec], model_outer_splits: int = 6) -> dict:
    recordings = datasets.arithmetic_recordings()
    targets: list[tuple[str, DeviceSpec | None]] = [("Referans: 19 kanal, 500 Hz", None)] + [(d.name, d) for d in devices]
    per_device: dict[str, dict] = {name: {"rows": [], "windows": []} for name, _ in targets}

    for subject, rest_file, task_file in recordings:
        rest, fs, labels = datasets.load_arithmetic_subject(rest_file)
        task, _, _ = datasets.load_arithmetic_subject(task_file)
        rest = rest[:, -int(REST_TAIL_S * fs):]

        for name, device in targets:
            rest_d, fs_d, labels_d = emulate(rest, fs, labels, device)
            task_d, _, _ = emulate(task, fs, labels, device)
            if not labels_d:
                continue

            rest_roi = eeg.roi_features(rest_d, fs_d, labels_d)
            task_roi = eeg.roi_features(task_d, fs_d, labels_d)
            quality = [eeg.assess_quality(x, fs_d, labels_d).usable_epoch_ratio for x in (rest_d, task_d)]
            per_device[name]["rows"].append({"subject": subject, "rest": rest_roi, "task": task_roi, "usable": float(np.mean(quality))})

            for condition, x in (("rest", rest_d), ("task", task_d)):
                windows = window_features(x, fs_d, labels_d)
                windows["subject"] = subject
                windows["y"] = condition
                per_device[name]["windows"].append(windows)

    reference = per_device[targets[0][0]]["rows"]
    ref_theta_change = {r["subject"]: r["task"]["fm_theta"] - r["rest"]["fm_theta"] for r in reference if r["task"]["fm_theta"] is not None}
    ref_alpha_change = {r["subject"]: r["task"]["po_alpha"] - r["rest"]["po_alpha"] for r in reference if r["task"]["po_alpha"] is not None}

    cards, experiments = [], []
    for name, device in targets:
        rows = per_device[name]["rows"]
        if not rows:
            continue
        cards.append(_device_card(name, device, rows, ref_theta_change, ref_alpha_change))

        windows = pd.concat(per_device[name]["windows"], ignore_index=True)
        windows = windows[windows["clean"]].drop(columns="clean")
        features = [c for c in windows.columns if c not in ("subject", "y")]
        results = models.nested_cv(windows[features], windows["y"].to_numpy(), windows["subject"].to_numpy(),
                                   task="classification", outer_splits=model_outer_splits)
        experiments.append({
            "key": f"eeg_workload_{_slug(name)}",
            "title": f"İş yükü (dinlenim / aritmetik) sınıflandırması: {name}",
            "dataset": datasets.DATASETS["eeg_arithmetic"].title,
            "task": "classification",
            "target": "dinlenim / zihinsel aritmetik",
            "n_participants": int(windows["subject"].nunique()),
            "n_samples": int(len(windows)),
            "n_features": len(features),
            "evaluation": f"İç içe katılımcı bazlı ÇD (dış {model_outer_splits} katlı GroupKFold, iç 3 katlı)",
            "primary_metric": "balanced_accuracy",
            "results": [{"model": r.model, "metrics": r.metrics} for r in results],
        })

    return {"devices": cards, "models": experiments, "participants": len(recordings)}


def _device_card(name: str, device: DeviceSpec | None, rows: list[dict], ref_theta: dict, ref_alpha: dict) -> dict:
    fs = device.sampling_rate_hz if device else 500
    labels = list(device.channel_labels) if device else []
    usable = float(np.mean([r["usable"] for r in rows]))
    theta = _effect([r["rest"]["fm_theta"] for r in rows], [r["task"]["fm_theta"] for r in rows])
    alpha = _effect([r["rest"]["po_alpha"] for r in rows], [r["task"]["po_alpha"] for r in rows])
    has_theta = rows[0]["rest"]["fm_theta"] is not None
    has_alpha = rows[0]["rest"]["po_alpha"] is not None

    def agreement(effect: dict, reference: dict) -> float | None:
        if effect.get("diff") is None:
            return None
        subjects = [r["subject"] for r in rows if r["rest"]["fm_theta" if reference is ref_theta else "po_alpha"] is not None]
        ref = [reference[s] for s in subjects if s in reference]
        if len(ref) != len(effect["diff"]) or len(ref) < 3:
            return None
        return float(np.corrcoef(effect["diff"], ref)[0, 1])

    theta_r, alpha_r = agreement(theta, ref_theta), agreement(alpha, ref_alpha)
    alpha_erd = float(np.mean([eeg.erd_percent(10 ** r["task"]["po_alpha"], 10 ** r["rest"]["po_alpha"]) for r in rows])) if has_alpha else None
    midline = eeg.frontal_midline_channels(labels) if device else ["Fz", "Cz"]
    po = eeg.parieto_occipital_channels(labels) if device else ["P7", "P3", "Pz", "P4", "P8", "O1", "O2"]

    checks = [
        _check("Örnekleme hızı", f"≥{EEG_MIN_SAMPLING_HZ} Hz (form)", f"{fs:g} Hz", "ok" if fs >= EEG_MIN_SAMPLING_HZ else "warn"),
        _check("Frontal orta hat theta", "Fz/FCz/Cz kapsamı", ", ".join(midline) or "Yok — ölçülemez", "ok" if midline else "fail"),
        _check("Paryetal-oksipital alpha", "≥2 P/PO/O kanalı", ", ".join(po) or "Yok", "ok" if len(po) >= 2 else ("warn" if po else "fail")),
        _check("Kullanılabilir epok oranı", f"≥%{QUALITY['min_eeg_usable_epoch_ratio'] * 100:.0f}", f"%{usable * 100:.1f}",
               "ok" if usable >= QUALITY["min_eeg_usable_epoch_ratio"] else "fail"),
        _check("İş yükünde frontal theta artışı", "Görev > dinlenim (eşleştirilmiş t)", _fmt_effect(theta) if has_theta else "Ölçülemez",
               _effect_status(theta, positive=True) if has_theta else "fail"),
        _check("Alpha ERD (görev vs dinlenim)", "Görevde alpha düşüşü", f"%{alpha_erd:.1f}; {_fmt_effect(alpha)}" if has_alpha else "Ölçülemez",
               _effect_status(alpha, positive=False) if has_alpha else "fail"),
    ]
    if device is not None:
        checks.append(_check("Referansla uyum (theta değişimi)", "r ≥ 0,80 iyi; ≥ 0,50 sınırlı", f"r = {theta_r:.2f}" if theta_r is not None else "—",
                             "no_data" if theta_r is None else ("ok" if theta_r >= 0.8 else "warn" if theta_r >= 0.5 else "fail")))
        checks.append(_check("Referansla uyum (alpha değişimi)", "r ≥ 0,80 iyi; ≥ 0,50 sınırlı", f"r = {alpha_r:.2f}" if alpha_r is not None else "—",
                             "no_data" if alpha_r is None else ("ok" if alpha_r >= 0.8 else "warn" if alpha_r >= 0.5 else "fail")))

    missing_in_data = [l for l in (device.channel_labels if device else []) if l not in _dataset_labels()] if device else []
    summary = None
    if device is not None:
        summary = (f"Veri setinde bulunan {len(device.channel_labels) - len(missing_in_data)}/{len(device.channel_labels)} elektrot kullanıldı"
                   + (f" (veri setinde olmayan: {', '.join(missing_in_data)})." if missing_in_data else "."))

    return {"device": name, "modality": "eeg", "source": device.source if device else "referans",
            "dataset": datasets.DATASETS["eeg_arithmetic"].title, "checks": checks, "summary": summary}


def _dataset_labels() -> list[str]:
    return ["Fp1", "Fp2", "F7", "F3", "Fz", "F4", "F8", "T7", "C3", "Cz", "C4", "T8", "P7", "P3", "Pz", "P4", "P8", "O1", "O2"]


def run_stew() -> dict:
    """Real Emotiv EPOC recordings: data quality and prediction of self-rated workload.

    One recording per person, so this is the thesis' setting in miniature: a
    continuous per-participant outcome, few participants, many candidate features.
    """
    labels = datasets.STEW_CHANNELS
    fs = 128.0
    rows, usable = [], []

    for subject, rating, workload, data in datasets.load_stew():
        usable.append(eeg.assess_quality(data, fs, labels).usable_epoch_ratio)
        windows = window_features(data, fs, labels)
        clean = windows[windows["clean"]].drop(columns="clean")
        source = clean if len(clean) else windows.drop(columns="clean")
        rows.append({"subject": subject, "rating": rating, "workload": workload, **source.mean().to_dict()})

    frame = pd.DataFrame(rows)
    features = [c for c in frame.columns if c not in ("subject", "rating", "workload")]
    frontal = [c for c in features if c.split("_")[0] in ("F3", "F4", "AF3", "AF4") and c.endswith("theta")] +               [c for c in features if c.split("_")[0] in ("O1", "O2", "P7", "P8") and c.endswith("alpha")]
    ratio = float(np.mean(usable))

    card = {
        "device": "Emotiv EPOC (gerçek kayıt, 14 kanal, 128 Hz)",
        "modality": "eeg",
        "source": "gerçek cihaz verisi",
        "dataset": datasets.DATASETS["stew"].title,
        "checks": [
            _check("Örnekleme hızı", f"≥{EEG_MIN_SAMPLING_HZ} Hz (form)", "128 Hz", "warn"),
            _check("Frontal orta hat theta", "Fz/FCz/Cz kapsamı", "Yok — ölçülemez (yalnızca F3/F4 vekil)", "fail"),
            _check("Kullanılabilir epok oranı (ham kayıt)", f"≥%{QUALITY['min_eeg_usable_epoch_ratio'] * 100:.0f}", f"%{ratio * 100:.1f}",
                   "ok" if ratio >= QUALITY["min_eeg_usable_epoch_ratio"] else "fail"),
            _check("Eşiği geçen kayıt", "Kayıt bazında ≥%70", f"{sum(u >= QUALITY['min_eeg_usable_epoch_ratio'] for u in usable)}/{len(usable)}",
                   "ok" if np.mean([u >= QUALITY["min_eeg_usable_epoch_ratio"] for u in usable]) >= 0.9 else "warn"),
        ],
        "summary": "Önceden temizlenmemiş tuzlu su elektrotlu kayıt; artefakt reddinden sonra kalan veri, sahada beklenecek kalitenin gerçekçi göstergesidir.",
    }

    n = len(frame)
    common = {"dataset": datasets.DATASETS["stew"].title, "n_participants": n, "n_samples": n,
              "evaluation": "İç içe katılımcı bazlı ÇD (dışta 10 katlı GroupKFold, içte 3 katlı); katılımcı başına tek satır"}
    regression = models.incremental_value(frame, "rating", "subject",
                                          {"Önceden tanımlı ROI (4 özellik)": frontal, "Tüm kanal × bant (42 özellik)": features},
                                          task="regression", outer_splits=10)
    classification = models.nested_cv(frame[frontal], frame["workload"].to_numpy(), frame["subject"].to_numpy(), task="classification", outer_splits=10)

    experiments = [
        {**common, "key": "eeg_rating_stew", "title": "Öznel iş yükü puanı (1–9) regresyonu: Emotiv EPOC gerçek kayıt",
         "task": "regression", "target": "SIMKAP sonrası öznel iş yükü puanı", "primary_metric": "mae",
         "results": [{"feature_set": row.feature_set, "model": row.model,
                      "metrics": {k: float(row[k]) for k in ("mae", "rmse", "r2", "delta_mae")}} for _, row in regression.iterrows()],
         "note": "Tezin birincil hesaplamalı hedefi gibi sürekli, katılımcı başına tek değer. Önceden sınırlandırılmış özellik seti ile tüm özellikler karşılaştırılır."},
        {**common, "key": "eeg_class_stew", "title": "İş yükü sınıfı (düşük / orta / yüksek): önceden tanımlı ROI özellikleri",
         "task": "classification", "target": "düşük / orta / yüksek", "primary_metric": "balanced_accuracy",
         "results": [{"model": r.model, "metrics": r.metrics} for r in classification]},
    ]

    return {"devices": [card], "models": experiments, "participants": n}


def _check(label: str, requirement: str, value: str, status: str) -> dict:
    return {"label": label, "requirement": requirement, "value": value, "status": status}


def _fmt_effect(effect: dict) -> str:
    if effect["dz"] is None:
        return "—"
    return f"dz = {effect['dz']:.2f}, p = {effect['p']:.3g}, n = {effect['n']}"


def _effect_status(effect: dict, positive: bool) -> str:
    if effect["dz"] is None:
        return "no_data"
    right_direction = effect["dz"] > 0 if positive else effect["dz"] < 0
    if right_direction and effect["p"] < 0.05:
        return "ok"
    return "warn" if right_direction else "fail"


def _slug(text: str) -> str:
    return "".join(c.lower() if c.isalnum() else "_" for c in text).strip("_")
