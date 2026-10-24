# Ag（vendor_id=1）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 轉帳錢包（Transfer Wallet）；玩家餘額由 AG 端保管 |
| 傳輸格式 | 請求：`DES-ECB + Base64`（`params`）+ `MD5` 簽章（`key`）；回應：XML |
| 域名分流 | `doBusiness.do`→ gi 域名（`BUSINESS_HOST`）；`forwardGame.do`→ gci 域名（`FORWARD_HOST`） |
| 必填 Header | `User-Agent: WEB_LIB_GI_<cagent>`（`doBusiness.do` 必填；`forwardGame.do` 不需要） |
| 成功判定 | `<result info="0">` / `info` 直接帶餘額（GetBalance）|
| 轉帳 | 兩階段：PrepareTransfer（tc）→ TransferConfirm（tcc） |

## 高風險陷阱

### 1. VND/IDR/MMK 幣值折算落點不明（高風險）

AG 官方文件明確規定：VND/IDR 轉帳時須 **÷ 1000**（轉入）/ **× 1000**（轉出）；MMK 須 **÷ 100 / × 100**。但 Driver 的 `transfer()` 直接以 `Common::numFloor($params->vendorAmount, 2)` 送出，**Driver 本身未見折算**。

若此折算未在上游錢包匯率邏輯中處理，開通 VND/IDR/MMK 線路時轉帳金額將差 1000 倍（VND）或 100 倍（MMK），造成資損。**上線 VND/IDR/MMK 線路前必須驗證**。

### 2. 建號（`method=lg`）呼叫落點不明

Driver.php 未見 `method=lg`（CheckOrCreateGameAccount）呼叫。AG 規定「進入遊戲 / 轉帳前須先建立遊戲帳號」。若建號未被呼叫，可能出現「帳號未建就轉帳」的失敗。需向 RD 確認建號觸發位置。

### 3. 查單（`method=qos`）缺失導致不確定狀態坍縮為 FAILED

官方 §3.5 要求 AGIN 平台每次轉帳都可調用查單。Driver 目前在 `prepareTransfer`/`confirmTransfer` 失敗時直接回 `FAILED`，未走查單確認最終狀態。AG 端可能已成功而本地誤判失敗，造成餘額不一致。

建議：HTTP 例外 / `network_error` 應回 `TIMEOUT`，由上層走 `checkTransferStatus`（需 override 呼叫 `qos`）。

### 4. `tcc` info=1 未重試

官方說明 `tcc` 回 `info=1`（訂單未處理）應等 5 秒後再呼叫 `tcc`。Driver 目前對 `info=1` 之外的情況直接拋例外，未實作重試。

### 5. HTTP Timeout 未顯式設定

官方 §3.4/§3.6 要求 timeout ≥ 30 秒。Driver 未顯式設定 `Http::timeout(30)`，依賴框架預設。

### 6. `getGameUrl()` mobile URL 疑似未帶 `mh5=y`

Driver 設 `$mobileData['mh5']='y'` 後，`http_build_query($data, ...)` 仍用原始 `$data`（未含 mh5），mobile 與 PC URL 可能完全相同。需 RD 確認。

## 錢包流程（Transfer Wallet 兩階段）

```
getGameUrl()
  └─ DES+MD5 簽章組 URL → forwardGame.do（gci 域名，無 User-Agent Header）
  └─ 前端跳轉進入遊戲

transferToVendor（type=IN）/ transferToLocal（type=OUT）
  └─ prepareTransfer（method=tc）→ info=0
  └─ confirmTransfer（method=tcc, flag=1）→ info=0 = SUCCESS
  └─ 失敗 → 目前直接 FAILED（建議改 TIMEOUT + qos 查單）

getBalance
  └─ method=gb → info 直接帶餘額字串（2 位小數）

checkTransferStatus（未 override）
  └─ AbstractTransferWallet 預設拋例外；需 override 呼叫 method=qos
```

## `info.GAME_TYPE` 命名陷阱

`vendor_infos.info.GAME_TYPE` 被用來填 AG 的 **`actype`**（真錢=1/試玩=0），**並非** AG 的 `gameType`（遊戲廳編號）。AG 的 `gameType` 在本專案來自 `getGameUrl` 的 `$params->gameCode`。閱讀 config 時勿混淆。

## 整合落地建議

| AG 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Ag/Driver.php`（`extends AbstractTransferWallet`） |
| Account Model | `app/Vendors/Models/AgAccount.php` |
| BetRecord Model | `app/Vendors/Models/AgBetRecord.php` |
| DES 加密 | `app/Facades/DES.php` → `app/Services/Encryption/DesService.php`（`DES-ECB`，手動 PKCS7 padding） |
| 注單來源 | AGIN Data API（`AGIN_DataAPI_V3.34.pdf`）定期拉取；Driver 未實作拉單 |
| `BUSINESS_HOST` | gi 域名（`doBusiness.do`），含 scheme/port，如 `http://gi.xxx:81` |
| `FORWARD_HOST` | gci 域名（`forwardGame.do`），如 `http://gci.xxx:81` |
| `AGENT` | `cagent`（代理編碼常數） |
| `MD5_KEY` | MD5 簽章 secret |
| `DES_KEY` | DES-ECB 加密金鑰 |
| `CURRENCY` | `cur`（幣別代碼） |
| `GAME_TYPE` | `actype`（填 0/1，非遊戲廳編號） |
| `ODD_TYPE` | `oddtype`（預設 A；特定大號帳號 `GB4MELG00093701` 改 `GB4B`） |

## Gaps / 待確認

- 正式 Base URL（gi / gci 域名與 port）：文件以 `<server>` 佔位，須由 AG 上線說明文檔提供
- 建號（`method=lg`）呼叫落點
- VND/IDR ÷1000、MMK ÷100 折算是否在上游已處理
- 查單（`method=qos`）是否需補 override `checkTransferStatus`
- 注單 Data API 保留期（GI 文件未載明）
