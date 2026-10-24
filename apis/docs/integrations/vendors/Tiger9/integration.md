# Tiger9（vendor_id=91）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（Seamless）；玩家餘額由本平台保管，Tiger9 回調扣加款 |
| 回調方向 | **Tiger9 → 本平台**（本平台為 server，被呼叫方） |
| 驗證 | `X-PROVIDER-IDENTITY` header 比對 `vendor_infos.info['IDENTITY']` + IP 白名單；**無 3DES 簽章**（官方文件有，實作未用） |
| 成功判定 | `code === 0`，HTTP 一律 200 |
| 匯率 | 入庫 `Common::numDiv($amount, $rate, 4)`；回應 `Common::numMul($balance, $rate, 4)` |
| 對帳保留期 | **7 天**（Log API 限制，逾期無法取回） |
| 共用 Driver | **PegasusSW 聚合 Driver**（vid 50 PGSSW / 91 Tiger9 / 145 BWG 共用） |

## 最高優先：雙協議不一致（必讀）

Tiger9 有**兩套完全不同的協議**並存於文件中：

| 軸線 | 說明 | 狀態 |
|---|---|---|
| **§A 官方 PDF**（GALAXY / TigerNine）| `apiID` + 3DES-ECB 簽章、`/balance/detail` + `/balance/transfer`（`action` code）、boolean `status` 回應 | 文件事實，**未實作** |
| **§B target 聚合協議**（實際落地）| `X-PROVIDER-IDENTITY` header、`/target/*` 路由、整數 `code` 回應 | **實際使用** |

> ⚠️ 兩套欄位名稱、endpoint、簽章、回應格式全然不同。需向 RD/客服確認：Tiger9 正式環境串接哪一套？官方 PDF 是否為舊版或誤置的 GALAXY 品牌文件？

## 共用 Driver 路由（高風險陷阱）

`config/vendor.php` 中 Tiger9（91）、PGSSW（50）、BWG（145）均指向同一個 `PegasusSW\Driver`。

- `PegasusController::callAction()` 透過 `X-PROVIDER-IDENTITY` header **動態覆寫** `$vendorId`（不固定為 91），因此同一個 `/target/*` 路由可能服務三個不同 vid。
- 回調 Controller 中 `$this->vendorInfo->vendor_id` 為實際生效的 vendor_id。
- 排查問題時須確認 `IDENTITY` 比對結果是否正確對應至 vid=91。

## BetRecord 狀態機

`BET(0)` →（settle / settle-without-payout / settle-change）`SETTLE(1)` 或（cancel）`ROLLBACK(2)`，後兩者皆終態。

- **placeBet**：建立 BR `status=BET`、`win=0`、`win_lose = 0 - bet`，`payout_time/settlement_time = 1970-01-01`；扣款；寫 TBR。
- **settle**：`bet=validAmount`（注意：`validAmount` 同時覆蓋 `bet` 與 `valid_bet`）、`win=payoutAmount`、`win_lose=win-bet`、`status=SETTLE`；派彩；寫 TBR（帶 `valid_bet`）。
- **settleWithoutPayout**：投注與結算合併單筆，不先呼 placeBet，建立直接 `status=SETTLE`。
- **settleChange**：已結算注單改單（`afterBetAmount` / `afterValidAmount` / `afterPayoutAmount`），重算後仍 `status=SETTLE`；寫 TBR。
- **bonus**：`bet=0`，`win=win_lose=payoutAmount`；TBR 帶 `['bonus' => $win]`；回應格式特殊（含 `merchantOrderNo`、`beforeAmount`、`afterAmount`）。
- **cancel**：`bet/win/win_lose=0`、`status=ROLLBACK`；回退已扣金額；先建 `PgsswCancelLog(status=0)` 再更新 BR，完成後標 `CancelLog status=1`。
- **eventBonus**：活動獎勵，`gameCode=0`，`bet=0`，`win=win_lose=amount`；TBR 帶 `['bonus' => $win]`；另寫 `PgsswEventRecord`。

## 冪等性

| 場景 | 處理 |
|---|---|
| placeBet / settle-without-payout / bonus / event-bonus 重複 `transactionNo` | `DUPLICATE_TRANSACTION (500029)` |
| settle / settle-change 找到 BR 但狀態非 BET | `INVALID_STATUS (403601)` |
| cancel 但 BR 已是 ROLLBACK | `INVALID_STATUS (403601)` |
| getBalance 但 account.status=0 | `INVALID_SIGNATURE (401002)` |

## settle 的 validAmount 陷阱

`settle` 中 `validAmount` 欄位**同時作為 `bet` 與 `valid_bet` 寫入 BR**（代碼註解 `by mars`）。這與一般「`betAmount` → bet、`validAmount` → valid_bet」的直覺不同，需特別注意對帳時有效投注額的計算來源。

## 整合落地建議

| 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/PegasusSW/Driver.php`（Tiger9 / PGSSW / BWG 共用） |
| 回調 Controller | `app/Http/Controllers/External/SingleWallets/PegasusController.php`（`$vendorId` 由 IDENTITY header 動態覆寫為 91） |
| 路由 | `routes/external/sw.php`，前綴 `target`（路由註解：「Tiger9 單錢包 API, 跟 target 同個 api、同個路由」） |
| `merchantID` | `vendor_infos.info['API_APPID']` |
| `merchantSecretKey` | `vendor_infos.info['API_KEY']`（主動呼叫時帶出，回調未驗） |
| `API_URL` | `vendor_infos.info['API_URL']` |
| 商戶識別（回調比對） | `vendor_infos.info['IDENTITY']` ↔ 回調 header `X-PROVIDER-IDENTITY` |
| 直播限紅 | `vendor_infos.info['LIVE_BET_LIMIT']`（可選，`getGameUrl` 帶 `liveCasinoLevelID`） |
| 帳號 Model | `App\Vendors\Models\PgsswAccount`（`username`、`session_token`、`status`） |
| 注單 Model | `App\Vendors\Models\PgsswBetRecord`（`bet_id`=`transactionNo`、`game_code`、`bet`/`win`/`win_lose`、`status`、`bet_time`/`payout_time`/`settlement_time`） |
| 取消紀錄 | `App\Vendors\Models\PgsswCancelLog` |
| 活動派彩紀錄 | `App\Vendors\Models\PgsswEventRecord` |
| BetRecord 狀態 | `BetRecordStatusEnum`：`BET=0` / `SETTLE=1` / `ROLLBACK=2` |
| 回應碼 | `OperatorStatusEnum`（見 api.md §B-4） |
| IP 白名單 | `Common::verifyIPWhitelistByVendorId(91)` |
| 對帳保留期 | **7 天**（Log API 限制） |
| session_token | `getGameUrl` 每次呼叫前以 `Str::uuid()` 重新產生並寫入 account |

## Gaps / 待 RD / 客服確認

- **協議選定**：正式環境是否走 GALAXY PDF 協議（`/balance/transfer` + 3DES）或 target 聚合協議（`/target/*` + IDENTITY header）？
- **官方 PDF 來源**：兩份 GALAXY PDF（V1.1.7 / V1.1.8）是否為 Tiger9 的有效規格，或為誤置的其他品牌文件？
- **3DES 簽章**：官方 PDF 要求，但實作未用，是否為已協議好的豁免？
- **getBonusCode freespin gameIDs**：Driver 中硬編碼的 freespin gameID 清單與 `rounds=10` 來源（target 後台活動 API，無對應 PDF 章節）。
- **對帳**：Log API 僅保留 7 天；若需超出 7 天的歷史資料需聯絡三方客服。
