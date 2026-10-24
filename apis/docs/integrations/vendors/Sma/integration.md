# Sma（vendor_id=77）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 轉帳錢包（Transfer Wallet）；玩家餘額由 Sma 端保管 |
| 回調方向 | **無**（Transfer Wallet 無下注回調；本平台僅主動呼叫 Sma） |
| 驗證 | MD5 簽名（`sign` 欄位）+ `appId` + 毫秒 `timestamp` |
| 成功判定 | 一般接口 `(string) code === '200'`；`checkToken` 嚴格 `code === 0`（int）|
| 時區 | 文件未指定（timestamp 毫秒級，伺服器端判定） |
| 餘額查詢 | `wallet/queryBalance`；Driver 把 `data` 當純數值而非 object（見落差 §1） |

## 實作陷阱

### 1. `queryBalance` `data` 結構不符（高風險）

官方文件 `wallet/queryBalance` 回傳 `data` 為 object（`data.balance`），但 Driver `getBalance()` 直接把 `$result['data']` 當純數值（`is_numeric($result['data'])`、`* 100`）。若 Sma 對 Transfer Wallet 實際回傳 object，`is_numeric` 會失敗導致餘額讀為 0。**上線前須以實際回應驗證，若為 object 需改 `$result['data']['balance']`。**

### 2. `code` 成功值語意不一致（高風險）

全域錯誤碼表 `200` = Success；但 `checkToken` 以 `code = 0` 為有效、`code = 5` 為過期。Driver 已按介面分開處理：login/deposit/withdraw/queryBalance 以 `'200'`，`checkToken` 以嚴格 int `0`。**新接口須確認適用哪套判定邏輯。**

### 3. `appId` 大小寫敏感

文件部分介面 body 寫 `appid`（小寫：createAccount、checkToken），部分寫 `appId`（login、deposit）。Driver 一律送 `appId`。簽名以實際 key 排序計算，key 大小寫不一致會造成 `code = 4 Invalid Signature`。**待與 Sma 確認。**

### 4. timestamp 需回退 3 秒

Driver `getPreciseTimestamp(3)` 取毫秒，並以 `now()->subSeconds(3)` 回退。官方文件未明示此限制；若不回退實際回傳錯誤（RD 經驗值）。

### 5. `wallet/init` 未實作

文件定義初始化錢包接口 `wallet/init`（設定 `walletMode=1`），但 Driver 無對應呼叫。若 Sma 要求首次建立玩家必須先 init，需補上。**列為 gap，待與 Sma 確認是否必要。**

## 轉帳錢包流程

```
createAccount（首次建立）
    │
    ▼
player/login → 取 token（或 checkToken 驗既有 token）
    │（token 無效則重新 login）
    ▼
wallet/deposit → 上分（transferToVendor）
    │
    ▼
player/openGame（GET）→ 產生遊戲 URL，導向玩家
    │
    ▼
wallet/withdraw → 下分（transferToLocal）
    │
    ▼
report/queryOrder → 轉帳查單 / TIMEOUT 收斂
```

## 遊戲清單同步

`Driver::getGameList()` 遷自 RD-Studio `get_sma_games`，打 `/api/v2/ext/gameList`（僅共同欄位 `appId`／`timestamp`／`sign`，timestamp 回退 5 秒）。Driver 標 `ShouldSyncGameList`，由 `update_game_list` 每小時排程逐商派發；也可在 Olympus 系統操作頁手動刷。

| 三方欄位 | 落地 | 說明 |
|---|---|---|
| `id` | `games.game_type` | 唯一鍵；缺 `id` 的列略過 |
| `name` | `games.name` | 單一語系，不寫 `game_names` |
| `squareIcon` | `games.image` | 方形圖；`portraitIcon`／`loadingPic` 不落地 |
| `type` | `games.type_id` | `slots` → SLOTS(2)、`fishing` → FISHING(5)；表外值落 EVENT_AND_OTHERS(98) 並記 warning |
| — | `games.status` | 新增列預設 `1`（沿用舊指令行為，本商為少數新遊戲直接上架者） |

