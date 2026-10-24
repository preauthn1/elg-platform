# MotivationSW — API 規格

> 來源：`V2._單一錢包_API_介接文件_24.10.09.html`（MW→EC 回調）
> 補充來源：`正式商戶.docx`、`測試商戶.docx`（正式/測試環境連線資訊，未讀入）
> 整合日期：2026-06-03
> 文件版本：sw.24.06.20（Gameboy API gb.23.08.08，Sport sport.23.05.08）
> 錢包模式：Single Wallet（單一錢包）
> Vendor ID：125

---

## 通用約定

### 雙向 API 架構

| 方向 | 說明 |
|---|---|
| **Wallet API（回調）** | MotivationSW（MW）→ 貴方（EC），必須實作 |
| **Gameboy API** | 貴方（EC）→ MotivationSW（MW），取遊戲連結、查注單、報表等 |

### 時區

- **Wallet 回調時間**：RFC3339 格式，時區依代理設定（文件範例多為 `-04:00`，即 UTC-4）
- **Gameboy API 查詢**：時區明確要求 **UTC-4**
- 本系統 `MotivationSWController::getDateTime()` 以 `DateTimeZone('-04:00')` 格式化

### 請求格式

| 方向 | 方法 | Content-Type |
|---|---|---|
| 回調 GET API | GET | — |
| 回調 POST API（大多數） | POST | `application/x-www-form-urlencoded` |
| 回調 POST API（批次） | POST | `application/json` |
| Gameboy API | GET / POST | `application/x-www-form-urlencoded`（POST） |

### 認證方式

**回調方向（MW → EC）**：MW 在 Request Header 帶入 `wtoken`，EC 驗證是否與後台設定一致。

| Header | 說明 |
|---|---|
| `wtoken` | MW 帶入；EC 比對 `VendorInfoDetailData::token` |

**Gameboy API 方向（EC → MW）**：

| Header | 說明 |
|---|---|
| `Authorization` | MW 提供的系統商 Token |
| `Content-Type` | `application/x-www-form-urlencoded`（POST 時） |

> 注意：`wtoken`（回調認證）與 `Authorization`（Gameboy API）均對應 `vendor_infos.info.token` 同一欄位，兩用途共用。

### 金額規格

- 最大值：12 位整數 + 4 位小數（`999999999999.9999`）
- 金額**不得為負值**（負值須回傳錯誤 1003）
- 時間格式：RFC3339，至少支援毫秒第 9 位（例：`2002-10-02T10:00:00.123456789-04:00`）
- `currency` 欄位依代理設定回填（例：`CNY`）

### 冪等性（mtcode）

- 所有交易 API 以 `mtcode` 作為冪等鍵
- 重複收到相同 `mtcode` → 回傳前次成功的餘額，不再增減
- `mtcode` 與 `roundid` 均須唯一，不含特殊字元，建議 UUID

### 錢包交易紀錄保留期

- 文件要求至少保留 **45 天**（對帳依據）
- Gameboy 測試後台保留 D+3 天；正式後台保留 D+3 個月

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

### 3. Table / Fish / Arcade（全部額度 TakeAll 模式）

```
TakeAll（轉出全部額度）→ Rollin（結算後轉回）
- TakeAll response balance 必為 0
- Rollin 規則同上
- 同一局 TakeAll/Rollin 共用 roundid
```

### 4. Sports / Lotto（體彩批次）

```
Batch Bets → 批次下注（最多 200 筆，全成功或全失敗）
Wins       → 多人多派彩（可部分成功）
Refunds    → 批次退款
Cancel     → 取消退款，恢復可派彩狀態
Amends / Amend → 批次 / 單人修改派果（Credit / Debit）
```

> MW 設有每小時重送與退款機制，依冪等性規則處理。

---

## 回調 API（MW → EC）

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

### 路由對照（prefix `motivation/`）

