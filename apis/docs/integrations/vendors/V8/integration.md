# V8（vendor_id=42）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 轉帳錢包（Transfer Wallet）；玩家餘額由 V8 端保管，本平台主動上分 / 下分 |
| 呼叫方向 | **本平台 → V8**（本平台為 client，主動呼叫方）；V8 無下注回調 |
| HTTP Method | `GET`（所有 API 皆 GET，業務參數在 query string） |
| Endpoint | `channelHandle`（錢包 / 玩家類）/ `getRecordHandle`（注單類） |
| 驗證 | IP 白名單 ＋ `param`（AES-128-ECB 加密 → base64 → urlencode）＋ `key`（MD5 簽名） |
| 成功判定 | `d.code === 0`（回應體），外層 `s` 對應請求 `s+100` |
| 金鑰欄位 | `DESKey`（實為 AES key，命名沿用舊版）、`MD5Key` |
| 多幣別 | 須先換算為遊戲幣（見 api.md 兌換比例表），遊戲幣 2 位小數以後捨棄 |
| 對帳保留期 | 官方未明確標示（**gaps**，須向 V8 客服確認） |

## 高風險陷阱

### 1. DESKey 命名 ≠ DES 演算法（必讀）

**金鑰欄位名稱叫 `DESKey`，但實際加密演算法是 AES-128-ECB，不是 DES。**

官方附件 `Encrypt.php` / `Encrypt_7.1.php` 使用舊版 DES（`mcrypt_encrypt(MCRYPT_DES, ...)`），且錯誤碼 22 的文字說明也寫「DES 解密失敗」。但所有 API 章節的 `param` 說明一律標 `Encrypt.AESEncrypt(param, DESKey)`，本專案 Driver 也以 `openssl_encrypt($message, 'aes-128-ecb', $desKey, 0)` 實作。

**必須以 AES-128-ECB 實作，切勿複製官方 PHP 附件（DES）**，否則會持續觸發 `code:22`。

### 2. `param` 不可用 `http_build_query` 拼整個 URL

`param` 已是 AES 加密後的 base64 字串，再經 `http_build_query` 對整個 URL 拼接，`param` 內的 `+`、`/`、`=` 字元會被二次編碼（`+`→`%2B`），V8 端解密必然失敗（`code:22`）。

Driver 以 `params()` 自訂方法逐段拼接 `key=val&`，**不用 `http_build_query` 拼接整體 URL**。

### 3. `s=12` 在兩個不同 API 章節語意不同

`s=12` 在 §3.2.11「取得帳戶餘額」（channelHandle + `account`）與 §3.2.16「玩家輸贏排行」（getRecordHandle + `dayTime`）語意完全不同。兩者共用同一 `s` 值是官方文件的設計衝突。接通前務必向 V8 客服確認當前版本的 `s` 值對應。

### 4. `checkTransferStatus` 下分場景的 `status=-1` 風險

Driver 將 `status=-1`（訂單不存在）與 `status=2`（失敗）皆歸 `FAILED`。對**上分**而言合理（訂單不存在 = 請求未送達，回滾）；但對**下分**而言，若 V8 已扣分但本地判 FAILED，可能出現「V8 扣了分，本地沒加分」的資損情境。上線前須配合「每 5 秒查一次，共 3 次」重試規範審視下分路徑。

### 5. 幣別換算責任邊界

官方要求上下分前先把當地幣換算為遊戲幣（捨棄遊戲幣 2 位小數以後）。Driver 的 `transfer()` 直接傳 `vendorAmount`，換算是否已在 `AbstractTransferWallet` / cashflow rate 上層完成**需 RD 確認**，避免漏算或重複換算導致金額異常。

## 錢包流程

```
玩家請求進遊戲
   │
   ▼
getGameUrl (s=0)  ── V8 端不存在帳號時自動建號；本專案傳 money=0（不附帶上分）
   │  回傳 d.url（遊戲 H5 連結，含 token / lang / skin / gameId）
   ▼
[需要轉入時] transferToVendor (s=2 上分)  本地餘額 → V8 錢包
   │
   ▼
玩家於 V8 端遊戲（下注 / 派彩皆在 V8 端結算，不回調本平台）
   │
   ▼
transferToLocal (s=3 下分)  V8 錢包 → 本地餘額
   │
   ▼
[未收到回應時] checkTransferStatus (s=4)  每 5 秒查一次，共 3 次，以 status 判定
```

