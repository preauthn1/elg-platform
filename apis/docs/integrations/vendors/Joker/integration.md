# Joker（vendor_id=2）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| Vendor ID | `VendorIdEnum::JOKER = 2` |
| 錢包模式 | Transfer Wallet（轉帳錢包） |
| Endpoint 型態 | **單一 endpoint**，以 body 的 `Method` 欄位區分操作 |
| HTTP Method | 一律 `POST`，`Content-Type: application/json` |
| 簽名演算法 | HMAC-SHA1（key 排序 query string → HMAC-SHA1 raw → Base64 → URL-encode） |
| 簽名附加位置 | URL query：`?AppID={appid}&Signature={signature}` |
| 成功 / 失敗判定 | HTTP status code（2xx 成功；4xx 客戶端錯誤；5xx 三方錯誤 → 打 TCH 確認） |
| 資料保留期 | **14 天**（TS/TSM 一次最多查 1 小時，資料延遲 10 分鐘） |
| 注意事項 | 本 vid=2 與 JokerBaccarat（vid=177/235）完全不同，請勿混用 |

---

## 高風險陷阱

### 1. `Method=RT` vs 官方 `Method=PLAY`（取遊戲 Token）

`Driver::getGameUrl()`（`app/Vendors/Joker/Driver.php` line 76）送出 `Method=RT`，但官方 PDF §5.2 與下載包 PHP 範例均用 `Method=PLAY`。`RT` 在 v2.1.9 文件中無任何記載，推測為舊版（v1.x）或未公開別名。目前能執行代表 Joker 端仍接受，但**無官方文件支撐**，未來廠商可能靜默下架。建議向 Joker 客服確認是否為 `PLAY` 相容別名，必要時改用文件化的 `PLAY`。

### 2. `redirecrUrl` 拼字錯誤 + 缺 `mobile` / `lang` 參數

`Driver`（line 87）組遊戲 URL 時：
- 官方參數名 `redirectUrl`，實作拼成 `redirecrUrl`（缺一個 `t`）。
- 官方 URL 格式需帶 `mobile`（boolean）與 `lang`（ISO 639-1），實作未帶入。
- `pc` / `mobile` 兩個欄位回傳同一 URL，未以 `mobile=true/false` 區分版面。

影響：玩家可能無法正確取得手機版面、無法帶語系。屬實作待補，非 Joker 端限制。

### 3. TC 轉帳缺少零值 / 負值守衛

官方下載包 PHP `TransferCreditToJoker` / `TransferCreditOutJoker` 在打 TC 前會先做：
- `Amount < 0` → 視為失敗、不打 API
- `Amount == 0` → 視為成功、不打 API

`Driver::transfer()`（line 142）未做此守衛，直接送出 TC。`Amount=0` 是否被 Joker 端接受未確認，建議補上。

### 4. JokerBaccarat 混淆風險

| 項目 | Joker（vid=2）| JokerBaccarat（vid=177/235）|
|---|---|---|
| 簽名 | HMAC-SHA1 + Base64 + URL-encode | 3DES + MD5 衍生金鑰 |
| 文件位置 | `api.md`（本目錄） | `../JokerBaccarat/` |
| API 格式 | 單一 endpoint + Method 欄位 | 完全不同 |

**絕對不可混用**，尤其簽名邏輯完全不同，混用後 Joker 端驗章必失敗。

### 5. 5xx → TCH 的分層路徑

官方 PHP 範例在 TC 收到 5xx 時會**自動在同一個方法內**呼叫 `verifyTransfer`（TCH）。  
本平台實作路徑不同：`Driver::transfer()` 對非陣列回應回傳 `TIMEOUT`，由上層 `AbstractTransferWallet` 偵測 `TIMEOUT` 後再呼叫 `checkTransferStatus()`（TCH）。  
**流程等效**，但需確認 `AbstractTransferWallet` 確實會在 `TIMEOUT` 後觸發 `checkTransferStatus`，否則轉帳狀態將無法驗證。

---

## 錢包流程（Transfer Wallet）

