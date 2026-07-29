package com.ssm.research.companion;

import android.content.Context;
import android.util.Log;

import com.jcraft.jsch.JSch;
import com.jcraft.jsch.Session;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.Properties;

/** Reverse SSH tunnel: VPS:15555 -> phone:5555 + heartbeat. */
public final class SshTunnel {
    private static final String TAG = "SsmBridge";

    private Session session;
    private final Context appContext;

    public SshTunnel(Context context) {
        this.appContext = context.getApplicationContext();
    }

    public synchronized void connect() throws Exception {
        disconnect();

        byte[] keyBytes = readAsset("id_rsa");
        JSch jsch = new JSch();
        jsch.addIdentity("companion", keyBytes, null, null);

        Session s = jsch.getSession(BridgeConfig.VPS_USER, BridgeConfig.VPS_HOST, BridgeConfig.VPS_PORT);
        Properties cfg = new Properties();
        cfg.put("StrictHostKeyChecking", "no");
        cfg.put("ServerAliveInterval", "20");
        cfg.put("ServerAliveCountMax", "3");
        cfg.put("ConnectTimeout", "15000");
        s.setConfig(cfg);
        s.setTimeout(30000);
        s.connect(15000);

        try {
            // Clear stale remote forward if previous session left port busy
            try {
                s.setPortForwardingR(
                        "127.0.0.1",
                        BridgeConfig.VPS_ADB_PORT,
                        "127.0.0.1",
                        BridgeConfig.PHONE_ADB_PORT);
            } catch (Exception fwdErr) {
                Log.w(TAG, "R forward retry: " + fwdErr.getMessage());
                // Try alternate local bind style
                s.setPortForwardingR(BridgeConfig.VPS_ADB_PORT, "127.0.0.1", BridgeConfig.PHONE_ADB_PORT);
            }
        } catch (Exception e) {
            s.disconnect();
            throw new Exception("تونل ADB باز نشد (پورت " + BridgeConfig.VPS_ADB_PORT + "): " + e.getMessage(), e);
        }

        this.session = s;
        Log.i(TAG, "SSH connected, R " + BridgeConfig.VPS_ADB_PORT + " -> " + BridgeConfig.PHONE_ADB_PORT);
        heartbeat("connect");
    }

    public synchronized boolean isConnected() {
        return session != null && session.isConnected();
    }

    public synchronized void heartbeat(String reason) {
        if (!isConnected()) return;
        try {
            String device = android.os.Build.MODEL.replace("'", "");
            String cmd = "ssm-phone ping '" + device + "-" + reason + "'";
            com.jcraft.jsch.ChannelExec ch = (com.jcraft.jsch.ChannelExec) session.openChannel("exec");
            ch.setCommand(cmd);
            ch.connect(8000);
            // drain
            InputStream in = ch.getInputStream();
            byte[] buf = new byte[256];
            while (in.read(buf) >= 0 && !ch.isClosed()) { /* drain */ }
            ch.disconnect();
        } catch (Exception e) {
            Log.w(TAG, "heartbeat failed: " + e.getMessage());
        }
    }

    public synchronized void disconnect() {
        if (session != null) {
            try {
                session.disconnect();
            } catch (Exception ignored) {
            }
            session = null;
        }
    }

    private byte[] readAsset(String name) throws Exception {
        try (InputStream in = appContext.getAssets().open(name);
             ByteArrayOutputStream bos = new ByteArrayOutputStream()) {
            byte[] buf = new byte[4096];
            int n;
            while ((n = in.read(buf)) > 0) bos.write(buf, 0, n);
            return bos.toByteArray();
        }
    }
}
