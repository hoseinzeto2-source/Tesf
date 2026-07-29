/**
 * Logs candidate shot parameters by intercepting protobuf field writes is hard;
 * this script instead scans for live puck/ball-like float clusters in the
 * game library heap when you call rpc.dumpState() from Frida REPL.
 *
 * Usage:
 *   frida -U com.miniclip.soccerstars -l scan_live_state.js
 *   %resume
 *   // in match, while aiming:
 *   rpc.dumpState()
 */

const LIB_NAME = "libgame-SSM-GooglePlay-Gold-Release-Module-1013.so";

function isOnPitch(x, y) {
  return x > 0.05 && x < 0.95 && y > 0.1 && y < 0.9;
}

function scanNormalizedBodies() {
  const results = [];
  const ranges = Process.enumerateRangesSync({ protection: "rw-", coalesce: false });

  ranges.forEach((range) => {
    if (range.size < 64 || range.size > 8 * 1024 * 1024) return;
    try {
      for (let off = 0; off < range.size - 16; off += 4) {
        const x = Memory.readFloat(range.base.add(off));
        const y = Memory.readFloat(range.base.add(off + 4));
        if (!isOnPitch(x, y)) continue;

        const vx = Memory.readFloat(range.base.add(off + 8));
        const vy = Memory.readFloat(range.base.add(off + 12));
        const speed = Math.sqrt(vx * vx + vy * vy);
        if (speed > 0.001 && speed < 5.0) {
          results.push({ x, y, vx, vy, speed, addr: range.base.add(off).toString() });
        }
      }
    } catch (e) {}
  });

  return results.slice(0, 40);
}

rpc.exports = {
  dumpState() {
    const lib = Process.findModuleByName(LIB_NAME);
    if (!lib) return { error: "lib not loaded" };

    const bodies = scanNormalizedBodies();
    console.log(JSON.stringify({ bodies }, null, 2));
    return { count: bodies.length, bodies };
  },
};

console.log("[+] scan_live_state loaded. In match, run: rpc.dumpState()");
