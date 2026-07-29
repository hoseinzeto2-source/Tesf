package com.ssm.research.companion;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.util.concurrent.TimeUnit;

public final class RootHelper {
    private RootHelper() {}

    public static Result runSu(String command) {
        try {
            Process p = Runtime.getRuntime().exec(new String[]{"su", "-c", command});
            boolean finished = p.waitFor(12, TimeUnit.SECONDS);
            if (!finished) {
                p.destroyForcibly();
                return Result.fail("su timeout");
            }
            String out = readAll(p.getInputStream());
            String err = readAll(p.getErrorStream());
            int code = p.exitValue();
            if (code != 0) return Result.fail("code=" + code + " " + err + out);
            return Result.ok(out.trim());
        } catch (Exception e) {
            return Result.fail(e.getMessage() == null ? "su failed" : e.getMessage());
        }
    }

    public static Result enableAdbTcp(int port) {
        String cmd = "setprop service.adb.tcp.port " + port
                + "; (command -v resetprop >/dev/null && resetprop ro.adb.secure 0; resetprop persist.adb.secure 0) || true"
                + "; setprop ro.adb.secure 0 || true"
                + "; setprop persist.adb.secure 0 || true"
                + "; stop adbd; start adbd; getprop service.adb.tcp.port";
        return runSu(cmd);
    }

    /** Install VPS adb pubkey so Agent is authorized without on-screen prompt. */
    public static Result installAdbKey(String pubLine) {
        if (pubLine == null || pubLine.trim().isEmpty()) return Result.fail("empty key");
        String key = pubLine.trim().replace("'", "");
        // Write to both classic and newer paths used across OEMs
        String cmd = "mkdir -p /data/misc/adb /data/adb;"
                + " touch /data/misc/adb/adb_keys;"
                + " grep -qxF '" + key + "' /data/misc/adb/adb_keys || echo '" + key + "' >> /data/misc/adb/adb_keys;"
                + " chmod 0640 /data/misc/adb/adb_keys;"
                + " chown system:shell /data/misc/adb/adb_keys 2>/dev/null || chown system:system /data/misc/adb/adb_keys;"
                + " restorecon /data/misc/adb/adb_keys 2>/dev/null || true;"
                + " (command -v resetprop >/dev/null && resetprop ro.adb.secure 0; resetprop persist.adb.secure 0) || true;"
                + " setprop ro.adb.secure 0 || true;"
                + " stop adbd; start adbd;"
                + " wc -l /data/misc/adb/adb_keys";
        return runSu(cmd);
    }

    public static boolean hasSu() {
        Result r = runSu("id");
        return r.ok && r.output.contains("uid=0");
    }

    private static String readAll(java.io.InputStream in) throws Exception {
        StringBuilder sb = new StringBuilder();
        try (BufferedReader br = new BufferedReader(new InputStreamReader(in))) {
            String line;
            while ((line = br.readLine()) != null) {
                if (sb.length() > 0) sb.append('\n');
                sb.append(line);
            }
        }
        return sb.toString();
    }

    public static final class Result {
        public final boolean ok;
        public final String output;

        private Result(boolean ok, String output) {
            this.ok = ok;
            this.output = output == null ? "" : output;
        }

        static Result ok(String o) { return new Result(true, o); }
        static Result fail(String o) { return new Result(false, o); }
    }
}
