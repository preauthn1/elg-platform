# PPSW（vendor_id=83）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill（方法論已遷市集）；本檔聚焦 PPSW 單錢包接入差異。
> 對帳相關差異另見 target-apis plugin 的 `reconcile-bet-records` skill 的 `vendors/ppsw.md`。

## 速查表

| 項目 | 值 |
|---|---|
| `vendor_id` | 83 |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::PPSW` |
| 架構 | Single Wallet（單一錢包） |
| 官方規格 | `docs/integrations/vendors/PPSW/api.md`（v3.243） |
| 主要 Controller | `app/Http/Controllers/External/SingleWallets/PpswController.php` |
| 整合測試 | `tests/Feature/External/SingleWallets/PPSW/PpswMultiBetTest.php` |

> ⚠️ Pragmatic Play 也有轉帳錢包版（`pragmatic` / `pragmaticplay` 等），各自有獨立 vendor_id 與 BR 表；本卡片只涵蓋 SW 版（vendor_id=83）。

## Multi-bet 累加（roundId 為唯一聚合鍵）

- 同一 `roundId` 可能收到**多筆不同 `reference` 的 bet 請求**，必須累加至**同一筆 BetRecord**（不要為每個 reference 建獨立 BR）
- `bonus` 欄位（含 `jackpotWin` / `bonusWin` / `promoWin`）一律用 `Common::numAdd` 累加，**勿覆寫**

## BonusWin 處理

- BonusWin 為**實際入帳交易**（非純通知），收到後需依 `roundId` 找既有 BetRecord 並累加至 `bonus` 欄位
- 不存在對應 BetRecord 時依規格處理（一般不會出現，但需 defensive）

## Refund / 退款

- 找不到原始 Bet 時回傳 `error:0`（官方規格）——不可回錯誤碼
- 退款金額透過 `gameRoundResult` 沖銷事件處理；`applied_at = NULL` 表示尚未結算入帳

## 錯誤碼回傳規則

| 情境 | 回傳 error |
|---|---|
| 一般成功 | `0` |
| 系統例外（DB 連線錯、未預期錯誤） | `120`（**勿回 `7`**） |
| 錯誤碼 `7` | 會觸發 PP Reconciliation；只在規格明確要求時使用 |
| 遊戲不存在 | `8`（`GAME_NOT_FOUND`） |
| Refund 找不到原始 Bet | `0`（官方規格） |

## BetRecord 狀態流程

`BetRecordStatusEnum`：

```
BET (0)  →  END_ROUND (1)  →  SETTLE (3)  ／  REFUND (2)
```

- `BET`：bet 進來但 round 未結束
- `END_ROUND`：round 結束但尚未結算
- `SETTLE`：已結算入帳
- `REFUND`：退款

## 冪等性

所有請求（bet / result / refund 等）必須透過 DealRecord 記錄並作為冪等性判斷依據（通用規範，PPSW 適用）。

## 維護期注意（歷史紀錄）

- 2026-04-18 ~ 2026-04-27：PPSW 遊戲維護 10 天，期間 BR/game_rounds 全 0 筆屬正常，**不要誤判為 DataFeed 斷線**
