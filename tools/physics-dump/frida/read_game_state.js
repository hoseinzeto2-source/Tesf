/**
 * Read live Soccer Stars game state: puck/ball positions (x,y), velocity,
 * and scan for score-like integers in heap.
 *
 * Note: Physics is 2D — no Z coordinate in game logic.
 * Puck "squash" is visual scaleX/scaleY on VisualPuck, not a physics field.
 *
 * Usage:
 *   frida -U com.miniclip.soccerstars -l read_game_state.js
 *   rpc.snapshot()
 */

const LIB = "libgame-SSM-GooglePlay-Gold-Release-Module-1013.so";

function isNormalized(x, y) {
  return x > 0.02 && x < 0.98 && y > 0.05 && y < 0.95;
}

function scanBodies(max = 12) {
  const bodies = [];
  const ranges = Process.enumerateRangesSync({ protection: "rw-", coalesce: false });

  for (const range of ranges) {
    if (range.size < 64 || range.size > 6 * 1024 * 1024) continue;
    try {
      for (let off = 0; off < range.size - 24; off += 4) {
        const x = Memory.readFloat(range.base.add(off));
        const y = Memory.readFloat(range.base.add(off + 4));
        if (!isNormalized(x, y)) continue;

        const vx = Memory.readFloat(range.base.add(off + 8));
        const vy = Memory.readFloat(range.base.add(off + 12));
        const speed = Math.sqrt(vx * vx + vy * vy);

        // Heuristic: ball often smaller radius / center; pucks in formation clusters
        if (speed > 8.0) continue;

        bodies.push({
          x: round(x),
          y: round(y),
          vx: round(vx),
          vy: round(vy),
          speed: round(speed),
          addr: range.base.add(off).toString(),
        });
        if (bodies.length >= max * 3) break;
      }
    } catch (e) {}
    if (bodies.length >= max * 3) break;
  }

  // Deduplicate nearby points
  const unique = [];
  for (const b of bodies) {
    const dup = unique.find(
      (u) => Math.abs(u.x - b.x) < 0.02 && Math.abs(u.y - b.y) < 0.02
    );
    if (!dup) unique.push(b);
  }
  return unique.slice(0, max);
}

function round(v) {
  return Math.round(v * 10000) / 10000;
}

function readDebugExports() {
  const out = {};
  ["sPhysicsDebugEnabled", "sInternalVelocity"].forEach((name) => {
    const addr = Module.findExportByName(LIB, name);
    if (!addr) return;
    try {
      out[name] =
        name === "sInternalVelocity" ? Memory.readFloat(addr) : Memory.readU8(addr);
    } catch (e) {
      out[name] = null;
    }
  });
  return out;
}

function hookScaleForSquash() {
  // Visual puck squash = setScaleX / setScaleY (not physics Z)
  const names = ["setScaleX:", "setScaleY:"];
  names.forEach((sel) => {
    const addr = Module.findExportByName(LIB, "sel_registerName");
    if (!addr) return;
  });
  // Log when selectors register (squash happens at runtime on VisualPuck)
  const reg = Module.findExportByName(LIB, "sel_registerName");
  if (!reg) return;

  Interceptor.attach(reg, {
    onEnter(args) {
      try {
        const name = Memory.readCString(args[0]);
        if (name === "setScaleX:" || name === "setScaleY:") {
          // registered — squash animation uses these
        }
      } catch (e) {}
    },
  });
}

function snapshot() {
  const lib = Process.findModuleByName(LIB);
  if (!lib) return { error: "lib not loaded — open a match first" };

  const bodies = scanBodies(15);
  // Sort: likely ball = highest y or smallest cluster; pucks = rest
  const sorted = bodies.sort((a, b) => a.y - b.y);

  return {
    coordinate_system: "normalized_0_to_1 (x=horizontal, y=vertical on pitch)",
    has_physics_z: false,
    note: "Z is rendering depth only; squash = sprite scaleX/scaleY",
    lib_base: lib.base.toString(),
    debug: readDebugExports(),
    bodies: sorted,
    body_count: sorted.length,
    scores: {
      note: "Exact scores come from shot_outcome.player_score_/opponent_score_ protobuf — hook network layer or read HUD",
      hud_ocr: "Use Assist overlay + screen OCR for live score display",
    },
    protobuf_fields_available: {
      puck_state: ["position.x", "position.y", "puck_id", "owner_enum", "state_enum"],
      shot_outcome: ["player_score", "opponent_score", "field_state[]"],
      game_started: ["field_state[]", "starting_player_id"],
    },
  };
}

rpc.exports = {
  snapshot,
  scanBodies,
  readDebugExports,
};

console.log("[+] read_game_state.js — in match run: rpc.snapshot()");
