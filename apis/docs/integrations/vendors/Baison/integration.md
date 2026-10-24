# Baison（vendor_id=113）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 轉帳錢包（Transfer Wallet）|
| 請求方式 | 代理主動呼叫，**全部 GET**（非 POST JSON） |
| 簽名 | `param`：AES-128-ECB 加密業務參數 → Base64；`key`：MD5(channel_id + timestamp + MD5_KEY) |
| 成功判定 | `code == 0` |
| 時區 | 未明述（**gap**，需向客服確認） |
| 憑證來源 | Baison 後台「運營管理 → 資料下載站」取得 ID / AES_KEY / MD5_KEY / API_URL |
| Driver | `app/Vendors/Baison/Driver.php` |
| vendor_id | `113`（`VendorIdEnum::BAISON`） |

---

## 高風險陷阱

### 1. 全部請求是 GET，param 用 AES 加密

Baison 所有 API 一律 GET 請求，業務參數**不是 POST body JSON**，而是先組裝為 query string 再 AES-128-ECB 加密後 Base64 放進 `param`。與其他遊戲商使用 POST JSON 的慣例完全不同，整合時要特別注意 HTTP client 的使用方式。

### 2. checkOrder（action=5）三態與 VendorTransferResultEnum 對映

`result.status` 有三個值，語意容易混淆：

| status | 說明 | VendorTransferResultEnum |
|---|---|---|
| `2` | 成功 | `SUCCESS` |
| `1` | 失敗 | `FAILED` |
| `0` | 不存在（訂單未建立或尚未處理） | `TIMEOUT`（繼續輪詢） |

> 注意：status `0` 表示「訂單不存在」而非「失敗」，屬於尚未有結果的中間態，應對應 TIMEOUT 繼續等待，不可直接歸為 FAILED 回滾。

### 3. order_id 格式：文件與實作有落差

- **文件要求格式**：`代理編號 + yyyyMMddHHmmssSSS + account`（含毫秒 SSS、4 位年）
- **目前實作格式**：`{ID}{date('ymdhis')}{username}`（秒級精度、2 位年）

精度不足 + 格式不符，高頻操作下有機率觸發 `code: 5200`（訂單重複）。若收到 `5200`，不可回滾（代表該 order_id 已被 Baison 記錄為成功），應改為查詢訂單確認結果。待 RD 評估是否修正為文件格式。

### 4. betRecords 欄位名稱映射

Baison 注單欄位名稱與平台資料庫欄位不一致，整合時需明確轉換：

| Baison 欄位 | 平台欄位（BaisonBetRecord） | 說明 |
|---|---|---|
| `round_id` | `bet_id` | 牌局唯一標識（Baison 端叫 round_id） |
| `user_id` | `username` | 玩家帳號 |
| `game_id` | `game_code` | 遊戲 ID |
| `all_bet` | `bet` | 總下注 |
| `avail_bet` | `valid_bet` | 有效下注 |
| `profit` | `win_lose` | 用戶盈虧（已扣服務費） |
| `start_time` | `bet_time` | 遊戲開始時間 |
| `end_time` | `payout_time` | 遊戲結束時間 |

### 5. 上分 code:5200 不可回滾

收到 `5200`（上分訂單重複）時，代表 Baison 已成功處理此 order_id，**平台不可回滾用戶餘額**；應改呼叫 action=5 查詢訂單確認金額，補記本地帳變。

---

## 轉帳流程與 VendorTransferResultEnum

### transferToVendor（上分，action=2）

```
平台 DB 扣除用戶餘額
  → 呼叫 action=2（上分）
    ├── code=0 → SUCCESS（記錄上分成功）
    ├── code=5200 → 訂單已處理 → 呼叫 action=5 確認 → SUCCESS（不回滾）
    ├── timeout / 無回應 → 呼叫 action=5 輪詢
    │     ├── status=2 → SUCCESS
    │     ├── status=1 → FAILED（回滾）
    │     └── status=0 → TIMEOUT（繼續等待）
    └── 其他 code 錯誤 → FAILED（回滾）
```

### transferToLocal（下分，action=20）

```
呼叫 action=20（指定金額下分，實作傳 vendorAmount）
  → 成功 → 平台 DB 增加用戶餘額
  → 失敗 / timeout → 呼叫 action=5 確認
        ├── status=2 → SUCCESS（加回餘額）
        ├── status=1 → FAILED（不加回）
        └── status=0 → TIMEOUT（繼續輪詢）
```

