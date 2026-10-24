---
type: Architecture
id: architecture/game-vendors
title: 遊戲商子系統
description: 轉帳錢包與無縫錢包兩種遊戲商各自繼承哪個基底類別、怎麼註冊在 config/vendor.php、無縫錢包回調的前置檢查怎麼分工，以及拉注單、注單彙總、帳號池、遊戲清單同步與退回未結算注單這幾個維運元件的觸發方式與先後關係。
domain: 遊戲與遊戲商
tags: [遊戲商, Vendor, 轉帳錢包, 無縫錢包, 單一錢包, Seamless Wallet, Transfer Wallet, config/vendor.php, Driver, 回調, 拉注單, 注單彙總, SBR, TBR, 帳號池, 遊戲清單同步, Olympus]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /config/vendor.php, title: "vendor id 對 Driver class 的註冊表", at: 2026-10-22 }
  - { kind: code, ref: /app/Vendors/AbstractTransferWallet.php }
  - { kind: code, ref: /app/Vendors/AbstractSingleWallet.php }
  - { kind: code, ref: /app/Vendors/BaseAbstractVendor.php, title: 會員從帳號池綁定玩家帳號 }
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/BaseController.php, title: 回調共通層、扣帳層與 marker interface 前置檢查 }
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/Contracts/ShouldVerifyIpWhitelist.php }
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/Contracts/ShouldResolveVendorAccount.php }
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/Contracts/ShouldVerifyRequestSignature.php }
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/MotivationSWController.php }
  - { kind: code, ref: /app/Http/Controllers/External/SingleWallets/SpadegamingSWController.php }
  - { kind: code, ref: /routes/console.php, title: 遊戲商維運命令的排程時間 }
  - { kind: code, ref: /app/Console/Commands/Vendor/FetchBetRecordCommand.php }
  - { kind: code, ref: /app/Console/Commands/SummaryBetRecord/DispatchSummaryBetRecordCommand.php }
  - { kind: code, ref: /app/Console/Commands/Schedule/UpdateVendorGameList.php }
  - { kind: code, ref: /app/Console/Commands/BetRecordRecovery/RefundUnsettled.php }
  - { kind: code, ref: /app/Vendors/PlayTechSW/GameRoundLock.php }
  - { kind: code, ref: /app/Filament/Admin/Pages/Operations/FetchBetRecord.php }
  - { kind: code, ref: /app/Filament/Admin/Pages/Operations/SummaryBetRecord.php }
  - { kind: code, ref: /app/Filament/Admin/Pages/Operations/CreateVendorAccount.php }
  - { kind: code, ref: /app/Filament/Admin/Pages/Operations/UpdateGameList.php }
  - { kind: code, ref: /app/Filament/Admin/Pages/Queries/VendorAccountPool.php }
---

# 遊戲商子系統

遊戲商整合放在 `app/Vendors/{Name}/Driver.php`，以 `VendorIdEnum::XXX->value` 為 key，註冊在 `config/vendor.php` 的 `driver` 陣列。依對接方式分成轉帳錢包與無縫錢包兩種。

## 轉帳錢包

- 基底類別：`AbstractTransferWallet`（`app/Vendors/AbstractTransferWallet.php`）。
- 核心方法：`transferToVendor`（轉入遊戲商）、`transferToLocal`（轉回我方錢包）。
- 回調入口：External 面向的 `routes/external/tw.php`（`prefix('tw')`），Controller 在 `app/Http/Controllers/External/TransferWallets/`。
- 參考實作：`app/Vendors/Pg/Driver.php`（`docs/codebase-reference.md` 列為範例）。

## 無縫錢包

- 基底類別：`AbstractSingleWallet`（`app/Vendors/AbstractSingleWallet.php`）。
- 核心：`getGameUrl` 取遊戲入口網址，以及回調扣款。投注與派彩由遊戲商的回調觸發，不是我方主動轉帳。
- 必須支援 multi-bet：同一個 `roundId` 可能有多筆 `reference`，以 DealRecord 確保同一筆只處理一次（冪等）。
- 回調入口：External 面向的 `routes/external/sw.php`（`prefix('sw')`），Controller 在 `app/Http/Controllers/External/SingleWallets/`。
- 參考實作：`app/Vendors/EpicWinSW/Driver.php`（`docs/codebase-reference.md` 列為範例）。

