# Soccer Stars Assist (Android)

اپ اندروید برای **Soccer Stars** که هنگام کشیدن مهره:
- **خط‌کش سفید** (ادامه خط شلیک) را نشان می‌دهد
- **مسیر احتمالی توپ** را پیش‌بینی می‌کند
- اگر شوت به گل برسد، مسیر **سبز** می‌شود

## نحوه کار

```
صفحه بازی (MediaProjection)
        ↓
تشخیص خط زرد + مهره + توپ
        ↓
شبیه‌سازی فیزیک (angle + power + برخورد)
        ↓
Overlay شفاف روی Soccer Stars
```

## ساخت APK

```bash
cd soccer-stars-android
./gradlew assembleDebug
```

فایل خروجی:
`app/build/outputs/apk/debug/app-debug.apk`

## نصب و استفاده

1. APK را نصب کنید
2. اپ را باز کنید → **اجازه نمایش روی برنامه‌ها**
3. **شروع ابزار کمکی** → اجازه ضبط صفحه
4. Soccer Stars را باز کنید و مهره را بکشید

## تنظیم دقت

اگر مسیر توپ دقیق نبود، در `AssistController.kt` مقادیر `AssistConfig` را تنظیم کنید:
- `maxShotSpeed` — سرعت شلیک
- `maxShotPower` — حداکثر قدرت کشیدن
- `goalTopRatio` / `goalBottomRatio` — محل دروازه

## محدودیت

- فیزیک **تقریبی** است (بر اساس تحلیل APK نسخه 36.14.4)
- نیاز به Android 8+ (API 26)
- برای بازی آنلاین، فقط کمک دیداری است و بازی را دستکاری نمی‌کند
