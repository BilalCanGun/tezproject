"""Primary ANCOVA and participant-independent prediction on the thesis data.

    analysis\\.venv\\Scripts\\python analysis\\run_study.py outcomes.csv [--sensors sensor_features.csv]

``outcomes.csv`` is the "outcomes" export of the admin panel (Veri dışa aktarımı).
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from tezanaliz import study  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("outcomes", type=Path)
    parser.add_argument("--sensors", type=Path)
    parser.add_argument("--output", type=Path, default=Path("study_results.json"))
    args = parser.parse_args()

    frame = study.load(args.outcomes, args.sensors)
    if frame["condition"].nunique() < 3 or len(frame) < 9:
        print("Birincil analiz için üç koşulda yeterli tamamlayan katılımcı yok.", file=sys.stderr)
        return 1

    primary = study.ancova(frame)
    models = study.prediction(frame)

    print(json.dumps(primary, ensure_ascii=False, indent=2))
    print(models.to_string(index=False))
    args.output.write_text(json.dumps({"ancova": primary, "prediction": models.to_dict(orient="records")}, ensure_ascii=False, indent=2, default=float), encoding="utf-8")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
