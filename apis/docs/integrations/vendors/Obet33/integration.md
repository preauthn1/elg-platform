# Obet33（vendor_id=43）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 基本資訊

| 項目 | 值 |
|---|---|
| Vendor ID | `VendorIdEnum::OBET33 = 43` |
| 底層系統 | OB Sports（OB Sportsbook） |
| 錢包模式 | Transfer Wallet（轉帳錢包） |
| 遊戲類型 | 體育博彩（Sportsbook） |
| 是否有 callback | **無**（轉帳錢包，對帳採主動拉取 betinfo） |

---

## 落地路徑

| 項目 | 路徑 |
|---|---|
| Driver | `app/Vendors/Obet33/Driver.php`（`AbstractTransferWallet`） |
| 帳號 Model | `app/Vendors/Models/Obet33Account.php` |
| 注單 Model | `app/Vendors/Models/Obet33BetRecord.php` |
| 路由（TW callback） | **無** |
| Vendor ID Enum | `VendorIdEnum::OBET33` |
| config 註冊 | `config/vendor.php` → `VendorIdEnum::OBET33->value (43) => Vendors\Obet33\Driver::class` |

---

## vendor_infos.info 欄位

```jsonc
{
  "AGENT_ID": "YYTES31",                      // Agent Id（hash 的 a 參數）
  "API_KEY":  "zFWUIhXrfXc2NNdXAo55",         // 簽章金鑰（hash 的 k 參數）
  "API_URL":  "https://example.invalid"  // Base URL（含 scheme，不含尾斜線）
}
```

---

## 驗證機制

| 機制 | 說明 |
|---|---|
| IP 白名單 | 測試環境 IP：`0.0.0.0`、`0.0.0.0`、`0.0.0.0`、`0.0.0.0`、`0.0.0.0`、`0.0.0.0`、`0.0.0.0` |
| hash 簽章 | 見下方簽名欄位說明 |

### 簽名欄位

| API 類型 | 明文組合 | hash 計算 |
|---|---|---|
| 一般 API（register / fund / fund/check / bal / open / close） | `k={API_KEY}&a={AGENT_ID}&u={userId}` | `strtoupper(MD5(明文))` |
| betinfo / parlay/detail（report 類） | `k={API_KEY}&a={AGENT_ID}`（**省略 `u`**） | `strtoupper(MD5(明文))` |

> `userId` 規則：所有 userId **前綴必須是 agentId**（例：agentId `AG0` → userId `AG0USR0`）。Driver 串接為 `$this->agentId . $username`。

---

## BetRecord 對帳流程

| 操作 | API | 說明 |
|---|---|---|
| transferToVendor | `/api/sb/fund`（amount 正值） | 存款，轉入 OBET33 |
| transferToLocal | `/api/sb/fund`（amount 負值） | 提款，轉回本地 |
| 轉帳補查 | `/api/sb/fund/check` | 以 `billNo` 確認最終狀態 |
| 注單查詢 | `/api/sb/betinfo` | 拉單對帳（hash 省 `u`） |
| 串關明細 | `/api/sb/parlay/detail` | 混合過關注單明細（hash 省 `u`） |

> **注意**：目前 Driver 尚未實作 `betinfo` 與 `parlay/detail`，亦無對應「省 `u`」的簽章方法。若需注單層級對帳（TBR/BR），須補實作。

- 對帳保留期：**文件未載明**，需向 OBET33 客服確認

---

## 高風險陷阱

### 1. GMT-4 與 GMT+8 混用（對帳容易算錯）

| 欄位 | 時區 | 說明 |
|---|---|---|
| `sDate`（算帳日） | **GMT-4** | betinfo 的算帳日期欄位 |
| `startdate` / `enddate`（查詢輸入） | GMT+8 | 格式 `yyyyMMddHHmmss` |
| `tDate` / `kDate` / `aDate` / `pDate` | GMT+8 | 注單時間戳欄位 |

**對帳工具中若以 GMT+8 解讀 `sDate`，會產生約 12 小時的日期偏移，導致注單對不上。**

### 2. fund API 正負號決定存提款方向

`/api/sb/fund` 同時處理存款與提款：
- `amount` 正數 → deposit（存款，充值）
- `amount` 負數 → withdraw（提款，提現）

**這是同一個 API，不是兩個 endpoint**。若建構參數時忘記符號，會反向操作。

### 3. 回應 key 大小寫：文件小寫、實際大寫

| 欄位 | 文件範例 | Driver 實際讀取 |
|---|---|---|
| 狀態 | `status`（小寫） | `Status`（大寫） |
| 資料 | `data`（小寫） | `Data`（大寫） |
| 遊戲連結 | `data.loginUrl` | `Data.LoginUrl` |
| 餘額 | `data.balance` | `Data.Balance` |

推測 OBET33 production 實際回傳大寫 key（文件範例有誤）。若 OBET33 未來改回小寫，Driver 的所有解析邏輯都會靜默失敗。建議向客服確認 production key 大小寫，或改為大小寫容錯讀取。

### 4. betinfo / parlay/detail hash 省 `u`

betinfo 與 parlay/detail 的 hash 明文**不含 userId**（省略 `u` 欄位）。若不小心帶入 `u`，hash 驗簽失敗，API 回 `1=授權失敗`。

---

## 實作與文件落差

| 項目 | 說明 |
|---|---|
| 回應 key 大小寫 | Driver 讀大寫 `Status`/`Data`，文件寫小寫；推測 production 為大寫，待確認 |
| fund 失敗 HTTP 400 | 三方 fund/fund_check 失敗回 HTTP 400 but 帶 JSON body；文件未說明此行為 |
| fund/check amount | 文件標 Optional，Driver 不帶 amount；運作正常，待確認可省略 |
| betinfo 未實作 | Driver 尚未實作 betinfo / parlay/detail 及對應的省 u 簽章；注單對帳需補 |
| 金額原生運算 | `transfer()` 內 `$amount = 0 + $credit` / `0 - $credit` 為原生浮點運算，違反專案規範，待整改為 `Common::numAdd/Sub` |
