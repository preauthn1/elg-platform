# SpadegamingSW（vendor_id=81）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。
>
> ⚠️ 與 `SpadeGaming`（vid 47，轉帳錢包）同源不同模式：本卡為 **vid 81 單一錢包**。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（Seamless）；玩家餘額由本平台保管，SG 回調扣加款 |
| 回調入口 | `POST /spadegaming`，以 Header `API`（`getBalance`/`transfer`）分派；`entrance()` 再依 `type` 分派 |
| 驗證 | `SpadegamingDigestCheck` 中介層驗 `Digest`（`md5(body+securityKey)`）＋ IP 白名單 |
| 成功判定 | `code === 0` |
| 匯率 | 進出 `Common::numMul/numDiv(.., rate, 4)`；transferTime 以 UTC+8 解析 |
| 冪等鍵 | `transferId`（重複回原餘額 + `code 0`）；BR `bet_id` unique。**撤單另以 `referenceId` 為鍵**（見「冪等性」） |

## 陷阱（高風險）

### 1. transfer 只有一條路徑

`SpadegamingSWController::entrance()` → 私有 `placeBet()`/`payout()`/`cancel()`/`bonus()`（route 實際指向 `entrance`），餘額經 `changeBalance()` 統一封裝。曾經並存的 `app/Vendors/SpadegamingSW/TransferService.php` 旁路（未被 route 引用）已刪除，排查時不會再看到第二套邏輯。

### 2. Type 5（PAYOUT_SPECIAL_GAME）為內部衍生狀態

文件 `transfer.type` 僅 `1/2/4/7`。payout 時若 `specialGame.sequence !== 0`，BR 記錄 type 改寫為 `5`，並走 `TotalBetRecordService::create`（而非 `createOrUpdate`）。`5` 不會出現在 SG 送來的請求。

### 3. ReturnStatusEnum 是文件碼集子集

`app/Vendors/SpadegamingSW/Enums/ReturnStatusEnum.php` 僅 `0/1/2/3/109/10113/10116/50100/50101/50103/50110/50113`。文件的 `50104` / `5003` / `120` 未納入：**IP 白名單失敗回 `2 INVALID_REQUEST` 而非文件的 `120`**，對接時注意此差異。

### 4. Digest 兩個方向計算式不同

- Merchant → SG（`getAuthorize`）：`md5(json_encode($postData) . Security_key)`。
- SG → Merchant（回調驗簽）：`md5(json_encode(json_decode($rawBody)) . Security_key)`——**先 decode 再 encode** 正規化空白 / Unicode escape 後再比對 Header `Digest`，失敗回 `code 2 "Digest verify failed"`。

## Type 對照與 BetRecord

| `type` | 方法 | 處理 |
|---|---|---|
| `1` 下注 | `placeBet()` | `isMemberAbleToBet`；BR（`bet=valid_bet=amount`、`win_lose=-amount`）；扣款；`transferId` 重複→回原餘額 |
| `2` 取消 | `cancel()` | 找 `referenceId` placeBet（無→`109`）；該 placeBet 已有 CANCEL 列→回 `code 0` 不動錢包；退款（含泥碼回退）；`win_lose = placeBet.win_lose × -1` |
| `4` 派彩 | `payout()` | 找 `referenceId` placeBet（無→`109`）；加款；棋牌（`C-` 開頭）特殊算 valid_bet；寫 TBR |
| `7` Bonus | `bonus()` | 不帶 `referenceId`；直接加款；`reference_bet_id = transferId`；寫 TBR |

- **棋牌 valid_bet**：派彩 `valid_bet = (placeBet.win_lose + payout.win_lose) > 0 ? 0 : 該和 × -1`（玩家贏時 valid_bet 歸零）。

## 冪等性

| 場景 | 處理 |
|---|---|
| `transferId` 已存在（任一 type） | 回當前餘額 + `code 0`（`buildDuplicateResponse`） |
| **cancel 的 `referenceId` 已有 CANCEL 列** | 回當前餘額 + `code 0`，**不動錢包**（`isBetAlreadyCanceled()`，鎖定讀） |
| **payout 的 `referenceId` 重複** | **未設閘**：仍只以 `transferId` 冪等，SG 逾時重送 payout 會重複加款。歷史零發生（`target/op_tickets#3098` 查證：APIs 期間 TPower 19 筆被撤下注皆無派彩、TBR 零筆），刻意未改 |
| cancel / payout 找不到 placeBet | 回 `109 RELATED_ID_NOT_FOUND` |

