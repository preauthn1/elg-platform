# SexySlotCq9SW — API 規格

> 來源：`V2._單一錢包_API_介接文件_26.01.13.html`（CQ9 GameBoy 單一錢包 Wallet API + Gameboy API）
> 整合日期：2026-07-20
> 文件版本：sw.24.06.20（Gameboy API gb.23.08.08，Sport sport.23.05.08）
> 錢包模式：Single Wallet（單一錢包）
> Vendor ID：255
> API URL：`https://api.cqgame.games`
> 品牌：Sexy Slot（CQ9 GameBoy 系統，帳號前綴 `tpower_sw`）

> 本文件與 `docs/integrations/vendors/MotivationSW/api.md` 為**同一份三方規格**（CQ9 GameBoy 單一錢包協議），差異僅在商戶憑證與 Vendor ID。實作範圍涵蓋 MotivationSW 已驗證覆蓋的 9 支 Wallet API，另補上 TakeAll／Payoff 共 11 支；體彩批次系列（Bets/Refunds/Cancel/Wins/Amends/Amend）仍未實作。

---

## 通用約定

### 雙向 API 架構

| 方向 | 說明 |
|---|---|
| **Wallet API（回調）** | CQ9（廠商）→ 本平台（EC），必須實作 |
| **Gameboy API** | 本平台（EC）→ CQ9（廠商），取遊戲連結、查注單、報表等 |

### 時區

- **Wallet 回調時間**：RFC3339 格式，文件範例時區為 `-04:00`（UTC-4）
- **Gameboy API 查詢**：時區明確要求 **UTC-4**

### 請求格式

| 方向 | 方法 | Content-Type |
|---|---|---|
| 回調 GET API | GET | — |
| 回調 POST API（大多數） | POST | `application/x-www-form-urlencoded` |
| 回調 POST API（批次，未實作範圍） | POST | `application/json` |
| Gameboy API | GET / POST | `application/x-www-form-urlencoded`（POST） |

### 認證方式

**回調方向（廠商 → EC）**：廠商在 Request Header 帶入 `wtoken`，EC 驗證是否與後台設定一致。

**Gameboy API 方向（EC → 廠商）**：

| Header | 說明 |
|---|---|
| `Authorization` | 廠商提供的系統商 Token |
| `Content-Type` | `application/x-www-form-urlencoded`（POST 時） |

> `wtoken`（回調認證）與 `Authorization`（Gameboy API）對應同一組憑證（`vendor_infos.info.TOKEN`），兩用途共用。本案提供之 Token：

```
eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VyaWQiOiI2YTVkOTU3YTZhNzcwNzY0ZGI1ZjRlOWYiLCJhY2NvdW50IjoidHBvd2VyX3N3Iiwib3duZXIiOiI2YTVkOTU3YTZhNzcwNzY0ZGI1ZjRlOWYiLCJwYXJlbnQiOiJzZWxmIiwiY3VycmVuY3kiOiJNWVIiLCJicmFuZCI6ImNxOSIsImp0aSI6Ijg2OTUwNTIzMiIsImlhdCI6MTc4NDUxODAxMCwiaXNzIjoiQ3lwcmVzcyIsInN1YiI6IlNTVG9rZW4ifQ.6S45tVOaddOSVf4Jq-F_EIMwv_VhRSLzrhXXfusgY9k
```

（JWT payload 解出 `account=tpower_sw`、`currency=MYR`、`brand=cq9`，僅供對照，不需要本平台自行解析）

### 金額規格

- 最大值：12 位整數 + 4 位小數（`999999999999.9999`）
- 金額**不得為負值**（負值須回傳錯誤 1003）
- 時間格式：RFC3339，至少支援毫秒第 9 位
- `currency` 欄位依代理設定回填（本案為 `MYR`）

### 冪等性（mtcode）

- 所有交易 API 以 `mtcode` 作為冪等鍵
- 重複收到相同 `mtcode` → 回傳前次成功的餘額，不再增減
- `mtcode` 與 `roundid` 均須唯一，不含特殊字元，建議 UUID

### 錢包交易紀錄保留期

- 文件要求至少保留 **45 天**（對帳依據）

---

