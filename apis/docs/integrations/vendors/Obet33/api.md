# Obet33（OB Sportsbook）— API 整合文件

> **原始來源**：「OB Sportsbook API 2.0.1.pdf」（V2.0.1，28 頁，繁中/簡中雙語）
> 補充來源：`info.txt`（測試環境介接資訊）、`obet limit adjustment.xlsx` / `obet limit adjustment-tpower.xlsx`（限額調整對照表，非 API 規格）
> 來源路徑：`/Users/rb/Workspace/遊戲商/43.OBET33/`
> **抓取日期**：2026-06-03

OBET33（OB Sportsbook）為**體育博彩（Sportsbook）遊戲商**，採 **Transfer Wallet（轉帳錢包）** 模式：玩家餘額由 OBET33 端保管，玩家進入遊戲前須先把代理錢包餘額轉入 OBET33。本專案 vid = **43**（`VendorIdEnum::OBET33`）。

---

## 通用約定

### 請求格式

- **HTTP Method**：建立會員 / 轉帳 / 查轉帳記錄為 `POST`（`application/json`）；餘額 / 登入 / 登出 / 注單查詢為 `GET`（query string）。
- **驗證方式**：IP 白名單 + `hash`（MD5 簽章）。
  - 測試環境 IP 白名單（info.txt）：`0.0.0.0 / 0.0.0.0 / 0.0.0.0 / 0.0.0.0 / 0.0.0.0 / 0.0.0.0 / 0.0.0.0`
- **`userId` 規則**：所有會員相關 API 的 `userId` **前綴必須是 `agentId`**（例：agentId `AG0` → userId `AG0USR0`）。

### 通用回應欄位

| 欄位 | 類型 | 說明 |
|---|---|---|
| `status` | String | `success` 成功 / `fail` 失敗（文件範例為**小寫**） |
| `message` | String | 失敗時為錯誤碼字串（如 `"301"`）或標準錯誤名（如 `"Unauthorized"`） |
| `data` | Object / List / Null | 成功資料；失敗為 `null` |

### Base URL

| 環境 | URL |
|---|---|
| 測試（本專案 info.txt） | `https://example.invalid` |
| 正式 | 由 OBET33 客服提供（文件以 `<server>` 表示） |

### 時區

- 注單查詢輸入 `startdate`/`enddate`：**GMT+8**，格式 `yyyyMMddHHmmss`。
- 注單明細 `sDate`（算帳日）：**GMT-4**；`tDate`/`kDate`/`aDate`/`pDate`：**GMT+8**。
- 對帳工具須留意此 GMT-4 / GMT+8 混用。

---

## API 一覽

| # | 名稱 | Endpoint | Method | hash 明文 |
|---|---|---|---|---|
| 3 | Create / Update Account | `/api/sb/register` | POST | `k&a&u` |
| 4 | Transfer Fund | `/api/sb/fund` | POST | `k&a&u` |
| 5 | Transfer Fund Record Enquiry | `/api/sb/fund/check` | POST | `k&a&u` |
| 6 | Get User Balance | `/api/sb/bal` | GET | `k&a&u` |
| 7 | User Login | `/api/sb/open` | GET | `k&a&u` |
| 10 | User Logout | `/api/sb/close` | GET | `k&a&u` |
| 8 | Ticket（注單查詢） | `/api/sb/betinfo` | GET | `k&a`（無 u） |
| 9 | Parlay Details Ticket | `/api/sb/parlay/detail` | GET | `k&a`（無 u） |

> （# 為 PDF 章節編號）詳見下方各 API 章節。

---

## 錢包流程（Transfer Wallet）

```
建立會員 (register)
   │
   ▼
存款：transferToVendor → /api/sb/fund (amount 正值)
   │           │ 逾時/不明
   │           ▼
   │      /api/sb/fund/check 補查
   ▼
取得遊戲 URL：getGameUrl → /api/sb/open  ──▶ 玩家在 OBET33 端下注
   ▲
   │
提款：transferToLocal → /api/sb/fund (amount 負值)
```

- 存款 = `amount` 正值；提款 = `amount` 負值；方向由正負號決定（非欄位）。
- 轉帳回傳 `success`/`fail`/其餘（視為逾時）→ 對映 `VendorTransferResultEnum::SUCCESS/FAILED/TIMEOUT`。
- 逾時走 `checkTransferStatus()`（`/api/sb/fund/check`）補查最終狀態。
- 注單同步以 `/api/sb/betinfo`（注單）+ `/api/sb/parlay/detail`（混合過關明細）查詢，供對帳寫入 BetRecord（本專案尚未實作呼叫，見落差）。

---

## 整合落地建議

