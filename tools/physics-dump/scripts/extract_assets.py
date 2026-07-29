#!/usr/bin/env python3
"""Extract formations and ADA config from Soccer Stars base.apk."""

from __future__ import annotations

import argparse
import json
import plistlib
import zipfile
from pathlib import Path


def unwrap(v):
    if isinstance(v, dict) and "_value" in v:
        return v["_value"]
    return v


def extract_formations(apk: Path) -> list[dict]:
    with zipfile.ZipFile(apk) as z:
        data = z.read("assets/unpack/Slice_formationList.plist")
    items = plistlib.loads(data)
    out = []
    for item in items:
        if not isinstance(item, dict):
            continue
        name = unwrap(item.get("name", ""))
        pucks = {}
        for i in range(1, 6):
            kx, ky = f"puckx{i}", f"pucky{i}"
            if kx in item and ky in item:
                pucks[f"p{i}"] = {
                    "x": unwrap(item[kx]),
                    "y": unwrap(item[ky]),
                }
        out.append(
            {
                "name": name,
                "strategy": unwrap(item.get("strategy")),
                "numberOfPucks": unwrap(item.get("numberOfPucks")),
                "level": unwrap(item.get("level")),
                "pucks": pucks,
            }
        )
    return out


def extract_ada_config(apk: Path) -> dict:
    with zipfile.ZipFile(apk) as z:
        data = z.read("assets/unpack/Slice_adaGeneralConfig.plist")
    pl = plistlib.loads(data)
    keys = [
        "isAdaEnabled",
        "maxAimTime",
        "minAimTime",
        "maxForce",
        "minForce",
        "defenseMaxForce",
        "defenseMinForce",
        "firstMatchTierId",
        "numberOfFirstGamesWithAda",
    ]
    return {k: unwrap(pl.get(k)) for k in keys if k in pl}


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("apk", nargs="?", default="../downloads/base.apk")
    parser.add_argument("-o", "--output", default="./dumped/assets_dump.json")
    args = parser.parse_args()

    apk = Path(args.apk)
    if not apk.exists():
        # try from extracted apks
        alt = Path("downloads/base.apk")
        if alt.exists():
            apk = alt
        else:
            raise SystemExit(f"APK not found: {args.apk}")

    report = {
        "formations": extract_formations(apk),
        "ada_config": extract_ada_config(apk),
    }

    out = Path(args.output)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"Wrote {out} — {len(report['formations'])} formations")


if __name__ == "__main__":
    main()