## 損益公式

| 遊戲類型 | 玩家損益公式 |
|---|---|
| Slot / Fish / Arcade / Sports / Lotto | `EndRound amount − Bet amount = profit` |
| Table / Live | `Rollin win − Rollin rake − Rollin roomfee = profit` |

> 有效投注（validbet）：Slot / Arcade / Fish 為 0，以 bet / rollout amount 計算。

---

## 錢包流程

### 1. Slot / Arcade（老虎機 / 街機）

```
Bet（扣款）→ EndRound（結算）
- 輸：EndRound amount=0
- 贏：EndRound amount 補回玩家錢包
- 同一局 Bet 對應一局 EndRound，共用 roundid
```

### 2. Table / Live / Fish / Arcade（部分額度 Rollout 模式）

```
Rollout（轉出部分額度）→ Rollin（結算後轉回）
- Fish/Arcade: amount = Rollout − bet + win
- Table/Live:  amount = Rollout + win − rake
- 同一局 Rollout/Rollin 共用 roundid
```

> TakeAll（全額轉出模式）：轉出玩家『全部』餘額至遊戲，roundid 與 Rollin 共用，見下方「TakeAll」章節。

---

## 回調 API（廠商 → EC）

### 通用回應格式

```json
{
  "data": { ... },
  "status": {
    "code": "0",
    "message": "...",
    "datetime": "..."
  }
}
```

### Wallet API 錯誤碼

| Code | 說明 |
|---|---|
| `0` | 成功 |
| `1002` | 動作錯誤 |
| `1003` | 參數錯誤（含金額為負） |
| `1004` | 時間格式錯誤 |
| `1005` | 餘額不足 |
| `1006` | 查無玩家 |
| `1014` | 查無紀錄 |
| `1100` | 伺服器錯誤 |

### 實作範圍與路由對照（prefix `sexyslotcq9sw/`）

| HTTP | Path | Controller 方法 | 狀態 |
|---|---|---|---|
| GET | `player/check/{account}` | `checkPlayer` | 已實作 |
| GET | `transaction/balance/{account}` | `balance` | 已實作 |
| POST | `transaction/game/bet` | `bet` | 已實作 |
| POST | `transaction/game/refund` | `refund` | 已實作 |
| POST | `transaction/game/endround` | `endround` | 已實作 |
| POST | `transaction/game/rollout` | `rollout` | 已實作 |
| POST | `transaction/game/rollin` | `rollin` | 已實作 |
| POST | `transaction/game/debit` | `debit` | 已實作 |
| POST | `transaction/game/credit` | `credit` | 已實作 |
| POST | `transaction/game/takeall` | `takeall` | 已實作 |
| POST | `transaction/user/payoff` | `payoff` | 已實作 |
| POST | `transaction/game/bets`／`refunds`／`cancel`／`wins`／`amends`／`amend` | — | 未實作（體彩批次系列） |

### CheckPlayer

```
GET /player/check/:account
```

回應：`data: bool`（true=玩家存在）

### Balance

```
GET /transaction/balance/:account?gamecode=...
```

回應：`data.balance`、`data.currency`

### Bet

```
POST /transaction/game/bet
Content-Type: application/x-www-form-urlencoded
```

| 參數 | 必填 | 說明 |
|---|---|---|
| `account` | ✅ | 玩家帳號 |
| `eventTime` | ✅ | RFC3339 |
| `gamehall` | ✅ | 遊戲廠商代號 |
| `gamecode` | ✅ | 遊戲代號 |
| `roundid` | ✅ | 局號 |
| `amount` | ✅ | 下注金額（不得為負） |
| `mtcode` | ✅ | 冪等鍵 |
| `session` | 選填 | SessionID |
| `platform` | 選填 | 平台標識 |

回應：`data.balance`、`data.currency`

### EndRound

```
POST /transaction/game/endround
```

| 參數 | 必填 | 說明 |
|---|---|---|
| `account` | ✅ | 玩家帳號 |
| `gamehall` | ✅ | — |
| `gamecode` | ✅ | — |
| `roundid` | ✅ | 與 Bet 同一局 |
| `data` | ✅ | JSON array，每筆含 `mtcode`/`amount`/`eventtime`/`validbet` |
| `createTime` | ✅ | RFC3339 |
| `freegame` / `bonus` / `luckydraw` / `jackpot` / `jackpotcontribution` / `freeticket` | 選填 | — |

