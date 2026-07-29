# فیلدهای وضعیت بازی — توپ، مهره، گل، شتاب

**نسخه lib:** 36.14.4 / build 1013

---

## خلاصه: چه چیزی قابل خواندن است؟

| داده | امکان‌پذیر؟ | منبع | X/Y/Z |
|------|-------------|------|-------|
| موقعیت توپ | ✅ | `ballPositionInPoints`, `puck_state` | **X, Y** (نه Z فیزیک) |
| موقعیت مهره‌ها | ✅ | `puck_state[]` در protobuf | **X, Y** |
| تعداد گل / امتیاز | ✅ | `shot_outcome`, `ongoing_game` | — |
| وضعیت بازی (نوبت، فاز) | ✅ | `session_state`, enums | — |
| سرعت / شتاب فیزیک | ⚠️部分 | حافظه زنده، `sInternalVelocity` | vx, vy |
| شکل‌شدگی مهره (squash) | ⚠️ فقط visual | `setScaleX/Y`, `VisualPuck` | scale نه Z |
| Z عمق | ❌ در فیزیک | فقط `zOrder` رندر Cocos2d | — |

**Soccer Stars یک بازی 2D billiards-style است.** مختصات فیزیک = **X و Y** (معمولاً نرمال 0..1 یا CGPoint). **Z در protobuf position وجود ندارد.**

---

## ساختار `position` (protobuf)

از type encoding در lib:

```
{CGPoint="x"d"y"d}
```

یعنی:
- `position.x` → double
- `position.y` → double
- **بدون `z`**

---

## ساختار `puck_state`

```
puck_state {
  position:  { x: double, y: double }
  puck_id:   int
  owner_enum: int    // تیم/بازیکن
  state_enum: int    // وضعیت مهره (مثلاً قفل، متحرک، ...)
}
```

از encoding: `^{position}iii` → position + 3 int

---

## امتیاز و گل

### `shot_outcome` (بعد از هر شلیک)

```
player_score_      int
opponent_score_    int
field_state_       repeated puck_state   ← موقعیت جدید همه مهره‌ها + توپ
next_player_id_
scoring_player_id_
type_              int
player_game_time_remaining_
opponent_game_time_remaining_
```

### `ongoing_game` (در session)

```
game_metadata
animation_outcome
shot_taken
game_player_user_data
... + فیلدهای int (امتیاز، زمان، ...)
```

### `game_ended`

```
player_score_
```

---

## وضعیت بازی (enum)

| فیلد | توضیح |
|------|--------|
| `session_state.current_game_state_enum` | فاز کلی (منو / match / ...) |
| `puck_state.owner_enum` | مالک مهره |
| `puck_state.state_enum` | وضعیت مهره |
| `aim_event.type_enum` | نوع aim |
| `shot_outcome.type_enum` | نوع نتیجه (گل، نوبت، ...) |

---

## شکل‌شدگی مهره (squash / deform)

**فیزیک:** collision در `Ball.mm` / `VisualPuck.mm` — impulse و velocity.

**نمایش:** افکت visual با:
- `setScaleX:` / `setScaleY:`
- `actionWithDuration:scaleX:scaleY:` (انیمیشن Cocos2d)
- کلاس `VisualPuck` (`VisualPuck.mm`)

این **scale روی sprite** است، نه مختصات Z. برای خواندن:
- Frida: hook `setScaleX` / `setScaleY` روی `VisualPuck`
- Vision: تحلیل نسبت عرض/ارتفاع مهره در اسکرین‌شات (کمکی Assist)

رشته `deform` در lib وجود دارد اما squash اصلی = **scaleX/scaleY**.

---

## سه روش خواندن

### ۱) Frida — حافظه زنده (گوشی روت)

```bash
frida -U com.miniclip.soccerstars -l frida/read_game_state.js
```

```javascript
rpc.snapshot()   // مختصات نرمال x,y + سرعت + تخمین امتیاز از حافظه
```

### ۲) Hook protobuf / network (پیشرفته)

پیام‌های `shot_outcome` و `game_started` شامل `field_state[]` با position کامل هستند.
نیاز: hook لایه deserialize یا SSL pinning bypass (خارج از scope assist).

### ۳) Vision — بدون روت (روش Assist)

`GameDetector.kt` از اسکرین‌شات:
- موقعیت توپ و مهره (X, Y پیکسل)
- خط aim
- `MotionTrailDetector` → سرعت بعد از شلیک از blur trail
- **گل/امتیاز:** از HUD با OCR یا Accessibility (هنوز کامل نیست)

---

## Z چیست در این بازی؟

| Z | معنی |
|---|------|
| فیزیک | **ندارد** — بازی روی صفحه 2D |
| رندر | `zOrder` — لایه نقاشی (مهره بالای زمین) |
| عمق کاذب | scale کوچک/بزرگ = squash visual |

اگر منظورتان «عمق» است → **Z فیزیکی وجود ندارد**.

---

## فایل‌های منبع در lib

| فایل | محتوا |
|------|--------|
| `SSM/pool/Ball.mm` | توپ |
| `SSM/pool/VisualPuck.mm` | مهره visual + squash |
| `BallPositionInPoints` | getter موقعیت توپ |
