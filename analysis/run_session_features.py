"""Session-1 sensor features and quality metrics for every recorded participant.

    analysis\\.venv\\Scripts\\python analysis\\run_session_features.py manifest.csv events.csv --output sensor_features.csv

``manifest.csv`` has one row per participant: ``participant_code``, ``xdf``
(path to the LabRecorder file) and optionally ``wearable`` (one or more CSV
paths separated by ``;``). ``events.csv`` is the panel's "events" export.

The output feeds ``run_study.py --sensors``. The quality columns
(eeg_usable_epoch_ratio, gaze_valid_ratio, wearable_loss_ratio) are the values
to enter in the session's quality review in the panel.
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parent))

from tezanaliz import session  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("manifest", type=Path)
    parser.add_argument("events", type=Path)
    parser.add_argument("--output", type=Path, default=Path("sensor_features.csv"))
    args = parser.parse_args()

    rows = []
    for _, entry in pd.read_csv(args.manifest).iterrows():
        code = str(entry.participant_code)
        events = session.load_events(args.events, code)
        streams = session.load_xdf(entry.xdf) if isinstance(entry.get("xdf"), str) else {}
        wearable_paths = [p for p in str(entry.get("wearable") or "").split(";") if p.strip() and p != "nan"]
        data = session.SessionData(events, streams.get("eeg"), streams.get("gaze"), session.load_wearable_csv(wearable_paths))

        features = session.extract(data)
        rows.append({"participant_code": code, **features})
        quality = {label: features.get(column) for column, label in session.QUALITY_COLUMNS.items()}
        print(code, "kalite:", {k: (round(v, 3) if isinstance(v, float) else v) for k, v in quality.items()})

    pd.DataFrame(rows).to_csv(args.output, index=False)
    print(f"{len(rows)} katılımcı yazıldı: {args.output}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