回應：`data.balance`、`data.currency`

### Rollout

```
POST /transaction/game/rollout
```

| 參數 | 必填 | 說明 |
|---|---|---|
| `account` | ✅ | 玩家帳號 |
| `eventTime` | ✅ | RFC3339 |
| `gamehall` | ✅ | — |
| `gamecode` | ✅ | — |
| `roundid` | ✅ | 局號 |
| `amount` | ✅ | 轉出金額 |
| `mtcode` | ✅ | 冪等鍵 |
| `session` | 選填 | — |

回應：`data.balance`、`data.currency`

### Rollin

```
POST /transaction/game/rollin
```

| 參數 | 必填 | 說明 |
|---|---|---|
| `account` | ✅ | 玩家帳號 |
| `eventTime` | ✅ | RFC3339 |
| `gamehall` | ✅ | — |
| `gamecode` | ✅ | — |
| `roundid` | ✅ | 與 Rollout 同一局 |
| `validbet` | ✅ | 有效投注 |
| `bet` | ✅ | 下注金額 |
| `win` | ✅ | 派彩金額 |
| `amount` | ✅ | 結算後轉回金額 |
| `mtcode` | ✅ | 冪等鍵 |
| `createTime` | ✅ | RFC3339 |
| `rake` | ✅ | 抽水（Table/Live 用） |
| `gametype` | ✅ | 遊戲類型 |
| `roomfee` | 選填 | 房費（Table/Live） |
| `tableid` | 選填 | 牌桌 ID |

金額規則：
- Arcade/Fish：`amount = Rollout amount − bet + win`
- Table/Live：`amount = Rollout amount + win − rake`

回應：`data.balance`、`data.currency`

### TakeAll

```
POST /transaction/game/takeall
```

轉出玩家『全部』額度至遊戲（轉回額度接口使用 Rollin API）。

| 參數 | 必填 | 說明 |
|---|---|---|
| `account` | ✅ | 玩家帳號 |
| `eventTime` | ✅ | RFC3339 |
| `gamehall` | ✅ | 遊戲廠商代號 |
| `gamecode` | ✅ | 遊戲代號 |
| `roundid` | ✅ | 局號（同一局 takeall/rollin 使用相同 roundid） |
| `mtcode` | ✅ | 冪等鍵 |
| `session` | 選填 | SessionID |

回應：`data.amount`（TakeAll 之前的餘額）、`data.balance`（執行後餘額，必為 0）、`data.currency`

### Debit

```
POST /transaction/game/debit
```

| 參數 | 必填 | 說明 |
|---|---|---|
| `account` | ✅ | — |
| `eventTime` | ✅ | — |
| `gamehall` | ✅ | — |
| `gamecode` | ✅ | — |
| `roundid` | ✅ | — |
| `amount` | ✅ | 扣款金額 |
| `mtcode` | ✅ | 冪等鍵 |

回應：`data.balance`、`data.currency`

### Credit

```
POST /transaction/game/credit
```

參數同 Debit（amount 為入帳金額）。

回應：`data.balance`、`data.currency`

### Refund

```
POST /transaction/game/refund
```

| 參數 | 必填 | 說明 |
|---|---|---|
| `mtcode` | ✅ | 原 Bet 的 mtcode |
| `account` | ✅ | 玩家帳號 |

回應：`data.balance`、`data.currency`

### Payoff

```
POST /transaction/user/payoff
```

貴司如有參與我方舉辦之活動，活動獎勵透過此支 API 派發給玩家；無 roundid 概念，不與任何注單掛勾。

| 參數 | 必填 | 說明 |
|---|---|---|
| `account` | ✅ | 玩家帳號 |
| `eventTime` | ✅ | 交易當下時間 |
| `amount` | ✅ | 金額（實際給玩家錢包的金額，不得為負值） |
| `mtcode` | ✅ | 冪等鍵 |
| `promoid` | 選填 | 活動 id |
| `remark` | 選填 | 備註 |

