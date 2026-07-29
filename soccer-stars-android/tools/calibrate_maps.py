#!/usr/bin/env python3
"""Extract HSV color profiles from Soccer Stars field/puck/ball textures."""

from __future__ import annotations

import colorsys
import json
import re
import statistics
from pathlib import Path

UNPACK = Path(__file__).resolve().parents[2] / "game-analysis/base/assets/unpack"
OUT = Path(__file__).resolve().parents[1] / "app/src/main/assets/map_profiles.json"


def rgb_to_hsv(r: int, g: int, b: int) -> tuple[float, float, float]:
    h, s, v = colorsys.rgb_to_hsv(r / 255.0, g / 255.0, b / 255.0)
    return h * 360.0, s, v


def sample_image(path: Path, center_frac: float = 0.6) -> list[tuple[float, float, float]]:
    from PIL import Image

    img = Image.open(path).convert("RGB")
    w, h = img.size
    left = int(w * (1 - center_frac) / 2)
    right = int(w * (1 + center_frac) / 2)
    top = int(h * (1 - center_frac) / 2)
    bottom = int(h * (1 + center_frac) / 2)
    step = max(2, min(w, h) // 80)
    samples: list[tuple[float, float, float]] = []
    for y in range(top, bottom, step):
        for x in range(left, right, step):
            r, g, b = img.getpixel((x, y))
            samples.append(rgb_to_hsv(r, g, b))
    return samples


def stats(values: list[float]) -> dict:
    if not values:
        return {"min": 0, "max": 0, "median": 0, "p10": 0, "p90": 0}
    values_sorted = sorted(values)
    n = len(values_sorted)

    def pct(p: float) -> float:
        idx = int(p * (n - 1))
        return values_sorted[idx]

    return {
        "min": round(min(values), 4),
        "max": round(max(values), 4),
        "median": round(statistics.median(values), 4),
        "p10": round(pct(0.10), 4),
        "p90": round(pct(0.90), 4),
    }


def analyze_samples(samples: list[tuple[float, float, float]]) -> dict:
    hs = [s[0] for s in samples]
    ss = [s[1] for s in samples]
    vs = [s[2] for s in samples]
    return {"hue": stats(hs), "sat": stats(ss), "val": stats(vs)}


def classify_field(name: str, analysis: dict) -> str:
    h = analysis["hue"]["median"]
    s = analysis["sat"]["median"]
    v = analysis["val"]["median"]
    lower = name.lower()
    if "ice" in lower:
        return "ice"
    if "cyber" in lower or "matrix" in lower or "hologram" in lower:
        return "cyber"
    if "street" in lower or "favela" in lower or "neon" in lower:
        return "street"
    if "golden" in lower or "gold" in lower:
        return "gold"
    if "fall" in lower or "crown" in lower:
        return "arena"
    if h >= 55:
        return "green"
    if 20 <= h < 55 and s >= 0.12:
        return "yellow_brown"
    if v < 0.35:
        return "dark"
    if s < 0.15:
        return "neutral"
    return "other"


def is_field_texture(path: Path) -> bool:
    name = path.name.lower()
    if not name.endswith(".png"):
        return False
    if "field" not in name:
        return False
    skip = ("shadow", "goals", "menu", "node", "fx", "particle")
    return not any(s in name for s in skip)


def main() -> None:
    field_files = sorted(p for p in UNPACK.glob("**/*") if is_field_texture(p))
    puck_files = sorted(UNPACK.glob("**/standard_*_puck*.png"))
    ball_files = sorted(UNPACK.glob("**/ball0*.png"))

    fields: list[dict] = []
    families: dict[str, list[dict]] = {}

    for path in field_files:
        try:
            samples = sample_image(path)
            if len(samples) < 20:
                continue
            analysis = analyze_samples(samples)
            family = classify_field(path.stem, analysis)
            entry = {
                "name": path.stem,
                "family": family,
                "file": path.name,
                "hue": analysis["hue"],
                "sat": analysis["sat"],
                "val": analysis["val"],
            }
            fields.append(entry)
            families.setdefault(family, []).append(analysis)
        except Exception as exc:
            print(f"skip {path.name}: {exc}")

    def merge_family(items: list[dict]) -> dict:
        hues = [i["hue"]["median"] for i in items]
        sats = [i["sat"]["p10"] for i in items]
        vals = [i["val"]["p10"] for i in items]
        hue_min = min(i["hue"]["p10"] for i in items)
        hue_max = max(i["hue"]["p90"] for i in items)
        return {
            "count": len(items),
            "hueMin": round(hue_min, 2),
            "hueMax": round(hue_max, 2),
            "satMin": round(min(sats), 4),
            "valMin": round(min(vals), 4),
            "hueMedian": round(statistics.median(hues), 2),
        }

    family_profiles = {k: merge_family(v) for k, v in families.items() if k not in ("dark", "other")}

    # Clean detection profiles for runtime vision (exclude dark/menu textures)
    turf_profiles = []
    profile_defs = {
        "green": ("زمین سبز (Brazil)", 75, 140, 0.20, 0.15),
        "yellow_brown": ("زمین زرد/قهوه‌ای (England)", 20, 78, 0.10, 0.18),
        "gold": ("زمین طلایی", 35, 100, 0.08, 0.12),
        "ice": ("زمین یخی", 170, 220, 0.05, 0.35),
        "cyber": ("زمین سایبری", 110, 240, 0.20, 0.12),
        "street": ("زمین خیابانی", 25, 250, 0.08, 0.18),
        "arena": ("زمین آرنا", 20, 250, 0.20, 0.25),
    }
    for family_id, (label, h_min, h_max, s_min, v_min) in profile_defs.items():
        if family_id in family_profiles:
            fp = family_profiles[family_id]
            turf_profiles.append({
                "id": family_id,
                "label": label,
                "hueMin": round(min(h_min, fp["hueMin"]), 2),
                "hueMax": round(max(h_max, fp["hueMax"]), 2),
                "satMin": round(min(s_min, fp["satMin"]), 4),
                "valMin": round(min(v_min, fp["valMin"]), 4),
                "mapCount": fp["count"],
            })

    # Named reference maps from APK textures
    named_maps = {}
    for ref in ("BrazilField-hd", "EnglandField-hd", "BerlinField-hd", "IceBronzeField-hd",
                "EuroBronzeField-hd", "AllInField-hd", "TrickshotHeroesField-hd", "StreetMastersField-hd"):
        match = next((f for f in fields if f["name"] == ref), None)
        if match:
            named_maps[ref.replace("-hd", "")] = {
                "family": match["family"],
                "hueMedian": match["hue"]["median"],
                "satMedian": match["sat"]["median"],
                "valMedian": match["val"]["median"],
            }

    puck_stats = []
    for path in puck_files[:6]:
        samples = sample_image(path, 0.5)
        puck_stats.append({"name": path.stem, **analyze_samples(samples)})

    ball_stats = []
    for path in ball_files[:4]:
        samples = sample_image(path, 0.7)
        ball_stats.append({"name": path.stem, **analyze_samples(samples)})

    # Global merged turf thresholds from all fields
    all_hue_min = min(f["hue"]["p10"] for f in fields)
    all_hue_max = max(f["hue"]["p90"] for f in fields)
    all_sat_min = min(f["sat"]["p10"] for f in fields)
    all_val_min = min(f["val"]["p10"] for f in fields)

    output = {
        "version": 1,
        "source": "game-analysis/base/assets/unpack",
        "fieldCount": len(fields),
        "globalTurf": {
            "hueMin": round(all_hue_min, 2),
            "hueMax": round(all_hue_max, 2),
            "satMin": round(max(0.08, all_sat_min), 4),
            "valMin": round(max(0.12, all_val_min), 4),
        },
        "turfProfiles": turf_profiles,
        "namedMaps": named_maps,
        "families": family_profiles,
        "fields": fields,
        "pucks": puck_stats,
        "balls": ball_stats,
    }

    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(output, indent=2), encoding="utf-8")
    print(f"Wrote {OUT} ({len(fields)} fields, {len(family_profiles)} families)")


if __name__ == "__main__":
    main()
