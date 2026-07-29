package com.ssm.research.companion;

import android.Manifest;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.pm.PackageManager;
import android.os.Build;
import android.os.Bundle;
import android.widget.Button;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;

public class MainActivity extends AppCompatActivity {
    private TextView statusText;
    private TextView detailText;
    private final BroadcastReceiver statusReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            if (intent == null) return;
            String state = intent.getStringExtra(BridgeService.EXTRA_STATE);
            String detail = intent.getStringExtra(BridgeService.EXTRA_DETAIL);
            applyStatus(state, detail);
        }
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        statusText = findViewById(R.id.statusText);
        detailText = findViewById(R.id.detailText);
        Button btnConnect = findViewById(R.id.btnConnect);
        Button btnDisconnect = findViewById(R.id.btnDisconnect);
        Button btnAdb = findViewById(R.id.btnAdb);
        Button btnGame = findViewById(R.id.btnGame);

        detailText.setText("VPS: " + BridgeConfig.VPS_HOST
                + "\nADB tunnel: VPS:" + BridgeConfig.VPS_ADB_PORT
                + " → phone:" + BridgeConfig.PHONE_ADB_PORT);

        requestNotifPermission();

        btnConnect.setOnClickListener(v -> {
            getSharedPreferences(BridgeConfig.PREFS, MODE_PRIVATE)
                    .edit().putBoolean(BridgeConfig.PREF_AUTO, true).apply();
            Intent svc = new Intent(this, BridgeService.class);
            ContextCompat.startForegroundService(this, svc);
            statusText.setText("وضعیت: در حال اتصال…");
        });

        btnDisconnect.setOnClickListener(v -> {
            getSharedPreferences(BridgeConfig.PREFS, MODE_PRIVATE)
                    .edit().putBoolean(BridgeConfig.PREF_AUTO, false).apply();
            Intent svc = new Intent(this, BridgeService.class);
            svc.setAction("STOP");
            startService(svc);
            statusText.setText("وضعیت: قطع");
        });

        btnAdb.setOnClickListener(v -> new Thread(() -> {
            RootHelper.Result r = RootHelper.enableAdbTcp(BridgeConfig.PHONE_ADB_PORT);
            runOnUiThread(() -> Toast.makeText(this,
                    r.ok ? ("ADB OK: " + r.output) : ("ADB fail: " + r.output),
                    Toast.LENGTH_LONG).show());
        }).start());

        btnGame.setOnClickListener(v -> {
            Intent launch = getPackageManager().getLaunchIntentForPackage(BridgeConfig.GAME_PACKAGE);
            if (launch == null) {
                Toast.makeText(this, "Soccer Stars نصب نیست", Toast.LENGTH_LONG).show();
                return;
            }
            startActivity(launch);
        });
    }

    private void applyStatus(String state, String detail) {
        if (state == null) state = "?";
        String label;
        switch (state) {
            case "connected":
                label = "وضعیت: ✅ متصل — به Agent بگویید وصل شدید";
                break;
            case "connecting":
                label = "وضعیت: در حال اتصال…";
                break;
            case "error":
                label = "وضعیت: ❌ خطا";
                break;
            default:
                label = "وضعیت: قطع";
                break;
        }
        statusText.setText(label);
        if (detail != null) detailText.setText(detail);
    }

    private void requestNotifPermission() {
        if (Build.VERSION.SDK_INT >= 33) {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
                    != PackageManager.PERMISSION_GRANTED) {
                ActivityCompat.requestPermissions(this,
                        new String[]{Manifest.permission.POST_NOTIFICATIONS}, 100);
            }
        }
    }

    @Override
    protected void onStart() {
        super.onStart();
        IntentFilter f = new IntentFilter(BridgeService.ACTION_STATUS);
        if (Build.VERSION.SDK_INT >= 33) {
            registerReceiver(statusReceiver, f, Context.RECEIVER_NOT_EXPORTED);
        } else {
            registerReceiver(statusReceiver, f);
        }
    }

    @Override
    protected void onStop() {
        try {
            unregisterReceiver(statusReceiver);
        } catch (Exception ignored) {
        }
        super.onStop();
    }
}
