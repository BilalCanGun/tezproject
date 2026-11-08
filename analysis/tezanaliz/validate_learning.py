"""Learning-context model comparison on the Confused Student EEG data.

Ten students watched ten short MOOC videos with a single-channel (Fp1)
consumer headset and reported whether they were confused. The device does not
meet any EEG requirement of the application, so this data is used only for
the modelling question the thesis shares with it: can a learning-time signal
predict a learner's later self-report for a participant the model has never seen?
The model comparison is run at video level (one row per participant x video),
which mirrors the thesis' small-n, one-row-per-unit setting.
"""

from __future__ import annotations

import numpy as np
import pandas as pd

from . import datasets, models

BANDS = ["Delta", "Theta", "Alpha1", "Alpha2", "Beta1", "Beta2", "Gamma1", "Gamma2"]


def video_table() -> pd.DataFrame:
    frame = datasets.load_confused()
    frame = frame.rename(columns={"SubjectID": "subject", "VideoID": "video", "user-definedlabeln": "confused"})
    for band in BANDS:
        frame[band] = np.log10(frame[band].clip(lower=1))

    grouped = frame.groupby(["subject", "video"])
    table = grouped[BANDS + ["Attention", "Mediation"]].mean()
    table["theta_alpha"] = table["Theta"] - (table["Alpha1"] + table["Alpha2"]) / 2
    table["confused"] = grouped["confused"].first()
    table["predefined"] = grouped["predefinedlabel"].first()

    return table.reset_index()


def run(model_outer_splits: int = 10) -> dict:
    table = video_table()
    feature_sets = {
        "Video zorluğu (önceden tanımlı etiket)": ["predefined"],
        "+ EEG bant güçleri": ["predefined", *BANDS, "theta_alpha"],
        "+ Üretici dikkat/meditasyon skorları": ["predefined", *BANDS, "theta_alpha", "Attention", "Mediation"],
    }
    results = models.incremental_value(table, "confused", "subject", feature_sets, task="classification", outer_splits=model_outer_splits)
    eeg_only = models.nested_cv(table[BANDS + ["theta_alpha"]], table["confused"].to_numpy(), table["subject"].to_numpy(),
                                task="classification", outer_splits=model_outer_splits)
    leaky = models.record_wise_cv(table[BANDS + ["theta_alpha"]], table["confused"].to_numpy(), "classification",
                                  models.ModelSpec("Random Forest", models.RandomForestClassifier(n_estimators=300, random_state=0), {}))

    common = {
        "dataset": datasets.DATASETS["confused"].title,
        "task": "classification",
        "target": "öğrencinin bildirdiği kafa karışıklığı (video başına)",
        "n_participants": int(table.subject.nunique()),
        "n_samples": int(len(table)),
        "evaluation": "İç içe katılımcı bazlı ÇD (dışta her seferinde bir öğrenci dışarıda, içte 3 katlı GroupKFold)",
        "primary_metric": "balanced_accuracy",
    }

    return {
        "devices": [],
        "participants": int(table.subject.nunique()),
        "models": [
            {
                **common,
                "key": "learning_confusion_eeg",
                "title": "Öğrenme sırasında kafa karışıklığı: yalnızca EEG özellikleri",
                "results": [{"model": r.model, "metrics": r.metrics} for r in eeg_only],
                "record_wise": {"model": "Random Forest", "metrics": leaky},
            },
            {
                **common,
                "key": "learning_confusion_incremental",
                "title": "Öğrenme sırasında kafa karışıklığı: görev bilgisine EEG'nin ek katkısı",
                "results": [
                    {"feature_set": row.feature_set, "model": row.model,
                     "metrics": {k: float(row[k]) for k in ("balanced_accuracy", "f1_macro", "roc_auc", "delta_balanced_accuracy") if k in row and pd.notna(row[k])}}
                    for _, row in results.iterrows()
                ],
                "note": ("Tezdeki AS5 sorusunun küçük ölçekli karşılığı: daha kolay elde edilen bilgiye (burada video zorluğu) sensör verisi ek bilgi katıyor mu? "
                         "Yalnızca video zorluğuyla kurulan modelin şansın altında kalması, her öğrencide etiketlerin dengeli olduğu küçük örneklemde "
                         "birini dışarıda bırakma doğrulamasının bilinen bir yan etkisidir; Dummy satırı da bu yüzden %50'nin altındadır."),
            },
        ],
    }
