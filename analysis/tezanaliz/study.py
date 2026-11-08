"""Section 6.1 and 6.3 analyses on the thesis' own data.

Inputs are the ``outcomes`` CSV exported from the admin panel (one row per
participant) and, optionally, a per-participant sensor-feature CSV produced
from the session-1 recordings (columns prefixed ``eeg_``, ``gaze_``, ``auto_``
and keyed by ``participant_code``).

* Primary analysis: ANCOVA of the delayed, unaided far-transfer score on
  condition with the baseline score as covariate, partial eta squared and
  Holm-corrected planned pairwise contrasts.
* Prediction: cumulative feature sets (baseline → +behaviour/AI interaction →
  +EEG, +gaze, +autonomic, +all sensors), evaluated with nested
  participant-level CV, reporting the change against the baseline model (AS5).
"""

from __future__ import annotations

import itertools

import numpy as np
import pandas as pd
import statsmodels.formula.api as smf
from statsmodels.stats.anova import anova_lm
from statsmodels.stats.multitest import multipletests

from . import models

PRIMARY = "s2_far_transfer_pct"
BASELINE_FEATURES = ["baseline_pct", "programming_experience_months"]
BEHAVIOUR_FEATURES = [
    "s1_learning_pct", "s1_near_transfer_pct", "s1_far_transfer_pct", "s1_debugging_pct",
    "s1_help_requests", "s1_resource_views", "s1_self_written_ratio", "s1_ai_pasted_chars",
]
CONDITIONS = ["standard_resource", "socratic_ai", "direct_ai"]


def load(outcomes_csv, sensors_csv=None) -> pd.DataFrame:
    frame = pd.read_csv(outcomes_csv)
    frame = frame[(frame["included_primary"] == 1) & frame[PRIMARY].notna()].copy()
    if sensors_csv is not None:
        frame = frame.merge(pd.read_csv(sensors_csv), on="participant_code", how="left")
    for condition in CONDITIONS[1:]:
        frame[f"cond_{condition}"] = (frame["condition"] == condition).astype(int)
    return frame


def ancova(frame: pd.DataFrame) -> dict:
    data = frame.dropna(subset=[PRIMARY, "baseline_pct", "condition"]).copy()
    data["condition"] = pd.Categorical(data["condition"], categories=CONDITIONS)
    fit = smf.ols(f"{PRIMARY} ~ C(condition) + baseline_pct", data=data).fit()
    table = anova_lm(fit, typ=2)
    ss_condition, ss_error = table.loc["C(condition)", "sum_sq"], table.loc["Residual", "sum_sq"]

    contrasts = []
    for a, b in itertools.combinations(CONDITIONS, 2):
        refit = smf.ols(f"{PRIMARY} ~ C(condition, Treatment('{a}')) + baseline_pct", data=data).fit()
        term = f"C(condition, Treatment('{a}'))[T.{b}]"
        low, high = refit.conf_int().loc[term]
        contrasts.append({"contrast": f"{b} − {a}", "estimate": float(refit.params[term]), "ci_low": float(low), "ci_high": float(high), "p": float(refit.pvalues[term])})

    adjusted = multipletests([c["p"] for c in contrasts], method="holm")[1]
    for contrast, p in zip(contrasts, adjusted):
        contrast["p_holm"] = float(p)

    return {
        "n": int(len(data)),
        "f": float(table.loc["C(condition)", "F"]),
        "p": float(table.loc["C(condition)", "PR(>F)"]),
        "partial_eta_sq": float(ss_condition / (ss_condition + ss_error)),
        "adjusted_means": {c: float(fit.predict(pd.DataFrame({"condition": [c], "baseline_pct": [data["baseline_pct"].mean()]}))[0]) for c in CONDITIONS},
        "contrasts": contrasts,
    }


def feature_sets(frame: pd.DataFrame) -> dict[str, list[str]]:
    base = [c for c in BASELINE_FEATURES if c in frame]
    behaviour = base + [c for c in BEHAVIOUR_FEATURES if c in frame] + [f"cond_{c}" for c in CONDITIONS[1:]]
    sets = {"Başlangıç": base, "+ Davranış ve YZ etkileşimi": behaviour}
    for prefix, label in (("eeg_", "EEG"), ("gaze_", "Göz izleme"), ("auto_", "Otonom")):
        columns = [c for c in frame if c.startswith(prefix)]
        if columns:
            sets[f"+ {label}"] = behaviour + columns
    sensor = [c for c in frame if c.startswith(("eeg_", "gaze_", "auto_"))]
    if sensor:
        sets["+ Tüm sensörler"] = behaviour + sensor
    return sets


def prediction(frame: pd.DataFrame, outer_splits: int = 10) -> pd.DataFrame:
    """Nested participant-level CV; with one row per person the outer loop is k-fold over people."""
    return models.incremental_value(frame, PRIMARY, "participant_code", feature_sets(frame), task="regression", outer_splits=outer_splits)
