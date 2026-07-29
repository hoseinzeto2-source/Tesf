package com.ssm.research.companion;

public final class BridgeConfig {
    public static final String VPS_HOST = "185.92.182.88";
    public static final int VPS_PORT = 22;
    public static final String VPS_USER = "root";

    /** Port on VPS (localhost) that Agent uses for adb connect */
    public static final int VPS_ADB_PORT = 15555;
    /** Local adbd TCP port on phone */
    public static final int PHONE_ADB_PORT = 5555;

    public static final String GAME_PACKAGE = "com.miniclip.soccerstars";
    public static final String PREFS = "ssm_bridge";
    public static final String PREF_AUTO = "auto_connect";

    private BridgeConfig() {}
}
