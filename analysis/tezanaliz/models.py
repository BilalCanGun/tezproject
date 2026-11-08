"""Participant-independent model comparison (Section 6.3 of the application).

* Train/test splits are made at participant level: no window or task record of
  a participant is ever in both the training and the test fold (Saeb et al., 2017).
* Hyper-parameters are tuned in an inner participant-level loop, performance is
  estimated in the outer loop (nested CV; Varma & Simon, 2006).
* Imputation, scaling and tuning happen inside the training fold only, because
  they are steps of one scikit-learn pipeline.
* Regularised linear models are the reference; Random Forest, XGBoost and SVR/SVC
  are the comparison models, and a dummy predictor shows the chance level.
"""

from __future__ import annotations

import warnings
from dataclasses import dataclass, field

import numpy as np
import pandas as pd
from sklearn.base import clone
from sklearn.dummy import DummyClassifier, DummyRegressor
from sklearn.ensemble import RandomForestClassifier, RandomForestRegressor
from sklearn.impute import SimpleImputer
from sklearn.linear_model import ElasticNet, LogisticRegression
from sklearn.metrics import (
    balanced_accuracy_score,
    f1_score,
    mean_absolute_error,
    r2_score,
    roc_auc_score,
    root_mean_squared_error,
)
from sklearn.model_selection import GridSearchCV, GroupKFold, KFold, LeaveOneGroupOut, StratifiedKFold
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import LabelEncoder, StandardScaler
from sklearn.svm import SVC, SVR

try:
    from xgboost import XGBClassifier, XGBRegressor
except ImportError:  # pragma: no cover - the venv installs xgboost
    XGBClassifier = XGBRegressor = None


@dataclass
class ModelSpec:
    name: str
    estimator: object
    grid: dict[str, list]


@dataclass
class ModelResult:
    model: str
    metrics: dict[str, float]
    fold_metrics: list[dict[str, float]] = field(default_factory=list)
    best_params: list[dict] = field(default_factory=list)


def regression_models(random_state: int = 42) -> list[ModelSpec]:
    specs = [
        ModelSpec("Dummy (ortalama)", DummyRegressor(), {}),
        ModelSpec("ElasticNet", ElasticNet(max_iter=20000), {"model__alpha": [0.01, 0.1, 1.0], "model__l1_ratio": [0.1, 0.5, 0.9]}),
        ModelSpec("Random Forest", RandomForestRegressor(n_estimators=200, random_state=random_state, n_jobs=1),
                  {"model__max_depth": [None, 6], "model__min_samples_leaf": [1, 5]}),
        ModelSpec("SVR", SVR(), {"model__C": [0.3, 1.0, 3.0], "model__gamma": ["scale"]}),
    ]
    if XGBRegressor is not None:
        specs.append(ModelSpec("XGBoost", XGBRegressor(n_estimators=300, learning_rate=0.05, subsample=0.8, random_state=random_state, n_jobs=1, verbosity=0),
                               {"model__max_depth": [2, 4], "model__reg_lambda": [1.0, 10.0]}))
    return specs


def classification_models(random_state: int = 42) -> list[ModelSpec]:
    specs = [
        ModelSpec("Dummy (çoğunluk)", DummyClassifier(strategy="prior"), {}),
        ModelSpec("Lojistik regresyon (L2)", LogisticRegression(max_iter=5000, class_weight="balanced"), {"model__C": [0.1, 1.0, 10.0]}),
        ModelSpec("Random Forest", RandomForestClassifier(n_estimators=200, random_state=random_state, n_jobs=1, class_weight="balanced"),
                  {"model__max_depth": [None, 6], "model__min_samples_leaf": [1, 5]}),
        ModelSpec("SVC (RBF)", SVC(class_weight="balanced"), {"model__C": [0.3, 1.0, 3.0]}),
    ]
    if XGBClassifier is not None:
        specs.append(ModelSpec("XGBoost", XGBClassifier(n_estimators=300, learning_rate=0.05, subsample=0.8, random_state=random_state, n_jobs=1, verbosity=0),
                               {"model__max_depth": [2, 4], "model__reg_lambda": [1.0, 10.0]}))
    return specs


def _pipeline(estimator: object) -> Pipeline:
    return Pipeline([("impute", SimpleImputer(strategy="median")), ("scale", StandardScaler()), ("model", clone(estimator))])


def _outer_splitter(groups: np.ndarray, max_splits: int):
    n_groups = len(np.unique(groups))
    return LeaveOneGroupOut() if n_groups <= max_splits else GroupKFold(n_splits=max_splits)


def _scores(task: str, y_true: np.ndarray, y_pred: np.ndarray, y_score: np.ndarray | None) -> dict[str, float]:
    if task == "regression":
        return {
            "mae": float(mean_absolute_error(y_true, y_pred)),
            "rmse": float(root_mean_squared_error(y_true, y_pred)),
            "r2": float(r2_score(y_true, y_pred)) if len(y_true) > 1 else float("nan"),
        }

    scores = {
        "balanced_accuracy": float(balanced_accuracy_score(y_true, y_pred)),
        "f1_macro": float(f1_score(y_true, y_pred, average="macro")),
    }
    if y_score is not None and len(np.unique(y_true)) == 2:
        scores["roc_auc"] = float(roc_auc_score(y_true, y_score))
    return scores


