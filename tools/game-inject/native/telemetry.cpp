#include "telemetry.h"

HudStatus buildHudStatus(int displayW, int displayH, bool eglHooked, int swapFrames) {
    HudStatus s;
    s.display_w = displayW;
    s.display_h = displayH;
    s.swap_frames = swapFrames;
    s.egl_hooked = eglHooked;
    return s;
}
