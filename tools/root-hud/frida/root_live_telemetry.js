/**
 * Root-only live telemetry for Soccer Stars (read-only, no shot injection).
 *
 * Usage (phone rooted, frida-server running, game open in match):
 *   frida -U com.miniclip.soccerstars -l frida/root_live_telemetry.js
 *
 * REPL:
 *   rpc.snapshot()
 *   rpc.enablePhysicsDebug()
 *   rpc.startPolling(2000)   // ms
 *   rpc.stopPolling()
 */

const LIB = "libgame-SSM-GooglePlay-Gold-Release-Module-1013.so";
const PKG = "com.miniclip.soccerstars";

let pollTimer = null;
let selectorLog = {};

function getLib() {
  return Process.findModuleByName(LIB);
}

function isOnPitch(x, y) {
  return x > 0.03 && x < 0.97 && y > 0.06 && y < 0.94;
}

function scanBodies(max = 14) {
  const bodies = [];
  const ranges = Process.enumerateRangesSync({ protection: "rw-", coalesce: false });

  for (const range of ranges) {
    if (range.size < 64 || range.size > 8 * 1024 * 1024) continue;
    try {
      for (let off = 0; off < range.size - 16; off += 4) {
        const x = Memory.readFloat(range.base.add(off));
        const y = Memory.readFloat(range.base.add(off + 4));
        if (!isOnPitch(x, y)) continue;

        const vx = Memory.readFloat(range.base.add(off + 8));
        const vy = Memory.readFloat(range.base.add(off + 12));
        const speed = Math.sqrt(vx * vx + vy * vy);
        if (speed > 6.0) continue;

        bodies.push({
          x: round(x),
          y: round(y),
          vx: round(vx),
          vy: round(vy),
          speed: round(speed),
        });
        if (bodies.length >= max * 4) break;
      }
    } catch (e) {}
    if (bodies.length >= max * 4) break;
  }

  const unique = [];
  for (const b of bodies) {
    const dup = unique.find(
      (u) => Math.abs(u.x - b.x) < 0.025 && Math.abs(u.y - b.y) < 0.025,
    );
    if (!dup) unique.push(b);
  }
  return unique.slice(0, max);
}

function round(v) {
  return Math.round(v * 10000) / 10000;
}

function readExports() {
  const out = {};
  ["sPhysicsDebugEnabled", "sPhysicsDebugDiagnostics", "sInternalVelocity"].forEach((name) => {
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

function enablePhysicsDebug() {
  const en = Module.findExportByName(LIB, "sPhysicsDebugEnabled");
  const diag = Module.findExportByName(LIB, "sPhysicsDebugDiagnostics");
  if (en) Memory.writeU8(en, 1);
  if (diag) Memory.writeU8(diag, 1);
  return { enabled: true };
}

function hookSelRegister() {
  const addr = Module.findExportByName(LIB, "sel_registerName");
  if (!addr) return;
  const watch = [
    "takeActualShot",
    "shot_taken",
    "ballPositionInPoints",
    "stopAllPhysics",
    "setScaleX",
    "setScaleY",
  ];
  Interceptor.attach(addr, {
    onEnter(args) {
      try {
        const name = Memory.readCString(args[0]);
        if (!name) return;
        for (const w of watch) {
          if (name.indexOf(w) !== -1) {
            selectorLog[name] = (selectorLog[name] || 0) + 1;
          }
        }
      } catch (e) {}
    },
  });
  console.log("[+] sel_registerName hooked (read-only log)");
}

function snapshot() {
  const lib = getLib();
  if (!lib) {
    return { error: "lib not loaded — enter a match first", package: PKG };
  }

  const bodies = scanBodies(12);
  const data = {
    time: Date.now(),
    package: PKG,
    lib_base: lib.base.toString(),
    lib_size: lib.size,
    coordinate_system: "normalized XY (0-1), no physics Z",
    physics_exports: readExports(),
    bodies,
    body_count: bodies.length,
    selectors_seen: selectorLog,
    notes: [
      "read-only — no shot injection",
      "online scores authoritative on server shot_outcome",
      "squash = visual scaleX/Y on VisualPuck, not in protobuf",
    ],
  };

  console.log(JSON.stringify(data, null, 2));
  return data;
}

function startPolling(ms) {
  stopPolling();
  const interval = ms || 2000;
  pollTimer = setInterval(() => {
    console.log("--- poll ---");
    snapshot();
  }, interval);
  console.log(`[+] polling every ${interval}ms`);
}

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
    console.log("[+] polling stopped");
  }
}

function main() {
  const lib = getLib();
  if (!lib) {
    console.log("[!] Open Soccer Stars and enter a match, then re-run or wait...");
    setInterval(() => {
      if (getLib()) {
        console.log("[+] lib loaded");
        hookSelRegister();
      }
    }, 1500);
    return;
  }
  console.log(`[+] ${LIB} base=${lib.base}`);
  hookSelRegister();
  console.log("[+] rpc.snapshot() | rpc.startPolling(2000) | rpc.enablePhysicsDebug()");
}

rpc.exports = {
  snapshot,
  startPolling,
  stopPolling,
  enablePhysicsDebug,
  readExports,
  listSelectors: () => selectorLog,
};

setImmediate(main);
