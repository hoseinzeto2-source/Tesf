#pragma once

#include <cstddef>

void logRingInit();
void logRingAppend(const char* msg);
void logRingGet(char* out, size_t cap);
