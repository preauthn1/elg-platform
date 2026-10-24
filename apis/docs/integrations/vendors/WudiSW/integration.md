# WudiSW（vendor_id=170）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| Wallet Mode | Single Wallet / Seamless |
| Callback 方向 | WudiSW → 本平台 |
| 驗證方式 | `auth_code`（MD5 排除 3 欄位 + `md5_key`）用於回調；`check_code`（`security_code=` 開頭）用於 gaming API |
| 成功判定 | `status.code === 1000` |
| 時區 | UTC-4（RFC3339）；API 判定超時 **15 秒** |
| 資料保留 | 文件未載明，**待向 WudiSW 客服確認** |

---

## auth_code vs check_code 不可混用

兩套驗證機制完全不同，**混用必然失敗**：

| | auth_code | check_code |
|---|---|---|
| 使用場景 | WudiSW 呼叫代理（回調驗證） | 代理呼叫 WudiSW gaming API |
| 密鑰 | `MD5_KEY` | `SECURITY_CODE` |
| 演算法 | 排除 3 欄位 + 字母排序 + `&md5_key` | 以 `security_code=<value>` 開頭 + 其餘字母排序 |
| 錯誤碼 | 代理驗證失敗直接拒絕 | WudiSW 回 `4103 Check code is wrong` |

---

## `amount=0` 的 start 只能回 1000

Free spin 的 `start` 金額為 `0`（不扣款）。文件規定：`start` 且 `amount=0` 時，代理**只能回 `1000`**，回任何其他狀態碼（包含 `5101` 餘額不足）都可能導致玩家被 WudiSW 限制遊戲。

實作中若餘額不足的邏輯觸發，需先判斷 `amount == 0` 的情況，強制回 `1000`。

---

## `adjust` 未實作

`transfer_type=adjust`（系統異常補償）目前 WudiSWController 未處理，收到後回 `5201`。

文件要求：`adjust` 也只能回 `1000`；若真的無法調整才回 `5201`。若強制回 `5201` 且 WudiSW 持續重送，玩家可能被限制遊戲。雖然發生機率小，但存在資損風險，建議評估是否補實作。

---

## `balance` 型別落差（v2.3.7）

文件 v2.3.7（2025/7/18）要求 04-03 / 04-04 回應的 `balance` 改為 String。實作目前仍以 `(float) $balance` 回傳 Float。若 WudiSW 側嚴格解析型別，可能有問題，待確認。

---

## `request_time` 欄位缺失（v2.3.8）

v2.3.8（2025/7/31）新增 04-04 Transfer amount 回應 `request_time` 欄位，實作中目前未回傳此欄位，待確認是否需補充。

---

## 超時重送規則

| 情境 | WudiSW 行為 | 代理應回覆 |
|---|---|---|
| `start` 超時（>15s） | WudiSW 發送 `refund` | 正常處理 refund |
| `end` / `refund` 超時後重送 | WudiSW 重送相同請求 | 依注單狀態：完成→`1000`；已處理→`5106`；不存在→`5105` |

---

## 冪等性

`bet_id`（= `order_id`）設有 MySQL UNIQUE KEY。重複 INSERT 時拋出 `QueryException` code `1062`，由 `WudiSWService::handleTransfer` 捕捉後轉為 `5107`（Duplicate tran_id）。

---

## BetRecord 狀態機

```
WudiSW 呼叫 transfer(start)
  → 建立 WudiswBetRecord(status=START)
  → 扣款 + TransactionLog

WudiSW 呼叫 transfer(end)
  → 依 order_id 找 BR → 更新 bet/win/valid_bet/win_lose/payout_time
  → 派彩 → 寫入 TBR

WudiSW 呼叫 transfer(refund)
  → 依 order_id 找 BR → 標記退款態 → 退還已扣金額

WudiSW 呼叫 transfer(reward)
  → 建立獨立 BR（不對應 start）→ 依 amount 加扣款
```

`order_id` 作為冪等鍵，一個 `start` 對應一個 `end` 或 `refund`（同 `order_id`）。Free game 的 `start` 金額為 `0`，BR 仍需建立。

---

## 實作落差（待確認）

1. **`balance` 回傳型別**：實作回傳 Float，文件 v2.3.7 起要求 String，待確認 WudiSW 是否嚴格解析。
2. **`adjust` 未實作**：收到時回 `5201`，有資損風險，評估是否需補充。
3. **`auth_code` 簽名驗證**：排除 `trans_details`、`betform_details`、`auth_code` 三欄位，與文件一致，無落差。
4. **`datetime` 欄位名稱**：04-03/04-04 文件寫 `status.datetime`，gaming 端寫 `status.time`；實作回傳用 `time`，待確認 WudiSW 回調是否預期 `datetime`。
5. **`request_time` 欄位**：v2.3.8 新增回應欄位，實作未回傳，待確認。

---

## 整合落地建議

| WudiSW 概念 | APIs 專案對應 |
|---|---|
| `account_id` | `vendor_infos.info.ACCOUNT_ID` |
| `md5_key`（交易回調驗證） | `vendor_infos.info.MD5_KEY` |
| `security_code`（gaming 端） | `vendor_infos.info.SECURITY_CODE` |
| `API_URL`（gaming 端） | `vendor_infos.info.API_URL` |
| `PPP URL`（回調 URL，代理提供） | APIs 外部域名 + `/wudi/get_user_balance` 或 `/wudi/transfer` |
| 玩家帳號 | `wudisw_accounts.username`（4~50 英數字） |
| VendorId Enum | `VendorIdEnum::WUDISW` = `170` |
| Driver | `app/Vendors/WudiSW/Driver.php`（繼承 `AbstractSingleWallet`） |
| 回調 Controller | `app/Http/Controllers/External/SingleWallets/WudiSWController.php` |
| 回調 Routes | `routes/external/sw.php`（prefix: `wudi`） |
| 回調路徑 | `POST /wudi/get_user_balance`、`POST /wudi/transfer` |
| IP 白名單驗證 | `Common::verifyIPWhitelistByVendorId(VendorIdEnum::WUDISW->value)` |
| 對帳 API | `07-01 Query game histories`（以 `result_time` 為時間軸） |
| 對帳保留期 | 文件未明載，**待向 WudiSW 客服確認** |
| SBR 重建 | `$timeField = 'payout_time'`（`Start.php` 中 `result_time` 對齊到 `payout_time`） |