> 實作使用 action=20（指定金額），而非 action=19（全額下分）。全額下分場景（`money=0`）需另確認業務需求是否存在。

### 注單查詢

- 主要接口：action=9（betRecords），建議間隔 30 秒以上、每次區間不超過 60 分鐘
- Baison 注單每 30 秒更新一次；30 秒內重複拉取會得到重複注單，需以 `round_id` 去重

---

## 冪等性

| 場景 | 防護機制 |
|---|---|
| 上分重複 | `order_id` 唯一性；收到 `5200` 改查 action=5 而非回滾 |
| 下分重複 | 同上；`5200` 時查訂單確認是否需補加餘額 |
| 注單重複 | 以 `round_id`（對應 `bet_id`）為 unique key，insert ignore / upsert |
| 回調重複（4.5） | 回調觸發下分前先檢查訂單狀態，避免重複下分 |

> 文件未說明 Baison 是否會重複發送 4.5 渠道回調，建議在本地記錄已處理的 `account + timestamp` 或訂單號作為防護。

---

## 整合落地建議

### vendor_infos.info 欄位對映

| Baison 概念 | vendor_infos.info 鍵名 | 說明 |
|---|---|---|
| 代理編號（channel_id） | `ID` | 所有請求的 channel_id 來源 |
| API 接口地址 | `API_URL` | 所有正式 API 的 Base URL |
| AES 加密金鑰 | `AES_KEY` | 16 bytes，用於 param 加密 |
| MD5 簽名金鑰 | `MD5_KEY` | 用於 key 計算 |
| 貨幣種類 | `CURRENCY` | 若 null 則 fallback 為 `vendor_infos.currency` |
| 注單拉取 URL | `BET_RECORD_URL` | 若與 API_URL 不同時使用 |

範例 `vendor_infos.info` JSON：

```json
{
  "API_URL": "https://example.invalid/Api/interface",
  "BET_RECORD_URL": "https://example.invalid/Api/interface",
  "ID": "1088",
  "AES_KEY": "Cn4crfd27TuQ6BGY",
  "MD5_KEY": "atwPbr4Nmmr4GjG5",
  "CURRENCY": "RMB"
}
```

### 相關檔案

| 類型 | 路徑 |
|---|---|
| Driver | `app/Vendors/Baison/Driver.php` |
| 帳號 Model | `app/Vendors/Models/BaisonAccount.php` |
| 注單 Model | `app/Vendors/Models/BaisonBetRecord.php` |
| 設定 | `config/vendor.php`（`VendorIdEnum::BAISON`，vid=113） |
| 回調 Controller | 無（轉帳錢包不需要 Single Wallet callback controller） |

### 玩家上線流程

```
檢查 baison_accounts 是否存在本地帳號
  ├── 不存在 → 呼叫 action=1（money=0）→ 帳號自動建立
  └── 存在 → transferToVendor（action=2）
                  → 成功後呼叫 action=1 取遊戲 URL
                  → 回傳 result.url 給玩家
```

### 渠道回調（4.5，可選）

Baison 在玩家完成一局後離線時會主動回調代理 URL，通知執行下分（`action=1`，`money=0`）。需在接入時向客服提供回調 URL。

---

## Gaps / 待確認

1. **系統時區**：文件未明確說明 Baison 的時間欄位時區（`start_time` / `end_time` / `time`），需向客服確認是 UTC、SGT 還是 CST。
2. **注單資料保留期限**：文件未說明，需向客服確認，以規劃對帳查詢範圍。
3. **order_id 格式修正**：目前實作為秒級 2 位年（`{ID}{date('ymdhis')}{username}`），文件要求含毫秒 SSS 的 4 位年格式，高頻操作下有重複風險（`code: 5200`）。
4. **渠道回調冪等保護**：文件未說明 Baison 是否會重複回調；是否有簽名驗證機制，需向客服確認。
5. **server_type（game_id）對照表**：文件說明「由客服人員提供」，未內建於 API 文件。
6. **試玩接口域名（trial_server）**：需聯繫客服另行取得。
7. **下分錯誤碼歧義**：3.2.4 有 `5103`（余額不足），全局附錄也有 `5002`（玩家金額不足），兩碼的精確使用場景需向客服確認。
8. **getBalance 接口選擇**：實作使用 action=4（查詢可下分 freeMoney），若需同時確認在線狀態應改用 action=6；業務需求待 RD 確認。
