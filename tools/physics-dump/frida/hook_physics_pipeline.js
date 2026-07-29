/**
 * Professional Frida hook for Soccer Stars physics / shot pipeline.
 * Targets: GNU ObjC runtime embedded in libgame-SSM (Android iOS-port).
 *
 * Prerequisites: rooted phone, frida-server, game in a match.
 *
 * Usage:
 *   frida -U com.miniclip.soccerstars -l hook_physics_pipeline.js
 *
 * REPL commands (rpc):
 *   rpc.enableDebug()     — turn on sPhysicsDebugEnabled
 *   rpc.listSelectors()   — show registered shot-related selectors
 *   rpc.readExports()     — read physics debug globals
 */

const LIB = "libgame-SSM-GooglePlay-Gold-Release-Module-1013.so";

const WATCH_SELECTORS = [
  "takeActualShot:angle:spin:playSound:",
  "calculateShot:power:angle:spin:",
  "calculateFinalPower:forBall:",
  "simulateAndSendShot:power:angle:",
  "aimTo:angle:power:",
  "commitShot",
  "stopAllPhysics",
  "getBallBallCollision:ballB:time:force:",
  "shot_taken",
  "puck_state",
];

const selectorRegistry = {};

function getLib() {
  return Process.findModuleByName(LIB);
}

function hookExports() {
  const names = ["sPhysicsDebugEnabled", "sPhysicsDebugDiagnostics", "sInternalVelocity"];
  const result = {};
  names.forEach((name) => {
    const addr = Module.findExportByName(LIB, name);
    if (!addr) {
      console.log(`[-] export missing: ${name}`);
      return;
    }
    result[name] = addr.toString();
    console.log(`[+] ${name} @ ${addr}`);
  });
  return result;
}

function enablePhysicsDebug() {
  const en = Module.findExportByName(LIB, "sPhysicsDebugEnabled");
  const diag = Module.findExportByName(LIB, "sPhysicsDebugDiagnostics");
  if (en) Memory.writeU8(en, 1);
  if (diag) Memory.writeU8(diag, 1);
  console.log("[+] Physics debug flags set to 1");
  return { sPhysicsDebugEnabled: en ? 1 : null, sPhysicsDebugDiagnostics: diag ? 1 : null };
}

function readExports() {
  const out = {};
  ["sPhysicsDebugEnabled", "sPhysicsDebugDiagnostics", "sInternalVelocity"].forEach((name) => {
    const addr = Module.findExportByName(LIB, name);
    if (!addr) return;
    try {
      if (name === "sInternalVelocity") {
        out[name] = Memory.readFloat(addr);
      } else {
        out[name] = Memory.readU8(addr);
      }
    } catch (e) {
      out[name] = "error";
    }
  });
  return out;
}

function hookSelRegisterName() {
  const addr = Module.findExportByName(LIB, "sel_registerName");
  if (!addr) {
    console.log("[-] sel_registerName not exported");
    return;
  }

  Interceptor.attach(addr, {
    onEnter(args) {
      try {
        const name = Memory.readCString(args[0]);
        if (!name) return;
        if (WATCH_SELECTORS.some((s) => name.indexOf(s) !== -1 || name === s)) {
          selectorRegistry[name] = selectorRegistry[name] || { count: 0, last: null };
          selectorRegistry[name].count += 1;
          selectorRegistry[name].last = Date.now();
          console.log(`[sel] ${name}`);
        }
      } catch (e) {}
    },
  });
  console.log(`[+] Hooked sel_registerName @ ${addr}`);
}

function hookMethodGetImplementation() {
  const addr = Module.findExportByName(LIB, "method_getImplementation");
  if (!addr) return;

  const hooked = new Set();

  Interceptor.attach(addr, {
    onLeave(retval) {
      // retval is IMP — we log when called from our watched selectors via pairing is hard;
      // instead log non-null IMP returns during match for manual correlation.
    },
  });
}

function hookShotLogging() {
  // Hook method_setImplementation / exchange if we find class+selector at runtime via scan
  const setImp = Module.findExportByName(LIB, "method_setImplementation");
  if (setImp) {
    Interceptor.attach(setImp, {
      onEnter(args) {
        try {
          const getName = Module.findExportByName(LIB, "method_getName");
          if (!getName) return;
          const namePtr = new NativeFunction(getName, "pointer", ["pointer"])(args[0]);
          const name = Memory.readCString(namePtr);
          if (name && WATCH_SELECTORS.indexOf(name) !== -1) {
            console.log(`[method_setImplementation] ${name} IMP=${args[1]}`);
          }
        } catch (e) {}
      },
    });
    console.log(`[+] Hooked method_setImplementation`);
  }
}

function main() {
  const lib = getLib();
  if (!lib) {
    console.log("[!] Library not loaded. Open Soccer Stars and enter a match.");
    setInterval(() => {
      if (getLib()) {
        console.log("[+] Library loaded, initializing hooks...");
        main();
      }
    }, 1500);
    return;
  }

  console.log(`[+] ${LIB} base=${lib.base} size=${lib.size}`);
  hookExports();
  hookSelRegisterName();
  hookShotLogging();
  console.log("[+] Ready. Drag puck to shoot — watch [sel] and export logs.");
  console.log("[+] REPL: rpc.enableDebug(), rpc.readExports(), rpc.listSelectors()");
}

rpc.exports = {
  enableDebug: enablePhysicsDebug,
  readExports,
  listSelectors: () => selectorRegistry,
  libBase: () => {
    const lib = getLib();
    return lib ? lib.base.toString() : null;
  },
};

setImmediate(main);