| HTTP | Path | Controller 方法 | 狀態 |
|---|---|---|---|
| GET | `player/check/{account}` | `checkPlayer` | 已實作 |
| GET | `transaction/balance/{account}` | `balance` | 已實作 |
| POST | `transaction/game/bet` | `bet` | 已實作 |
| POST | `transaction/game/refund` | `refund` | 已實作 |
| POST | `transaction/game/endround` | `endround` | 已實作 |
| POST | `transaction/game/rollout` | `bet`（共用） | 已實作（見落差 1） |
| POST | `transaction/game/rollin` | `rollin` | 已實作 |
| POST | `transaction/game/debit` | `debit` | 已實作 |
| POST | `transaction/game/credit` | `credit` | 已實作 |
| POST | `transaction/game/takeall` | — | **未實作** |
| POST | `transaction/user/payoff` | — | **未實作** |
| POST | `transaction/game/bets` | — | **未實作** |
| POST | `transaction/game/refunds` | — | **未實作** |
| POST | `transaction/game/cancel` | — | **未實作** |
| POST | `transaction/game/wins` | — | **未實作** |
| POST | `transaction/game/amends` | — | **未實作** |
| POST | `transaction/game/amend` | — | **未實作** |

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
| `freegame` | 選填 | — |
| `bonus` | 選填 | — |
| `luckydraw` | 選填 | — |
| `jackpot` | 選填 | — |
| `jackpotcontribution` | 選填 | — |
| `freeticket` | 選填 | — |

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

### TakeAll

```
POST /transaction/game/takeall
```

| 參數 | 必填 | 說明 |
|---|---|---|
| `account` | ✅ | 玩家帳號 |
| `eventTime` | ✅ | RFC3339 |
| `gamehall` | ✅ | — |
| `gamecode` | ✅ | — |
| `roundid` | ✅ | 局號 |
| `mtcode` | ✅ | 冪等鍵 |
| `session` | 選填 | — |

回應：`data.amount`（轉出前餘額）、`data.balance`（**必為 0**）、`data.currency`

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
| `roundid` | ✅ | 與 Rollout/TakeAll 同一局 |
| `validbet` | ✅ | 有效投注 |
| `bet` | ✅ | 下注金額 |
| `win` | ✅ | 派彩金額 |
| `amount` | ✅ | 結算後轉回金額（見損益公式） |
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

### Payoff（未實作）

```
POST /transaction/user/payoff
```

| 參數 | 必填 | 說明 |
|---|---|---|
| `account` | ✅ | 玩家帳號 |
| `eventTime` | ✅ | RFC3339 |
| `amount` | ✅ | 派彩金額 |
| `mtcode` | ✅ | 冪等鍵 |
| `promoid` | 選填 | 活動 ID |
| `remark` | 選填 | 備註 |

### Batch Bets（未實作）

```
POST /transaction/game/bets
Content-Type: application/json
```

| 參數 | 說明 |
|---|---|
| `account` | 玩家帳號 |
| `gamehall` | — |
| `gamecode` | — |
| `data` | array，每筆含 `mtcode`/`amount`/`roundid`/`eventtime`（最多 200 筆） |
| `createTime` | RFC3339 |
| `session` | 選填 |
| `genre` | 選填 |

全成功或全失敗（原子性）。

### Refunds（未實作）

```
POST /transaction/game/refunds
Content-Type: application/json
```

params：`mtcode`（string array，最多 200 筆，為原 Bet mtcode）

### Cancel（未實作）

```
POST /transaction/game/cancel
Content-Type: application/json
```

params：`mtcode`（string array，最多 200 筆）；取消退款後恢復可派彩狀態。

### Wins（未實作）

```
POST /transaction/game/wins
Content-Type: application/json
```

body：`list` array，每筆含：

| 欄位 | 說明 |
|---|---|
| `account` | 玩家帳號 |
| `eventtime` | RFC3339 |
| `ucode` | 唯一碼 |
| `event` | array，每筆含 `mtcode`/`amount`/`roundid`/`eventtime`/`gamecode`/`gamehall`/`validbet`（最多 200 筆） |

支援部分成功，回應含 `success` + `failed` 兩部分。

### Amends（未實作）

```
POST /transaction/game/amends
Content-Type: application/json
```

body：`list` array，每筆含：

| 欄位 | 說明 |
|---|---|
| `account` | 玩家帳號 |
| `eventtime` | RFC3339 |
| `amount` | 調整金額 |
| `action` | `credit` 或 `debit` |
| `ucode` | 唯一碼 |
| `event` | array，每筆含 `mtcode`/`amount`/`validbet`/`action`/`roundid`/`eventtime`/`gamecode`（最多 200 筆） |

