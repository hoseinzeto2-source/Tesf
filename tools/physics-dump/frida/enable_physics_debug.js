/**
 * Frida script for rooted Android + Soccer Stars
 *
 * Usage (PC):
 *   frida -U -f com.miniclip.soccerstars -l enable_physics_debug.js --no-pause
 *
 * Or attach while game is running:
 *   frida -U com.miniclip.soccerstars -l enable_physics_debug.js
 */

const LIB_NAME = "libgame-SSM-GooglePlay-Gold-Release-Module-1013.so";

function findLib() {
  const lib = Process.findModuleByName(LIB_NAME);
  if (!lib) {
    console.log("[!] Library not loaded yet. Open a match, then re-run or wait...");
    return null;
  }
  return lib;
}

function writeExportByte(name, value) {
  const addr = Module.findExportByName(LIB_NAME, name);
  if (!addr) {
    console.log(`[!] Export not found: ${name}`);
    return;
  }
  Memory.writeU8(addr, value);
  console.log(`[+] ${name} = ${value} @ ${addr}`);
}

function hookExportRead(name, label) {
  const addr = Module.findExportByName(LIB_NAME, name);
  if (!addr) return;

  let last = -1;
  setInterval(() => {
    try {
      const size = name === "sInternalVelocity" ? 4 : 1;
      const val = size === 4 ? Memory.readFloat(addr) : Memory.readU8(addr);
      if (val !== last) {
        console.log(`[${label}] ${name} = ${val}`);
        last = val;
      }
    } catch (e) {}
  }, 200);
}

function main() {
  const lib = findLib();
  if (!lib) {
    setInterval(() => {
      if (findLib()) main();
    }, 1000);
    return;
  }

  console.log(`[+] ${LIB_NAME} base=${lib.base} size=${lib.size}`);

  // These globals exist in the official 36.14.4 build (exported symbols).
  writeExportByte("sPhysicsDebugEnabled", 1);
  writeExportByte("sPhysicsDebugDiagnostics", 1);

  hookExportRead("sPhysicsDebugEnabled", "debug");
  hookExportRead("sInternalVelocity", "velocity");

  console.log("[+] Physics debug enabled. Play a match and watch logcat/Frida output.");
  console.log("[+] If debug vectors appear in-game, screenshot them for calibration.");
}

setImmediate(main);
