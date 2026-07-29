/**
 * Hook ObjC-style selectors inside Soccer Stars native lib.
 * The game embeds a custom ObjC runtime (iOS port). We scan the module
 * for the selector string and log when nearby shot methods fire.
 *
 * Usage:
 *   frida -U com.miniclip.soccerstars -l hook_shots.js
 */

const LIB_NAME = "libgame-SSM-GooglePlay-Gold-Release-Module-1013.so";
const SELECTORS = [
  "takeActualShot:angle:spin:playSound:",
  "playShot:",
  "stopAllPhysics",
  "getBallBallCollision:ballB:time:force:",
];

function scanSelectors(lib) {
  SELECTORS.forEach((sel) => {
    const matches = Memory.scanSync(lib.base, lib.size, sel.split("").map(c => c.charCodeAt(0).toString(16).padStart(2, "0")).join(" "));
    // Memory.scanSync needs hex pattern - use string scan via enumerateRanges
    const ranges = Process.enumerateRangesSync({ protection: "r--", coalesce: true })
      .filter((r) => r.file && r.file.path && r.file.path.indexOf(LIB_NAME) !== -1);

    let found = null;
  for (const range of ranges) {
      try {
        const hits = Memory.scanSync(range.base, range.size, stringToPattern(sel));
        if (hits.length > 0) {
          found = hits[0].address;
          break;
        }
      } catch (e) {}
    }

    if (found) {
      console.log(`[+] Selector '${sel}' string @ ${found}`);
    } else {
      console.log(`[-] Selector '${sel}' not found in read-only ranges`);
    }
  });
}

function stringToPattern(str) {
  return str.split("").map((c) => c.charCodeAt(0).toString(16).padStart(2, "0")).join(" ");
}

function hookLoggingExports() {
  const names = [
    "sPhysicsDebugEnabled",
    "sPhysicsDebugDiagnostics",
    "sInternalVelocity",
  ];
  names.forEach((name) => {
    const addr = Module.findExportByName(LIB_NAME, name);
    if (addr) console.log(`[export] ${name} @ ${addr}`);
  });
}

function main() {
  const lib = Process.findModuleByName(LIB_NAME);
  if (!lib) {
    console.log("[!] Open Soccer Stars first (in a match is best).");
    return;
  }

  console.log(`[+] Attached to ${LIB_NAME}`);
  hookLoggingExports();
  scanSelectors(lib);

  console.log("");
  console.log("Next step for deeper hooks:");
  console.log("  1) Pull lib with scripts/pull_lib.sh");
  console.log("  2) Open lib in Ghidra");
  console.log("  3) Search string: takeActualShot:angle:spin:playSound:");
  console.log("  4) Find xrefs -> function address -> hook in Frida with Interceptor.attach(base.add(OFFSET))");
}

setImmediate(main);