支援部分成功。

### Amend（未實作）

```
POST /transaction/game/amend
Content-Type: application/json
```

| 欄位 | 說明 |
|---|---|
| `account` | 玩家帳號 |
| `gamehall` | — |
| `gamecode` | — |
| `action` | `credit` 或 `debit` |
| `amount` | 調整金額 |
| `createTime` | RFC3339 |
| `data` | array（含各筆調整資訊） |

全成功或全失敗（原子性）。

---

## Gameboy API（EC → MW）

Base URL 由 MW 提供（正式/測試分開，存入 `vendor_infos.info.apiUrl`）。

### API 列表

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
| 代理報表 | GET | `/gameboy/report/ss` | 依代理彙總（slot/fish/table/arcade） |
| 彩票報表 | GET | `/gameboy/report/ss/lotto` | Lotto 類報表 |

> 所有查詢 API 時區均為 **UTC-4**。

### 取得遊戲大廳連結

```
POST /gameboy/player/sw/lobbylink
Headers: Authorization: {token}, Content-Type: application/x-www-form-urlencoded
```

| 參數 | 必填 | 說明 |
|---|---|---|
| `account` | ✅ | 玩家帳號（最大 36 字元） |
| `lang` | 選填 | 語言代碼（`zh-cn`/`en`/`th`/`vn`）；預設 `en` |
| `session` | 選填 | SessionID，將帶入後續 Bet/Rollout/TakeAll |
| `app` | 選填 | 是否 App（`Y`/`N`）；預設 `N` |
| `detect` | 選填 | 是否開啟不合規瀏覽器提示（`Y`/`N`）；預設 `N` |

回應：`data.url`（大廳連結）、`data.token`

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
| `gameplat` | ✅ | 平台（`web` 或 `mobile`）；不符預設為 `web` |
| `lang` | ✅ | 語言代碼 |
| `session` | 選填 | SessionID |
| `app` | 選填 | 是否 App |
| `detect` | 選填 | 是否開啟不合規提示 |

回應：`data.url`（遊戲連結）、`data.token`

可選 URL 後綴：`&tabletype=baccarat`、`&tableid=XXX`、`&nolobby=true`、`&LeaveButton=Y`、`&leaveUrl={{URL}}`

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
| `23` | 單一 CQ9 遊戲維護中 |
| `26` | 所有 CQ9 遊戲維護中 |
| `28` | 幣別不支援 |
| `33` | 交易進行中，請稍後再查 |
| `100` | 系統異常 |
| `200` | 代理被凍結 |
| `201` | 代理被停用 |
| `202` | 玩家被停用 |

---

## 實作與文件落差

1. **`rollout` 路由對應到 `bet` Controller 方法**：`Route::post('transaction/game/rollout', [MotivationSWController::class, 'bet'])`，Bet.php 以 `$request->segment()` 最後一段判斷 `bet`/`rollout`，分別寫入對應 enum。Rollout 文件欄位與 Bet 不完全相同（無 `freegame`/`bonus`/`luckydraw`），共用 FormRequest 邊界待 RD 確認。

2. **`TakeAll` 尚未實作**：文件定義 `POST /transaction/game/takeall`，路由及 `MotivationSWController` 均無對應方法。牌桌/魚機/街機全額轉出模式需要時須補——待 RD 確認。

3. **`Payoff` 未實作**：活動獎勵派發 `POST /transaction/user/payoff` 無對應處理——待確認是否需要。

4. **體彩相關 API 未實作**：`Batch Bets`/`Refunds`/`Cancel`/`Wins`/`Amends`/`Amend` 均未見實作，接入體彩遊戲時須補齊——待確認。

5. ~~`Credit`/`Debit` 回傳餘額未乘以 `rate`~~：已修正。`MotivationSWController::debit()`／`credit()` 回傳 `Common::numMul($walletDto->wallet->balance, $rate, 4)`，與 `rollin()` 同向。

6. ~~HTTP status code 方向問題~~：已修正。回應固定 HTTP 200，錯誤碼寫在 `status.code`；`MotivationSWController` 改繼承 `BaseController` 後由 `renderCallbackError()` 統一組回應。
