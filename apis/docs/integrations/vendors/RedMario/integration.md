# RedMario（vendor_id=32）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | Transfer Wallet；玩家餘額由 RedMario 端持有，代理透過 `updateBalance` 轉入/轉出 |
| 回調方向 | **無**（Transfer Wallet，RedMario 不回調本平台；Seamless 規格存在但未實作） |
| HTTP Method | POST（`application/x-www-form-urlencoded`） |
| 驗證 | `api_id` + `sign`（`md5(api_id + timestamp + apiKey)`） |
| 成功判定 | `code === 0` |
| 伺服器時區 | GMT+08:00（Kuala Lumpur / Singapore） |
| Base URL 格式 | **尾端必須帶 `/`**（Driver 直接拼接路徑，不補斜線） |
| 品牌 | 官方 PDF 品牌為「MARIO CLUB / marioclub88」；測試後台 `redmario.club`；專案代號 `RED_MARIO` |

## 高風險陷阱

### 1. `timestamp` 秒 vs 毫秒（高風險）

官方文件標示 `timestamp` 長度為 **13**（毫秒），Seamless 範例值 `1605970770769`（13 位）；但本平台 Driver 以 `date('U')` 產生**秒級 10 位**字串。

簽名計算 `md5(api_id + timestamp + apiKey)` 中 `timestamp` 是字串拼接，若三方僅做字串比對則無影響；但若三方對 `timestamp` 另做毫秒時效窗驗證，**秒級值可能導致驗簽或時效失敗**。

> **上線前須向客服確認三方對 `timestamp` 的長度與時效要求。**

### 2. `code=109` 被視為永久 FAILED（高風險）

現行 `transfer()` 對任何 `code != 0` 一律回 `VendorTransferResultEnum::FAILED`，包含：

| Code | 說明 | 正確語意 |
|---|---|---|
| `109` | Wallet lock（玩家正進行促銷活動，錢包暫鎖） | **暫時性 / 可重試**，非永久失敗 |

`109` 的性質（暫時性鎖定）與 `103` 餘額不足、`106` 帳號類型無效（確定失敗）不同。目前實作無區分，可能導致本應暫掛的轉帳被判為最終失敗。

> 確認是否需對 `109` 做重試或暫掛處理。

### 3. Base URL 尾端必須帶 `/`

Driver 以 `$this->apiUrl . 'api/acc/gameLogIn'` 直接拼接（無補斜線）。若 `vendor_infos.info.API_URL` 沒有尾端 `/`，會拼出 `...comapi/acc/...` 的錯誤路徑，所有 API 呼叫均失敗。

> 設定 `API_URL` 時一律確保尾端帶 `/`。

## 錢包流程

```
建立帳號 /api/acc/created
  │
  ▼
存款（本地 → RedMario）
  transferToVendor() → updateBalance action=deposit
  │
  ▼
進入遊戲
  getGameUrl() → gameLogIn → 回傳 gameUrl
  │
  ▼
玩家遊玩（下注 / 派彩在 RedMario 端結算）
  │
  ▼
提款（RedMario → 本地）
  transferToLocal() → updateBalance action=withdraw
  │
  ▼
查單核對
  checkTransferStatus() → getTransactionLog (reference_id)
```

`transfer()` 回傳語意：

| 情況 | 結果 |
|---|---|
| 非 JSON | `TIMEOUT` |
| `code` 缺失 / 非數字 | 拋 `ApiException`（format error） |
| `code != 0` | `FAILED`（含 `109`，見陷阱 §2） |
| `code == 0` | `SUCCESS` |

`checkTransferStatus()`：以 `reference_id` 查 `getTransactionLog`，`code==0` 且 `walletLogDTO` 非空 → `SUCCESS`；否則 `FAILED`；非 JSON → `TIMEOUT`。目前未核對 `amount` 方向與金額（見 Gaps §3）。

## 版本差異（v1.1 vs v2.0）

| 項目 | v1.1（2020）| v2.0（2025，主版本）|
|---|---|---|
| `getGameLog` 時間限制 | 72 小時 | 24 小時 |
| `code=109` | 不存在 | 新增（Wallet lock） |
| `/api/acc/getGameLogGroup` | 不存在 | 新增（分頁 1500 筆） |
| `created` / `update` `password` 欄位 | 不存在 | 新增（optional） |
| `update` `status=kick` | 不存在 | 新增 |
| `gameIconUrl`（遊戲圖標） | 不存在 | 新增 |
| 測試 Base URL | `example.invalid` | `demoapi.marioclub88.online` |

> 主平台以 **v2.0** 為準。

## 整合落地建議

| RedMario 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/RedMario/Driver.php`（繼承 `AbstractTransferWallet`） |
| Vendor 註冊 | `config/vendor.php`：`VendorIdEnum::RED_MARIO->value`（= 32） |
| Callback / Routes | 無（Transfer Wallet 不需回調） |
| `api_id` | `vendor_infos.info.API_ID` |
| `apiKey` | `vendor_infos.info.API_KEY`（後台「API 設定」可查） |
| Base URL（**尾端帶 `/`**） | `vendor_infos.info.API_URL` |
| 玩家帳號 | `App\Vendors\Models\RmAccount`（`rm_accounts.username`） |
| 注單模型 | `App\Vendors\Models\RmBetRecord`（`rm_bet_records`） |
| 對帳資料來源 | `getGameLogGroup`（分頁，每頁 ≤ 1500）/ `getGameLog`（24h 限）/ `getWinLose`（每日彙總） |
| 對帳保留期 | 文件未明示；`getGameLog` 限 24 小時，逾期須向客服索取 |
| IP 白名單 | 文件未提及，上線前確認 |

## Gaps / 待確認

- **`timestamp` 時效驗證**：三方是否對 13 位毫秒另做時間窗校驗（當前 10 位秒級，見陷阱 §1）
- **`code=109` 處理策略**：重試、暫掛、或最終失敗（見陷阱 §2）
- **`getTransactionLog` 唯一性**：同一 `reference_id` 是否可能回多筆或方向不符的紀錄（當前僅判非空即成功）
- **正式 Base URL（v2.0）**：文件只提供測試環境，正式網域以客服提供為準
- **Seamless 雙 endpoint 衝突**：文件「驗證遊戲日志」與「驗證交易日志」路徑字面相同（`/api/seamless/validateGameLog`），疑為文件複製錯誤
- **`/api/acc/getGameLogPage`（v2.0）**：API 訪問延遲表列出此 endpoint（3s 限制），但文件無請求 / 回應規格
- **對帳保留期**：確認 `getGameLogGroup` 可回溯多久的資料
- **IP 白名單**：是否需要報備出口 IP
