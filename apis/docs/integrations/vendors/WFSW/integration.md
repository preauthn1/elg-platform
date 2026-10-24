# WFSW（vendor_id=120）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | Single Wallet / Seamless |
| 回調方向 | WF → 本平台（6 個 endpoint） |
| 驗證方式 | MD5 簽名 + IP 白名單 |
| 成功判斷 | `Status=200` |
| 時區 | UTC（`RequestDateTime`/`TranDateTime` 皆 UTC，僅用於簽名與記錄，不作時區轉換） |
| 幣種換算 | `BetAmount`/`Payout`/`Jackpot` 均需 `numDiv(amount / rate, 4)` 換算 |

---

## 簽名陷阱

各 endpoint 的 MD5 輸入欄位**不統一**，混用會導致驗簽失敗：

| API | 簽名用的 ID 欄位 |
|---|---|
| `GetBalance` | 無（無 BetId/ResultId/TranId，`$betId = ''`） |
| `Bet` | `BetId` |
| `GameResult` | `ResultId`（非 `BetId`） |
| `Rollback` | `BetId` |
| `CashBonus` | `TranId` |
| `Jackpot` | `TranId` |

實作見 `WFController::checkSign()`（`app/Http/Controllers/External/SingleWallets/WFController.php`）：

```php
switch ($functionName) {
    case 'Bet':
    case 'Rollback':
        $betId = $request->input('BetId') ?? '';
        break;
    case 'GameResult':
        $betId = $request->input('ResultId') ?? '';
        break;
    case 'CashBonus':
    case 'Jackpot':
        $betId = $request->input('TranId') ?? '';
        break;
    default:
        $betId = '';
}
$signatureToValidate = md5($functionName . $betId . $requestDateTime . $operatorId . $secKey . $playerId);
```

---

## BetRecord 狀態機

```
Bet(1) ──→ GameResult(2) RESULT
       └──→ Rollback(3)  ROLLBACK

（獨立觸發）
CashBonus → BONUS(4)
Jackpot   → JACKPOT(5)
```

| status 值 | BetRecordStatusEnum | 觸發事件 |
|---|---|---|
| `1` | `BET` | Bet 投注扣款完成 |
| `2` | `RESULT` | GameResult 結算完成 |
| `3` | `ROLLBACK` | Rollback 取消退款完成 |
| `4` | `BONUS` | CashBonus 獎金加入 |
| `5` | `JACKPOT` | Jackpot 彩金加入 |

---

## 冪等性

| 場景 | 處理 |
|---|---|
| 收到重複 `BetId`（Bet） | 查到 `WfswBetRecord`，直接回 `DUPLICATE_TRANSACTION`（code 900409） |
| 收到重複 `BetId`（Rollback） | 查到 `status == ROLLBACK`，回 `DUPLICATE_TRANSACTION` |
| 收到重複 `ResultId`（GameResult） | 查到 `status == RESULT`，回 `DUPLICATE_TRANSACTION` |
| 收到重複 `TranId`（CashBonus/Jackpot） | 查到紀錄存在，回 `DUPLICATE_TRANSACTION` |
| Bet 時找不到餘額 | 回 `INSUFFICIENT_BALANCE`（code 900605） |
| GameResult 時找不到 Bet | 回 `BET_TRANSACTION_NOT_FOUND`（code 900415） |
| Rollback 時找不到 Bet | 回 `BET_TRANSACTION_NOT_FOUND`（code 900415） |

---

## GetBalance 注意

`GetBalance` **不呼叫** `BetHelper::isMemberAbleToBet`，只查餘額。

帳號 `status == 0` 時回傳 `INVALID_PLAYER`（code 900404），不回傳餘額。

---

## CashBonus / Jackpot 特殊

- 兩者 `bet = 0`，不是真實下注，但仍建立 `WfswBetRecord`
- `game_code` 固定為 `'CashBonus'` 或 `'Jackpot'`（非實際遊戲代碼）
- `TotalBetRecordService::create` 時分別帶 `['bonus' => $win]` / `['jackpot' => $win]` 參數

---

## 實作落差（待確認）

以下項目為實作與三方文檔存在潛在差異之處，已標記供 RD / 客服確認，**不代表已確認為 bug**。

1. **`GetBalance` 回應缺少 `Balance` 欄位**：文件中 `GetBalance` 的回應範例未列出 `Balance` 欄位（回應範例僅 Status/Description/ResponseDateTime），但實作回傳了 `Balance`。WF 應確認 GetBalance 的完整回應格式。

2. **`Rollback` 時 bet/payout/win_lose 歸零**：實作中 Rollback 將 `bet = 0 / payout = 0 / win_lose = 0`，文件未明確說明 Rollback 後 BetRecord 的各欄位應如何設定。待確認是否正確。

3. **`GameResult` controller 方法命名**：路由定義為 `Route::post('GameResult', [WFController::class, 'settle'])`，代理端方法名為 `settle`，而 WF 文件稱為 `GameResult`。此為正常內部命名差異，不影響行為。

4. **`checkSign` 中 `GetBalance` 無 `BetId`**：文件簽名規則 GetBalance 使用 `FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId`，實作的 `checkSign()` default case 將 `$betId = ''`，等同於 `FunctionName . '' . RequestDateTime . OperatorId . SecretKey . PlayerId`，語意上與文件相符。

---

## 整合落地建議

| WF 概念 | APIs 專案對應 |
|---|---|
| `OperatorId` | `vendor_infos.info.OperatorId` |
| `SecretKey` | `vendor_infos.info.SecretKey` |
| `API_URL` | `vendor_infos.info.API_URL` |
| `Currency` | `vendor_infos.currency` |
| `PlayerId` | `wfsw_accounts.username` |
| Driver | `app/Vendors/WFSW/Driver.php` |
| Seamless 回調 Controller | `app/Http/Controllers/External/SingleWallets/WFController.php` |
| 路由前綴 | `sw/wf`（見 `routes/external/sw.php`） |
| BetRecord 模型 | `App\Vendors\Models\WfswBetRecord`（DB 表 `wfsw_bet_records`） |
| Account 模型 | `App\Vendors\Models\WfswAccount`（DB 表 `wfsw_accounts`） |
| vid | `VendorIdEnum::WFSW = 120` |
| PullLog 保留期 | 未於文檔明確標示——**待 RD / 客服確認**（建議比照一般 3 個月） |
| SBR 重建 | 建立 `App\Services\SummaryBetRecords\WFSW`，`$timeField = 'payout_time'` |
