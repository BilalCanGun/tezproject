"""
LSL marker bridge for the participant kiosk.

Runs on the lab computer next to the browser. The kiosk connects over a local
WebSocket and sends every interaction marker; the bridge pushes it into an LSL
outlet (recorded by LabRecorder into the same XDF file as EEG and gaze) and
replies with the LSL timestamp so the web database stores it too.

It also:
  * answers clock-sync pings so browser time can be mapped to the LSL clock,
  * reports which LSL streams are visible (EEG, eye tracker) so the tester can
    see on the live monitor that the devices are streaming,
  * writes every marker to a local CSV file as a backup.

Usage:
    python -m pip install -r requirements.txt
    python lsl_bridge.py --port 8765 --log-dir ./marker-logs

Only localhost connections are accepted by default.
"""

from __future__ import annotations

import argparse
import asyncio
import csv
import json
import logging
import threading
import time
from datetime import datetime, timezone
from pathlib import Path

import pylsl
import websockets

log = logging.getLogger("lsl_bridge")


class MarkerOutlet:
    """Two outlets: a string stream (event name) and an int stream (code).

    Some EEG acquisition tools only accept numeric triggers, others are easier
    to analyse with labels; both carry identical timestamps.
    """

    def __init__(self, name: str, source_id: str) -> None:
        label_info = pylsl.StreamInfo(name, "Markers", 1, pylsl.IRREGULAR_RATE, pylsl.cf_string, f"{source_id}-labels")
        code_info = pylsl.StreamInfo(f"{name}Codes", "Markers", 1, pylsl.IRREGULAR_RATE, pylsl.cf_int32, f"{source_id}-codes")

        for info in (label_info, code_info):
            info.desc().append_child_value("manufacturer", "tezproject")
            info.desc().append_child_value("description", "Participant kiosk interaction markers")

        self.labels = pylsl.StreamOutlet(label_info)
        self.codes = pylsl.StreamOutlet(code_info)
        self.name = name

    def push(self, code: int, label: str, seq: int) -> float:
        timestamp = pylsl.local_clock()
        self.labels.push_sample([f"{label}|seq={seq}"], timestamp)
        self.codes.push_sample([int(code)], timestamp)

        return timestamp


class StreamWatcher(threading.Thread):
    """Periodically resolves visible LSL streams in a background thread."""

    def __init__(self, interval: float, own_names: set[str]) -> None:
        super().__init__(daemon=True)
        self.interval = interval
        self.own_names = own_names
        self.streams: list[dict] = []

    def run(self) -> None:
        while True:
            try:
                found = pylsl.resolve_streams(wait_time=1.0)
                self.streams = [
                    {
                        "name": info.name(),
                        "type": info.type(),
                        "channel_count": info.channel_count(),
                        "nominal_srate": info.nominal_srate(),
                        "source_id": info.source_id(),
                    }
                    for info in found
                    if info.name() not in self.own_names
                ]
            except Exception:  # noqa: BLE001 - keep watching even if resolution fails once
                log.exception("Stream resolution failed")

            time.sleep(self.interval)


class MarkerLog:
    def __init__(self, directory: Path) -> None:
        directory.mkdir(parents=True, exist_ok=True)
        path = directory / f"markers_{datetime.now().strftime('%Y%m%d_%H%M%S')}.csv"
        self.file = path.open("w", newline="", encoding="utf-8")
        self.writer = csv.writer(self.file)
        self.writer.writerow(["utc_iso", "lsl_time", "seq", "code", "label", "client"])
        log.info("Marker backup: %s", path)

    def write(self, lsl_time: float, seq: int, code: int, label: str, client: str) -> None:
        self.writer.writerow([datetime.now(timezone.utc).isoformat(), f"{lsl_time:.6f}", seq, code, label, client])
        self.file.flush()


async def serve(args: argparse.Namespace) -> None:
    outlet = MarkerOutlet(args.stream_name, args.source_id)
    watcher = StreamWatcher(args.resolve_interval, {outlet.name, f"{outlet.name}Codes"})
    watcher.start()
    backup = MarkerLog(Path(args.log_dir))

    async def handler(socket) -> None:
        client = f"{socket.remote_address[0]}:{socket.remote_address[1]}"

        if not args.allow_remote and socket.remote_address[0] not in ("127.0.0.1", "::1"):
            await socket.close(code=1008, reason="local connections only")

            return

        log.info("Kiosk connected: %s", client)
        await socket.send(json.dumps({"type": "hello", "outlet": outlet.name, "lsl_now": pylsl.local_clock()}))

        async def report_streams() -> None:
            while True:
                await socket.send(json.dumps({"type": "streams", "streams": watcher.streams}))
                await asyncio.sleep(args.resolve_interval)

        reporter = asyncio.create_task(report_streams())

        try:
            async for raw in socket:
                try:
                    message = json.loads(raw)
                except json.JSONDecodeError:
                    continue

                kind = message.get("type")

                if kind == "marker":
                    seq = int(message["seq"])
                    code = int(message["code"])
                    label = str(message.get("label", ""))[:64]
                    lsl_time = outlet.push(code, label, seq)
                    backup.write(lsl_time, seq, code, label, client)
                    await socket.send(json.dumps({"type": "ack", "seq": seq, "lsl_time": lsl_time}))
                elif kind == "sync":
                    await socket.send(json.dumps({
                        "type": "sync",
                        "id": message.get("id"),
                        "t0": message.get("t0"),
                        "lsl": pylsl.local_clock(),
                    }))
        except websockets.ConnectionClosed:
            pass
        finally:
            reporter.cancel()
            log.info("Kiosk disconnected: %s", client)

    async with websockets.serve(handler, args.host, args.port, max_size=2**16):
        log.info("Bridge listening on ws://%s:%d, LSL outlet '%s'", args.host, args.port, outlet.name)
        await asyncio.Future()


def main() -> None:
    parser = argparse.ArgumentParser(description="Kiosk → LSL marker bridge")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8765)
    parser.add_argument("--stream-name", default="TezKioskMarkers")
    parser.add_argument("--source-id", default="tezproject-kiosk")
    parser.add_argument("--log-dir", default="marker-logs")
    parser.add_argument("--resolve-interval", type=float, default=5.0)
    parser.add_argument("--allow-remote", action="store_true", help="Accept connections from other machines")
    args = parser.parse_args()

    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    asyncio.run(serve(args))


if __name__ == "__main__":
    main()
