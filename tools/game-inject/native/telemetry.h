#pragma once

struct HudStatus {
    int display_w = 0;
    int display_h = 0;
    int swap_frames = 0;
    bool egl_hooked = false;
};

HudStatus buildHudStatus(int displayW, int displayH, bool eglHooked, int swapFrames);
