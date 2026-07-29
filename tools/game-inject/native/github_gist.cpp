#include "github_gist.h"

#include <android/log.h>
#include <jni.h>
#include <string>

#define LOG_TAG "SSMResearchHUD"

static JavaVM* g_jvm = nullptr;

void githubSetJavaVm(void* vm) {
    g_jvm = static_cast<JavaVM*>(vm);
}

static std::string jsonEscape(const char* s) {
    std::string out;
    if (!s) return out;
    for (const char* p = s; *p; p++) {
        char c = *p;
        if (c == '\\' || c == '"') out += '\\';
        if (c == '\n') {
            out += "\\n";
            continue;
        }
        if (c == '\r') continue;
        out += c;
    }
    return out;
}

static std::string jstringToStd(JNIEnv* env, jstring js) {
    if (!js) return {};
    const char* utf = env->GetStringUTFChars(js, nullptr);
    if (!utf) return {};
    std::string out(utf);
    env->ReleaseStringUTFChars(js, utf);
    return out;
}

std::string githubUploadGist(const char* token, const char* filename, const char* content) {
    if (!g_jvm || !token || !token[0] || !filename || !content) return "error: missing token or data";

    JNIEnv* env = nullptr;
    if (g_jvm->AttachCurrentThread(&env, nullptr) != JNI_OK || !env) return "error: jni attach failed";

    std::string body = "{\"description\":\"SSM Research HUD dump\",\"public\":false,\"files\":{\"";
    body += filename;
    body += "\":{\"content\":\"";
    body += jsonEscape(content);
  body += "\"}}}";

    jclass url_cls = env->FindClass("java/net/URL");
    if (!url_cls) return "error: URL class";
    jmethodID url_init = env->GetMethodID(url_cls, "<init>", "(Ljava/lang/String;)V");
    jstring url_str = env->NewStringUTF("https://api.github.com/gists");
    jobject url = env->NewObject(url_cls, url_init, url_str);
    jmethodID open_conn = env->GetMethodID(url_cls, "openConnection", "()Ljava/net/URLConnection;");
    jobject conn = env->CallObjectMethod(url, open_conn);

    jclass http_cls = env->FindClass("java/net/HttpURLConnection");
    jmethodID set_method = env->GetMethodID(http_cls, "setRequestMethod", "(Ljava/lang/String;)V");
    env->CallVoidMethod(conn, set_method, env->NewStringUTF("POST"));

    jmethodID set_prop = env->GetMethodID(http_cls, "setRequestProperty", "(Ljava/lang/String;Ljava/lang/String;)V");
    std::string auth = std::string("Bearer ") + token;
    env->CallVoidMethod(conn, set_prop, env->NewStringUTF("Authorization"), env->NewStringUTF(auth.c_str()));
    env->CallVoidMethod(conn, set_prop, env->NewStringUTF("Content-Type"), env->NewStringUTF("application/json"));
    env->CallVoidMethod(conn, set_prop, env->NewStringUTF("Accept"), env->NewStringUTF("application/vnd.github+json"));
    env->CallVoidMethod(conn, set_prop, env->NewStringUTF("X-GitHub-Api-Version"), env->NewStringUTF("2022-11-28"));

    jmethodID set_do_out = env->GetMethodID(http_cls, "setDoOutput", "(Z)V");
    env->CallVoidMethod(conn, set_do_out, JNI_TRUE);

    jmethodID get_os = env->GetMethodID(http_cls, "getOutputStream", "()Ljava/io/OutputStream;");
    jobject os = env->CallObjectMethod(conn, get_os);
    jclass os_cls = env->FindClass("java/io/OutputStream");
    jmethodID write = env->GetMethodID(os_cls, "write", "([B)V");
    jbyteArray arr = env->NewByteArray((jsize)body.size());
    env->SetByteArrayRegion(arr, 0, (jsize)body.size(), reinterpret_cast<const jbyte*>(body.data()));
    env->CallVoidMethod(os, write, arr);

    jmethodID flush = env->GetMethodID(os_cls, "flush", "()V");
    jmethodID close = env->GetMethodID(os_cls, "close", "()V");
    env->CallVoidMethod(os, flush);
    env->CallVoidMethod(os, close);

    jmethodID get_code = env->GetMethodID(http_cls, "getResponseCode", "()I");
    int code = env->CallIntMethod(conn, get_code);
    if (code != 201) return std::string("error: http ") + std::to_string(code);

    jmethodID get_stream = env->GetMethodID(http_cls, "getInputStream", "()Ljava/io/InputStream;");
    jobject in = env->CallObjectMethod(conn, get_stream);
    jclass reader_cls = env->FindClass("java/io/InputStreamReader");
    jclass br_cls = env->FindClass("java/io/BufferedReader");
    jobject reader = env->NewObject(reader_cls, env->GetMethodID(reader_cls, "<init>", "(Ljava/io/InputStream;)V"), in);
    jobject br = env->NewObject(br_cls, env->GetMethodID(br_cls, "<init>", "(Ljava/io/Reader;)V"), reader);
    jmethodID read_line = env->GetMethodID(br_cls, "readLine", "()Ljava/lang/String;");

    std::string response;
    for (;;) {
        jstring line = (jstring)env->CallObjectMethod(br, read_line);
        if (!line) break;
        response += jstringToStd(env, line);
    }

    const char* key = "\"html_url\"";
    const char* pos = strstr(response.c_str(), key);
    if (!pos) return "ok: uploaded (no url in response)";
    pos = strchr(pos + strlen(key), ':');
    if (!pos) return "ok: uploaded";
    pos = strchr(pos, '"');
    if (!pos) return "ok: uploaded";
    pos++;
    const char* end = strchr(pos, '"');
    if (!end) return "ok: uploaded";
    return std::string("gist: ") + std::string(pos, end - pos);
}