與舊指令的差異（刻意不沿用）：

- 舊指令 `--close` 是「先全關再把清單內的開回」，屬差集互踩事故型；新機制 `GameListSyncService` 為純新增，「DB 有、API 無」只偵測「三方未提供」記 warning 提示人工關閉，**不會自動停用任何遊戲**，也不覆蓋人工上下架狀態。
- 舊指令名稱不同時會連 `image` 一起 update；新機制既有遊戲欄位一律不動，由後台人工維護。
- 三方 HTTP 200 但 `code` 非 `200` 時回空清單並記 error log，呼叫端遇空清單提前 return，不會把整批遊戲誤報成「三方未提供」；HTTP 層錯誤則拋 `ApiException` 讓排程失敗可見。

## 冪等性與轉帳結果判定

Driver `transfer()` 結果對應：

| 情況 | 回傳 |
|---|---|
| HTTP 例外 / `result` 非陣列 / 缺 `code` | `VendorTransferResultEnum::TIMEOUT` |
| `(string) code === '200'` | `VendorTransferResultEnum::SUCCESS` |
| 其他 code（含 26/27 等失敗碼） | `VendorTransferResultEnum::FAILED` |

TIMEOUT 後以 `report/queryOrder`（精準查詢帶 `requestId`）確認 `state` 狀態機：

| `state` | 含義 |
|---|---|
| `0` | 未進分 |
| `200` | 進分成功 |
| `1` / `2` | 中間態（更新分數中） |
| `-1` / `-2` | 失敗 |

## 整合落地對照

| Sma 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Sma/Driver.php`（繼承 `AbstractTransferWallet`） |
| API base URL | `vendor_infos.info.API_URL` |
| `appId` | `vendor_infos.info.API_APPID` |
| Secret Key（簽名金鑰） | `vendor_infos.info.API_SECRET_KEY` |
| 遊戲返回 URL | `vendor_infos.info.BACK_URL`（`getGameUrl` 會 `base64_encode`） |
| 關閉返回按鈕 | `vendor_infos.info.CLOSE_BACK`（`1`＝關閉；`0`＝不關閉） |
| 玩家帳號 / token | `sma_accounts.username` / `sma_accounts.token`（`App\Vendors\Models\SmaAccount`） |
| 下注記錄 | `App\Vendors\Models\SmaBetRecord` |
| 遊戲清單同步 | Driver `getGameList()` 打 `/api/v2/ext/gameList`，標 `ShouldSyncGameList` 進 `update_game_list` 每小時排程；亦可 Olympus 系統操作手動刷（見下方「遊戲清單同步」） |
| 註冊 | `config/vendor.php`：`VendorIdEnum::SMA->value => Vendors\Sma\Driver::class` |
| Callback Controller | **無**（Transfer Wallet 無下注回調） |
| Routes | **無**（無 external sw/tw 路由；本地只主動呼叫 Sma） |
| 對帳資料來源 | `report/queryOrder`（轉帳，3 個月 / 區間 ≤3 天）、`report/gameBet`（下注）、`report/daily`（每日彙總，6 個月）、`report/activePlayer`（6 個月 / 區間 ≤1 天） |
| 對帳保留期 | 轉帳記錄 **3 個月**；統計類（gameBet/daily/activePlayer）**6 個月** |

## Gaps / 待 Sma 確認

- **`queryBalance` `data` 結構**：實際為 object 還是純數值（文件與實作不一致）
- **`appId` vs `appid`**：各介面 body 的 key 大小寫（影響簽名正確性）
- **`wallet/init`**：是否需要在 createAccount 後呼叫
- **lang 代碼 `my`/`ida`**：Driver 額外映射，官方附錄未列，是否被 Sma 接受
- **`code = 26/27`**：不在全域錯誤碼表，deposit/withdraw 特有；Sma 是否有其他特有碼
- **deposit 介面描述「下分」**：疑文件 copy-paste typo，不影響欄位但列為確認項
- **`report/gameBet` `bet`/`win` desc 文字**：與欄位名相反，以欄位名為準但建議確認