| OBET33 概念 | APIs 專案對應 |
|---|---|
| `agentId` | `vendor_infos.info.AGENT_ID`（測試值 `YYTES31`） |
| `API Key`（簽章金鑰） | `vendor_infos.info.API_KEY`（測試值 `zFWUIhXrfXc2NNdXAo55`） |
| Base URL | `vendor_infos.info.API_URL`（測試 `https://example.invalid`） |
| `userId`（含 prefix） | `agentId` + `Obet33Account::username`（Driver 內串接 `$this->agentId . $username`） |
| Driver | `app/Vendors/Obet33/Driver.php`（`extends AbstractTransferWallet`） |
| 帳號 / 注單 Model | `app/Vendors/Models/Obet33Account.php` / `Obet33BetRecord.php` |
| 轉帳實作 | `transferToVendor`（存）/ `transferToLocal`（提）/ `checkTransferStatus`（補查） |
| 註冊位置 | `config/vendor.php` → `VendorIdEnum::OBET33->value (43) => Vendors\Obet33\Driver::class` |
| 回調 controller / routes | **無**（轉帳錢包，無 seamless callback；對帳採主動拉取注單 API） |
| 對帳保留期 | 文件未載明 OBET33 注單保留期限 → 見 gaps，需向客服確認 |

> 轉帳錢包不需 `routes/external/sw.php` 的單一錢包回調；OBET33 無回調 controller。

---

## vendor_infos.info 建議欄位

```jsonc
{
  "AGENT_ID": "YYTES31",                      // Agent Id
  "API_KEY":  "zFWUIhXrfXc2NNdXAo55",         // 簽章金鑰
  "API_URL":  "https://example.invalid"  // Base URL（含 scheme，不含尾斜線）
}
```

> Driver `setInfo()` 讀取 `API_URL` / `AGENT_ID` / `API_KEY` 三鍵，缺一會在進入遊戲/轉帳時報錯。

---

## 實作與文件落差（待 RD / 客服確認，非確認 bug）

1. **回應欄位大小寫不一致** — 官方文件 JSON 範例全為**小寫** `status` / `data` / `loginUrl` / `balance`；但 `Driver.php` 讀取的是**大寫** `$json['Status']`、`$json['Data']['LoginUrl']`、`$json['Data']['Balance']`，且狀態值比對 `'success'` / `'fail'`（小寫值）。
   - 推測：實際 production 回應 key 為大寫 `Status`/`Data`（與文件範例不符），Driver 依實測值寫成大寫；否則所有呼叫都會落空。
   - **風險**：若 OBET33 哪天把 key 改回文件所載小寫，Driver 會全部判為「format error / 非 success」。建議向客服確認權威大小寫，或 Driver 改為大小寫容錯。

2. **轉帳成功的 status 文件值 vs 實作** — 文件 Response Format 寫 `status: success/fail`；Driver 對映 `success → SUCCESS`、`fail → FAILED`、其餘 → `TIMEOUT`，方向正確。惟轉帳失敗時三方回 **HTTP 400 但帶 JSON**（Driver `checkTransferStatus` 註解明載），文件未說明此 HTTP 400 行為 → 建議客服確認 fund / fund/check 失敗時的 HTTP 狀態碼約定。

3. **`fund/check` 參數** — 文件參數表 `serialNo` 必填、`amount` Optional，但 Driver `checkTransferStatus()` 只送 `hash/agentId/userId/serialNo`（不帶 `amount`）。目前運作正常推測 `amount` 確為可省略；待確認。

4. **注單查詢 / 混合過關明細未實作** — Driver 未實作 `/api/sb/betinfo`、`/api/sb/parlay/detail`，亦無對應「不含 `u`」的簽章方法。若需做注單層級對帳（TBR/BR），須補上。

5. **金額原生運算** — `transfer()` 內 `$amount = 0 + $credit` / `0 - $credit` 為原生浮點運算，違反專案「禁止原生浮點數運算（須用 `Common::numAdd/Sub`）」規範。屬實作層面待整改，與三方文件無關，於此一併記錄。

6. **`getGameUrl` 取 `Data.LoginUrl`** — Driver 取 `$json['Data']['LoginUrl']`（大寫 L、大寫 U），文件範例為 `data.loginUrl`（小寫）。同第 1 點，須確認 production 權威大小寫。

---

## Change Log（官方）

PDF §1 Change History 表格為**空白**（無填任何版本記錄），僅文件本身標示版本 V2.0.1。

---

## Sources

- 「OB Sportsbook API 2.0.1.pdf」V2.0.1，§1~§11，`/Users/rb/Workspace/遊戲商/43.OBET33/`（2026-06-03 讀取，全 28 頁）。
- `info.txt`（測試環境 Agent ID / API Key / Domain / IP 白名單 / Wallet=Transfer Mode）。
- 交叉驗證：`app/Vendors/Obet33/Driver.php`、`config/vendor.php`、`app/Enums/Vendor/VendorTransferResultEnum.php`。
</content>
