package com.ssm.research.companion;

import android.util.Log;

import java.io.BufferedInputStream;
import java.io.BufferedOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.util.Enumeration;
import java.util.zip.CRC32;
import java.util.zip.ZipEntry;
import java.util.zip.ZipFile;
import java.util.zip.ZipOutputStream;

/**
 * In-Java APK hotpatch (no shell zip). Replaces libssm_research_hud.so inside the
 * game's arm64 split using root for pm path + install path overwrite.
 */
public final class HudHotpatch {
    private static final String TAG = "SsmBridge";
    private static final String SO_NAME = "lib/arm64-v8a/libssm_research_hud.so";
    private static final String SRC = "/sdcard/Download/libssm_research_hud.so";

    private HudHotpatch() {}

    public static RootHelper.Result apply() {
        File src = new File(SRC);
        if (!src.isFile()) return RootHelper.Result.fail("missing " + SRC);

        RootHelper.Result path = RootHelper.runSu(
                "pm path com.miniclip.soccerstars 2>/dev/null | grep arm64 | head -1 | cut -d: -f2",
                8);
        if (!path.ok || path.output.trim().isEmpty()) {
            return RootHelper.Result.fail("arm64 split not found: " + path.output);
        }
        String splitPath = path.output.trim().split("\n")[0].trim();

        File work = new File("/sdcard/Download/ssm_hotpatch_work");
        // cleanup + mkdir via root (sdcard is fine without root usually)
        //noinspection ResultOfMethodCallIgnored
        work.mkdirs();
        File localSplit = new File(work, "split.apk");
        File outSplit = new File(work, "split_patched.apk");

        RootHelper.Result cp = RootHelper.runSu(
                "cp -f '" + splitPath + "' '" + localSplit.getAbsolutePath() + "' && chmod 666 '"
                        + localSplit.getAbsolutePath() + "' && ls -la '" + localSplit.getAbsolutePath() + "'",
                30);
        if (!cp.ok) return RootHelper.Result.fail("copy split: " + cp.output);

        try {
            replaceSoInApk(localSplit, src, outSplit);
        } catch (Exception e) {
            Log.e(TAG, "zip patch failed", e);
            return RootHelper.Result.fail("zip patch: " + e.getMessage());
        }

        RootHelper.Result install = RootHelper.runSu(
                "cp -f '" + outSplit.getAbsolutePath() + "' '" + splitPath + "' && "
                        + "chmod 644 '" + splitPath + "' && "
                        + "am force-stop com.miniclip.soccerstars && "
                        + "echo HOTPATCH_OK size=" + src.length() + " split=" + splitPath,
                60);
        if (!install.ok) return RootHelper.Result.fail("install split: " + install.output);
        return RootHelper.Result.ok(install.output);
    }

    /** STORED (no compress) entry replacement for .so — matches APK convention. */
    static void replaceSoInApk(File apkIn, File soFile, File apkOut) throws Exception {
        byte[] soBytes = readAll(soFile);
        CRC32 crc = new CRC32();
        crc.update(soBytes);

        try (ZipFile zf = new ZipFile(apkIn);
             ZipOutputStream zos = new ZipOutputStream(new BufferedOutputStream(new FileOutputStream(apkOut)))) {
            zos.setMethod(ZipOutputStream.STORED);
            Enumeration<? extends ZipEntry> en = zf.entries();
            boolean replaced = false;
            byte[] buf = new byte[8192];
            while (en.hasMoreElements()) {
                ZipEntry in = en.nextElement();
                String name = in.getName();
                if (SO_NAME.equals(name)) {
                    writeStored(zos, SO_NAME, soBytes, crc.getValue());
                    replaced = true;
                    continue;
                }
                // Copy entry as-stored when possible to avoid recompress issues
                ZipEntry out = new ZipEntry(name);
                if (in.getMethod() == ZipEntry.STORED) {
                    out.setMethod(ZipEntry.STORED);
                    out.setSize(in.getSize());
                    out.setCompressedSize(in.getSize());
                    out.setCrc(in.getCrc());
                } else {
                    out.setMethod(ZipEntry.DEFLATED);
                }
                zos.putNextEntry(out);
                if (!in.isDirectory()) {
                    try (InputStream is = new BufferedInputStream(zf.getInputStream(in))) {
                        int n;
                        while ((n = is.read(buf)) > 0) zos.write(buf, 0, n);
                    }
                }
                zos.closeEntry();
            }
            if (!replaced) {
                writeStored(zos, SO_NAME, soBytes, crc.getValue());
            }
        }
    }

    private static void writeStored(ZipOutputStream zos, String name, byte[] data, long crc)
            throws Exception {
        ZipEntry e = new ZipEntry(name);
        e.setMethod(ZipEntry.STORED);
        e.setSize(data.length);
        e.setCompressedSize(data.length);
        e.setCrc(crc);
        zos.putNextEntry(e);
        zos.write(data);
        zos.closeEntry();
    }

    private static byte[] readAll(File f) throws Exception {
        try (FileInputStream in = new FileInputStream(f)) {
            byte[] all = new byte[(int) f.length()];
            int off = 0;
            while (off < all.length) {
                int n = in.read(all, off, all.length - off);
                if (n < 0) break;
                off += n;
            }
            return all;
        }
    }
}
