#pragma once

#include <cstddef>
#include <cstdint>

void refreshReadableMaps();
bool isReadable(const void* ptr, size_t len);
bool isWritable(const void* ptr, size_t len);
bool safeRead(const void* src, void* dst, size_t len);
bool safeWrite(void* dst, const void* src, size_t len);
