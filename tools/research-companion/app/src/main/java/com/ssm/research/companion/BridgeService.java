package com.ssm.research.companion;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Intent;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.util.Log;

import androidx.core.app.NotificationCompat;

import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicBoolean;

public class BridgeService extends Service {
    public static final String ACTION_STATUS = "com.ssm.research.companion.STATUS";
    public static final String EXTRA_STATE = "state";
    public static final String EXTRA_DETAIL = "detail";

    private static final String TAG = "SsmBridge";
    private static final String CHANNEL = "ssm_bridge";
    private static final int NOTIF_ID = 42;

    private final ExecutorService worker = Executors.newSingleThreadExecutor();
    private final Handler main = new Handler(Looper.getMainLooper());
    private final AtomicBoolean running = new AtomicBoolean(false);
    private SshTunnel tunnel;
    private Runnable heartbeatLoop;

    @Override
    public void onCreate() {
        super.onCreate();
        createChannel();
        tunnel = new SshTunnel(this);
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        String action = intent != null ? intent.getAction() : null;
        if ("STOP".equals(action)) {
            stopBridge();
            return START_NOT_STICKY;
        }

        startForeground(NOTIF_ID, buildNotification("در حال اتصال…"));
        if (running.compareAndSet(false, true)) {
            worker.execute(this::connectLoop);
        }
        return START_STICKY;
    }

    private void connectLoop() {
        int backoff = 3;
        while (running.get()) {
            try {
                broadcast("connecting", "SSH → " + BridgeConfig.VPS_HOST);

                RootHelper.Result adb = RootHelper.enableAdbTcp(BridgeConfig.PHONE_ADB_PORT);
                String adbMsg = adb.ok ? ("ADB TCP " + BridgeConfig.PHONE_ADB_PORT + " OK")
                        : ("ADB TCP fail: " + adb.output);

                String keyMsg = "adbkey skip";
                try {
                    try (java.io.InputStream in = getAssets().open("adbkey.pub");
                         java.io.ByteArrayOutputStream bos = new java.io.ByteArrayOutputStream()) {
                        byte[] buf = new byte[1024];
                        int n;
                        while ((n = in.read(buf)) > 0) bos.write(buf, 0, n);
                        String pub = bos.toString("UTF-8").trim();
                        RootHelper.Result kr = RootHelper.installAdbKey(pub);
                        keyMsg = kr.ok ? ("adbkey OK " + kr.output) : ("adbkey fail " + kr.output);
                    }
                } catch (Exception ke) {
                    keyMsg = "adbkey err " + ke.getMessage();
                }

                tunnel.connect();
                tunnel.heartbeat("alive");

                String detail = "Connected\nVPS " + BridgeConfig.VPS_HOST
                        + "\nADB reverse :" + BridgeConfig.VPS_ADB_PORT
                        + " → phone :" + BridgeConfig.PHONE_ADB_PORT
                        + "\n" + adbMsg
                        + "\n" + keyMsg
                        + "\nModel " + Build.MODEL;
                broadcast("connected", detail);
                updateNotification("تونل فعال — Agent می‌تواند وصل شود");

                backoff = 3;
                while (running.get() && tunnel.isConnected()) {
                    try {
                        Thread.sleep(25000);
                    } catch (InterruptedException ie) {
                        break;
                    }
                    tunnel.heartbeat("tick");
                    broadcast("connected", detail + "\nlast beat " + System.currentTimeMillis());
                }
            } catch (Exception e) {
                Log.e(TAG, "tunnel error", e);
                broadcast("error", "خطا: " + e.getMessage());
                updateNotification("قطع شد — تلاش مجدد…");
                tunnel.disconnect();
                try {
                    Thread.sleep(backoff * 1000L);
                } catch (InterruptedException ignored) {
                }
                backoff = Math.min(backoff * 2, 60);
            }
        }
        tunnel.disconnect();
        broadcast("idle", "قطع شد");
        stopForeground(STOP_FOREGROUND_REMOVE);
        stopSelf();
    }

    private void stopBridge() {
        running.set(false);
        worker.execute(() -> {
            tunnel.disconnect();
            broadcast("idle", "قطع دستی");
            stopForeground(STOP_FOREGROUND_REMOVE);
            stopSelf();
        });
    }

    private void broadcast(String state, String detail) {
        Intent i = new Intent(ACTION_STATUS);
        i.setPackage(getPackageName());
        i.putExtra(EXTRA_STATE, state);
        i.putExtra(EXTRA_DETAIL, detail);
        sendBroadcast(i);
    }

    private void createChannel() {
        if (Build.VERSION.SDK_INT < 26) return;
        NotificationChannel ch = new NotificationChannel(
                CHANNEL, getString(R.string.notif_channel), NotificationManager.IMPORTANCE_LOW);
        NotificationManager nm = getSystemService(NotificationManager.class);
        if (nm != null) nm.createNotificationChannel(ch);
    }

    private Notification buildNotification(String text) {
        Intent open = new Intent(this, MainActivity.class);
        PendingIntent pi = PendingIntent.getActivity(
                this, 0, open, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        return new NotificationCompat.Builder(this, CHANNEL)
                .setContentTitle(getString(R.string.notif_title))
                .setContentText(text)
                .setSmallIcon(android.R.drawable.ic_menu_upload)
                .setContentIntent(pi)
                .setOngoing(true)
                .build();
    }

    private void updateNotification(String text) {
        NotificationManager nm = getSystemService(NotificationManager.class);
        if (nm != null) nm.notify(NOTIF_ID, buildNotification(text));
    }

    @Override
    public void onDestroy() {
        running.set(false);
        if (tunnel != null) tunnel.disconnect();
        worker.shutdownNow();
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
