# PxplaySW（vendor_id=236）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；本檔聚焦 PxplaySW 單錢包接入差異。
> 官方 API 規格見同目錄 `api.md`（*Pxplay 整合文件* v1.24；來源文件編碼損毀，內容經比對同平台的 `RectangleSW` 官方文件重建，細節見 `api.md` 開頭說明）。
>
> **PxplaySW 與 RectangleSW 是同一底層平台的不同品牌**：兩者 `Driver.php`／`Controller.php` 幾乎逐行相同、簽名 scheme 同樣叫 `RG-HMAC-SHA256`，且 TPower 正式環境兩者的 `API_URL` 都指向 `https://example.invalid`（僅 `OC` 不同：RectangleSW 用 `TPOWER`、PxplaySW 用 `TPOWER_cl`）。改這支 vendor 前建議一併看過 `docs/integrations/vendors/RectangleSW/integration.md`。

## 速查表

| 項目 | 值 |
|---|---|
| `vendor_id` | 236 |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::PXPLAYSW`（[VendorIdEnum.php:243](../../../../app/Enums/Vendor/VendorIdEnum.php#L243)） |
| 架構 | Single Wallet（單一錢包） |
| 官方規格 | `docs/integrations/vendors/PxplaySW/api.md`（v1.24，來源文件編碼損毀，內容經重建） |
| Driver | `app/Vendors/PxplaySW/Driver.php` |
| 主要 Controller | `app/Http/Controllers/External/SingleWallets/PxplaySwController.php` |
| 路由 | `routes/external/sw.php`（`pxplay` prefix） |
| 資料表 | `pxplaysw_accounts`、`pxplaysw_bet_records` |
| 整合測試 | 目前**無**（TODO：補 Feature test） |
| 環境現況 | Dev（`mysql`）：`vendor_info_id=2825`，`API_URL=https://example.invalid`、`OC=tpowerdev`（沙箱）；TPower 正式：`vendor_info_id=492`，`API_URL=https://example.invalid`、`OC=TPOWER_cl` |

## 路由

| 方法 | 路徑 | Controller Action |
|---|---|---|
| `POST` | `pxplay/api/v1/authenticate` | `authenticate()` |
| `GET` | `pxplay/api/v1/wallets` | `wallets()` |
| `POST` | `pxplay/api/v1/transactions` | `transactions()` |

> 官方規格另有 `TestTokenV1`（`POST /api/v1/testtokens`，見 `api.md` §9）——非強制但官方文件強調正式營運後會**定期自動調用**做連通性驗證。**本專案未實作此端點**，路由與 Controller action 都不存在。若 Pxplay 正式營運後真的會固定打這支 API，缺失可能被 Pxplay 誤判為串接異常，需評估是否補上。

## vendor_info.info 設定欄位

`Driver::VendorInfoDetailData`：與 RectangleSW 完全相同的四個欄位（`API_URL` / `OC` / `SECRET` / `CURRENCY`）。

## 簽名驗證

`PxplaySwController::isSignatureValid()`：與 RectangleSW **逐行相同**的實作——解析 `Authorization` header 的 `RG-HMAC-SHA256` scheme，HMAC-SHA256(`Host . X-Content-SHA256 . X-Date`, `SECRET`)；`SECRET` 為空字串時同樣**略過驗簽直接放行**（僅適用測試環境）。

## PayoutTransactionV1 與 RectangleSW 的具體差異

雖然 Controller 結構幾乎一致，但下列幾點是實測程式碼中**確認存在**的差異：

### 1. 幣別驗證：動態比對，不是寫死

RectangleSW 的 validator 寫死 `'currency' => 'required|string|in:MYR'`；PxplaySW 改成：

```php
'currency' => 'required|string',
// ...
if ($request->input('currency') !== $this->vendorInfo->currency) {
    return $this->errorResponse(1601, 'Currency mismatch');
}
```

即動態比對 `vendor_infos.currency`，不綁死特定幣別——這與官方規格 §8 描述的「請求幣別須與玩家錢包幣別一致」語意一致，比 RectangleSW 的寫死版本更貼近規格。

### 2. `walletType` 多接受 `FREE_GAME`

