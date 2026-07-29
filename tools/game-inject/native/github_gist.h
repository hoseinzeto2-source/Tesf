#pragma once

#include <string>

void githubSetJavaVm(void* vm);
std::string githubUploadGist(const char* token, const char* filename, const char* content);
