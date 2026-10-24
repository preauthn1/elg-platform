---
type: Rule
id: rules/single-wallet-unsettled-refund
title: 退回無縫錢包未結算注單的投注額
description: 無縫錢包遊戲商判定投注失敗、永遠不會派彩的已扣款注單，維運怎麼用命令把投注額退回玩家，以及已取消投注的注單怎麼把殘留的投注量歸零。
domain: 遊戲與遊戲商
subdomain: 維護
tags: [無縫錢包, 單一錢包, 未結算注單, 取消投注, 退回投注額, 補帳作業, 投注量, 有效投注, FumaSW, PTSW, AFBSW, refund-unsettled, zero-refunded-turnover, 維運命令, 會員, seamless wallet, single wallet, unsettled bet, refund, bet record recovery]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T10:40:41+08:00" }
evidence:
  - kind: code
    ref: /app/Console/Commands/BetRecordRecovery/RefundUnsettled.php
    title: 退回未結算注單投注額的命令
    at: 2026-10-22
  - kind: code
    ref: /app/Console/Commands/BetRecordRecovery/ZeroRefundedTurnover.php
    title: 已取消投注注單的投注量歸零命令
  - kind: code
    ref: /app/Vendors/FumaSW/Libs/Settle.php
    title: FumaSW 派彩回調拒絕已退回的下注
  - kind: code
    ref: /app/Console/Commands/BetRecordRecovery/Revert.php
    title: 撤回命令只處理補帳紀錄
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2120
    title: 新增退回命令與 FumaSW 派彩守衛
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2138
    title: 退回命令支援 PTSW
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2128
    title: 新增 AFBSW 已取消投注注單的投注量歸零命令
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3083
    title: PTSW 已作廢但已扣款的注單
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3067
    title: AFBSW 已取消投注的注單殘留投注量
entities:
  - command: bet-record-recovery:refund-unsettled
  - command: bet-record-recovery:zero-refunded-turnover
  - class: 'App\Console\Commands\BetRecordRecovery\RefundUnsettled'
  - class: 'App\Console\Commands\BetRecordRecovery\ZeroRefundedTurnover'
  - class: 'App\Vendors\FumaSW\Libs\Settle'
  - table: bet_record_recovery_logs
related:
  - rules/single-wallet-callback-common-checks
  - rules/vendor-ptsw
---

## 這個主題在講什麼

無縫錢包（額度只在我方、由遊戲商即時回調異動餘額的對接方式）遊戲商下注回調扣款後，有時遊戲商因為逾時判定投注失敗，之後不再送派彩或取消投注。這類注單停在未結算，玩家的投注額已經扣掉，只能由我方主動退回。

本主題的命令讓維運把這類注單的投注額退回原扣款錢包，並留下稽核紀錄。另一支命令處理相反的情況：遊戲商已經取消投注、錢也退回了，但投注量還留在報表裡，要把投注量歸零。

