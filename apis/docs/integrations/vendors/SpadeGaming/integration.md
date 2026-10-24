# SpadeGaming（vendor_id=47）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 轉帳錢包（Transfer Wallet）；玩家餘額由廠商端保管 |
| 實作來源 | **薄套 `App\Vendors\NextSpin\Driver`**；本類僅設 Model 與 `actionTitle='SPADEGAMING'`，所有轉帳/進遊戲/餘額邏輯都在 NextSpin |
| 傳輸 | `POST` + JSON；以 HTTP header `API`（`getAcctInfo`/`deposit`/`withdraw`）區分動作 |
| 轉帳成功判定 | `json.code == '0'` → `SUCCESS`；無 JSON → `TIMEOUT`；`code` 非 0 → throw `ApiException`（帶 `msg`） |
| 進遊戲 | **純組 URL 不打 API**：`GAME_URL + Merchant_Code + /auth/?acctId&language&token&game`，`token` 取本地 `getMemberAccessToken()` |
| authorize | 與轉帳錢包遊戲模式**共用 authorize 接口**，掛 `routes/external/tw.php`（見 Driver class 註解） |

## 陷阱（高風險）

### 1. 邏輯不在本目錄

`SpadeGaming\Driver` 只有 6 行有效碼（`extends NextSpin\Driver`）。改行為、追 bug、對帳邏輯**一律看 `app/Vendors/NextSpin/Driver.php`**，勿在 SpadeGaming 目錄空找。`SpadegamingSW`（單一錢包，vid=81）是另一條獨立實作，勿與本轉帳錢包混淆。

### 2. 無補單 API

NextSpin `transfer()` `code != '0'` 直接 throw、無 JSON 回 `TIMEOUT`，但**未實作 `checkTransferStatus()`**，廠商亦無轉帳狀態查詢 endpoint → 逾時後最終狀態無法確認，存在資損風險（見 gaps）。

### 3. serialNo 以時間戳 + rand 生成

`serialNo = Carbon::now()->getTimestampMs() . rand(10,99)`。非以本地交易號（`transactionNo`）綁定 → 廠商端冪等以此 serialNo 為鍵時，補送會被視為新請求。對接時確認 SpadeGaming 端是否以 `acctId+amount` 或 serialNo 去重。

## 轉帳流程（NextSpin）

```
存款：transferToVendor → header API=deposit
   │
進遊戲：getGameUrl → 組 /auth/ URL（含本地 token，無 API call）
   │
提款：transferToLocal → header API=withdraw
   │
餘額：getBalance → header API=getAcctInfo，取 list[0].balance
```

- 轉帳欄位：`acctId` / `currency` / `amount` / `serialNo` / `merchantCode`。
- 語言：`en_US`/`zh_CN`/`zh_TW`/`th_TH`/`id_ID`/`vi_VN`/`my_MM`/`hi_IN`，預設 `en_US`。

## 整合落地建議

| SpadeGaming 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/SpadeGaming/Driver.php`（`extends NextSpin\Driver`） |
| 實際邏輯 | `app/Vendors/NextSpin/Driver.php` |
| 帳號 / 注單 Model | `app/Vendors/Models/SpadegamingAccount.php`（`username`）/ `SpadegamingBetRecord.php` |
| `vendor_infos.info` | `API_URL` / `GAME_URL` / `Merchant_Code` / `CURRENCY`（NextSpin `setInfo()` 四鍵） |
| 註冊位置 | `config/vendor.php` → `VendorIdEnum::SG->value (47) => Vendors\SpadeGaming\Driver::class` |
| authorize 路由 | `routes/external/tw.php`（轉帳錢包遊戲模式共用 authorize） |
| 回調 controller | **無** seamless 扣加款回調；僅 authorize |

## gaps / 待 SpadeGaming / RD 確認（非確認 bug）

1. **無補單 API**（見陷阱 2）：待確認 SpadeGaming/NextSpin 是否提供轉帳狀態查詢，以補上 `checkTransferStatus()`。
2. **serialNo 非綁定交易號**（見陷阱 3）：待確認廠商端去重鍵，評估補送是否會重複扣加款。
3. **共用 authorize 接口語意**：轉帳錢包卻掛 `tw.php` authorize，與標準轉帳錢包（無 external 入口）不同，待確認此 authorize 的觸發情境與安全邊界。
4. **`amount` 未經 Common 正規化**：NextSpin `transfer()` 直接傳 `$credit`，待確認精度。
