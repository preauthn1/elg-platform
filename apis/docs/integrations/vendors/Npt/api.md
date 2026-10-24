# NPT（Playtech）— API 整合文件

> 原始來源（檔名）：
> - `Playtech对接手册v2.4.pdf`（簡體中文，版本號 2.4，2020 年 9 月）— **主要事實來源**
> - `Playtech Integration v2.4.pdf`（英文對照版，同版本）
> - `5_6111666122073839074.txt`（前端遊戲打開 Sample Code，HTML/JS）
> - `info.txt`（後台帳號）、`Games_*.xlsx` / `*Gameslists.xlsx`（遊戲清單）
>
> 抓取日期：2026-06-03

NPT 在本專案 vendor id 為 **41**（`VendorIdEnum::NPT`），底層為 **Playtech** 系統，錢包型態為 **Transfer Wallet（轉帳錢包）**：玩家餘額由 Playtech（NPT）端保管，進入遊戲前須先由代理透過 `player/deposit` 把本地餘額轉入 NPT，離開時透過 `player/withdraw` 轉回。

---

> 本廠商**沒有**遊戲商主動回呼代理的 callback（Transfer Wallet 模式；下注扣款發生在 NPT 端）。

---

## 通用約定

### 請求格式

- **HTTP Method**：所有 API 一律 `POST`
- **Content-Type**：`application/x-www-form-urlencoded`（請求參數以表單欄位送出，**非** JSON body）
- **回應格式**：JSON
- **Base URL**：文件以 `baseURI`（亦寫作 `baseURI`）佔位，正式網址由 NPT 提供（見 gaps）
- **Help 文件鏈接**：每支 API 都有對應 `baseURI/help/<path>` 的線上說明頁，可查錯誤碼對照（文件本身未附完整錯誤碼表）

### 驗證授權（見下方「簽名」節）

NPT 不採用「簽章字串」機制，而是雙重憑證驗證：

| 機制 | 說明 |
|---|---|
| 實體密鑰（Entity Key） | 由 NPT 提供，放入請求標頭 `X_ENTITY_KEY` |
| API 證書（Client Certificate） | 由 NPT 提供的 SSL client cert（`.pem` + `.key`，或 `.p12`），以 mTLS 方式附帶於每次請求 |

### 通用成功回應

成功時回應含 `result`（array/物件），內容依各 API 而定：

```jsonc
{
  "result": { /* 各 API 自有欄位 */ }
}
```

### 通用失敗回應

| 欄位 | 類型 | 說明 |
|---|---|---|
| `error` | String | 錯誤信息 |
| `errorcode` | Integer | 錯誤代碼，需透過各 API 的 `help` 鏈接查對照 |

> 專案 `Driver::request()` 判斷邏輯：回應含 `error` 鍵即視為失敗，拋出 `ApiException`（帶 `error` 與 `errorcode`）。

---

## 錢包流程（Transfer Wallet）

```
玩家進入遊戲前
   │  player/deposit （本地 → NPT，transferToVendor）
   ▼
玩家在 NPT 端遊戲（下注 / 派彩皆在 NPT 結算，代理不介入）
   │
玩家離開 / 結算
   │  player/logout（先登出，否則無法提款）→ player/withdraw（NPT → 本地，transferToLocal）
   ▼
對帳：game/flow / player/transactions / entity/brokengames
```

- **存款（transferToVendor）**：呼叫 `player/deposit`，傳 `playername`（含前綴）、`amount`、`adminname`、`externaltranid`（外部交易號）。
- **提款（transferToLocal）**：**先呼叫 `player/logout` 將玩家踢出**（實作註解：「先登出, 不然不能提款」），再呼叫 `player/withdraw`。
- **不確定狀態**：`player/deposit`、`player/withdraw` 無回應（逾時）時，實作回傳 `VendorTransferResultEnum::TIMEOUT`，後續由 `player/checktransaction`（轉帳狀態查詢）以 `externaltransactionid` 確認最終結果。

### 轉帳狀態映射（player/checktransaction）

| NPT `result.status` | 專案 `VendorTransferResultEnum` |
|---|---|
| `approved` | `SUCCESS` |
| `declined` | `FAILED` |
| `missing` | `FAILED` |
| `waiting` | `TIMEOUT` |
| `notallowed` | `FAILED` |
| 其他 / 無回應 / 含 `error` | `TIMEOUT` |

> 此映射在 `Driver::checkTransferStatus()` 與文件 §2.11「转账状态」一致。

---

## 遊戲打開（Game Launch）

文件 §5 明載：**打開遊戲無法透過 API 直接取得遊戲鏈接，需在前端實現**。代理把以下參數交給前端 JS（`integration.js` / `iapi*` 系列函式），由前端登入並產生遊戲 URL。

