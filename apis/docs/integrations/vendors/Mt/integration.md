# Mt（美天棋牌）（vendor_id=19）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 基本資訊

| 項目 | 值 |
|---|---|
| Vendor ID | 19（`VendorIdEnum::MT`） |
| 錢包模式 | Transfer Wallet（轉帳錢包） |
| 繼承類別 | `AbstractTransferWallet` |
| Driver | `app/Vendors/Mt/Driver.php` |
| Account Model | `App\Vendors\Models\MtAccount` |
| BetRecord Model | `App\Vendors\Models\MtBetRecord` |
| 對帳保留期 | **30 天**（`queryMerchantGameRecord2/3` 的 startDate/endDate 最大差距） |
| 時區 | GMT+8 |

---

## info 欄位對照

| `vendor_infos.info` 鍵 | 說明 |
|---|---|
| `API_URL` | Base URL（含前綴 `/services/dg/player/`，Driver 會 `rtrim('/')` + `'/'`） |
| `MERCHANT_ID` | 商戶號（注意：Driver 中送出欄位為 `merchanId`，見陷阱 1） |
| `KEY` | 商戶密鑰，用於簽名 `code = md5(key . rawData)` |

---

## 實作方法對照

| AbstractTransferWallet 方法 | Mt API | 說明 |
|---|---|---|
| `getBalance()` | `getPlayerBalance` | 回 `money`，以 `Common::numFloor($coinBalance, 4)` 正規化 |
| `transferToVendor()` | `deposit2` | 本地→MT，回 `balance` |
| `transferToLocal()` | `withdraw2` | MT→本地，回 `balance` |
| `getGameUrl()` | `playerPlatformUrl` | 回 `platformUrl` |
| `getTransRecord()` | `queryTransbyId` | 查轉帳狀態，回轉帳結果 |

**未實作的 API**：`playerCreate2`、`updatePlayerPwds`、`queryMerchantGameRecord2`、`queryMerchantGameRecord3`

---

## 轉帳結果判斷

| 條件 | 結果 |
|---|---|
| `resultCode == 1` | `SUCCESS` |
| JSON 解析失敗（非 JSON 回應） | `TIMEOUT` |
| 其他 resultCode | `FAILED` |

`checkTransferStatus`（`queryTransbyId`）另看 `status` 欄位：`1`=SUCCESS、`2`=FAILED、其他=TIMEOUT。

---

## 平台陷阱

### 陷阱 1：`merchanId` 拼字（高風險）

Driver 送出的 URL path segment / body 欄位為 `merchanId`（少一個 `t`），但文件用 `merchantId`。

- MT 接口主吃 **URL path segment**（參數依序排列），body 欄位可能被忽略
- 功能層面未必造成問題，但與文件不符
- **config 儲存鍵為 `MERCHANT_ID`（完整拼字）**；Driver 組 path 時寫死為 `merchanId`，兩者不同
- 接新白標或切正式環境前務必確認 MT 端是否讀取 body 欄位

### 陷阱 2：rawData 必須 JSON encode 整個 params 陣列

簽名流程：
```
rawData = json_encode($params)           // 先 JSON encode 整個 params 陣列
data    = base64_encode($rawData)        // 放 URL path segment
code    = md5($key . $rawData)           // MD5(key + rawData)，放 URL path segment
```

常見誤解：直接 `base64_encode($params)` 或對個別欄位簽名——均錯誤。

### 陷阱 3：玩家密碼衍生規則

```php
$password = [REDACTED] . strtotime($account->created_at)), 8, 12);
$pwd      = [REDACTED];
```

`created_at` 是 DB 的 `MtAccount.created_at`，非任意時間戳。密碼衍生後再 MD5 才是 `pwd`。

---

## 無回調設計

Transfer Wallet 不需 callback controller，也無 sw/tw 路由。餘額由 MT 端保管，靠主動呼叫 API 對帳。

---

## 注意事項

- 金額精確到小數 4 位（`coins`）；`getBalance` 以 `Common::numFloor($coinBalance, 4)` 正規化
- 所有接口均設 IP 白名單（`IP限制 = TRUE`）
- 對帳須在 **30 天**內完成（`queryMerchantGameRecord2/3` 保留期）
- `queryMerchantGameRecord2/3` Driver 目前未實作，對帳取數需另行規劃
- 轉帳結果 `match` 僅辨識 `0/1/2`，`9/6/33/31` 等確定性失敗碼目前落入 `TIMEOUT`（見 api.md 落差 2）
