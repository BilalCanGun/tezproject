"""Public datasets used to test what each candidate device has to deliver.

Downloads go through the Kaggle CLI, which reads ``KAGGLE_API_TOKEN`` (or
``~/.kaggle``) from the environment; no credential is stored in the project.
Downloaded files are untrusted: WESAD's pickles are opened with an unpickler
that only accepts numpy arrays, and every subject is converted to a compact
``.npz`` so the 850 MB originals do not have to be kept.
"""

from __future__ import annotations

import io
import pickle
import subprocess
import sys
import zipfile
from dataclasses import dataclass
from pathlib import Path

import numpy as np
import pandas as pd
from scipy import signal

from .config import DATA_ROOT


@dataclass(frozen=True)
class DatasetInfo:
    key: str
    title: str
    kaggle_ref: str
    modality: str
    url: str
    note: str


DATASETS = {
    "eeg_arithmetic": DatasetInfo(
        "eeg_arithmetic", "EEG During Mental Arithmetic Tasks (Zyma vd., 2019)", "swapnilsafkat/eeg-during-arithmetic-task", "EEG",
        "https://www.kaggle.com/datasets/swapnilsafkat/eeg-during-arithmetic-task",
        "19 kanal, 500 Hz, 10-20 dizilimi. Dinlenim ve zihinsel aritmetik: cihaz öykünmesi, theta/alpha ve ERD, iş yükü sınıflandırması.",
    ),
    "stew": DatasetInfo(
        "stew", "STEW: Simultaneous Task EEG Workload", "mitulahirwal/mental-cognitive-workload-eeg-data-stew-dataset", "EEG",
        "https://www.kaggle.com/datasets/mitulahirwal/mental-cognitive-workload-eeg-data-stew-dataset",
        "Emotiv EPOC (14 kanal, 128 Hz) ile gerçek kayıt. EPOC X sınıfı bir cihazın veri kalitesi ve iş yükü ayrımı.",
    ),
    "confused": DatasetInfo(
        "confused", "Confused Student EEG Brainwave Data", "wanghaohan/confused-eeg", "EEG (tek kanal)",
        "https://www.kaggle.com/datasets/wanghaohan/confused-eeg",
        "Ders videosu izlerken tek kanallı EEG ve kafa karışıklığı etiketi. Öğrenme bağlamında katılımcı bağımsız tahmin.",
    ),
    "vreed": DatasetInfo(
        "vreed", "VR Eyes: Emotions Dataset (VREED)", "lumaatabbaa/vr-eyes-emotions-dataset-vreed", "Göz izleme",
        "https://www.kaggle.com/datasets/lumaatabbaa/vr-eyes-emotions-dataset-vreed",
        "60 Hz, örnek düzeyinde bakış ve göz kırpması (pupil yok). Geçerli örnek oranı, fiksasyon/sakkad ölçümleri, 30 Hz öykünmesi, duygu sınıflandırması.",
    ),
    "wesad": DatasetInfo(
        "wesad", "WESAD: Wearable Stress and Affect Detection", "orvile/wesad-wearable-stress-affect-detection-dataset", "Bileklik + göğüs EKG",
        "https://www.kaggle.com/datasets/orvile/wesad-wearable-stress-affect-detection-dataset",
        "Empatica E4 (EDA 4 Hz, BVP 64 Hz, ACC 32 Hz, TEMP 4 Hz) ve referans göğüs EKG'si. Bileklikten HR/RMSSD doğruluğu ve stres sınıflandırması.",
    ),
}

WESAD_SUBJECTS = [f"S{i}" for i in [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 14, 15, 16, 17]]


def kaggle(*args: str) -> None:
    executable = Path(sys.executable).with_name("kaggle.exe" if sys.platform == "win32" else "kaggle")
    subprocess.run([str(executable), *args], check=True)


def download(key: str) -> Path:
    """Downloads one dataset (WESAD subject by subject) unless already present."""
    info = DATASETS[key]
    target = DATA_ROOT / key

    if key == "wesad":
        return download_wesad()
    if key == "confused":
        for name in ("EEG_data.csv", "demographic_info.csv"):
            if not (target / name).exists():
                kaggle("datasets", "download", info.kaggle_ref, "-f", name, "-p", str(target))
                _unzip_in_place(target)
        return target

    if not target.exists() or not any(target.iterdir()):
        kaggle("datasets", "download", info.kaggle_ref, "-p", str(target), "--unzip", "-q")
    return target


def _unzip_in_place(folder: Path) -> None:
    for archive in folder.glob("*.zip"):
        with zipfile.ZipFile(archive) as handle:
            handle.extractall(folder)
        archive.unlink()


class _NumpyOnlyUnpickler(pickle.Unpickler):
    """Refuses every global except what a pickled dict of numpy arrays needs."""

    ALLOWED = {
        ("numpy.core.multiarray", "_reconstruct"), ("numpy._core.multiarray", "_reconstruct"),
        ("numpy.core.multiarray", "scalar"), ("numpy._core.multiarray", "scalar"),
        ("numpy", "ndarray"), ("numpy", "dtype"), ("_codecs", "encode"),
    }

    def find_class(self, module: str, name: str):
        if (module, name) not in self.ALLOWED:
            raise pickle.UnpicklingError(f"İzin verilmeyen pickle nesnesi: {module}.{name}")
        return super().find_class(module, name)


