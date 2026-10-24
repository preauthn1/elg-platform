# WmliveSW（vendor_id=126）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| Wallet Mode | Single Wallet / Seamless |
| Callback 方向 | WM → 本平台（主要）；代理也主動呼叫 WM 查帳/報表 |
| 驗證方式 | `signature` 商戶碼識別 + IP 白名單；**無 HMAC/3DES** |
| 成功判定 | `errorCode === 0` |
| 時區 | 未明確規範；`timestamp` 須在 WM 伺服器時間 **±30 秒**內 |
| 資料保留 | WM 保留 **60 天** |

---

## 無密碼學簽名（重要）

WM 的 `signature` 是**商戶識別碼**（非加密簽章）。安全性完全依賴：

1. **IP 白名單**：`Common::verifyIPWhitelistByVendorId(VendorIdEnum::WMLIVESW)` 驗證 WM 來源 IP
2. **signature**：僅用於識別是哪個商戶，比對 `vendor_infos.info.SIGNATURE`

若 IP 白名單配置不完整，任何人偽造 `signature` 即可操控玩家錢包。

---

## timestamp 時序問題

代理端呼叫 WM 或接收 WM 回調時，WM 的 `timestamp` 須在 ±30 秒內（錯誤碼 `10202`）。若代理伺服器時鐘偏差超過 30 秒，**所有回調與主動呼叫均會失敗**。部署前確認 NTP 同步正常。

---

## PointInout code 流向

| `code` 值 | 方向 | 路由方法 |
|---|---|---|
| `0` / `2` / `4` | 扣點（deductBalance） | `deductBalance()` |
| `1` / `3` / `5` | 加點（increaseBalance） | `increaseBalance()` |
| `cancel` | 未支援 | 回錯 `"The code is illegal"` |

`cancel` 值目前收到回錯（default 分支），是否需要支援待向 WM 確認。

---

## 禮碼（RollingChip）優先扣

**下注（PointInout code=0/2/4）**：先扣禮碼（`RollingChipService::sub()`），不足再扣真金，並記錄 DealRecord 上的 `rolling_chips` 使用量。

**TimeoutBetReturn 退款**：`splitRefundAmount()` 優先還真金，再還禮碼。退款至禮碼錢包時建立 `RollingChipLog`（type=`BET_CANCEL`）。

---

## 帳號停用時仍記帳

加點（PointInout code=1/3/5）時，若帳號 `status == 0`（停用），**不更新餘額**，但仍建立 `TransactionLog`。此設計避免派彩紀錄遺漏，但玩家實際無法取得款項。

---

## TimeoutBetReturn 回滾條件

僅以下兩種狀態允許回滾：

| 條件 | 說明 |
|---|---|
| `status == 1 && deal_code == 2` | 扣點成功 → 取消下注退款 |
| `status == 2 && deal_code == 1` | 加點失敗 → 補退款 |

其餘狀態一律拒絕。若 WM 先送 `TimeoutBetReturn` 才送對應的 `PointInout`，目前實作因 dealid 不存在直接回錯（保守處理），建議確認 WM 是否存在此時序。

---

## SendMemberReport 需特別開通

路由注解：「需要關閉打賞，請聽方開啟 SendMemberReport 功能」。WM **不會自動啟用**此功能，接入前必須主動聯絡 WM 客服申請，並確認打賞已關閉。開通後才能透過此 callback 拿到結算資料，BetRecord 才會寫入。

---

## rollbackBalance 回應欄位落差

`TimeoutBetReturn` 回應：

| 來源 | 欄位 |
|---|---|
| 實作回傳 | `balance`、`updated_at` |
| 文件定義 | `money`、`cash`、`responseDate`、`dealid` |

欄位名稱不一致。待確認 WM 是否驗證回應欄位名稱。若 WM 側嚴格解析，需修改實作對齊文件。

---

## GetDateTimeReport timetype=1

對帳排程建議使用 `timetype=1`（結算時間）而非 `timetype=0`（下注時間），以確保每筆注單的最終輸贏狀態已確定，避免撈到未結算注單。

---

## BetRecord 狀態機

```
WM 呼叫 PointInout（code=2 下注）
  → 建立 WmliveswDealRecord（status=0→1）

WM 呼叫 PointInout（code=1 派彩）
  → 建立 WmliveswDealRecord（status=0→1）

WM 呼叫 SendMemberReport（結算）
  → firstOrCreate WmliveswBetRecord（status=0）
  → 批次更新 DealRecord（is_settlement=1）
  → 寫入 TBR
  → 更新 BetRecord（status=1 完成 / status=2 失敗）
```

**BetRecord 僅在 SendMemberReport 時寫入**，不在 PointInout 時寫。

---

## 冪等性

| 場景 | 處理 |
|---|---|
| 相同 `dealid` 再次送入 PointInout | `firstOrCreate` → 已存在則回錯 |
| 相同 `betId` 再次送入 SendMemberReport | `firstOrCreate` → `status==1` 直接 `continue` |
| `status==0/2` 的 BetRecord 重新推送 | 覆蓋式修復（重試 settlement 流程） |

---

## 實作落差（待確認）

1. **`CallBalance` 方向**：端點由 WM 主動呼叫代理，Controller 路由正確，無實質問題，留此備查。
2. **`SendMemberReport` 需特別開通**：文件未強調，接入前必確認。
3. **`PointInout code="cancel"`**：實作未支援，收到回錯，是否需要支援待確認。
4. **`rollbackBalance` 回應欄位**：實作 `balance/updated_at` vs 文件 `money/cash/responseDate/dealid`，待確認 WM 是否驗證。
5. **匯率轉換**：`vendor_infos.rate` 用於 `money` 換算，文件未說明此機制，為平台內部設計。

---

## 整合落地建議

| WM 概念 | APIs 專案對應 |
|---|---|
| `vendorId` | `vendor_infos.info.VENDOR_ID` |
| `signature`（商戶碼） | `vendor_infos.info.SIGNATURE`（識別商戶，非加密金鑰） |
| API URL | `vendor_infos.info.API_URL`（由 WM 客服提供） |
| 玩家帳號 | `wmlive_sw_accounts.username` |
| 玩家密碼 | `wmlive_sw_accounts.password` |
| VendorId Enum | `VendorIdEnum::WMLIVESW` = `126` |
| Driver | `app/Vendors/WmliveSW/Driver.php`（繼承 `AbstractSingleWallet`） |
| 回調 Controller | `app/Http/Controllers/External/SingleWallets/WmLiveSwController.php` |
| 回調路由 | `routes/external/sw.php` → `POST /wmlive` |
| 交易紀錄 | `wmlivesw_deal_records`（`WmliveswDealRecord`） |
| 注單紀錄 | `wmlivesw_bet_records`（`WmliveswBetRecord`）；TBR 寫入於 `SendMemberReport` |
| 對帳保留期 | WM 保留 **60 天**，超過需聯絡客服補資料 |
| SBR 重建 | `$timeField = 'settlement_time'` |
