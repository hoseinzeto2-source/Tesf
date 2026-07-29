#pragma once

void installTouchHooks();
void pollTouchHooks();
void touchApplyPendingEvents();
void hudSetDisplaySize(float w, float h);
void hudUpdateWindowRect(float min_x, float min_y, float max_x, float max_y);
bool touchHooksInstalled();
