#!/usr/bin/env python3
"""Generate tiny, trackless-network synthetic v1 torrents for qBit layout smoke tests.

No real tracker / no actual seeding data. 100% deterministic; safe for offline tests.
Do NOT start/download these torrents: the tracker URL is deliberately unreachable.
"""
from __future__ import annotations
from hashlib import sha1
from pathlib import Path
import sys

def enc(obj):
    if isinstance(obj, int):
        return b"i" + str(obj).encode("ascii") + b"e"
    if isinstance(obj, bytes):
        return str(len(obj)).encode("ascii") + b":" + obj
    if isinstance(obj, str):
        return enc(obj.encode("utf-8"))
    if isinstance(obj, (list, tuple)):
        return b"l" + b"".join(enc(item) for item in obj) + b"e"
    if isinstance(obj, dict):
        pairs = sorted(((k.encode("utf-8") if isinstance(k, str) else k), v)
                        for k, v in obj.items())
        return b"d" + b"".join(enc(k) + enc(v) for k, v in pairs) + b"e"
    raise TypeError(f"Unsupported bencode value: {type(obj)}")

def fixture(name, file_payloads):
    """file_payloads: [(relative path *within* info.name, bytes)]"""
    piece_length = 16384
    concatenated = b"".join(data for _, data in file_payloads)
    pieces = b"".join(sha1(concatenated[n:n+piece_length]).digest()
                      for n in range(0, len(concatenated), piece_length))
    info = {"name": name, "piece length": piece_length, "pieces": pieces, "private": 1}
    if len(file_payloads) == 1 and file_payloads[0][0] is None:
        info["length"] = len(file_payloads[0][1])
    else:
        info["files"] = [{"length": len(data), "path": rel.split("/")}
                         for rel, data in file_payloads]
    torrent = {
        "announce": "http://127.0.0.1:9/announce",
        "created by": "PT Disk Router Synthetic Offline Fixture",
        "encoding": "UTF-8",
        "info": info,
    }
    return enc(torrent), sha1(enc(info)).hexdigest()

def main():
    base = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp/pt-disk-router-fixtures")
    base.mkdir(mode=0o755, parents=True, exist_ok=True)
    cases = [
        ("PTSIM.Single.2026.mkv", [(None, b"S" * 2048)]),
        ("PTSIM.Series.S01", [("E01.mkv", b"A" * 2048), ("E02.mkv", b"B" * 2048)]),
        ("PTSIM.Collection", [("Disc1/A.mkv", b"C" * 2048),
                              ("Disc2/B.mkv", b"D" * 2048)]),
    ]
    for name, files in cases:
        raw, infohash = fixture(name, files)
        output = base / (name + ".torrent")
        output.write_bytes(raw)
        print(f"{output}  hash={infohash}  bytes={len(raw)}")
    print("NOTE: Add PAUSED only. Private test torrents have no reachable tracker.")
    print("NOTE: No matching payload files were generated; do not resume/start.")

if __name__ == "__main__":
    main()
