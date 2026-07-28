# Soccer Stars Assist — راهنمای کامل

ابزار کمکی اندروید برای **Soccer Stars** (Miniclip) — مخصوص دسترسی‌پذیری و هدف‌گیری بهتر.

## دانلود APK

**نسخه 1.0.0:** فایل `releases/SoccerStarsAssist-v1.0.0.apk`

پس از push به GitHub، لینک مستقیم:
`https://github.com/hoseinzeto2-source/Tesf/releases/download/v1.0.0/SoccerStarsAssist-v1.0.0.apk`

## نصب

1. APK را دانلود و نصب کنید (منبع ناشناس را اجازه دهید)
2. اپ **Soccer Stars Assist** را باز کنید
3. اجازه **نمایش روی برنامه‌ها** (Overlay)
4. **شروع ابزار کمکی** → اجازه **ضبط صفحه**
5. Soccer Stars را باز کنید

## هنگام بازی

وقتی مهره را می‌گیرید و می‌کشید:

| رنگ | معنی |
|-----|------|
| سفید خط‌کش‌دار | ادامه جهت شلیک |
| آبی | مسیر پیش‌بینی توپ |
| زرد | مسیر مهره |
| سبز | احتمال گل |

## تنظیمات

از دکمه **تنظیمات** یا نوتیفیکیشن:
- طول خط‌کش
- کیفیت تشخیص (سرعت vs دقت)
- نمایش/عدم نمایش مسیر مهره

## کالیبره فیزیک (گوشی روت)

فایل: `/sdcard/SoccerStarsAssist/physics.json`

ابزار dump: `tools/physics-dump/README_FA.md`

## نیازمندی‌ها

- Android 8.0+ (API 26)
- Soccer Stars نصب شده
- اجازه Overlay + MediaProjection

## ساخت از سورس

```bash
cd soccer-stars-android
./gradlew assembleRelease
```

## محدودیت

- فیزیک تقریبی است (~90% دقت پس از کالیبره)
- فقط overlay دیداری — بازی را دستکاری نمی‌کند
- مصرف باتری متوسط به‌خاطر capture صفحه
