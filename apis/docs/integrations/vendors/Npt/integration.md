# Npt（vendor_id=41）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 基本資訊

| 項目 | 值 |
|---|---|
| Vendor ID | `VendorIdEnum::NPT = 41` |
| 底層系統 | Playtech |
| 錢包模式 | Transfer Wallet（轉帳錢包） |
| 遊戲類型 | 老虎機、真人荷官、桌遊 |
| 是否有 callback | **無**（Transfer Wallet，無遊戲商回呼） |
| 協定格式 | HTTP form-urlencoded（非 JSON） |

---

## 落地路徑

| 項目 | 路徑 |
|---|---|
| Driver | `app/Vendors/Npt/Driver.php`（`AbstractTransferWallet`） |
| 帳號 Model | `app/Vendors/Models/NptAccount.php` |
| 注單 Model | `app/Vendors/Models/NptBetRecord.php` |
| 路由（TW callback） | **無**（無 callback） |
| Vendor ID Enum | `VendorIdEnum::NPT` |

---

## vendor_infos.info 欄位

| 欄位 | 說明 |
|---|---|
| `API_URL` | Playtech API base URL（Driver 讀取時自動 `rtrim('/')` 去尾斜線） |
| `ENTITY_KEY` | X_ENTITY_KEY Header 值 |
| `CERT_PATH` | mTLS 憑證路徑前綴（相對 `base_path()`；實際讀 `<CERT_PATH>.pem` 與 `<CERT_PATH>.key`） |
| `ADMIN_NAME` | 管理員名稱（存提款 API 的 `adminname` 參數） |
| `PLAYER_PREFIX` | 玩家帳號前綴（呼叫時組 `PLAYER_PREFIX . username`） |
| `LANG_CODE` | 語言代碼 |

---

## 驗證機制

Npt 採**雙重驗證**，兩者缺一均會 403：

| 機制 | 說明 |
|---|---|
| mTLS（client cert） | 每次 HTTP 請求附帶 `.pem` + `.key` client certificate |
| `X_ENTITY_KEY` Header | 由 Playtech 提供的實體密鑰，置於 Request Header |

> **注意**：Driver 設定 `verify => false`（忽略 server 端 SSL 憑證驗證）。Driver 註解說明「NPT 根本沒啟用 https」，且 Playtech 官方範例（C#/Java）同樣 bypass certificate check。mTLS 的 client cert 仍需正確配置。

---

## 遊戲啟動（Game Launch）

Npt **無法透過 API 直接取得遊戲 URL**，需由前端 SDK 處理：

1. `Driver::getGameUrl()` 回傳 `extra` 參數包（username、password、lang、mode、game、client）
2. 前端收到 `extra` 後，透過 Playtech 提供的 `integration.js`（`iapi*` 系列函式）登入並產生遊戲 URL

| 參數 | 說明 |
|---|---|
| `username` | 玩家 PT 帳號（全大寫，含前綴 `PLAYER_PREFIX`） |
| `password` | 玩家密碼 |
| `game` | 遊戲代碼（大廳留空） |
| `lang` | 語言代碼（見 api.md 語言代碼表） |
| `client` | `ngm_desktop` / `ngm_mobile`（非真人）或 `live_desk` / `live_mob`（真人） |
| `mode` | `real`（真錢）/ `offline`（試玩） |

> `client` 取自 `params->remark`。

---

## BetRecord 對帳流程

| 操作 | API | 說明 |
|---|---|---|
| transferToVendor | `player/deposit` | 本地餘額轉入 Playtech |
| transferToLocal | `player/logout` → `player/withdraw` | **必須先 logout 再 withdraw** |
| 轉帳補查 | `player/checktransaction` | 以 `externaltransactionid`（`externaltranid`）確認最終狀態 |
| 注單對帳 | `game/flow` | 遊戲流水（需等 30 秒後才有資料） |
| 轉帳記錄 | `player/transactions` | 轉帳歷史 |
| 未完成遊戲 | `entity/brokengames` | 破碎遊戲記錄 |

- 對帳保留期：**文件未載明**，需向 Playtech 確認

---

## 高風險陷阱

### 1. logout → withdraw 順序不可逆

**玩家仍在線時，`player/withdraw` 會失敗。** 必須先呼叫 `player/logout` 踢出玩家，再執行 `player/withdraw`。

> Driver 原始碼有明確註解：「先登出, 不然不能提款」

文件 §2.5 的 `player/withdraw` API 有 `isForce` 參數（在遊戲中強制下分），但目前實作未使用。若要改用 `isForce=1` 省去 logout 步驟，需向 Playtech 客服確認行為。

### 2. mTLS 與 `verify: false` 並存

`verify: false` 僅略過對 **Playtech server** 的憑證驗證，**不免除 client cert 配置**。若 `CERT_PATH` 對應的 `.pem` / `.key` 不存在或路徑錯誤，所有 API 呼叫均會失敗。

### 3. form-urlencoded（非 JSON）

所有請求以 `application/x-www-form-urlencoded` 送出，不是 JSON body。Http 客戶端必須以表單欄位格式序列化參數。

### 4. game/flow 30 秒延遲

`game/flow`（遊戲流水 API）需等遊戲結束後 **約 30 秒** 才有資料。太早查詢會得到空集合，對帳工具須設置適當延遲。

### 5. amount 以 floatval() 傳入（已知規範違反）

`Driver::transfer()` 對金額做 `floatval($credit)`，違反專案「禁止原生浮點數運算（須用 `Common::numAdd/Sub`）」規範。Playtech 文件定義 `amount` 型別為 `float`，目前精度尚可，但大額/小數場景存在風險，待整改。

---

## 實作與文件落差

| 項目 | 說明 |
|---|---|
| `verify => false` | 忽略 server SSL 驗證；Playtech 官方範例同樣 bypass，但建議確認正式環境是否已啟用 TLS |
| 提款前 logout | 文件未明文規定，實作為經驗值；`isForce=1` 替代方案待確認 |
| 錯誤碼表缺失 | 完整錯誤碼須透過各 API 的 `baseURI/help/...` 線上頁查詢，文件未附 |
| amount 精度 | `floatval()` 在大額/極小數場景可能有誤差，需整改為 `Common::numSub/Add` |
