---
type: Rule
id: rules/bet-record-pull
title: 拉取遊戲商注單
description: 我方主動向遊戲商拉注單時，排程、命令列與 Olympus「拉取注單」頁各怎麼觸發，注單與 TBR 怎麼寫入，乾跑比對什麼，以及 Oppo、Wbet、ClotPlaySW、PP 各自的特殊規則。
domain: 遊戲與遊戲商
subdomain: 維護
tags: [拉注單, 拉取注單, 補拉, 乾跑, 對帳, fetch_bet_record, 注單, TBR, 遊戲商, Olympus, ACE333, SunCity, Oppo, Wbet, ClotPlaySW, PP, bet record, fetch bet record, dry run, reconciliation]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:30+08:00" }
evidence:
  - { kind: code, ref: /app/Services/BetRecordFetchService.php, title: 拉注單服務, at: 2026-10-22 }
  - { kind: code, ref: /app/Console/Commands/Vendor/FetchBetRecordCommand.php, title: fetch_bet_record 命令 }
  - { kind: code, ref: /app/Jobs/FetchVendorBetRecord.php, title: 拉注單背景任務 }
  - { kind: code, ref: /app/Filament/Admin/Pages/Operations/FetchBetRecord.php, title: Olympus 拉取注單頁 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2106, title: Oppo 遷入舊系統拉注單邏輯 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2119, title: Oppo 退出自動排程 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2100, title: ClotPlaySW 拉注單對帳與乾跑反向對帳 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2191, title: Wbet 新增拉單能力 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2297, title: 拉注單逐片印進度 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2310, title: 拉注單頁日期下限放寬到 60 天 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2328, title: 回溯 30 天、4 天以上先確認 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2360, title: 進度排除流量限制重拉 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2363, title: 運維 30 天、管理員 60 天 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2397, title: 拉注單接上後台中斷 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2454, title: PP 真人取消局不計投注 }
  - { kind: ticket, ref: http://example.invalid:1688/target/tickets/-/work_items/4281, title: Oppo 拉注單遷入 }
  - { kind: ticket, ref: http://example.invalid:1688/target/tickets/-/work_items/4279, title: ClotPlaySW 拉注單對帳 }
  - { kind: ticket, ref: http://example.invalid:1688/target/tickets/-/work_items/1310, title: Wbet 拉單 }
  - { kind: ticket, ref: http://example.invalid:1688/target/tickets/-/work_items/2887, title: 拉取注單限制回溯 30 天 }
  - { kind: ticket, ref: http://example.invalid:1688/target/tickets/-/work_items/4472, title: 管理員回溯 60 天 }
  - { kind: ticket, ref: http://example.invalid:1688/target/ticket_tasks/-/work_items/1853, title: PP 真人取消局 }
entities:
  - { command: fetch_bet_record }
  - { api: "GET olympus/fetch-bet-record" }
  - { class: "App\\Console\\Commands\\Vendor\\FetchBetRecordCommand" }
  - { class: "App\\Services\\BetRecordFetchService" }
  - { class: "App\\Jobs\\FetchVendorBetRecord" }
  - { class: "App\\Filament\\Admin\\Pages\\Operations\\FetchBetRecord" }
  - { class: "App\\Vendors\\Contracts\\SupportsBetRecordFetch" }
  - { class: "App\\Vendors\\Contracts\\ShouldScheduleBetRecordFetch" }
  - { class: "App\\Vendors\\Oppo\\Driver" }
  - { class: "App\\Vendors\\Wbet\\Driver" }
  - { class: "App\\Vendors\\ClotPlaySW\\Driver" }
  - { class: "App\\Vendors\\Pp\\Driver" }
  - { table: total_bet_records }
---

## 這個主題在講什麼

拉注單是我方主動呼叫遊戲商的查詢 API，把一段時間內的注單拉回來，寫進該遊戲商自己的注單表，再轉成 TBR（各家注單轉成同一組欄位之後的注單）。轉帳錢包遊戲商靠它入帳；無縫錢包遊戲商的注單已經由回調即時記帳，拉注單只拿來比對三方與我方的差異，不寫入。

同一套流程有三種跑法：

| 跑法 | 怎麼啟動 | 做什麼 |
|---|---|---|
| 排程 | 系統排程，或命令列不帶模式參數 | 逐家遊戲商派發背景任務，每個任務只跑一個時間片 |
| 同步 | 命令列帶 `--direct`，或 Olympus 頁執行 | 在同一個程序裡逐片跑完整個窗口，跑完才回結果 |
| 乾跑 | 命令列帶 `--dry-run`，或 Olympus 頁開「乾跑模式」 | 跟同步一樣逐片跑，但只比對、不寫入，列出差異 |

本份目前收錄：共用的拉取流程（範圍、切片、流量、寫入、乾跑、進度輸出）、Olympus「拉取注單」頁，以及 Oppo、Wbet、ClotPlaySW、PP 四家的特殊規則。其他遊戲商（ACE333、SunCity、Evo 等）各自怎麼解析注單，本份還沒收錄。拉完之後重跑注單彙總不在本份範圍。

## 怎麼觸發

- 排程：每 5 分鐘自動執行一次 `fetch_bet_record`。
- 命令列：`php artisan fetch_bet_record`，參數見 `--help`。常用的是 `--vendor`（遊戲商 ID）、`--date` 或 `--start`／`--end`（時間，UTC）、`--direct`、`--dry-run`、`--update`、`--use-cache`。
- Olympus：「系統操作」底下的「拉取注單」頁，選遊戲商、時區、日期、小時後按「執行」。

## 規則

### 觸發與範圍

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 排程每 5 分鐘執行一次，沒指定時間時拉取前 1 小時。排程只處理加入排程的遊戲商，目前是 ACE333 與 SunCity。 | command:fetch_bet_record、code:BetRecordFetchService::applySchedulableVendorScope |
| R-2 | 排程跑法不直接呼叫三方，而是逐家遊戲商派發背景任務，由背景任務去拉。 | code:BetRecordFetchService::dispatchQueueFetch、test:FetchBetRecordCommandTest::test_default_mode_dispatches_queue_job |
| R-3 | 有拉單能力、但沒加入排程的遊戲商，只能指定遊戲商手動拉：命令列帶遊戲商 ID，或從 Olympus「拉取注單」頁執行。Oppo、Wbet、PP、ClotPlaySW 屬於這一類。 | code:BetRecordFetchService::applySchedulableVendorScope、test:Oppo\DriverTest::test_driver_supports_bet_record_fetch_without_schedule、test:Wbet\DriverTest::test_driver_supports_bet_record_fetch_without_schedule、mr:!2119、mr:!2191 |
| R-4 | 遊戲商停用、列在停用遊戲商清單，或底下沒有任何啟用中的遊戲商戶時，不拉取。 | code:AbstractVendorBatchService::enabledVendorsQuery、code:BetRecordFetchService::fetchChunk |
| R-5 | 遊戲商維護中照常拉取。維護只擋玩家下注，已結算的注單仍要拉回來。 | code:BetRecordFetchService::excludesMaintenanceVendors、test:FetchBetRecordCommandTest::test_vendor_under_maintenance_is_still_fetched |
| R-6 | 命令列指定遊戲商戶時必須同時指定遊戲商，否則回「--vendor-info 需搭配 --vendor 使用」並以失敗結束。指定之後只拉那一個遊戲商戶。 | code:FetchBetRecordCommand::handle、test:FetchBetRecordCommandTest::test_vendor_info_option_requires_vendor |

### 時間片、呼叫預算與可拉取界線

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-7 | 窗口依該遊戲商單次查詢的上限切成時間片，一片一片拉。例如 ACE333 與 Oppo 一片 1 小時，ClotPlaySW 30 分鐘，PP 10 分鐘。背景任務每次只跑一片，窗口還有剩下時派發接續任務。 | code:BetRecordFetchService::fetchChunk、code:FetchVendorBetRecord::dispatchRemainingIfNeeded、test:Ace333\FetchBetRecordTest::test_fetch_processes_single_chunk_and_reports_next_chunk_start |
| R-8 | 共用同一組三方憑證的遊戲商戶，同一片只呼叫三方一次。 | code:BetRecordFetchService::fetchChunk、test:FetchBetRecordCommandTest::test_vendor_infos_sharing_credentials_are_fetched_once |
| R-9 | 每家遊戲商有自己的呼叫預算，旗下所有遊戲商戶共用。預算用完時，該片整片重拉：背景任務延後到預算釋放（至少等 60 秒）再派發同一片；同步與乾跑原地等待，預算釋放後重拉同一片。重拉的注單以唯一鍵去重，不會重複寫入。 | code:BetRecordFetchService::fetchChunk、code:BetRecordFetchService::fetchVendorWindow、code:FetchVendorBetRecord::dispatchRemainingIfNeeded |
| R-10 | 同一家遊戲商同一時間只會有一個背景任務在跑，搶不到的任務 30 秒後再試。 | code:FetchVendorBetRecord::middleware |
| R-11 | 遊戲商有設定可拉取的最早時間時：窗口整段早於它，就不呼叫三方、不做任何事；窗口跨過它，起點改成該時間。例如 ACE333 是台灣時間 2026-08-01 00:00，Wbet 是 150 天內，ClotPlaySW 是 60 天內。 | code:BetRecordFetchService::fetchChunk、test:Ace333\FetchBetRecordTest::test_fetch_skips_window_entirely_before_min_start_time、test:Ace333\FetchBetRecordTest::test_fetch_clamps_start_time_to_min_start_time |

### 寫入注單與 TBR

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-12 | 預設只新增：以注單唯一鍵去重，已存在的注單不覆寫，三方事後改了金額也一樣。 | code:BetRecordFetchService::persistBetRecords、test:FetchBetRecordCommandTest::test_rerun_deduplicates_and_keeps_original_row |
| R-13 | 開啟「更新既有列」（`--update`）時，金額有異動的既有注單會被更新，並重新轉入 TBR；金額沒變的不動。注單轉成取消、未結算轉成已結算，都要靠這個模式重拉才會反映。 | code:BetRecordFetchService::updateChangedRecords、test:Pp\DriverTest::test_fetch_chunk_with_update_zeroes_cancelled_rounds |
| R-14 | 新增注單與轉入 TBR 在同一個交易內完成，每 500 筆一批。一批中途失敗就整批回滾，不會留下有注單、沒有 TBR 的情況。 | code:BetRecordFetchService::insertNewRecords |
| R-15 | 轉入 TBR 時，用注單上的玩家帳號找會員。找不到玩家帳號、或玩家帳號沒有綁定會員的注單，只留在遊戲商注單表，不轉入 TBR。 | code:BetRecordFetchService::transferToTotalBetRecords、test:FetchBetRecordCommandTest::test_unbound_account_is_kept_out_but_zero_type_is_kept |
| R-16 | 轉入 TBR 時：遊戲對不到就記為 0；有效投注比例先看該遊戲商戶對這款遊戲的設定，沒有再看遊戲本身，再沒有就看遊戲商；統計時間取派彩時間所在的整點。 | code:BetRecordFetchService::transferToTotalBetRecords、test:FetchBetRecordCommandTest::test_bet_record_and_total_bet_record_are_written、test:FetchBetRecordCommandTest::test_vendor_info_game_rate_wins_over_game_rate、test:FetchBetRecordCommandTest::test_unknown_game_code_falls_back_to_vendor_rate_and_zero_game_id |
| R-17 | TBR 以「遊戲商＋注單」為唯一，重新轉入時覆寫金額，不會多出第二列。 | code:BetRecordFetchService::transferToTotalBetRecords |
| R-18 | 三方拉取失敗（連線錯誤、三方回錯誤、我方設定缺漏）一律中止，不當作該時段沒有注單。同步與乾跑時，該遊戲商記一筆錯誤後繼續下一家，命令以失敗結束，Olympus 操作紀錄因此標為失敗；背景任務記為失敗任務，由之後的排程重新涵蓋。 | code:BetRecordFetchService::fetchErrorCallback、code:FetchVendorBetRecord::handle、test:FetchBetRecordCommandTest::test_third_party_failure_reports_error_and_exits_failure、test:Oppo\DriverTest::test_fetch_throws_when_third_party_returns_error、test:ClotPlaySW\DriverTest::test_fetch_throws_when_response_has_no_data、test:Pp\DriverTest::test_fetch_throws_when_bet_url_is_missing |
| R-19 | 該站沒有建這家遊戲商的主檔時，背景任務略過並記為成功，不產生失敗任務。 | code:FetchVendorBetRecord::handle |

### 乾跑

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-20 | 乾跑（`--dry-run`）照常呼叫三方並與資料庫比對，但不寫入注單與 TBR。結果列出將新增的注單；搭配「更新既有列」時，另列金額有異動的既有注單與各欄位的前後值。每一類最多列 500 筆。 | code:BetRecordFetchService::previewFetch、test:FetchBetRecordCommandTest::test_dry_run_reports_but_persists_nothing、test:FetchBetRecordCommandTest::test_dry_run_lists_new_records_and_amount_diffs |
| R-21 | 乾跑另外列出「三方查無」：派彩時間落在窗口內、但整個窗口三方都沒回的既有注單。這份清單只是提示，不影響寫入。以下情況不列：窗口沒跑完（被中斷）、有指定遊戲商戶、該遊戲商的注單沒有派彩時間欄位（例如 ACE333）。輸出附一句提示：窗口邊界前後各放寬一小時重跑可排除邊界誤報。 | code:BetRecordFetchService::collectMissingFromThirdParty、test:ClotPlaySW\DriverTest::test_dry_run_lists_records_the_third_party_did_not_return、mr:!2100 |
| R-22 | 無縫錢包遊戲商只能乾跑。同步或背景任務要寫入時直接拒絕，錯誤訊息是「單一錢包遊戲商（vendor_id: N）拉注單只支援乾跑模式（--dry-run）」。原因是這類注單已由回調即時記帳，再寫一次會產生沒有錢包異動的注單。 | code:BetRecordFetchService::fetchChunk、mr:!2100 |
| R-23 | 開啟三方回應快取（`--use-cache`）時，三方回應依「遊戲商、憑證、時間片」保存 1 小時。命中快取就不呼叫三方、不佔呼叫預算；拉取失敗的回應不保存。用途是乾跑看完差異後，一小時內接著正式跑不必再打三方。 | code:BetRecordFetchService::fetchChunk、test:FetchBetRecordCommandTest::test_use_cache_reuses_dry_run_response_for_real_run |

### 執行進度與結果

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-24 | 同步與乾跑每跑完一片印一行進度，格式是「[第幾片/總片數] 遊戲商 片起點 三方 N 筆，新增 N、更新 N」。撞到呼叫預算的片改印「觸發三方流量限制，等待 N 秒後重拉同一片」，不佔片號，三方筆數也不重複累計；新增與更新筆數照常累計。背景任務不印逐片進度。 | code:FetchBetRecordCommand::chunkProgressReporter、code:BetRecordFetchService::fetchVendorWindow、test:FetchBetRecordCommandTest::test_direct_mode_prints_chunk_progress、mr:!2297、mr:!2360 |
| R-25 | 每家遊戲商跑完印一行總結：三方回傳、新增、更新的筆數，乾跑時另印三方查無的筆數，以及共幾個時間片。被中斷而沒跑完時，另印「已依「中斷」要求停止，剩餘窗口未處理（自 X 起）」，X 是續跑起點。 | code:FetchBetRecordCommand::renderVendorResults、mr:!2397 |

### Olympus「拉取注單」頁

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-26 | 頁面在 Olympus「系統操作」底下的「拉取注單」頁，運維、管理員、超級管理員都能進。遊戲商清單列出所有有拉單能力的遊戲商，包含沒加入排程的。 | code:FetchBetRecord::formComponents、code:UserRoleEnum::canAccessPage、mr:!2191 |
| R-27 | 選遊戲商時自動帶入時區：該遊戲商有注單彙總設定的，帶它的對帳時區；沒有的，帶遊戲商的計算時區。時區可以自己改。換遊戲商時，已選的遊戲商戶會清空。 | code:FetchBetRecord::formComponents、test:FetchBetRecordPageTest::test_choosing_a_vendor_prefills_its_reconcile_timezone、test:FetchBetRecordPageTest::test_vendor_outside_summary_config_prefills_its_calc_time_zone |
| R-28 | 日期與小時依所選時區換算成 UTC 後送出；小時留空就是整天。 | code:FetchBetRecord::buildParams、test:FetchBetRecordPageTest::test_execute_with_hour_converts_taipei_to_utc、test:FetchBetRecordPageTest::test_utc_timezone_passes_hour_through、test:FetchBetRecordPageTest::test_empty_hour_pulls_whole_day_in_selected_timezone |
| R-29 | 頁面一律用同步跑法跑完整個窗口，不派背景任務，執行輸出回寫到操作紀錄。開「乾跑模式」改用乾跑；開「更新既有列」就更新既有列；在「商戶」欄選了遊戲商戶就只拉那一個；「使用三方回應快取」預設開啟。 | code:FetchBetRecord::buildParams、test:FetchBetRecordPageTest::test_execute_with_hour_converts_taipei_to_utc、test:FetchBetRecordPageTest::test_dry_run_toggle_replaces_direct_with_dry_run、test:FetchBetRecordPageTest::test_update_toggle_passes_update_flag、test:FetchBetRecordPageTest::test_vendor_info_passes_flag、test:FetchBetRecordPageTest::test_use_cache_toggle_off_omits_the_flag |
| R-30 | 日期可回溯的天數：運維 30 天，管理員與超級管理員 60 天；不能選未來的日期。超出範圍的日期在月曆上選不到，送出也會被擋下。 | code:AbstractOperationPage::maxDaysAgo、test:FetchBetRecordPageTest::test_ops_date_older_than_30_days_is_rejected、test:FetchBetRecordPageTest::test_admin_can_submit_date_within_60_days、test:FetchBetRecordPageTest::test_admin_date_older_than_60_days_is_rejected、mr:!2328、mr:!2363 |
| R-31 | 選的日期是 4 天前（含）以上時，按「執行」會先跳出「確認重跑舊日期」視窗，寫明是幾天前，按「確定執行」才執行；3 天內按下去直接執行。 | code:AbstractOperationPage::executeAction、mr:!2328 |
| R-32 | 選到無縫錢包遊戲商時，「乾跑模式」自動打開並鎖住。送出時再檢查一次，即使開關被繞過也只會乾跑。 | code:FetchBetRecord::buildParams、test:FetchBetRecordPageTest::test_single_wallet_vendor_is_forced_to_dry_run、mr:!2100 |
| R-33 | 同一時間只能有一筆未完成的拉取注單，不分遊戲商。已有一筆一小時內建立、還沒結束的執行時，再按「執行」會提示「相同作業執行中」，不會再派一筆。 | code:AbstractOperationPage::execute、code:AdminOperationService::dispatch |
| R-34 | 執行中按「中斷」：拉單停在片與片之間，已完成的片保留；正在等呼叫預算釋放時，每 5 秒檢查一次中斷要求。停下後輸出續跑起點（見 R-25）。 | code:BetRecordFetchService::fetchVendorWindow、code:BetRecordFetchService::sleepUnlessCancelled、code:FetchBetRecord::supportsCancelSignal、mr:!2397 |
| R-35 | 「接續注單彙總」按鈕帶著時區、日期、小時跳到「重跑注單彙總」頁；遊戲商也在注單彙總設定內時，一併帶入遊戲商。 | code:FetchBetRecord::summaryPrefill、test:FetchBetRecordPageTest::test_continue_action_carries_time_params_to_summary_page |

### Oppo

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-36 | Oppo 沒有一次查整批注單的 API：先查時段內有投注的玩家（重複的只算一次），再逐一查每位玩家的注單，兩支都會翻完所有分頁。一片 1 小時，查詢時間換算成 GMT+8 送出。 | code:Oppo\Driver::fetchBetRecords、test:Oppo\DriverTest::test_fetch_queries_players_first_then_each_player_bet_log、test:Oppo\DriverTest::test_fetch_follows_pagination、mr:!2106 |
| R-37 | 只有已結算的 Oppo 注單轉入 TBR。未結算的留在 Oppo 注單表，結算後要用「更新既有列」重拉才會補轉。注單以三方的玩家識別碼對回會員。 | code:Oppo\Driver::formatBaseTotalBetRecord、test:Oppo\DriverTest::test_unsettled_record_is_not_transferred_to_total_bet_record、mr:!2106 |

### Wbet

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-38 | Wbet 不能依時間查詢：每次取回最舊、還沒標記為已拉取的注單，所選的窗口不影響拉到哪些注單。一片設為一天，最早只能拉 150 天內。 | code:Wbet\Driver::fetchBetRecords、test:Wbet\DriverTest::test_driver_supports_bet_record_fetch_without_schedule、mr:!2191 |
| R-39 | 一次拉取依序查一般投注、串關投注、結算結果、活動獎金結果，再把拉到的標記為已拉取。每次呼叫之間固定間隔 30 秒。 | code:Wbet\Driver::fetchBetRecords、code:Wbet\Driver::postReportApi、mr:!2191 |
| R-40 | 一般投注與串關投注只保留狀態 Accepted 的注單。標記時排除 Pending，Accepted、Rejected、Cancel 都會標記。結算結果與活動獎金不論狀態全部標記。沒有東西要標記時不呼叫標記。 | code:Wbet\Driver::fetchBetRecords、test:Wbet\DriverTest::test_fetch_only_returns_accepted_bets_and_marks_all_but_pending、test:Wbet\DriverTest::test_fetch_marks_all_results_and_bonus_regardless_of_status、test:Wbet\DriverTest::test_fetch_skips_mark_call_when_nothing_to_mark |
| R-41 | 標記發生在我方寫入之前。標記之後如果寫入失敗，這批注單三方不會再回傳。這是沿用舊系統、經需求方確認接受的做法。 | code:Wbet\Driver::fetchBetRecords、mr:!2191 |
| R-42 | 結算結果依狀態處理：END 為已結算；錦標賽結果（RESULT）的金額記為彩金，投注、有效投注與輸贏歸零；建房、付費進房、免費進房是中繼狀態；REOPEN（賽事重開）清掉結算金額與派彩時間，回到未結算；REFUND（房間關閉、退回投注）投注、有效投注與輸贏歸零。 | code:Wbet\Driver::formatBetRecord、test:Wbet\DriverTest::test_format_bet_record_maps_settled_result、test:Wbet\DriverTest::test_format_bet_record_reopen_resets_to_unsettled、test:Wbet\DriverTest::test_format_bet_record_maps_bonus_room_states、test:Wbet\DriverTest::test_format_bet_record_maps_bonus_result_jackpot、test:Wbet\DriverTest::test_format_bet_record_maps_bonus_refund |
| R-43 | 只有已結算且有派彩時間的 Wbet 注單轉入 TBR。三方回傳的玩家帳號要補上遊戲商戶設定的類別後綴，才對得到我方的玩家帳號。 | code:Wbet\Driver::formatBetRecord、code:Wbet\Driver::formatBaseTotalBetRecord、test:Wbet\DriverTest::test_format_bet_record_maps_unsettled_bet、test:Wbet\DriverTest::test_format_base_total_bet_record_only_transfers_settled |
| R-44 | 三方回狀態 2 視為沒有資料，不算錯誤；其他錯誤狀態中止這次拉取。注單缺投注時間也中止。 | code:Wbet\Driver::postReportApi、code:Wbet\Driver::formatBetRecord、test:Wbet\DriverTest::test_fetch_treats_status_2_as_empty_not_error、test:Wbet\DriverTest::test_fetch_throws_on_error_status、test:Wbet\DriverTest::test_format_bet_record_throws_when_bet_time_missing |

### ClotPlaySW（含 P2P、Legend Slot）

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-45 | ClotPlaySW、ClotPlay P2P、ClotPlay Legend Slot 是無縫錢包，只能乾跑（見 R-22）。一片 30 分鐘，每分鐘最多呼叫 10 次，最早只能拉 60 天內。查詢時間換算成 GMT+8，結束點往前退 1 秒，避免與下一片重疊。 | code:ClotPlaySW\Driver::fetchBetRecords、test:ClotPlaySW\DriverTest::test_driver_implements_supports_bet_record_fetch、test:ClotPlaySW\DriverTest::test_fetch_sends_taipei_time_window_with_inclusive_end、mr:!2100 |
| R-46 | 三方以交易為單位回傳，同一回合可能有多筆。比對前先把同一回合的交易加總成一筆，對應我方一回合一列的注單。 | code:ClotPlaySW\Driver::groupByRound、test:ClotPlaySW\DriverTest::test_fetch_sums_transactions_of_the_same_round |
| R-47 | 比對只看三方知道的金額。禮碼與我方自己的結算狀態不列入比對，也不會被三方資料覆蓋。 | code:ClotPlaySW\Driver::formatBetRecord、mr:!2100 |

### PP（含 PPPOP）

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-48 | 以下兩種 PP 注單視為取消局：局內結果為 Betcancelled；或真人局（資料類型 LC）的狀態為 F。取消局的投注與輸贏記為 0，仍轉入 TBR（金額 0），對帳時看得到這一筆。 | code:Pp\Driver::isCancelled、code:Pp\Driver::formatBetRecord、test:Pp\DriverTest::test_format_bet_record_zeroes_cancelled_rounds、test:Pp\DriverTest::test_format_bet_record_zeroes_cancelled_live_casino_rounds、mr:!2454 |
| R-49 | RNG 局狀態為 F 是自動完成，有真實輸贏，照一般注單入帳。真人局狀態為 C 時，即使派彩等於投注（和局）也照一般注單入帳。 | code:Pp\Driver::isCancelled、test:Pp\DriverTest::test_format_bet_record_keeps_auto_finalized_rng_rounds、test:Pp\DriverTest::test_format_bet_record_keeps_completed_live_casino_rounds_where_win_equals_bet、mr:!2454 |
| R-50 | 已經入庫的 PP 注單之後變成取消局時，要用「更新既有列」重拉，注單與 TBR 的金額才會歸零；不帶更新既有列重拉，既有列不動。 | code:BetRecordFetchService::updateChangedRecords、test:Pp\DriverTest::test_fetch_chunk_with_update_zeroes_cancelled_rounds、mr:!2454 |

## Examples

| 情境 | 結果 | 依據 |
|---|---|---|
| 運維在 Olympus 選 Oppo、日期 5 天前、小時留空，按「執行」 | 先跳「確認重跑舊日期」視窗；確定後同步拉該日整天，每 1 小時一片 | R-31、R-29、R-36 |
| 同一天先開乾跑看差異，一小時內關掉乾跑再執行 | 正式執行沿用乾跑時的三方回應，不再呼叫三方 | R-23 |
| 對 ClotPlaySW 在命令列帶 `--direct` | 直接拒絕，回只支援乾跑模式的錯誤 | R-22 |
| PP 真人局已入帳，之後 PP 回報狀態 F | 一般重拉不會改；帶「更新既有列」重拉後投注與輸贏歸零 | R-48、R-50 |

## 還沒確認的事

- Wbet 結算結果用哪個欄位當派彩時間、缺投注時間時改用哪個欄位，沿用舊系統寫法，還沒對照 Wbet 現行回應重新驗證（Wbet Driver 類別註解，MR !2191）。
- ClotPlaySW 的反向清單在窗口邊緣仍可能誤報（派彩時間在窗口內、三方更新時間在窗口外），目前只在輸出加提示，沒有解決（MR !2100）。