| 前端參數 | 值 / 範例 | 說明 |
|---|---|---|
| `username` | — | 玩家 PT 用戶名，**需全大寫並包含前綴** |
| `password` | — | 玩家密碼 |
| `game` | — | 遊戲代碼（大廳則留空；真人牌桌參考遊戲清單 Live Tables） |
| `lang` | 見 [語言代碼](#語言代碼) | 語言 |
| `client` | `ngm_desktop` / `ngm_mobile` / `live_desk` / `live_mob` | 非真人用 `ngm_*`，真人用 `live_*` |
| `mode` | `real` / `offline` | `real`=真錢、`offline`=離線試玩 |

> 專案 `Driver::getGameUrl()` 即回傳 `extra`（username/password/lang/mode=real/game/client），交由前端 SDK 組 URL，與文件一致。`client` 取自 `params->remark`。

---

## 語言代碼

| Code | 語言 |
|---|---|
| `BG` | 保加利亞語 |
| `CH` | 繁體中文 |
| `CS` | 捷克語 |
| `DA` | 丹麥語 |
| `DE` | 德語 |
| `EL` | 希臘語 |
| `EN` | 英語 |
| `ES` | 西班牙語 |
| `ES-MX` | 墨西哥西班牙語 |
| `ET` | 愛沙尼亞語 |
| `FI` | 芬蘭語 |
| `FR` | 法語 |
| `IT` | 義大利語 |
| `JA` | 日語 |
| `KO` | 韓文 |
| `MS` | 馬來語 |
| `NL` | 荷蘭語 |
| `PL` | 波蘭語 |
| `PT` | 葡萄牙語 |
| `RO` | 羅馬尼亞語 |
| `RU` | 俄語 |
| `SK` | 斯洛伐克語 |
| `SV` | 瑞典語 |
| `TH` | 泰語 |
| `ZH-CN` | 簡體中文 |

> 專案 `Driver::getLanguage()` 對照：EN→`EN`、ZH_TW→`CH`、ZH_CN→`ZH-CN`、TH_TH→`TH`、MS_MY→`MS`，預設 `EN`。與文件語言表一致（繁中=`CH`、簡中=`ZH-CN`）。

---

## 修訂日誌（官方文件 §修訂日志）

| 版本 | 日期 | 內容 |
|---|---|---|
| 1.0 | 2020.2 | 初始文檔 |
| 2.0 | 2020.4 | 移除 Kiosk 相關 API（`kiosk/*` → `entity/*`，參數已合併） |
| 2.1 | 2020.4 | 添加 `player/checktransaction` |
| 2.2 | 2020.4 | 添加 API 返回參數，移除返回例子 |
| 2.3 | 2020.5 | 修改遊戲打開示例代碼 |
| 2.4 | 2020.9 | 添加遊戲記錄額外參數 & 添加真人遊戲打開教程 |

---

## 整合落地建議

| NPT / Playtech 概念 | APIs 專案對應 |
|---|---|
| Base URL | `vendor_infos.info.API_URL`（`Driver::setInfo()` 會 `rtrim('/')`） |
| 管理員名稱 `adminname` | `vendor_infos.info.ADMIN_NAME` |
| 實體密鑰 `X_ENTITY_KEY` | `vendor_infos.info.ENTITY_KEY` |
| API 證書路徑 | `vendor_infos.info.CERT_PATH`（相對 `base_path()`，實際讀 `<CERT_PATH>.pem` 與 `<CERT_PATH>.key`） |
| 玩家前綴 `playerusernameprefix` | `vendor_infos.info.PLAYER_PREFIX`（呼叫時組 `PLAYER_PREFIX . username`） |
| Driver | `app/Vendors/Npt/Driver.php`（繼承 `AbstractTransferWallet`） |
| 帳號模型 | `app/Vendors/Models/NptAccount.php` |
| 注單模型 | `app/Vendors/Models/NptBetRecord.php` |
| 存款（本地→NPT） | `transferToVendor()` → `player/deposit` |
| 提款（NPT→本地） | `transferToLocal()` → `player/logout` + `player/withdraw` |
| 餘額查詢 | `getBalance()` → `player/balance`（取 `result.balance`） |
| 轉帳補單查詢 | `checkTransferStatus()` → `player/checktransaction` |
| 遊戲打開 | `getGameUrl()` 回 `extra` 參數，URL 由前端 SDK 產生（無 server-side URL API） |
| callback controller / routes | **無**（Transfer Wallet，無遊戲商回呼） |
| 對帳 | `game/flow`（遊戲記錄，最大延遲 30 秒）+ `player/transactions`（轉帳記錄）+ `entity/brokengames`（破碎/未完成遊戲） |
| 對帳保留期 | **文件未明載**（見 gaps；對帳請及早執行並參考 `game/flow` 30 秒延遲限制） |

### 實作與文件落差（待 RD / 客服確認，非確認 bug）

1. **`verify => false`（忽略 SSL 憑證驗證）**：`Driver::request()` 註解「NPT 根本沒啟用 https」，停用了對 NPT 伺服器憑證的驗證。文件附錄 C#/Java 範例同樣示範了「bypass certificate / hostname check」（`TrustAllCertificatePolicy` / `trustAllCerts`），故此為 Playtech 官方範例行為，但仍建議向客服確認正式環境是否已啟用 TLS 並可改回驗證。
2. **`amount` 以 `floatval()` 傳入**：`Driver::transfer()` 對金額做 `floatval($credit)`，與本專案「禁止原生浮點數」規範存在張力。文件定義 `amount` 型別為 `float`，故符合三方；但建議確認大額/小數精度在 urlencoded 序列化後是否有誤差風險。
3. **提款前強制 logout**：文件 §2.9「踢出玩家」與 §2.5「下分操作」未明文規定「提款前必須 logout」，此為實作經驗值（程式註解）。`player/withdraw` 另有 `isForce`（在遊戲中強制執行下分）參數，實作未使用 — 待確認 logout 流程是否可改用 `isForce=1` 簡化。
4. **錯誤碼對照缺失**：文件所有 API 的失敗回應僅給 `error` / `errorcode`，完整錯誤碼表須透過各 API 的 `baseURI/help/...` 線上頁查詢，本文件無法列舉（見 gaps）。