def _decision(model: Pipeline, X: pd.DataFrame) -> np.ndarray | None:
    if hasattr(model, "predict_proba"):
        try:
            proba = model.predict_proba(X)
            return proba[:, 1] if proba.shape[1] == 2 else None
        except AttributeError:
            pass
    if hasattr(model, "decision_function"):
        values = model.decision_function(X)
        return values if values.ndim == 1 else None
    return None


def nested_cv(
    X: pd.DataFrame,
    y: np.ndarray,
    groups: np.ndarray,
    task: str = "regression",
    models: list[ModelSpec] | None = None,
    outer_splits: int = 10,
    inner_splits: int = 3,
    random_state: int = 42,
) -> list[ModelResult]:
    """Nested participant-level cross-validation for every model.

    Metrics are computed on the pooled out-of-fold predictions; per-fold values
    are kept for the spread. With ten or fewer participants the outer loop is
    leave-one-participant-out.
    """
    models = models or (regression_models(random_state) if task == "regression" else classification_models(random_state))
    y = np.asarray(y)
    if task == "classification":
        y = LabelEncoder().fit_transform(y)
    groups = np.asarray(groups)
    outer = _outer_splitter(groups, outer_splits)
    results = []

    for spec in models:
        predictions = np.empty(len(y), dtype=float if task == "regression" else y.dtype)
        scores = np.full(len(y), np.nan)
        fold_metrics, best_params = [], []

        for train, test in outer.split(X, y, groups):
            pipe = _pipeline(spec.estimator)
            n_inner = min(inner_splits, len(np.unique(groups[train])))

            if spec.grid and n_inner >= 2:
                search = GridSearchCV(pipe, spec.grid, cv=GroupKFold(n_splits=n_inner),
                                      scoring="neg_mean_absolute_error" if task == "regression" else "balanced_accuracy", n_jobs=-1)
                with warnings.catch_warnings():
                    warnings.simplefilter("ignore")
                    search.fit(X.iloc[train], y[train], groups=groups[train])
                fitted = search.best_estimator_
                best_params.append(search.best_params_)
            else:
                fitted = pipe.fit(X.iloc[train], y[train])

            predictions[test] = fitted.predict(X.iloc[test])
            decision = _decision(fitted, X.iloc[test]) if task == "classification" else None
            if decision is not None:
                scores[test] = decision
            with warnings.catch_warnings():
                warnings.simplefilter("ignore")
                fold_metrics.append(_scores(task, y[test], predictions[test], decision if decision is not None and len(np.unique(y[test])) == 2 else None))

        pooled_score = scores if task == "classification" and np.isfinite(scores).all() else None
        results.append(ModelResult(spec.name, _scores(task, y, predictions, pooled_score), fold_metrics, best_params))

    return results


def record_wise_cv(X: pd.DataFrame, y: np.ndarray, task: str, spec: ModelSpec, splits: int = 5, random_state: int = 42) -> dict[str, float]:
    """The leaky evaluation the application warns against, kept only to show the gap."""
    y = np.asarray(y)
    if task == "classification":
        y = LabelEncoder().fit_transform(y)
    splitter = KFold(splits, shuffle=True, random_state=random_state) if task == "regression" else StratifiedKFold(splits, shuffle=True, random_state=random_state)
    predictions = np.empty(len(y), dtype=float if task == "regression" else y.dtype)

    for train, test in splitter.split(X, y):
        predictions[test] = _pipeline(spec.estimator).fit(X.iloc[train], y[train]).predict(X.iloc[test])

    return _scores(task, y, predictions, None)


def incremental_value(
    frame: pd.DataFrame,
    target: str,
    group: str,
    feature_sets: dict[str, list[str]],
    task: str = "regression",
    models: list[ModelSpec] | None = None,
    **kwargs,
) -> pd.DataFrame:
    """Runs every model on cumulative feature sets (baseline → +behaviour → +sensors).

    The first entry of ``feature_sets`` is the reference; the table reports each
    later set's change against it, which answers AS5 directly.
    """
    rows = []
    for set_name, columns in feature_sets.items():
        for result in nested_cv(frame[columns], frame[target].to_numpy(), frame[group].to_numpy(), task=task, models=models, **kwargs):
            rows.append({"feature_set": set_name, "model": result.model, "n_features": len(columns), **result.metrics})

    table = pd.DataFrame(rows)
    key = "mae" if task == "regression" else "balanced_accuracy"
    reference = table[table.feature_set == next(iter(feature_sets))].set_index("model")[key]
    table[f"delta_{key}"] = table.apply(lambda row: row[key] - reference.get(row["model"], np.nan), axis=1)

    return table