本份目前收錄：退回未結算注單投注額的命令（FumaSW、PTSW）、已取消投注注單的投注量歸零命令（AFBSW）。FumaSW 的下注被退回後、遲到的派彩回調怎麼處理，正本在 [rules/vendor-fumasw#R-12](vendor-fumasw.md)。

## 怎麼觸發

沒有操作畫面，由維運在容器內執行 artisan 命令：

- 退回投注額：`php artisan bet-record-recovery:refund-unsettled --vendor=<fumasw|ptsw>`，再以 `--bet-id`、`--bet-ids` 或 `--bet-id-file` 指定注單。加 `--execute --reason="..."` 才真正寫入。
  - 清單檔（`--bet-id-file`）每行一筆注單編號；CSV 取第一欄。帶表頭時，第一欄的表頭寫 `bet_id`，其餘欄位是該筆注單的附加資料：`br_id`（注單列 ID）、`member_id`（會員 ID）、`refund_tc`（遊戲商原始取消投注回調的交易碼）、`refund_request_id`（遊戲商原始取消投注回調的請求序號）。PTSW 一定要附 `refund_tc` 與 `refund_request_id`。
- 投注量歸零：`php artisan bet-record-recovery:zero-refunded-turnover --vendor=afbsw`，以 `--all-refunded` 或 `--bet-id` 系列指定注單。加 `--execute --reason="..."` 才真正寫入。

FumaSW 的派彩保護由遊戲商的派彩回調觸發，規則見 [rules/vendor-fumasw#R-12](vendor-fumasw.md)。PTSW 在本命令退回後再送取消投注時怎麼處理，寫在 [rules/vendor-ptsw#R-23](vendor-ptsw.md)。

## 規則

本份作廢的 R-19 不再使用。

原本 R-19 的內容併入 [rules/vendor-fumasw#R-12](vendor-fumasw.md)。

### 退回投注額：執行方式

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 退回命令目前只支援 FumaSW（含免費場）與 PTSW。指定其他遊戲商時，命令直接以失敗結束，不動任何資料。 | code:RefundUnsettled::resolveVendorProfile、test:RefundUnsettledTest::test_unknown_vendor_fails_before_touching_anything、mr:!2120、mr:!2138 |
| R-2 | 沒加執行旗標時只做預覽：列出可退回的注單、每筆應退金額，以及每位會員的退回筆數與預估餘額，不改錢包、注單與帳變。 | code:RefundUnsettled::process、test:RefundUnsettledTest::test_dry_run_reports_refundable_bet_without_writing、test:RefundUnsettledPtswTest::test_dry_run_reports_refundable_bet_without_writing |
| R-3 | 加了執行旗標卻沒附退回原因時，命令以失敗結束，一筆都不處理。 | code:RefundUnsettled::handle、test:RefundUnsettledTest::test_execute_without_reason_is_rejected |
| R-4 | 執行模式動手前先確認寫入連線不是唯讀的從庫；是唯讀就中止，一筆都不處理。預覽模式不做這項檢查。 | code:RefundUnsettled::writeConnectionIsWritable、test:RefundUnsettledTest::test_execute_aborts_when_write_connection_is_read_only、test:RefundUnsettledTest::test_dry_run_does_not_probe_write_connection |
| R-5 | 注單可以單筆指定、以逗號分隔多筆指定，或從檔案讀取。檔案每行一筆，CSV 取第一欄，略過空行與表頭列（表頭格式見「怎麼觸發」），開頭的 UTF-8 BOM 會被去掉。帶表頭的多欄檔，其餘欄位當作該筆注單的附加資料。 | code:RefundUnsettled::resolveBetIds、test:RefundUnsettledTest::test_reads_bet_ids_from_file_one_per_line、test:RefundUnsettledTest::test_headered_multi_column_file_still_refunds_fumasw_without_meta_requirement、test:RefundUnsettledPtswTest::test_reads_meta_csv_whose_header_starts_with_utf8_bom、mr:!2138 |
| R-6 | 清單附了注單列 ID 或會員 ID 時，命中的注單或會員與清單不一致就跳過該筆，不退回。 | code:RefundUnsettled::identityMismatch、test:RefundUnsettledPtswTest::test_skips_when_csv_identity_columns_disagree_with_database、mr:!2138 |

### 退回投注額：哪些注單可以退

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-7 | 只退同時符合以下條件的注單：是下注那一列；狀態仍是未結算（FumaSW 為未派彩，PTSW 為進行中）；同一局沒有派彩或結算事件；還沒轉成 TBR；不含禮碼；沒有退回過；投注額大於 0。任一條件不符就跳過，並在結果列出原因，不動錢包。 | code:RefundUnsettled::validate、test:RefundUnsettledTest::test_skips_bet_that_already_has_settle_row、test:RefundUnsettledTest::test_skips_bet_already_aggregated_into_total_bet_records、test:RefundUnsettledPtswTest::test_skips_bet_that_already_has_settlement_event、mr:!2120 |
| R-8 | 下注用到禮碼的注單一律不退回，命令不處理禮碼錢包。 | code:RefundUnsettled::validate、test:RefundUnsettledTest::test_refuses_bet_paid_with_rolling_chips、mr:!2120 |
| R-9 | PTSW 的注單要從帶表頭的清單檔附上遊戲商原始取消投注回調的交易碼與請求序號，缺任一個就跳過該筆。 | code:RefundUnsettled::missingMeta、test:RefundUnsettledPtswTest::test_skips_bet_without_refund_meta、mr:!2138 |
| R-10 | 同一批注單重複執行時，已退回的注單因狀態已不是未結算或已有退回紀錄而被跳過，不會重複退回。 | code:RefundUnsettled::validate、code:RefundUnsettled::hasRefundLog、test:RefundUnsettledTest::test_skips_settled_bet_and_never_refunds_twice、test:RefundUnsettledPtswTest::test_second_execution_never_refunds_twice |

### 退回投注額：執行時做什麼

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-11 | 每筆注單在獨立的交易內處理：先鎖注單並重新檢查可退條件，再把投注額退回下注時扣款的那個錢包，寫一筆帳變類型為「取消注單補點」的帳變，注單狀態改為「已退款」並把派彩時間記成退回時間，最後寫一筆退回類的補帳稽核紀錄（含前後快照，餘額變動等於退回金額）。注單上的投注額保留不改。 | code:RefundUnsettled::refundOne、test:RefundUnsettledTest::test_execute_refunds_wallet_marks_record_and_writes_audit_log、test:RefundUnsettledPtswTest::test_execute_refunds_wallet_marks_record_writes_audit_and_refund_deal_record、mr:!2120 |
| R-12 | 退回不建 TBR，也不動 SBR。 | code:RefundUnsettled::refundOne、test:RefundUnsettledPtswTest::test_execute_refunds_wallet_marks_record_writes_audit_and_refund_deal_record |
| R-13 | FumaSW 的注單另在注單附註記下退回時間、執行者、原因與帳變編號。 | code:RefundUnsettled::appendRefundMarker、test:RefundUnsettledTest::test_execute_refunds_wallet_marks_record_and_writes_audit_log |
| R-14 | PTSW 的注單另補一筆遊戲商結算事件紀錄：投注額為負的退回金額、派彩為 0，交易碼與請求序號用清單提供的值，外部令牌（externalToken，玩家進遊戲時我方發給 PlayTech 的登入憑證）沿用注單下注當時的值。清單的注單編號與資料庫的大小寫不同時，仍以清單那一列的附加資料為準。 | code:RefundUnsettled::insertPtswRefundDealRecord、test:RefundUnsettledPtswTest::test_execute_refunds_wallet_marks_record_writes_audit_and_refund_deal_record、test:RefundUnsettledPtswTest::test_refund_deal_record_keeps_bet_time_session_token_after_player_logged_out、test:RefundUnsettledPtswTest::test_meta_lookup_uses_csv_bet_id_even_when_database_value_differs_in_case、mr:!2138 |
| R-15 | PTSW 補結算事件時，請求序號與既有事件重複，該筆整筆回滾：錢包、注單、帳變、稽核紀錄都不落地，結果列為失敗。 | code:RefundUnsettled::process、test:RefundUnsettledPtswTest::test_request_id_collision_rolls_back_wallet_record_and_audit_together |
| R-16 | PTSW 的退回與該遊戲商回調共用同一局的鎖。同一局的回調正在處理、5 秒內拿不到鎖時，該筆列為失敗、不退回，確認遊戲商停止重送後可以重跑。 | code:RefundUnsettled::withRoundLock、test:RefundUnsettledPtswTest::test_does_not_refund_while_callback_holds_the_game_round_lock |
| R-17 | 單筆失敗不影響其他筆。跑完列出各狀態的筆數與金額合計，並寫出逐筆明細與玩家彙總兩份 CSV 報告。 | code:RefundUnsettled::process、code:RefundUnsettled::writeReport |
| R-18 | 退回之後沒有反向命令。撤回命令只撤回補帳紀錄，不撤回本命令的退回。 | code:Revert::handle、mr:!2120、mr:!2138 |

### FumaSW 遲到的派彩回調

FumaSW 的下注被本命令退回後，遊戲商再送該局派彩回調時的處理，見 [rules/vendor-fumasw#R-12](vendor-fumasw.md)。

### 已取消投注注單的投注量歸零

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-20 | 歸零命令目前只支援 AFBSW，只處理狀態為已取消投注的注單；其他狀態（包含已結算）跳過，不動投注量。 | code:ZeroRefundedTurnover::validate、test:ZeroRefundedTurnoverTest::test_skips_bet_record_that_is_not_refunded、mr:!2128 |
| R-21 | 沒加執行旗標時只預覽，列出每筆注單與 TBR 的有效投注會從多少變成 0，不寫入。加了執行旗標要附原因，沒附就以失敗結束；執行前同樣確認寫入連線不是唯讀從庫。 | code:ZeroRefundedTurnover::handle、test:ZeroRefundedTurnoverTest::test_dry_run_reports_target_without_writing、test:ZeroRefundedTurnoverTest::test_execute_requires_reason、mr:!2128 |
| R-22 | 執行時每筆在獨立交易內處理：注單的投注、有效投注、派彩、輸贏、禮碼歸零並在附註記下歸零資訊，對應 TBR 的投注、有效投注、輸贏、禮碼歸零，再寫一筆投注量歸零類的補帳稽核紀錄（餘額變動為 0）。玩家錢包與帳變不動。 | code:ZeroRefundedTurnover::zeroOne、test:ZeroRefundedTurnoverTest::test_execute_zeroes_bet_record_and_total_bet_record、mr:!2128 |
| R-23 | 對應 TBR 的彩金、活動獎金或佣金不為 0 時，該筆不歸零，結果標為需人工判讀。 | code:ZeroRefundedTurnover::hasUnexpectedAmounts |
| R-24 | 注單與 TBR 的投注量都已是 0 時跳過，不重複寫稽核紀錄。 | code:ZeroRefundedTurnover::validate、test:ZeroRefundedTurnoverTest::test_is_idempotent_when_turnover_already_zero |
| R-25 | 掃全部已取消投注注單的模式預設上限 500 筆，超過就中止並要求縮小範圍；這個模式不能與指定注單併用。 | code:ZeroRefundedTurnover::resolveRecords |
| R-26 | 歸零不會自動更新 SBR。命令跑完依該遊戲商彙總設定的時間欄位，列出受影響的小時桶與對應的補跑命令。 | code:ZeroRefundedTurnover::printSummaryBetRecordHints、test:ZeroRefundedTurnoverTest::test_reports_summary_bet_record_rerun_hint、mr:!2128 |
| R-27 | 有任何一筆失敗或找不到注單時，命令以失敗結束。 | code:ZeroRefundedTurnover::hasFailure |

## 還沒確認的事

- 歸零命令目前只支援 AFBSW。MR !2128 提到 Acewin 有同型問題，要不要擴充還沒定案。