```php
'walletType' => 'string|in:CASH,BONUS,FREE_GAME',
```

官方文件 §8 只提到 `CASH` / `BONUS` 兩種值，**沒有 `FREE_GAME`**。這是實作接受範圍**超出**官方文件記載的情況，需要跟 Pxplay 確認 `FREE_GAME` 是否為後續版本才新增、或單純是防禦性放寬。

### 3. `betAmount=0` 時跳過帳號狀態與投注資格檢查

```php
if (Common::numCompare((string) $request->input('betAmount', 0), 0) > 0) {
    if ($this->account->status == 0) {
        return $this->errorResponse(1200, 'Player account is disabled');
    }
    $this->verifyBettability($gameCode);
}
```

這段是 RectangleSW 沒有的邏輯：純派彩（`betAmount=0`，例如免費旋轉後續派彩）不檢查帳號啟用狀態與遊戲可投注性，只有真正扣款的請求才檢查。設計動機推測是避免「帳號在派彩過程中被停用」導致合法派彩卡住，但這也代表**已停用帳號仍可能收到派彩入帳**——如果這不是預期行為，需要跟需求方確認。

### 4. 錯誤訊息更明確

RectangleSW 的 `1800`（缺參數）固定回 `'Missing required parameters'`；PxplaySW 改用 `$validator->errors()->first()` 回傳實際驗證失敗訊息，除錯上更友善，但代表回給三方的錯誤內容包含我方 validator 規則的具體描述（非官方規格定義的固定文案）。

## 冪等性

與 RectangleSW 相同：`pxplaysw_bet_records.reference_no`（= `transactionId`）有 DB unique constraint；處理前先查詢一次是否已存在，命中則直接回傳當前餘額；DB 寫入階段仍撞到 unique constraint（MySQL `1062`）時同樣視為冪等命中而非報錯。

## 遊戲清單同步（`ShouldSyncGameList`）

與 RectangleSW 相同：`getGameList()` 呼叫 `GET {API_URL}/api/v1/available-games?oc={OC}`，狀態對照 `ACTIVE`→`1`、其餘→`0`，遊戲 URL 存於 `example.invalid` JSON。

> 官方規格 `api.md` §10 提到 Pxplay 自 v1.20 起已從 `ListAvailableGamesV1` 回應移除 `launcherUrl` 欄位（因 Multi-game Launcher 已下架）；`Driver::getGameList()` 本來就沒有讀取這個欄位，故此規格變更對本專案無影響。

## 語言對照

`Driver::getLanguage()`：與 RectangleSW 完全相同（`ZH_CN`→`zh`、`ZH_TW`→`tzh`、`TH_TH`→`th`、`ID_ID`→`id`，其餘 fallback `en`）。

## 錯誤碼回傳規則

| 情境 | 回傳 code |
|---|---|
| IP 不在白名單 | `2000` |
| token 無效 / 帳號停用 | `1200` |
| 缺少必填參數 / 驗證失敗 | `1800`（附 `$validator->errors()->first()` 訊息） |
| 簽名驗證失敗 | `2000` |
| 幣別不合法（與玩家錢包幣別不符） | `1601` |
| 餘額不足 | `1602` |
| DB 例外 / 未預期例外 | `2000` |

> 對照官方規格 `api.md` §13：以上碼皆為官方定義碼；官方錯誤碼表比 RectangleSW **少一條 `2403`**（重複發獎），其餘完全一致。

## 與 RectangleSW 的落差總表（待確認事項）

| 項目 | 官方規格是否要求 | 本專案是否實作 | 建議動作 |
|---|---|---|---|
| `TestTokenV1`（`/api/v1/testtokens`） | 建議實作，正式營運後 Pxplay 會定期自動調用 | ❌ 未實作 | 評估是否需要補上，避免被 Pxplay 誤判串接異常 |
| `walletType=FREE_GAME` | 文件未記載（只提 CASH/BONUS） | ✅ 已接受 | 跟 Pxplay 確認此值語意與是否為文件遺漏 |
| 幣別驗證方式 | 動態比對玩家錢包幣別 | ✅ 已依規格動態比對（優於 RectangleSW 的寫死做法） | 無需動作 |
