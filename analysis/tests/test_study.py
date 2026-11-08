import numpy as np
import pandas as pd

from tezanaliz import study

RNG = np.random.default_rng(1)


def synthetic_outcomes(n_per_condition: int = 25) -> pd.DataFrame:
    rows = []
    effects = {"standard_resource": 0.0, "socratic_ai": 8.0, "direct_ai": -8.0}
    for condition, effect in effects.items():
        for i in range(n_per_condition):
            baseline = RNG.uniform(20, 80)
            rows.append({
                "participant_code": f"{condition[:3]}{i:03d}",
                "condition": condition,
                "baseline_pct": baseline,
                "programming_experience_months": RNG.integers(6, 48),
                "s1_learning_pct": RNG.uniform(40, 100),
                "s1_help_requests": RNG.poisson(5),
                "s2_far_transfer_pct": 10 + 0.6 * baseline + effect + RNG.normal(0, 6),
                "included_primary": 1,
            })
    rows.append({**rows[0], "participant_code": "dropout", "included_primary": 0, "s2_far_transfer_pct": None})
    return pd.DataFrame(rows)


def test_ancova_recovers_the_condition_effect(tmp_path):
    path = tmp_path / "outcomes.csv"
    synthetic_outcomes().to_csv(path, index=False)

    frame = study.load(path)
    result = study.ancova(frame)

    assert result["n"] == 75
    assert result["p"] < 0.001
    assert result["adjusted_means"]["socratic_ai"] > result["adjusted_means"]["standard_resource"] > result["adjusted_means"]["direct_ai"]
    socratic_vs_direct = next(c for c in result["contrasts"] if c["contrast"] == "direct_ai − socratic_ai")
    assert socratic_vs_direct["estimate"] < -10
    assert all(c["p_holm"] >= c["p"] for c in result["contrasts"])


def test_sensor_feature_sets_are_cumulative(tmp_path):
    outcomes = tmp_path / "outcomes.csv"
    sensors = tmp_path / "sensors.csv"
    frame = synthetic_outcomes()
    frame.to_csv(outcomes, index=False)
    pd.DataFrame({"participant_code": frame.participant_code, "eeg_fm_theta": RNG.normal(size=len(frame)), "auto_scr_per_min": RNG.normal(size=len(frame))}).to_csv(sensors, index=False)

    sets = study.feature_sets(study.load(outcomes, sensors))

    assert list(sets) == ["Başlangıç", "+ Davranış ve YZ etkileşimi", "+ EEG", "+ Otonom", "+ Tüm sensörler"]
    assert set(sets["+ Davranış ve YZ etkileşimi"]) < set(sets["+ Tüm sensörler"])
    assert "eeg_fm_theta" not in sets["+ Otonom"]