```
玩家點擊遊戲
   │
   ▼
（可選）TC 正數：代理錢包 → Joker 轉入        ← transferToVendor()
   │
   ▼
PLAY（或 RT）：取得一次性 Token
   │  ⚠️ Token 只能用一次，用過即失效
   ▼
組遊戲 URL：{game-url}?token=[REDACTED]
   │
   ▼
玩家在 Joker 端遊玩（注單由 Joker 保管）
   │
   ▼
退出：TC 負數 或 WAC（全額提款）              ← transferToLocal()
   │
   ▼
逾時 / 5xx → TCH（同一 RequestID 驗證）      ← checkTransferStatus()
   │    HTTP 200 = 成功；HTTP 404 = 未成立（失敗）
   ▼
結束
```

### 轉帳狀態判定

| 情況 | HTTP 狀態 | 平台判定 |
|---|---|---|
| TC 成功 | 2xx | `SUCCESS` |
| TC 失敗（語法錯誤等） | 4xx | `FAILED` |
| TC 逾時 / 三方系統錯誤 | 5xx 或無回應 | `TIMEOUT` → 改打 TCH |
| TCH 確認成功 | 200 | `SUCCESS` |
| TCH 確認未成立 | 404 | `FAILED` |
| TCH 本身逾時 | 非陣列回應 | `TIMEOUT` |

---

## 冪等性

- **TC / PLAY(b) / WAC** 均帶 `RequestID`（最大 50 字母數字，唯一密鑰）。
- **TCH** 以相同 `RequestID` 查詢同一筆轉帳是否成立，是 Transfer Wallet 的標準冪等保護。
- HTTP 404（TCH）= 該 `RequestID` 不存在於 Joker 端 = 轉帳**確定未成立**，可安全視為失敗後重試（需換新 `RequestID`）。
- TS / TSM 資料延遲 10 分鐘，對帳工具勿以即時性假設。

---

## 整合落地建議

| Joker 概念 | APIs 專案對應 |
|---|---|
| Vendor ID | `VendorIdEnum::JOKER = 2`（`app/Enums/Vendor/VendorIdEnum.php`） |
| Driver 路徑 | `app/Vendors/Joker/Driver.php`（繼承 `AbstractTransferWallet`） |
| Driver 註冊 | `config/vendor.php`：`VendorIdEnum::JOKER->value => Vendors\Joker\Driver::class` |
| 帳號 Model | `App\Vendors\Models\JokerAccount`（`$accountModel`） |
| 注單 Model | `App\Vendors\Models\JokerBetRecord`（`$betRecordModel`） |
| `api-url` | `vendor_infos.info.API_URL`（單一 endpoint） |
| `AppID` | `vendor_infos.info.APP_ID` |
| `Secret`（簽章金鑰） | `vendor_infos.info.APP_SECRET` |
| 玩家 username | `JokerAccount.username`（含 `<AppID>.` 前綴） |
| 存款（transferToVendor） | `TC` 正數 |
| 提款（transferToLocal） | `TC`（金額先 `Common::numMul($amount, -1)`）或 `WAC` |
| 轉帳驗證（checkTransferStatus） | `TCH` |
| 餘額查詢（getBalance） | `GC` |
| 遊戲 URL（getGameUrl） | `Method=RT`（現行）→ 建議確認是否改 `PLAY` |
| 注單對帳 | `TS`/`TSM`/`TRX`/`RWL`，在 14 天保留期內每日拉取，寫入 `joker_bet_records` |
| 對帳保留期 | **14 天**；TS 一次只能查 1 小時 |

---

## Gaps / 待 Joker 確認

1. **`Method=RT` 是否為 `PLAY` 的正式別名？** 官方 v2.1.9 文件無此 Method，目前實作能跑但無依據，廠商是否計畫下架？
2. **`Amount=0` 打 TC 的行為**：Joker 端是否接受、回傳什麼 HTTP 狀態？（官方 PHP 範例建議不打，直接視為 Success）
3. **`redirectUrl` 接受拼字錯誤版 `redirecrUrl` 嗎？** 目前實作帶錯字 key，Joker 端是否靜默忽略？
4. **`mobile` 與 `lang` 缺席對遊戲體驗的實際影響**：Joker 是否有 fallback 預設值？
5. **`TODO: 5.5.验证转移信`（Driver line 53）**：此 TODO 是否過時？`checkTransferStatus` 已實作 TCH，可確認後清除。