### 撤單為什麼不能只用 `transferId` 當冪等鍵

SG 未收到 placeBet 回應（timeout）時會重發 cancel，**每次帶新的 `transferId`**（`api.md`「Type 對照」type=2）。只比對 `transferId` 的話每一次重送都會通過，`changeBalance` 再以「原下注 `bet` → 0」的差額把真金與禮碼各退一次——生產已發生：TPower 9 筆超退 1,321.00、Yes2Win 1 筆 4.00（`target/op_tickets#3098`；其中 2024-11 的 7 筆 1,285.00 早於 APIs 接手，屬舊系統期間）。

被擋下時回 `code 0` 而非 `109`：`api.md` 明訂重複的 `referenceId` 可回 `0` 或 `109`，而**回 `109` 時 SG 會持續重送**。

閘門粒度是「同一筆下注」而非「同一玩家」——不同下注各自撤一次都要放行。

🔴 **這個閘門必須是鎖定讀（`lockForUpdate()`），不能用一般讀。** `cancel()` 的交易一進去就由 `WalletService::getMemberLockedWallets()` 的一般讀（`$member->agent`）建立 REPEATABLE READ 快照，之後才等會員列鎖；拿到鎖後的一般讀仍是那張舊快照，看不到併發的另一筆 cancel 剛 commit 的撤單列，於是放行再退一次。同型事故已在 PegasusSW 發生（`target/op_tickets#3075`，commit `8c86a78e1`）。生產那 9 筆重複撤單中有 6 筆 `created_at` 落在同一秒，正是這條併發路徑——**循序重送的測試測不到它**，回歸測試見 `tests/Feature/External/SingleWallets/Spadegaming/CancelStaleSnapshotTest.php`。

代價：`reference_bet_id` 無索引，`FOR UPDATE` 沿 `type` 索引取 next-key lock，等於把全站 SG 撤單序列化。以 2026-09-08 實測 TPower `type=2` 僅 168 列（全表約 3,259 萬列）的量體，這個代價接近零；下注（`type=1`）與派彩（`type=4`／`5`）的 INSERT 落在索引的其他區段，不受影響。要收斂鎖範圍須補 `(type, reference_bet_id)` 索引，屬大表 DDL，另案排期。

## 整合落地建議

| Spadegaming 概念 | APIs 專案對應 |
|---|---|
| Vendor 註冊 | `config/vendor.php` → `VendorIdEnum::SPADEGAMINGSW->value (81) => Vendors\SpadegamingSW\Driver::class` |
| Driver | `app/Vendors/SpadegamingSW/Driver.php`（`AbstractSingleWallet`，僅 `getGameUrl`） |
| 回調 Controller | `External/SingleWallets/SpadegamingSWController.php`（`entrance`） |
| Digest 中介層 | `app/Http/Middleware/External/SingleWallets/SpadegamingDigestCheck.php` |
| Route | `routes/external/sw.php` → `POST /spadegaming`（中介層 `SpadegamingDigestCheck`） |
| Account / BetRecord Model | `App\Vendors\Models\SpadegamingswAccount`（`username`）/ `SpadegamingswBetRecord`（`bet_id` unique） |
| FormRequest | `App\Http\Requests\External\SpadegamingSWRequest` |
| `vendor_infos.info` | `Merchant_Code` / `API_URL` / `GAME_URL`(保留) / `CURRENCY` / `Security_key`（Digest）/ `operator_id` |
| getAuthorize token | `token = [REDACTED] . Merchant_Code . microtime(true))`；`serialNo = time() . rand(10,99)`；自驗 `code`/`serialNo`/`token` |

## gaps / 待 SG / RD 確認（非確認 bug）

1. **正式 host / securityKey / merchantCode / 對帳保留期**：文件未列，須向 SG 客服索取（getBetHistory 僅給取單區間規則，未給保留期）。
2. **語言 default 回 `en` 非 `en_US`**：`Driver::mapLanguageCode()` 預設回 `en`（文件定義 `en_US`），且繁中與簡中都映射成 `zh_CN`，待確認是否影響遊戲端顯示。
3. **Digest 大小寫 / header 名**：C# 範例輸出大寫 hex 且回應 header 用 `hash`，Java 用 `Digest`；本平台 `md5()` 小寫，須與 SG 確認比對規則。
4. **`getAuthorize` 未驗 SG 回應 Digest**：本平台只驗 `code`/`serialNo`/`token`，未驗回應簽章，待確認是否需補。
