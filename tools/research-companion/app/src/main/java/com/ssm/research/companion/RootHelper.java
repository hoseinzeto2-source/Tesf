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
                + "; stop adbd; start adbd; getprop service.adb.tcp.port";
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
