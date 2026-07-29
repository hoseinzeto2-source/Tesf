# Soccer Stars APK Analysis (so.apks)

Source: `https://dl.mr-cheat.ir/so.apks` (downloaded 2026-07-28)

## Identity

| Field | Value |
|-------|-------|
| Package | `com.miniclip.soccerstars` |
| App name | Soccer Stars |
| Version | `36.14.4` (build `1013`) |
| Main activity | `com.miniclip.soccerstars.SoccerStarsActivity` |
| Native engine | `libgame-SSM-GooglePlay-Gold-Release-Module-1013.so` |
| Signature | **Miniclip Portugal** (official, not a custom mod) |

The `.apks` bundle contains:
- `base.apk` (~182 MB)
- `split_config.arm64_v8a.apk` (~55 MB)

## Game architecture

- Core gameplay is **native C++** (internally called `SSM/pool` — billiards-style physics engine).
- UI/assets use **Cocos2d-style** binaries (`.ccbi`, `.plist`, sprites).
- Online protocol uses **Protobuf** messages (`soccer.proto`).

## Match flow (network)

Important messages found in native library:

| Message | Role |
|---------|------|
| `game_started` | Initial `puck_state` list + starting player |
| `aim_event` | Sent while player is aiming/dragging |
| `shot_taken` | Final shot: `angle`, `power`, `puck_id`, `shot_id` |
| `animation_outcome` | Physics animation result |
| `shot_outcome` | Goal, next turn, scores, updated `puck_state` |
| `referee_field_ready_request` | Field/state sync |

### `shot_taken` fields (from binary)

```
player_id, puck_id, shot_id
angle (double)
power (double)
extra_max_power_level (int)
field_state[] (puck positions)
```

Native shot call: `takeActualShot:angle:spin:playSound:`

## Aiming (in-game)

- Slingshot drag on selected puck.
- Yellow aim guide line shown while dragging.
- Built-in **ADA aim assist** (`Slice_adaGeneralConfig.plist`):
  - `isAdaEnabled`, `maxAimTime`, `minAimTime`
  - `maxForce`, `minForce`, `defenseMaxForce`, `defenseMinForce`
  - `firstMatchTierId`, `numberOfFirstGamesWithAda`

## Physics (native symbols)

| Symbol | Meaning |
|--------|---------|
| `mFrictionFactor` | Table friction |
| `mEdgeRestitutionFactor` | Wall bounce |
| `dragForce` | Drag/slingshot force model |
| `BallBallCollision` | Ball ↔ ball |
| `BallLineCollision` | Ball ↔ wall/line |
| `baseMaxPowerLevel` / `maxPower` / `extraMaxPower` | Power caps |
| `stopAllPhysics` | End of turn simulation |

Ball settings per mode/skin: `GameSoccerBallSettings`, `IceGameSoccerBallSettings`, etc.

## Maps / stadiums (assets)

Field skins are visual themes; play area geometry is consistent.

Examples in `assets/unpack/`:
- `BrazilField`, `BerlinField`, `EnglandField`
- `EuroBronzeField`, `IceBronzeField`, `AllInField`
- `LiveOpsTier_10004_Field`, `LiveOpsTier_10037_Field`, ...
- `TrickshotHeroesField`, `GoldenShot`, `StreetMastersField`

Tier/match selection uses `tier_id` in `match_request` and championship stadium lists.

## Formations

Defined in `Slice_formationList.plist` (binary plist slice).

Each formation has:
- `puckx1..5`, `pucky1..5` — normalized puck positions
- `strategy`, `level`, `name`, `numberOfPucks`

Known formation names:
`diamond`, `psycho`, `bow`, `wave`, `edge`, `fangs`, `lifeline`, `frontline`, `turtle`, `arrow`, ...

## Implication for Android assist tool

Because gameplay is **server-authoritative** (shots sent as `angle` + `power`), an external overlay cannot modify game logic safely.

Best approach for accessibility:
1. `MediaProjection` screen capture
2. Detect yellow aim line + puck/ball positions
3. Draw extended ruler line + predicted ball path on `TYPE_APPLICATION_OVERLAY`
4. Optional: voice/switch input via Accessibility Service

Do **not** rely on modding this APK — the hosted file is the official Miniclip build.