## 轉帳狀態對應

| V8 `status`（s=4） | 含意 | `checkTransferStatus()` 回傳 |
|---|---|---|
| `-1` | 不存在 | `VendorTransferResultEnum::FAILED` |
| `0` | 成功 | `VendorTransferResultEnum::SUCCESS` |
| `2` | 失敗 | `VendorTransferResultEnum::FAILED` |
| `3` | 處理中 | `VendorTransferResultEnum::TIMEOUT` |
| 其他未知值 | — | `VendorTransferResultEnum::TIMEOUT` |

## 整合落地建議

| V8 概念 | APIs 專案對應 |
|---|---|
| API Base URL（`<server>:<port>`） | `vendor_infos.info.API_URL`（**正式 URL 須向 V8 客服索取**） |
| `agent` 代理編號 | `vendor_infos.info.AGENT` |
| DESKey（AES 金鑰） | `vendor_infos.info.DESKEY`（欄位名沿用，值為 AES-128-ECB key） |
| MD5Key（簽名金鑰） | `vendor_infos.info.MD5KEY` |
| `lineCode` 站點標識 | `vendor_infos.info.LINECODE`（≤10 字英數；**勿一玩家一 lineCode**） |
| 玩家帳號（`account`） | `v8_accounts.username`（`App\Vendors\Models\V8Account`） |
| Driver | `app/Vendors/V8/Driver.php`（繼承 `AbstractTransferWallet`） |
| 注單模型 | `App\Vendors\Models\V8BetRecord` |
| 回調 Controller | **無**（Transfer Wallet 無下注回調） |
| 注單同步 | `getRecordHandle` `s=6`；建議區間 1~5 分鐘，距當前 ≥30 秒；Driver 未實作，由排程負責 |
| VendorId | `VendorIdEnum::V8`（值 = `42`） |
| IP 白名單 | 須向 V8 客服提供本平台伺服器 IP 加入白名單 |

### vendor_infos.info 必要鍵

```
API_URL    // channelHandle / getRecordHandle 的 base（不含 path）
AGENT      // 代理編號（int）
DESKEY     // AES-128-ECB 金鑰（欄位名叫 DESKey，值為 AES key）
MD5KEY     // MD5 簽名金鑰
LINECODE   // 站點標識
```

## 模型對應（注單 s=6）

| V8 欄位 | 類型 | BetRecord 欄位 |
|---|---|---|
| `list.GameID` | Array | `game_id`（遊戲局號） |
| `list.Accounts` | Array | `account`（玩家帳號） |
| `list.KindID` | Array | `kind_id`（遊戲類型） |
| `list.CellScore` | Array | `valid_bet`（有效下注） |
| `list.AllBet` | Array | `bet`（總下注） |
| `list.Profit` | Array | `win_lose`（盈利，正=玩家贏） |
| `list.Revenue` | Array | `revenue`（抽水） |
| `list.GameStartTime` | Array | `bet_at`（遊戲開始時間） |
| `list.GameEndTime` | Array | `result_at`（遊戲結束時間） |
| `list.LineCode` | Array | `line_code`（站點標識） |

> ⚠️ 注單金額（`CellScore` / `AllBet` / `Profit` / `Revenue`）為 Float，JSON decode 後必須以 `Common::numAdd('0', (string) $value, 4)` 正規化，禁止直接 `(string)` 轉型。

## Gaps / 待 V8 確認

- **對帳保留期**：官方文檔未明確標示注單保留期，須向 V8 客服確認
- **AES vs DES 最終確認**：建議附上 Driver `openssl_encrypt('aes-128-ecb')` 範例請 V8 客服白紙黑字確認
- **s=12 語意歸屬**：「取得帳戶餘額」與「玩家輸贏排行」共用 `s=12`，須確認最新版文檔的 `s` 值對應
- **下分 `status=-1` 語意**：訂單不存在時是否確定 V8 端未扣分，以確認歸 FAILED 的安全性
- **幣別換算位置**：換算（當地幣 → 遊戲幣）是否在上層（cashflow rate）完成，Driver 是否需要再算一次
- **`getGameUrl` 同毫秒重入**：`orderid` 由 `agentId + timestampMs + account` 組成，同毫秒重入有極小機率撞單，評估是否需要額外熵