回應：`data.balance`、`data.currency`

---

## Gameboy API（EC → 廠商）

Base URL：`https://api.cqgame.games`

| API | Method | Path | 說明 |
|---|---|---|---|
| Ping | GET | `/gameboy/ping` | 取得伺服器時間 |
| 遊戲大廳連結 | POST | `/gameboy/player/sw/lobbylink` | 單一錢包大廳連結 |
| 遊戲連結 | POST | `/gameboy/player/sw/gamelink` | 指定遊戲連結 |
| 廠商列表 | GET | `/gameboy/game/halls` | 所有遊戲廠商 |
| 遊戲列表 | GET | `/gameboy/game/list/:gamehall` | 指定廠商的遊戲清單 |
| 注單查詢 | GET | `/gameboy/order/view` | 時間區間查詢（24H 內，UTC-4） |
| 單一注單 | GET | `/gameboy/order/record` | 以 roundid 查詢單筆 |
| 玩家報表 | GET | `/gameboy/report/player` | 依玩家彙總 |
| 代理報表 | GET | `/gameboy/report/ss` | 依代理彙總 |

### 取得遊戲連結

```
POST /gameboy/player/sw/gamelink
Headers: Authorization: {token}, Content-Type: application/x-www-form-urlencoded
```

| 參數 | 必填 | 說明 |
|---|---|---|
| `account` | ✅ | 玩家帳號（最大 36 字元） |
| `gamehall` | ✅ | 遊戲廠商代號 |
| `gamecode` | ✅ | 遊戲代號 |
| `gameplat` | ✅ | 平台（`web` 或 `mobile`） |
| `lang` | ✅ | 語言代碼（`zh-cn`/`en`/`th`/`vn`） |

回應：`data.url`（遊戲連結）、`data.token`

### Gameboy API 錯誤碼

| Code | 說明 |
|---|---|
| `0` | 成功 |
| `1` | 餘額不足 |
| `2` | 玩家不存在 |
| `3` | Token 無效 |
| `4` | Authorization 無效 |
| `5` | 參數錯誤 |
| `6` | 帳號已存在 |
| `8` | 資料不存在 |
| `9` | MTCode 重複 |
| `10` | 時間格式錯誤 |
| `11` | 查詢時間超出範圍 |
| `12` | 時區必須為 UTC-4 |
| `13` | 找不到遊戲 |
| `100` | 系統異常 |
| `200`/`201`/`202` | 代理凍結／代理停用／玩家停用 |

---

## 實作備註

- 本 vendor 為 CQ9 GameBoy 單一錢包協議的第二個實例（第一個為 MotivationSW，vendor_id=125），程式碼結構與行為 1:1 沿用 MotivationSW（`Driver`／`Service`／`Libs`／`Controller`／欄位命名），僅置換 namespace、Model、資料表前綴與 `vendor_infos.info` 憑證。
- `bet`／`rollout` 各自獨立 Controller 方法，共用 `handleBetOrRollout()` 私有方法（差異僅在傳入的 `BetRecordStatusEnum`），不採 MotivationSW 以 URL 最後一段判斷 `bet`/`rollout` 的寫法。
- TakeAll 與 Bet/Rollout 共用同一套 round 累計寫法，差異只在 amount 直接取當下錢包全額（執行後餘額必為 0）；`rollin()` 已同時接受 `ROLLOUT`/`TAKEALL` 兩種 type 以完成配對結算。
- Payoff 沒有 roundid，`bet_id` 借用 `mtcode` 當唯一鍵，視為一筆獨立的 win_lose=amount 事件寫入 `sexyslotcq9sw_bet_records`（供報表/對帳），不與任何注單掛勾。
- 體彩批次系列（Batch Bets/Refunds/Cancel/Wins/Amends/Amend）未實作，待接入相關遊戲類型時再補（同 MotivationSW 現況）。
- 所有真金錢包異動一律經 `BaseController::changeBalance()`，不直接呼叫 `increaseWalletBalance()`（原 debit() 曾誤用 `increaseWalletBalance(+amount)` 導致扣款方向反了，已隨本次修改一併修正為經 `changeBalance()` 換算）。
