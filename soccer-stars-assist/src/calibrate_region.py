#!/usr/bin/env python3
"""Pick the game window region for Soccer Stars assist overlay."""

from __future__ import annotations

import json
from pathlib import Path

import cv2
import mss
import numpy as np


def main() -> None:
    print("یک مستطیل روی پنجره بازی بکشید و Enter بزنید.")
    with mss.mss() as sct:
        monitor = sct.monitors[1]
        shot = sct.grab(monitor)
        frame = np.array(shot)[:, :, :3]
        frame_bgr = cv2.cvtColor(frame, cv2.COLOR_BGRA2BGR)

    roi = cv2.selectROI("Select Soccer Stars Window", frame_bgr, fromCenter=False, showCrosshair=True)
    cv2.destroyAllWindows()
    x, y, w, h = roi
    if w <= 0 or h <= 0:
        raise SystemExit("ناحیه انتخاب نشد.")

    region = {
        "left": int(monitor["left"] + x),
        "top": int(monitor["top"] + y),
        "width": int(w),
        "height": int(h),
    }

    config_path = Path(__file__).resolve().parent.parent / "config" / "default.json"
    config = json.loads(config_path.read_text(encoding="utf-8"))
    config["capture"]["region"] = region
    config_path.write_text(json.dumps(config, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print("ذخیره شد:", region)


if __name__ == "__main__":
    main()
