# FFFSW（vendor_id=143）
> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| `VendorIdEnum` | `FFFSW = 143` |
| 廠商名 | FFF Marble League（彈珠 / Marbles 樂透型遊戲） |
| 錢包模式（config） | `is_single_wallet = 1`（但見下方「根本性落差」） |
| Driver | `app/Vendors/FFFSW/Driver.php`（`AbstractSingleWallet`，**僅實作 `getGameUrl`**） |
| 回調 Controller | **尚未建立** |
| 回調路由 | **尚未註冊** |
| Models | `FffswAccount`（`fffsw_accounts`）、`FffswBetRecord`（`fffsw_bet_records`） |
| `API_URL` | `vendor_infos.info['API_URL']` |
| `AES_KEY` | `vendor_infos.info['AES_KEY']` |
| `MD5_KEY` | `vendor_infos.info['MD5_KEY']`（也以明文帶進 POST body `key` 欄位） |
| 簽名演算法 | AES-256-CBC 加密參數 + MD5 簽名（見 api.md〈簽名 / 加密〉節） |
| 時區 | UTC+8 |
| 文件版本 | 代理 API v0.0.5（2024-12-01）；訂單通知 v0.0.2（2024-10-16） |

---

## 高風險陷阱

### 1. 錢包模式根本性落差（最高優先確認）

| 來源 | 認定 |
|---|---|
| 專案 `config/vendor.php` | `is_single_wallet = 1`，Driver 繼承 `AbstractSingleWallet` |
| FFFSW 代理 API 文件 | 提供 `transfer/charge`（存款）、`transfer/withdraw`（取款）、`transfer/wallet`（查餘額）等**轉帳錢包**端點 |
| FFFSW 訂單通知文件 | 另提供「扣款 / 取餘額 / 中獎 / 撤單」**回調規格**，供業主二選一：① 提供自家通知串接文件，或 ② 實作 FFFSW 建議規格 |

**目前 Driver 只有 `getGameUrl`**，既無轉帳代收代付，也無任何回調 controller / route。實際資金流採哪套機制**必須向 RD / FFFSW 客服確認後再動工**。

### 2. 取餘額回調：`balance` 在頂層，不在 `data` 內

取餘額回調回應結構與其他三種（扣款 / 中獎 / 撤單）不同：

```jsonc
// 取餘額（balance 在頂層）
{ "code": 0, "message": "success", "balance": 1045.00 }

// 其他三種（資料在 data）
{ "code": 0, "message": "成功", "data": {} }
```

若統一用 `data.balance` 讀取，取餘額會拿到 null。

### 3. 金額正負號：下注為負，中獎 / 撤單為正

訂單通知的 `order.amount`：
- **扣款（下注）**：負數（範例 `"amount": -1`）
- **中獎通知 / 撤單通知**：正數（範例 `"amount": 1.35`）

實作扣款邏輯時需先轉正再扣，或依 endpoint 類型分流，不可一律照 `amount` 符號處理。

### 4. `MD5_KEY` 以明文帶進 POST body 的 `key` 欄位

官方設計如此（`$curl_post_data = ['params' => ..., 'sign' => ..., 'key' => $md5Key]`），md5 key 屬敏感金鑰，寫入請求 log 時必須確認 `Http::logAction` 有 mask `key` 欄位（或用 `Common::maskCredentials()`）。

### 5. 代理 API 查詢區間限制

- 注單查詢（`/open/v2/order`、`/open/v2/order/updated`）：時間區間**最多 5 分鐘（300 秒）**。
- 轉帳記錄查詢（`/open/v2/transfer/exchangelog`）：**只能查最近 7 天**。

對帳排程若以大時間段一次查詢，FFFSW 會拒絕，須分批拉取。

### 6. HTTP 500 = 服務異常，應停止發送請求

收到 HTTP 500 代表 FFFSW 服務異常，**應停止所有 API 發送並掛起維護頁面**，聯絡 FFFSW 客服，不可一般性 retry。

### 7. 回調驗簽機制未明述

訂單通知文件未說明 IP 白名單或驗簽要求，視「業主自家規格 vs 實作 FFFSW 建議規格」協商結果。若採建議規格，需向 FFFSW 確認如何驗證 callback 來源合法性。

