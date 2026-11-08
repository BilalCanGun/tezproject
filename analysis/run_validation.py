"""Device validation and model comparison on public datasets.

Usage (from the project root):

    php artisan devices:export-profiles
    set KAGGLE_API_TOKEN=...            (or ~/.kaggle/kaggle.json)
    analysis\\.venv\\Scripts\\python analysis\\run_validation.py --download

The report is written to storage/app/private/analysis/device_validation.json
and shown under Analiz → Cihaz doğrulama ve modeller in the admin panel.
"""

from __future__ import annotations

import argparse
import json
import math
import platform
import sys
import time
from datetime import datetime, timezone
from importlib import metadata
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from tezanaliz import datasets, validate_eeg, validate_gaze, validate_learning, validate_wearable  # noqa: E402
from tezanaliz.config import RESULTS_ROOT, load_devices  # noqa: E402

STEPS = {
    "eeg": ("eeg_arithmetic", lambda devices: validate_eeg.run_arithmetic([d for d in devices if d.modality == "eeg"])),
    "stew": ("stew", lambda devices: validate_eeg.run_stew()),
    "gaze": ("vreed", validate_gaze.run),
    "wearable": ("wesad", validate_wearable.run),
    "learning": ("confused", lambda devices: validate_learning.run()),
}

LIMITATIONS = [
    "Cihazlar gerçek donanımla değil, açık veri setleri cihazın kanal dizilimine ve örnekleme hızına indirgenerek öykünüldü. Elektrot tipi (kuru/jel/tuzlu su), gürültü ve bağlantı kararlılığı bu yolla test edilemez; bunlar pilot uygulamada ölçülmelidir.",
    "EEG veri setinde PO7, PO8, Oz ve FCz elektrotları yok; Unicorn ve EPOC Flex için bu elektrotlar öykünmede kullanılamadı.",
    "Aritmetik veri seti üretici tarafından ICA ile temizlenmiş 60 sn'lik bölümlerden oluşur; kullanılabilir epok oranı gerçek oturumdan yüksek çıkar. Ham kayıt kalitesi için STEW (Emotiv) sonucuna bakılmalıdır.",
    "VREED 60 Hz kaydedildiği ve pupil içermediği için 120 Hz eşiği ve pupil ölçümü bu veriyle doğrulanamaz.",
    "WESAD'da Empatica E4 kullanıldı; EmbracePlus öykünmesi E4 sinyallerinin EmbracePlus örnekleme hızlarına getirilmesiyle yapıldı. İvme E4'te 32 Hz olduğu için 64 Hz'e yükseltilemedi.",
    "Model sonuçları bu veri setlerindeki görevler içindir; tezdeki birincil hedef (gecikmeli uzak transfer puanı) için öngörü gücü hakkında doğrudan bilgi vermez, yalnızca analiz hattının ve değerlendirme yönteminin doğrulamasıdır.",
]


def _clean(value):
    """JSON cannot carry NaN; numpy scalars become plain floats."""
    if isinstance(value, dict):
        return {k: _clean(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [_clean(v) for v in value]
    if hasattr(value, "item") and not isinstance(value, (str, bytes)):
        value = value.item()
    if isinstance(value, float) and (math.isnan(value) or math.isinf(value)):
        return None
    return value


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--download", action="store_true", help="Eksik veri setlerini Kaggle'dan indir")
    parser.add_argument("--only", nargs="*", choices=list(STEPS), help="Yalnızca seçilen adımları çalıştır")
    parser.add_argument("--output", type=Path, default=RESULTS_ROOT / "device_validation.json")
    args = parser.parse_args()

    devices = load_devices()
    steps = args.only or list(STEPS)
    previous = json.loads(args.output.read_text(encoding="utf-8")) if args.output.exists() and args.only else {}
    sections = {s["step"]: s for s in previous.get("sections", [])}

    for step in steps:
        dataset_key, runner = STEPS[step]
        if args.download:
            datasets.download(dataset_key)
        started = time.time()
        print(f"[{step}] çalışıyor...", flush=True)
        result = runner(devices)
        sections[step] = {"step": step, "dataset": dataset_key, "seconds": round(time.time() - started, 1), **result}
        print(f"[{step}] bitti ({sections[step]['seconds']} sn)", flush=True)

    ordered = [sections[s] for s in STEPS if s in sections]
    used = {s["dataset"]: s.get("participants") for s in ordered}
    report = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "environment": {
            "python": platform.python_version(),
            "packages": {p: metadata.version(p) for p in ("numpy", "scipy", "scikit-learn", "xgboost", "mne", "pandas")},
        },
        "datasets": [
            {"key": key, "title": info.title, "url": info.url, "modality": info.modality, "participants": used[key], "note": info.note}
            for key, info in datasets.DATASETS.items() if key in used
        ],
        "devices": [card for section in ordered for card in section["devices"]],
        "models": [experiment for section in ordered for experiment in section["models"]],
        "sections": ordered,
        "limitations": LIMITATIONS,
    }

    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(_clean(report), ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Rapor yazıldı: {args.output}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
