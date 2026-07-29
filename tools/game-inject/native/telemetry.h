#pragma once
#include <string>
#include <vector>

struct BodySample {
    float x, y, vx, vy, speed;
};

std::vector<BodySample> scanBodies(int maxBodies);
float readInternalVelocity();
int readPhysicsDebug();