def _compact_wesad(raw: bytes) -> dict[str, np.ndarray]:
    data = _NumpyOnlyUnpickler(io.BytesIO(raw), encoding="latin1").load()
    wrist, chest = data["signal"]["wrist"], data["signal"]["chest"]
    labels = np.asarray(data["label"]).astype(np.int8)

    return {
        "bvp": np.asarray(wrist["BVP"], dtype=np.float32).ravel(),
        "eda": np.asarray(wrist["EDA"], dtype=np.float32).ravel(),
        "temp": np.asarray(wrist["TEMP"], dtype=np.float32).ravel(),
        "acc": np.asarray(wrist["ACC"], dtype=np.float32),
        "ecg": signal.resample_poly(np.asarray(chest["ECG"], dtype=np.float64).ravel(), 1, 2).astype(np.float32),
        "label_4hz": labels[::175],
    }


def download_wesad() -> Path:
    target = DATA_ROOT / "wesad"
    target.mkdir(parents=True, exist_ok=True)
    ref = DATASETS["wesad"].kaggle_ref

    for subject in WESAD_SUBJECTS:
        compact = target / f"{subject}.npz"
        if compact.exists():
            continue

        work = target / "_tmp"
        work.mkdir(exist_ok=True)
        kaggle("datasets", "download", ref, "-f", f"WESAD/{subject}/{subject}.pkl", "-p", str(work), "-q")

        pkl = next(work.glob("*.pkl"), None)
        archive = next(work.glob("*.zip"), None)
        if pkl is not None:
            raw = pkl.read_bytes()
        elif archive is not None:
            with zipfile.ZipFile(archive) as handle:
                raw = handle.read(next(n for n in handle.namelist() if n.endswith(".pkl")))
        else:
            raise FileNotFoundError(f"{subject} indirilemedi")

        np.savez_compressed(compact, **_compact_wesad(raw))
        for leftover in work.iterdir():
            leftover.unlink()
        print(f"WESAD {subject} hazır", flush=True)

    return target


# --------------------------------------------------------------------- loaders

ARITHMETIC_RENAME = {"T3": "T7", "T4": "T8", "T5": "P7", "T6": "P8"}


def load_arithmetic_subject(path: Path) -> tuple[np.ndarray, float, list[str]]:
    """One EDF recording in microvolts with modern 10-20 labels."""
    import mne

    raw = mne.io.read_raw_edf(path, preload=True, verbose="ERROR")
    labels = []
    keep = []
    for i, name in enumerate(raw.ch_names):
        label = name.replace("EEG", "").strip().split("-")[0].strip()
        label = ARITHMETIC_RENAME.get(label, label)
        if label.upper() in {"A2", "A1"} or "ECG" in name.upper() or "EKG" in name.upper():
            continue
        labels.append(label.capitalize() if label.lower().endswith("z") else label)
        keep.append(i)

    data = raw.get_data(picks=keep) * 1e6

    return data, float(raw.info["sfreq"]), labels


def arithmetic_recordings() -> list[tuple[str, Path, Path]]:
    """``(subject, rest_file, task_file)`` for every subject."""
    files = sorted((DATA_ROOT / "eeg_arithmetic").rglob("Subject*_1.edf"))
    return [(f.stem.split("_")[0], f, f.with_name(f.stem[:-1] + "2.edf")) for f in files if f.with_name(f.stem[:-1] + "2.edf").exists()]


def arithmetic_counting_quality() -> dict[str, int]:
    """Good (1) or bad (0) counter per subject, from subject-info.csv if present."""
    info = next((DATA_ROOT / "eeg_arithmetic").rglob("subject-info.csv"), None)
    if info is None:
        return {}
    frame = pd.read_csv(info)
    return dict(zip(frame["Subject"].astype(str).str.strip(), frame["Count quality"].astype(int)))


STEW_CHANNELS = ["AF3", "F7", "F3", "FC5", "T7", "P7", "O1", "O2", "P8", "T8", "FC6", "F4", "F8", "AF4"]


def load_stew() -> list[tuple[str, int, int, np.ndarray]]:
    """``(subject, rating, workload_class, data)``; data is 14 channels x 150 s at 128 Hz.

    The Kaggle release holds the SIMKAP multitasking recordings of 45 people,
    each with a self-rated workload (1-9) and a low/medium/high class.
    """
    from scipy.io import loadmat

    folder = DATA_ROOT / "stew"
    array = np.asarray(loadmat(next(folder.rglob("dataset.mat")))["dataset"], dtype=float)
    ratings = np.asarray(loadmat(next(folder.rglob("rating.mat")))["rating"]).ravel()
    classes = np.asarray(loadmat(next(folder.rglob("class_012.mat")))["class_012"]).ravel()

    return [(f"sub{r + 1:02d}", int(ratings[r]), int(classes[r]), array[:, :, r]) for r in range(array.shape[2])]


def load_confused() -> pd.DataFrame:
    frame = pd.read_csv(DATA_ROOT / "confused" / "EEG_data.csv")
    frame.columns = [c.strip() for c in frame.columns]
    return frame


def vreed_files() -> list[Path]:
    return sorted((DATA_ROOT / "vreed").rglob("*_EyeTracking_PreProcessed.dat"))