---

## BetRecord 狀態機

> 以下為依 `FffswBetRecord` Model PHPDoc 與 FFFSW 注單狀態碼推導的建議對映。**回調尚未實作，須由 RD 確認後落地。**

| 回調事件 | `fffsw_bet_records.status` | 意義 | 對應 FFFSW 注單 `status.id` |
|---|---|---|---|
| 扣款通知（下注） | `0` | 未完成 | `0` 下注 |
| （結算未中獎） | `1` | 已扣款 | `1` 未中獎 |
| 中獎通知 | `2` | 已派彩 | `2` 已中獎 / `3` 和 |
| 撤單通知 | `-1` | 取消 | `-1` 撤單 |
| 回滾（異常退款） | `-2` | 回滾 | — |

冪等鍵建議：`order.id`（文件未明示，須確認）。

---

## 冪等性

訂單通知文件未明示去重機制。建議實作：

| 回調 | 建議冪等鍵 | 撞重時動作 |
|---|---|---|
| 扣款通知 | `order.id` + 行為類型 | 回 `code: 0`（假裝成功，避免 FFFSW 重送） |
| 取餘額 | 無狀態，每次重新查詢 | 正常回餘額 |
| 中獎通知 | `order.id` + 行為類型 | 回 `code: 0` |
| 撤單通知 | `order.id` + 行為類型 | 回 `code: 0` |

> 建議以 `FffswBetRecord` 的 `order_id` + `action` 欄位（或 `status` 值）判斷是否已處理，避免重複扣款 / 派彩。

---

## 整合落地建議

| FFFSW 概念 | APIs 對應 |
|---|---|
| Base URL | `vendor_infos.info['API_URL']`（Driver `sendRequest` 串接 path） |
| AES 加密 key | `vendor_infos.info['AES_KEY']` |
| MD5 簽名 key | `vendor_infos.info['MD5_KEY']`（POST body 中亦以 `key` 明文帶出） |
| 玩家帳號 / 密碼 | `fffsw_accounts.username` / `fffsw_accounts.password` → `xusername` / `xpassword` |
| 幣別 | `vendor_infos.currency`（小寫後送 `cry`） |
| 語系對映 | `Driver::mapLanguageCode()`（`2→tw`、`3→zh`、`6→vn`、`7→td`，default `en`） |
| 回調 Controller | **待建立** `app/Http/Controllers/External/SingleWallets/FffswController.php` |
| 回調路由 | **待註冊**於 `routes/external/sw.php` |

### 若採回調式（FFFSW 建議規格）落地清單

- [ ] 與 FFFSW 客服確認採「業主自家規格」或「實作 FFFSW 建議規格」
- [ ] 確認 callback 來源 IP 白名單 / 驗簽機制
- [ ] 建立 `FffswController`，至少 4 個 action（扣款 / 取餘額 / 中獎 / 撤單）
- [ ] 於 `routes/external/sw.php` 註冊路由
- [ ] 扣款：`lockForUpdate` + 餘額檢查 + `isMemberAbleToBet` + 冪等 + `changeBalance` + `FffswBetRecord` + `TotalBetRecord`
- [ ] 取餘額：**回頂層 `balance`**（非 `data.balance`）
- [ ] 中獎：`changeBalance` 加款 + 補 `FffswBetRecord` 的 `win_lose` / `payout_time` + 寫 `TotalBetRecord`
- [ ] 撤單：退款 + `FffswBetRecord.status = -1`
- [ ] 金額正負：扣款 `amount` 為負（取絕對值再扣），中獎 / 撤單為正
- [ ] 確認 `Http::logAction` 對 `key` 欄位有 mask

---

## Gaps

- **Base URL / AES key / MD5 key**：文件標「請向我方索取」，未提供測試 / 正式值。
- **回調 HTTP 認證 / IP 白名單 / 驗簽**：文件未明述，視協商結果。
- **完整錯誤碼表**：僅知 `code=0` 成功，未列舉各錯誤碼意義。
- **冪等鍵**：文件未明示，建議以 `order.id` + 行為類型（須向客服確認）。
- **本專案實際採用的資金流模式**：轉帳錢包 vs 單一錢包回調，尚未在程式碼體現，須向 RD 確認。
