#!/usr/bin/env python3
"""
Static reverse-engineering report for Soccer Stars libgame-SSM.
Uses: ELF parsing, strings, radare2 (optional), exported symbols.

Usage:
  python3 analyze_lib.py path/to/libgame-SSM*.so -o report.json
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

LIB_NAME = "libgame-SSM-GooglePlay-Gold-Release-Module-1013.so"

KEY_SELECTORS = [
    "takeActualShot:angle:spin:playSound:",
    "calculateShot:power:angle:spin:",
    "calculateFinalPower:forBall:",
    "simulateAndSendShot:power:angle:",
    "aimTo:angle:power:",
    "commitShot",
    "stopAllPhysics",
    "getBallBallCollision:ballB:time:force:",
    "getBallLineCollision:angle:time:force:pointA:pointB:",
    "BallBallCollision",
    "BallLineCollision",
    "playShot:",
    "animateShot:",
]

KEY_EXPORTS = [
    "sPhysicsDebugEnabled",
    "sPhysicsDebugDiagnostics",
    "sPhysicsDebugDiagnosticsComplete",
    "sInternalVelocity",
    "sel_registerName",
    "method_getImplementation",
]

PROTO_MESSAGES = [
    "game_started",
    "aim_event",
    "shot_taken",
    "animation_outcome",
    "shot_outcome",
    "puck_state",
    "referee_field_ready_request",
]

PROTO_FIELDS = [
    "angle_",
    "power_",
    "puck_id",
    "shot_id",
    "player_id",
    "field_state",
    "owner_enum",
    "state_enum",
    "position",
    "velocity",
]

PHYSICS_CLASSES = [
    "GameSoccerBallSettings",
    "IceGameSoccerBallSettings",
    "FireGameSoccerBallSettings",
    "BaseSoccerBallSettings",
    "BallSettingsProtocol",
]


def run(cmd: list[str]) -> str:
    try:
        return subprocess.run(cmd, capture_output=True, text=True, check=False).stdout
    except FileNotFoundError:
        return ""


def elf_info(lib: Path) -> dict:
    out = run(["readelf", "-h", str(lib)])
    info: dict = {"path": str(lib), "size_bytes": lib.stat().st_size}
    for line in out.splitlines():
        if "Class:" in line:
            info["class"] = line.split(":", 1)[1].strip()
        if "Machine:" in line:
            info["machine"] = line.split(":", 1)[1].strip()
        if "Type:" in line:
            info["type"] = line.split(":", 1)[1].strip()
    soname = run(["readelf", "-d", str(lib)])
    m = re.search(r"Library soname: \[(.+?)\]", soname)
    if m:
        info["soname"] = m.group(1)
    return info


def nm_exports(lib: Path) -> list[dict]:
    rows = []
    out = run(["nm", "-D", "--defined-only", str(lib)])
    for line in out.splitlines():
        parts = line.split()
        if len(parts) < 3:
            continue
        addr, kind, name = parts[0], parts[1], parts[2]
        if any(k in name.lower() for k in ("physics", "velocity", "puck", "shot", "friction")):
            rows.append({"address": addr, "kind": kind, "name": name})
    return rows


def strings_scan(lib: Path) -> dict:
    raw = run(["strings", "-n", "4", str(lib)])
    lines = raw.splitlines()

    selectors = {}
    for sel in KEY_SELECTORS:
        hits = [i for i, l in enumerate(lines) if l == sel]
        if hits:
            selectors[sel] = {"count": len(hits)}

    proto = {m: l in lines for m in PROTO_MESSAGES for l in [m]}
    # fix proto dict
    proto = {m: any(l == m for l in lines) for m in PROTO_MESSAGES}
    fields = {f: any(l == f for l in lines) for f in PROTO_FIELDS}
    classes = [c for c in PHYSICS_CLASSES if any(l == c for l in lines)]

  # ObjC method signatures from type encodings
    shot_taken_sig = None
    puck_state_sig = None
    for line in lines:
        if line.startswith("{shot_taken="):
            shot_taken_sig = line[:500]
        if line.startswith("{puck_state=") and "position" in line:
            puck_state_sig = line[:300]

    physics_members = sorted(
        {l for l in lines if re.match(r"^m(Friction|Edge|Power|Velocity|Mass|Radius)", l)}
    )

    ssm_paths = sorted({l for l in lines if "SSM/pool" in l and l.endswith(".mm")})[:30]

    return {
        "selectors": selectors,
        "protobuf_messages": proto,
        "protobuf_fields": fields,
        "physics_classes": classes,
        "physics_members": physics_members,
        "shot_taken_type_encoding": shot_taken_sig,
        "puck_state_type_encoding": puck_state_sig,
        "ssm_source_paths_sample": ssm_paths,
    }


def r2_string_offsets(lib: Path) -> dict:
    """Radare2 izz search for virtual addresses of key strings."""
    offsets: dict = {}
    if not shutil_which("r2"):
        return offsets
    for sel in KEY_SELECTORS[:8]:
        out = run(["r2", "-q", "-e", "bin.cache=true", "-c", f"izz~{sel}", str(lib)])
        addrs = []
        for line in out.splitlines():
            parts = line.split()
            if len(parts) >= 2 and parts[1].startswith("0x"):
                addrs.append(parts[1])
        if addrs:
            offsets[sel] = addrs[:3]
    return offsets


def shutil_which(cmd: str) -> bool:
    return bool(run(["which", cmd]).strip())


def shot_pipeline() -> list[dict]:
    return [
        {"step": 1, "phase": "aim", "selector": "aimTo:angle:power:", "desc": "Player drags puck — aim direction + power"},
        {"step": 2, "phase": "calculate", "selector": "calculateShot:power:angle:spin:", "desc": "Compute shot parameters from drag"},
        {"step": 3, "phase": "power", "selector": "calculateFinalPower:forBall:", "desc": "Clamp power per ball settings"},
        {"step": 4, "phase": "simulate", "selector": "simulateAndSendShot:power:angle:", "desc": "Local preview / send to network layer"},
        {"step": 5, "phase": "execute", "selector": "takeActualShot:angle:spin:playSound:", "desc": "Apply impulse to puck in physics world"},
        {"step": 6, "phase": "collision", "selector": "getBallBallCollision / getBallLineCollision", "desc": "Ball-ball and ball-wall collisions"},
        {"step": 7, "phase": "stop", "selector": "stopAllPhysics", "desc": "End turn when all bodies rest"},
        {"step": 8, "phase": "network", "message": "shot_taken", "fields": ["angle", "power", "puck_id", "shot_id", "field_state"]},
        {"step": 9, "phase": "server", "message": "shot_outcome", "fields": ["puck_state[]", "scores", "next_turn"]},
    ]


def main() -> None:
    parser = argparse.ArgumentParser(description="Analyze Soccer Stars native lib")
    parser.add_argument("lib", nargs="?", default=f"./dumped/{LIB_NAME}")
    parser.add_argument("-o", "--output", default="./dumped/lib_analysis.json")
    args = parser.parse_args()

    lib = Path(args.lib)
    if not lib.exists():
        print(f"Library not found: {lib}")
        print("Run: ./scripts/extract_lib_from_apks.sh")
        sys.exit(1)

    report = {
        "version": "36.14.4",
        "build": "1013",
        "package": "com.miniclip.soccerstars",
        "elf": elf_info(lib),
        "engine": {
            "name": "SSM/pool",
            "description": "Billiards-style physics (Miniclip iOS port with embedded GNU ObjC)",
            "jenkins_module": "SSM-GooglePlay-Gold-Release-Module",
        },
        "exported_symbols": nm_exports(lib),
        "strings": strings_scan(lib),
        "r2_vaddr_strings": r2_string_offsets(lib),
        "shot_pipeline": shot_pipeline(),
        "frida_notes": {
            "lib_module": LIB_NAME,
            "debug_exports": ["sPhysicsDebugEnabled", "sPhysicsDebugDiagnostics", "sInternalVelocity"],
            "hook_script": "frida/hook_physics_pipeline.js",
        },
        "limitations": [
            "Online matches are server-authoritative; client lib animates shot_outcome from server.",
            "takeActualShot is stripped from public exports; hook via ObjC IMP or Ghidra offset.",
            "Physics constants (mFrictionFactor) are class members, not exported globals.",
        ],
    }

    out = Path(args.output)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"Wrote {out} ({out.stat().st_size} bytes)")
    print(f"  selectors found: {len(report['strings']['selectors'])}")
    print(f"  physics classes: {len(report['strings']['physics_classes'])}")


if __name__ == "__main__":
    main()