### BaseController 的兩層

`app/Http/Controllers/External/SingleWallets/` 底下的回調 Controller，除了 `TFGamingSWController`（直接繼承 `Controller`）以外，都直接或間接繼承 `BaseController.php`。間接繼承的有三支：`BWGController` 繼承 `PegasusController`，`ClotPlayLsController`、`ClotPlayP2PController` 繼承 `ClotPlayController`，這兩支父類再繼承 `BaseController`。

- 第一層是回調共通層：`$account`、`$member`、`$vendorInfo`、`setUpVendorAccount()`、`getVendorInfo()`、`logRequest()`、`verifyBettability()`。轉帳錢包的 `TransferWallets\NextSpinController` 與繼承它的 `SpadeGamingController` 也在用這一層，搬動或收窄這一層要連它們一起改。
- 第二層是無縫錢包專屬的扣帳層，由 `changeBalance()` 領頭。

細節（必要屬性、核心方法慣例表）見 `docs/codebase-reference.md`「單一錢包 Controller 架構」一節。

### 回調前置檢查的兩種做法

回調進 action 之前的檢查（IP 白名單、帳號與遊戲商戶解析、驗簽）目前有兩種做法並存，逐家遷移中。

| 做法 | 哪幾家 | 怎麼運作 |
|---|---|---|
| 共用前置檢查 | MotivationSW、SpadegamingSW | 子類實作 `Contracts\` 底下的 marker interface（只用來標記、本身沒有方法的介面），由 `BaseController::callAction()` 依序跑對應的 trait。子類不覆寫 `callAction`。 |
| 各家自己檢查 | 其餘各家 | 在自己覆寫的 `callAction()` 裡檢查，或在各個 action 內各自檢查。IP 白名單多半呼叫 `Common::verifyIPWhitelistByVendorId()`。擋下的情況與錯誤碼各家不同，寫在各家的 Rule 文件。 |

兩家實作的介面（2026-10-22 以 `grep -l` 對照 `Contracts\` 每個介面名的結果）：

| 介面 | 做什麼 | MotivationSW | SpadegamingSW |
|---|---|---|---|
| `ShouldVerifyIpWhitelist` | 來源 IP 白名單 | 有 | 有 |
| `ShouldResolveVendorAccount` | 解析玩家帳號、會員、遊戲商戶，並驗遊戲商戶歸屬 | 有 | 有 |
| `AllowsUnboundAccount` | 允許尚未綁定會員的池帳號通過帳號解析 | 有 | 無 |
| `ShouldVerifyRequestSignature` | 驗簽 | 有 | 有 |
| `RendersCallbackErrors` | 把前置檢查與 action 內的例外翻成該家的錯誤回應 | 有 | 有 |
| `ShouldDecodeCallbackPayload` | 驗簽前先解開加密的回調內容 | 無 | 無 |

- `BaseController::callAction()` 的執行順序：IP 白名單、解開回調內容、解析帳號與遊戲商戶、驗簽。沒實作任何 marker interface 的子類，走到這裡等於直接進 action。
- 實作 marker interface 的同時，子類 `callAction` 裡同一項檢查要刪掉，否則會重複檢查。
- 請求 log 由 `RequestLogger` middleware 記，不在前置檢查內。
- `ShouldVerifyGameBettability` 是另一個介面，標記這支 Controller 在扣款類回調要驗投注資格。它不屬於上面的前置檢查，實作它的 Controller 不限上述兩家。

共用前置檢查擋下哪些情況、各家回什麼錯誤碼，見 [rules/single-wallet-callback-common-checks](../rules/single-wallet-callback-common-checks.md)。

## 聚合商底下的遊戲商

`config/vendor.php` 用 `array_fill_keys()`，把聚合商的多個子遊戲商（整數 vendor id）合併指向同一個 Driver，例如 target、DCT、PGSSW、Titan 與 Evo、TOP55SW、TTHSW（EliteHub）、GMSW。

- 整數 key 必須用 union（`+`）合併，不可用 array spread（`...`），否則 key 會被重新編號。
- 接入通道與回調路徑預設由 Driver 自己宣告，不另存對照表，見 [decisions/0003](../decisions/0003-vendor-channel-and-callback-declared-by-driver.md)。
- 新增遊戲商由 RD 用 `php artisan make:vendor` 產骨架、在程式裡登記，部署後跑 `php artisan app:upgrade` 把遊戲商主檔補進資料庫，見 [rules/vendor-and-channel-management](../rules/vendor-and-channel-management.md)。

## 遊戲商資料流的維運元件

本節只寫各元件由誰觸發、讀寫什麼、彼此的先後關係。規則細節以各 Rule 文件為準。

### 注單從哪裡進來

- 無縫錢包：遊戲商的回調即時寫入該家的 BR（遊戲商回報進來、未經轉換的注單）並轉成 TBR（各家注單轉成同一組欄位之後的注單）。
- 轉帳錢包：靠我方主動拉注單寫入 BR 與 TBR。
- 無縫錢包遊戲商也可以拉注單，但只用來比對三方與我方的差異，不寫入。

### 各元件的觸發方式

| 元件 | 排程 | 命令列 | Olympus 頁 | 讀 | 寫 | Rule 文件 |
|---|---|---|---|---|---|---|
| 拉取注單 | `php artisan fetch_bet_record` 每 5 分鐘 | `php artisan fetch_bet_record`（`--direct` 同步跑、`--dry-run` 乾跑） | 「系統操作」的「拉取注單」 | 遊戲商的注單查詢 API | BR、TBR | [rules/bet-record-pull](../rules/bet-record-pull.md) |
| 注單彙總（SBR） | `php artisan summary_bet_record:dispatch` 每小時 30 分；`php artisan summary_bet_record:rerun-full-day` 每天 UTC 00:25；`php artisan summary_bet_record:audit` 每天 UTC 02:40 | `php artisan summary_bet_record` | 「系統操作」的「重跑注單彙總」 | TBR | SBR（每人每小時每款遊戲的結算數字）、`queue_job_runs` | [rules/summary-bet-record](../rules/summary-bet-record.md) |
| 帳號池 | `php artisan create_vendor_account` 每 5 分鐘補建；`php artisan vendor:monitor-account-pool` 每小時監測 | `php artisan create_vendor_account` | 「系統操作」的「補建遊戲帳號」；「查詢」的「帳號庫存查詢」 | 遊戲商戶的預備帳號數量 | 各家的玩家帳號表 | [rules/vendor-account-pool](../rules/vendor-account-pool.md) |
| 遊戲清單同步 | `php artisan update_game_list` 每小時 12 分 | `php artisan update_game_list --vendor-id=<id> --direct` | 「系統操作」的「更新遊戲清單」 | 遊戲商的遊戲清單 API（PlayTech 改讀官方活頁簿） | `games`、`vendor_info_games`、`agent_games`、`game_names` | [rules/game-list-sync](../rules/game-list-sync.md) |
| 退回未結算注單 | 無 | `php artisan bet-record-recovery:refund-unsettled`、`php artisan bet-record-recovery:zero-refunded-turnover` | 無 | 指定的注單 | 錢包、注單、`bet_record_recovery_logs` | [rules/single-wallet-unsettled-refund](../rules/single-wallet-unsettled-refund.md) |

### 先後關係

- **拉注單與注單彙總之間沒有直接串接**：拉注單寫 TBR，注單彙總由自己的整點排程讀 TBR。整點彙總回看最近 6 個小時桶，所以拉注單遲到補進的注單，只要還在回看範圍內，會在之後的整點被算進去；超出範圍的，靠隔日整天重跑或人工補跑。
- **人工補拉之後要接著人工補跑彙總**：Olympus「拉取注單」頁的「接續注單彙總」按鈕會帶著時區、日期、小時跳到「重跑注單彙總」頁。
- **帳號池要先有可綁定帳號，會員才進得了遊戲**：會員第一次在某個遊戲商戶需要玩家帳號時，`BaseAbstractVendor::getVendorAccount()` 從帳號池綁定一個。池空了會員就拿不到玩家帳號。無縫錢包回調遇到尚未綁定會員的池帳號，預設擋下；實作 `AllowsUnboundAccount` 的遊戲商放行部分回調。
- **遊戲清單同步只新增遊戲**：新遊戲寫進主檔並建好遊戲商戶與代理的關聯後，由客服在後台開放；遊戲商下架的遊戲不會自動關閉。
- **退回未結算注單是無縫錢包回調的補救**：處理遊戲商判定投注失敗、不會再送派彩的已扣款注單。PTSW 的退回與 PlayTech 回調共用同一把同局鎖（`app/Vendors/PlayTechSW/GameRoundLock.php`），避免同一局被退兩次。

## 目前 app/Vendors 目錄清單

AFBSW、Ace333、Ace333SW、AcewinSW、Aes、Ag、AiGamingSW、Astar、Baison、BeSoftSW、Bola2、Bolakk4d、BoleSW、BoracayFish、CRSW、ClotPlayLegendSlotSW、ClotPlayP2PSW、ClotPlaySW、Connect93、CosmoPlaySW、Ct、Dct、Dg、DstPlaySW、E88Sport、Ebet、EpicWinSW、Evo、FFFSW、FumaSW、GMSW、Gfg2、GreatWall、Haolong、I1betnow、Ia、ImperiumSW、J3、Joker、JokerBaccaratSW、KissH5SW、Lfc888、Live22、M8bet、Mega888、MegaH5SW、MegawinSW、MetaGamingSW、MotivationSW、Mt、N918、New4d2026、Nex4DSW、NextSpin、Npt、Obet33、OnebxSW、Oppo、PPPOP、PPSW、PegasusSW、Pg、target、Play8oy、PlayTechSW、Plus、Pp、Pussy888、PxplaySW、Qqpk、RGSW、Rcb988、RectangleSW、RedMario、Sa、Saba、Scr、SexySlotCq9SW、SexySlotSW、Sky1388、Slot4DSW、Sma、SpadeGaming、SpadegamingSW、Suncity、TFGamingSW、TTHSW、Top55、UUSlotSW、V8、VplusSW、Vpower、VpowerSW、Vsport、WESW、WFSW、Wbet、Wcasino、Wm、WmliveSW、WudiSW、Ybet。

`app/Vendors/` 底下另有 `Contracts/`、`Models/` 兩個共用目錄，以及 `AbstractSingleWallet.php`、`AbstractTransferWallet.php`、`BaseAbstractVendor.php` 三個基底類別，不是遊戲商。

## 邊界

- **進口**：
  - External 面向的 `routes/external/sw.php`（無縫錢包回調）與 `routes/external/tw.php`（轉帳錢包）。
  - 內部由 `app/Services/` 業務層呼叫 Driver 做轉帳（轉帳錢包）與取遊戲網址。Driver 帶著會員初始化時，由 `BaseAbstractVendor` 取得或從帳號池綁定該會員的玩家帳號。
  - 排程與 artisan 命令：拉注單、注單彙總、帳號池補建與監測、遊戲清單同步、退回未結算注單。
  - Olympus「系統操作」與「查詢」底下的維運頁。
  - 代理後台「廠商管理」頁讀遊戲商主檔與 Driver 宣告的接入通道、回調路徑。
- **出口**：
  - Driver 呼叫遊戲商的 API：轉帳、建號、取遊戲網址、拉注單、拉遊戲清單。
  - 寫入 `app/Vendors/Models/` 下的 BR 表、TBR、SBR，以及玩家帳號表與遊戲主檔。輸贏正負號的方向見 [decisions/0001](../decisions/0001-win-lose-uses-player-perspective.md)。
  - 帳號池補不進去、注單彙總失敗或缺口時，發 Telegram 告警。

## 相關文件

- 決策：
  - [decisions/0001-win-lose-uses-player-perspective](../decisions/0001-win-lose-uses-player-perspective.md)
  - [decisions/0003-vendor-channel-and-callback-declared-by-driver](../decisions/0003-vendor-channel-and-callback-declared-by-driver.md)
- 架構：[architecture/api-facets](api-facets.md)
- 共用規則：
  - [rules/single-wallet-callback-common-checks](../rules/single-wallet-callback-common-checks.md)
  - [rules/bet-record-pull](../rules/bet-record-pull.md)
  - [rules/summary-bet-record](../rules/summary-bet-record.md)
  - [rules/vendor-account-pool](../rules/vendor-account-pool.md)
  - [rules/game-list-sync](../rules/game-list-sync.md)
  - [rules/single-wallet-unsettled-refund](../rules/single-wallet-unsettled-refund.md)
  - [rules/vendor-and-channel-management](../rules/vendor-and-channel-management.md)
  - [rules/report-sr12-payout](../rules/report-sr12-payout.md)
- 各家遊戲商的規則：
  - [rules/vendor-acewin](../rules/vendor-acewin.md)
  - [rules/vendor-afbsw](../rules/vendor-afbsw.md)
  - [rules/vendor-aigamingsw](../rules/vendor-aigamingsw.md)
  - [rules/vendor-astar](../rules/vendor-astar.md)
  - [rules/vendor-epicwin](../rules/vendor-epicwin.md)
  - [rules/vendor-fumasw](../rules/vendor-fumasw.md)
  - [rules/vendor-motivationsw](../rules/vendor-motivationsw.md)
  - [rules/vendor-onebxsw](../rules/vendor-onebxsw.md)
  - [rules/vendor-target](../rules/vendor-target.md)
  - [rules/vendor-ptsw](../rules/vendor-ptsw.md)
  - [rules/vendor-rcb988](../rules/vendor-rcb988.md)
  - [rules/vendor-rgsw](../rules/vendor-rgsw.md)
  - [rules/vendor-sky1388](../rules/vendor-sky1388.md)
  - [rules/vendor-spadegamingsw](../rules/vendor-spadegamingsw.md)
  - [rules/vendor-top55](../rules/vendor-top55.md)
  - [rules/vendor-tthsw](../rules/vendor-tthsw.md)
  - [rules/vendor-uuslotsw](../rules/vendor-uuslotsw.md)
  - [rules/vendor-wf](../rules/vendor-wf.md)
  - [rules/vendor-wudisw](../rules/vendor-wudisw.md)
- 入口骨架：
  - 無縫錢包回調：`apis/external/` 底下各家 Controller，例如 [apis/external/MotivationSWController](../apis/external/MotivationSWController.md)、[apis/external/SpadegamingSWController](../apis/external/SpadegamingSWController.md)、[apis/external/TTHSWController](../apis/external/TTHSWController.md)
  - 維運命令：[apis/console/fetch_bet_record](../apis/console/fetch_bet_record.md)、[apis/console/summary_bet_record](../apis/console/summary_bet_record.md)、[apis/console/summary_bet_record-dispatch](../apis/console/summary_bet_record-dispatch.md)、[apis/console/summary_bet_record-audit](../apis/console/summary_bet_record-audit.md)、[apis/console/summary_bet_record-rerun-full-day](../apis/console/summary_bet_record-rerun-full-day.md)、[apis/console/create_vendor_account](../apis/console/create_vendor_account.md)、[apis/console/vendor-monitor-account-pool](../apis/console/vendor-monitor-account-pool.md)、[apis/console/update_game_list](../apis/console/update_game_list.md)、[apis/console/bet-record-recovery-refund-unsettled](../apis/console/bet-record-recovery-refund-unsettled.md)、[apis/console/bet-record-recovery-zero-refunded-turnover](../apis/console/bet-record-recovery-zero-refunded-turnover.md)、[apis/console/make-vendor](../apis/console/make-vendor.md)、[apis/console/app-upgrade](../apis/console/app-upgrade.md)
  - 代理後台：[apis/agent/VendorManagementController](../apis/agent/VendorManagementController.md)、[apis/agent/ReportController](../apis/agent/ReportController.md)
- 資料表：[data-model/total_bet_records](../data-model/total_bet_records.md)、[data-model/summary_bet_records](../data-model/summary_bet_records.md)、[data-model/vendor_infos](../data-model/vendor_infos.md)、[data-model/vendors](../data-model/vendors.md)、[data-model/games](../data-model/games.md)
