# Plus（vendor_id=46）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 轉帳錢包（Transfer Wallet）；玩家餘額由 Plus 端保管，進入遊戲前轉入、離開後轉出 |
| 流向 | **本平台 → Plus**（本平台主動呼叫 Plus API；無回調，Plus 不呼叫本平台） |
| 驗證 | IP 白名單 + 每請求 HMAC-SHA256 `hash`（放 URL query string） |
| 成功判定 | `status === "success"` |
| 金額格式 | 字串，最多 4 位小數 |
| 時區 | UTC（時間戳單位：毫秒） |
| 語系 | 僅支援 `zh-CN` / `en`（`ZH_TW`、`ZH_CN` 皆映射為 `zh-CN`） |

## 簽章陷阱（高風險）

PHP `json_encode` 必須帶 `JSON_UNESCAPED_UNICODE`，否則中文字被 escape 成 `\uXXXX`，Plus 端重算的 hash 與送出的不符：

```php
$json_str = json_encode($message, JSON_UNESCAPED_UNICODE);
$hash = hash_hmac('SHA256', $json_str, $API_key);
```

> `Driver::hash()` 已正確實作此旗標。新增或修改 API 請求時務必確認 `JSON_UNESCAPED_UNICODE` 仍在。

## 冪等性

`wp_023 transactionId is exist` 代表先前那筆已存在（不應視為新失敗）。收到此碼應改呼叫 `transferRecordById` 確認原交易結果，而非直接回 FAILED。目前 `checkTransferStatus()` 依賴此 API，回傳語意：

| 情況 | 映射 |
|---|---|
| 回應非陣列 / 缺 `status` | `TIMEOUT`（無法確認，不擅自還錢） |
| `status != 'success'` | `FAILED` |
| `status == 'success'` | `SUCCESS` |

## 錢包流程

```
建帳 createUser
       │
       ▼
deposit（本地 → Plus，transferToVendor，amount 為正字串）
       │
       ▼
enterGame 取得遊戲 URL → 玩家遊玩（餘額在 Plus 端）
       │
       ▼
withdraw（Plus → 本地，transferToLocal，固定 takeAll=0）
       │
補查：checkTransferStatus → transferRecordById
```

- `transactionId` 唯一、最長 36 字元、建議 UUID。
- `transferToLocal` 固定 `takeAll=0`（部分提出），屬設計選擇，不使用 `takeAll=1`。

## 整合落地建議

| Plus 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Plus/Driver.php`（`AbstractTransferWallet`） |
| Account Model | `App\Vendors\Models\PlusAccount` |
| BetRecord Model | `App\Vendors\Models\PlusBetRecord` |
| config 註冊 | `config/vendor.php`：`VendorIdEnum::PLUS->value (46) => Vendors\Plus\Driver::class` |
| Callback Controller | **無**（轉帳錢包，Plus 不呼叫本平台） |
| Routes | **無**（轉帳錢包，無回調路由） |

### vendor_infos.info 欄位

| info 欄位 | Plus 概念 | 說明 |
|---|---|---|
| `API_URL` | `url` | API base URL（Driver 建構子會 `trim('/')` 去尾斜線） |
| `MERCH` | `merch` | 商戶帳號 |
| `API_KEY` | `API key` | HMAC-SHA256 簽章金鑰 |
| `CURRENCY` | `currency` | 幣別（建帳用，例如 `CNY`、`IDR`） |

### Driver 方法 ↔ Plus API 對應

| Driver 方法 | Endpoint | Plus API |
|---|---|---|
| `getGameUrl()` | `{API_URL}/m/enterGame` | 2.5 enterGame |
| `getBalance()` | `{API_URL}/m/playerBalance` | 2.7 playerBalance |
| `checkTransferStatus()` | `{API_URL}/m/transferRecordById` | 2.10 transferRecordById |
| `transferToVendor()` | `{API_URL}/m/deposit` | 2.2 deposit |
| `transferToLocal()` | `{API_URL}/m/withdraw` | 2.3 withdraw |

> **endpoint 多了 `m/` 前綴**：文件 URL 為 `{url}/deposit` 等，但 Driver 實際打 `{API_URL}/m/deposit` 等（見下方 gaps §1）。

## Gaps / 待 Plus 確認

1. **endpoint `m/` 前綴**：官方文件路徑為 `{url}/deposit`、`{url}/enterGame` 等；Driver 實際呼叫 `{API_URL}/m/deposit`、`{API_URL}/m/enterGame` 等。推測 Plus 正式環境路由帶 `m/`（手機 / merchant 路徑），需向 Plus 客服確認正式 endpoint 路徑，避免測試環境可行但正式不通。

2. **`checkTransferStatus()` 未比對 `transactionId`**：呼叫 `transferRecordById` 後只看 `status`，未交叉驗證回傳的 `transactionId` / `amount` 是否吻合查詢值。若 Plus 在「查無此筆」時也回 `status:"success"`，可能誤判成功。建議補：成功時再比對 `transactionId` 一致才回 `SUCCESS`。

3. **對帳資料保留期**：官方文件未明示 `roundRecordByTime`、`transferRecordByTime` 可回溯的最大區間，需向 Plus 客服確認後再設定對帳工具查詢窗口。
