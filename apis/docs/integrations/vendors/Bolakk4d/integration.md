# Bolakk4d（vendor_id=104）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 品牌 | ProBet（馬來西亞彩票，4D / Toto / Lotto） |
| 錢包模式 | 轉帳錢包（Transfer Wallet）；玩家餘額由遊戲商端保管 |
| 傳輸格式 | 請求：JSON Body（POST）；回應：**XML** |
| 驗證 | 雙層帳密（`apiUser`/`apiPass` + `user`/`pass`），**無簽章** |
| 成功判定 | `<errorCode>0</errorCode>` |
| 時區 | **UTC+08:00** |
| 資料保留期 | **90 天** |

## 彩票特性（非即時對局）

投注日期與結算日期（= 開彩日期 `drawDate`）不同，是兩個獨立查詢維度：

| API | 查詢維度 |
|---|---|
| `betlistPage.aspx` / `betlist.aspx` | **投注日期** |
| `winNumber.aspx` | **開彩日期** |
| `winLoss.aspx` | 開彩日期 |

## 陷阱與落差

### 1. `betLogin.aspx` 的 `user`/`pass` 是玩家帳密（非代理）

其他 API 的 `user`/`pass` 帶代理帳密，但 `betLogin.aspx`（Login）的 `user`/`pass` 帶**玩家**登入名與密碼。玩家密碼由 `getDefaultPassword(username, created_at)` 推導，與 `createPlayer.aspx` 寫入的 `loginPass` 必須同源。若建號流程密碼不一致，玩家無法進遊戲。

### 2. `amountm` vs `amountn`（欄位名落差）

PDF 文件 Deposit/Withdraw 的禮碼數額欄位名為 **`amountn`**，但 Driver 送出的是 **`amountm`**（固定 `0`）。需向客服確認三方實際接受的欄位名。

### 3. 回應標籤名不一致（`desc` vs `description` vs `errorMessage`）

PDF 頂部範例寫 `<description/>`，錯誤信息章節用 `<desc/>`，Driver 讀的是 `<errorMessage>`。需以實際回應抓包確認正確標籤名。

### 4. Driver 僅實作核心 4 支 API

實際已實作：GetProfile（getBalance）、Login（betLogin）、Deposit、Withdraw。CreatePlayer、報表類（GetBetList / WinLoss / WinningNumber / result / currencyRate / txnList）等尚未在此 Driver 出現，對帳 / 建號邏輯可能在他處或待補。

### 5. `getProfile.aspx` 回傳大量賠率欄位

回應含 `priceBig`/`prizeBig1~5`/`priceSmall` 等各彩種賠率，Driver 僅取 `<balance>`，其餘未使用。

## 錢包流程（Transfer Wallet）

```
createPlayer.aspx（建立玩家，Driver 未見直接呼叫）
      ↓
transferToVendor → deposit.aspx（本地→廠商）
      ↓
getGameUrl → betLogin.aspx 取 sessionID + tokenCode
           → 組 URL：PC /api/web-bet 或 WAP {AUTH_WAP_URL}/auth
      ↓
transferToLocal → withdraw.aspx（廠商→本地）
```

**transfer 結果判定**：

| errorCode | 結果 |
|---|---|
| `0` | SUCCESS |
| `1`/`2`/`3`/`4`/`5`/`999` | FAILED |
| 其他未知碼 / 回應異常 | TIMEOUT（後續查單確認） |

## 整合落地建議

| Bolakk4d 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Bolakk4d/Driver.php`（`extends AbstractTransferWallet`） |
| Account Model | `app/Vendors/Models/Bolakk4dAccount.php` |
| BetRecord Model | `app/Vendors/Models/Bolakk4dBetRecord.php` |
| Vendor 註冊 | `config/vendor.php` → `VendorIdEnum::BOLAKK4D->value (104) => Vendors\Bolakk4d\Driver::class` |
| `CLIENT_ID` / `CLIENT_SECRET` | `apiUser` / `apiPass` |
| `CLIENT_AGENT` / `CLIENT_AGENT_PASS` | 代理層 `user` / `pass` |
| `AUTH_URL` | PC 下注頁域名（`+/api/web-bet`） |
| `AUTH_WAP_URL` | WAP 下注頁域名（`+/auth`） |
| `CURRENCY` | 幣別（測試授權 `MYR`） |
| `CLIENT_CLASS` | 用途待確認（文件無明確對應） |
| 對帳保留期 | **90 天**（API + 前台） |

> 本廠商為 Transfer Wallet，**無單一錢包回調**，不需 External/SingleWallets controller。注單同步靠主動拉取報表 API。

## Gaps / 待 Bolakk4d 確認

- 回應標籤名 `desc` / `description` / `errorMessage` 以哪個為準（需抓包）
- `amountm` vs `amountn` 禮碼欄位名（PDF 與 Driver 不一致）
- `drawType=7` 對應哪個彩種（PDF 未列）
- `CLIENT_CLASS` 欄位實際語意
- CreatePlayer 是否已在他處實作，玩家建號流程是否正確
