# SunCity（iGaming Clubsuncity）— API 規格

> 來源：
> - `iGaming_API_Guid_v10.1`（iGaming Document v10.1，2022-05，中文版）— 工單 `target/tickets#4434` 附件
>
> 對應 vendor：`VendorIdEnum::SUNCITY`（Transfer Wallet），程式識別名 `Suncity`，Driver `app/Vendors/Suncity/Driver.php`（繼承 `App\Vendors\Play8oy\Driver`）。

**規格與 [`../Play8oy/api.md`](../Play8oy/api.md) 完全相同（同一 iGaming 平台、同一版本 v10.1），API 清單、Request／Response 欄位、簽名演算法、錯誤碼一律以該檔為準。** 本檔只記 SunCity 的差異，以及本次實作直接依賴的拉單規格摘錄。

---

## SunCity 差異

### App 自動登入 deep-link（附錄 5）

```
lobbyclubsuncity://lobbyclubsuncity?account={帳號}&password=[REDACTED]
```

Play8oy 為 `lobbyplay8oy://lobbyplay8oy?…`，兩者只差 scheme；Driver 以 `$appScheme` 屬性區分（Play8oy=`lobbyplay8oy`、SunCity=`lobbyclubsuncity`）。

### 環境資訊（staging）

| 項目 | 值 |
|---|---|
| API URL | `https://example.invalid/` |
| 後台（KioskBackend） | `https://example.invalid/KioskBackend/` |

三方以 IP 白名單限制請求，dev／正式出口 IP 需先提供給三方。

staging 憑證過期：`*.example.invalid` 的 TLS 憑證自 2025-09-29 過期，驗證會 cURL 60 失敗，dev 商戶 info 需設 `VERIFY_SSL=false`。正式商戶不設（缺省驗證憑證），禁止設 false。

### 帳號結構（Master／Agent）

三方開了兩組帳號：Master（總代）與 Agent（網吧／Kiosk）。**本次採用 Agent 組**：

| 欄位 | `vendor_infos` 對應 | 值 |
|---|---|---|
| merchantName | `info.API_MERCHANT_NAME`（header `merchantName`） | `hero9stg` |
| Kiosk（Agent） | `info.API_AGENT`（`player/create` 的 `Agent`） | `hero9stg01` |
| 玩家帳號前綴 | `account_prefix` | `hero9stg_` |
| PrivateKey | `info.API_KEY` | 機敏資訊，不入文件，見工單 |

### 幣值（MYR 1:100）

三方以 MYR 1:100 呈現，**轉換由三方負責**：API 傳入／回傳的金額即實際金額，僅遊戲畫面顯示 ×100。因此 `vendor_infos.rate = 1`。

---

## 拉單相關摘錄（本次實作依據）

Play8oy 由舊系統拉單，APIs 從未實作 `game/log/record`；SunCity 由 APIs 的 `fetch_bet_record` 排程拉單，以下為實作直接依賴的規格。

### `game/log/record` — Request

| 欄位 | 必填 | 說明 |
|---|---|---|
| `PageNumber` | ✅ | 頁碼，自 1 起 |
| `StartDate` | ✅ | 起始時間，格式 `Y-m-d H:i:s.v`（如 `2026-09-29 00:40:00.000`） |
| `EndDate` | ✅ | 結束時間，格式同上（如 `2026-09-29 00:44:59.999`） |

- **時間為 UTC+8**。
- `StartDate`、`EndDate` 必須**同一日**，區間 **≤ 600 秒**。
- 簽名同其他端點（body 參數升冪 + `&PrivateKey=`）。

### `game/log/record` — Response

```jsonc
{
  "Code": 0,
  "Message": "Success.",
  "Data": {
    "Page": { "PageNumber": 1, "PageSize": …, "TotalSize": …, "TotalPage": 1 },
    "Record": [
      {
        "ThemeID": 57060001,          // 前四碼 = 遊戲 ID（對應 games.game_type），後四碼 = 廳館 ID
        "GameNO": "…",                // 局號
        "GameSerialID": 345740679818, // 注單序號（本專案冪等鍵 bet_id）
        "UserName": "hero9stg_…",     // 玩家帳號（= suncity_accounts.username）
        "BetCoin": 0.9,               // 下注金額
        "WinAmount": 81.21,           // 贏分
        "AvailTotalBet": 0.9,         // 有效下注
        "AvailTotalWin": 81.21,       // 有效贏分
        "CreateTime": "2026-09-29T11:46:36.047" // 紀錄時間（UTC+8）
      }
    ]
  }
}
```

只回已結算的結果單；`Data.Page.TotalPage` > 1 時需逐頁續拉。

### `game/log/record` — 錯誤碼

| Code | 說明 | 本專案處理 |
|---|---|---|
| `-50001` | 查無資料 | 視為該窗口無注單，回空陣列 |
| `-50003`／`-50004`／`-50005`／`-50020` | 時間條件錯誤（`-50004` 跨日、`-50020` 超過 600 秒等） | 拋例外，片失敗重跑 |
| 其他非 0 | 見 Play8oy api.md 錯誤碼彙整 | 拋例外，片失敗重跑 |

### 頻率限制（附錄 6）

| 端點 | 每分鐘上限 |
|---|---|
| `game/log/record` | 40 |
| `transaction/create`、`player/create` | 20 |
| `player/info` | 200 |

文件建議拉單頻率：**每 1 分鐘取 20 分鐘前的 1 分鐘資料**（三方資料延遲約 20 分鐘）。

---

## 簽名驗證常數（測試用）

文件範例（建立玩家）：

```
Agent=RD_TestEntity1&Password=[REDACTED]
```

SHA256 raw bytes → Base64：

```
7XxkcLhd+AbNO2xf7M+VPNUYJ6zuYFcLn1SudAZVp10=
```

`sign()` 繼承自 Play8oy：參數名升冪後以原始值串成 `key=value&…&PrivateKey={key}`（不經 `http_build_query`、不做 URL 編碼），`StartDate`／`EndDate` 的空白與冒號、staging 金鑰含的 `%`／`?`／`#`／`$` 皆原樣進字串，與文件 PHP 範例一致。
