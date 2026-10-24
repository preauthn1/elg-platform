---
type: Rule
id: rules/payout-bank-code-mapping
title: 代付銀行碼對照維護
description: 代付銀行碼對照表怎麼由同步命令更新、什麼情況會被覆寫或保留、停用的對照列怎麼改回啟用，以及 FPay 代付銀行清單的每日稽核查什麼、告警長什麼樣。
domain: 金流
subdomain: 渠道與貨幣
tags: [金流, 代付, 代付銀行碼, 代付銀行碼對照, 三方銀行碼, 三方碼, 銀行代碼, 銀行碼對照, cash_flow_banks, cfb, provider_bank_code, 人工覆寫, manual override, 同步, cash-flow:sync, sync, 重新啟用, reactivate, 每日稽核, 銀行清單稽核, 換碼, remap, FPay, 印尼盾, IDR, 支付組別, 基底層銀行, 金流商戶, available_banks, BotNotify, 告警]
entities:
  - command: cash-flow:sync
  - command: bank:reactivate-cfb-rows
  - command: cash-flow:audit-fpay-bank-list
  - command: cash-flow:remap-tpower-fpay-idr-bankcodes
  - command: cash-flow:third-party-query
  - class: 'App\Models\CashFlowBank'
  - class: 'App\Models\DriftBaseline'
  - class: 'App\Services\Payment\Providers\FPay\WithdrawBankList'
  - class: 'App\Services\Payment\Providers\FPay\Driver'
  - table: cash_flow_banks
  - table: drift_baselines
evidence:
  - kind: code
    ref: /app/Console/Commands/CashFlow/SyncCashFlowsCommand.php
    title: 同步命令：預先停用、依銀行名稱重建對照列、人工覆寫保護、同組別才對齊我方銀行代碼
    at: 2026-10-20
  - kind: code
    ref: /app/Console/Commands/CashFlow/Bank/ReactivateCfbRowsCommand.php
    title: 把停用的對照列改回啟用，可選配改代付銀行碼（五道預檢、人工覆寫標記、審計）
  - kind: code
    ref: /app/Console/Commands/CashFlow/Maintenance/AuditFpayBankList.php
    title: FPay 代付銀行清單每日稽核（與基準比、查我方在用的代號、BotNotify 告警）
  - kind: code
    ref: /app/Console/Commands/CashFlow/Guard/RemapTpowerFpayIdrBankCodes.php
    title: TPower FPay 印尼盾代付銀行碼換碼與校正（整組判定、補人工覆寫標記）
  - kind: code
    ref: /app/Models/DriftBaseline.php
    title: 稽核基準的存放（通用表，FPay 代付銀行清單是第一個監測對象）
  - kind: code
    ref: /app/Services/Payment/Providers/FPay/WithdrawBankList.php
    title: 向 FPay 查代付銀行清單
  - kind: code
    ref: /app/Services/Payment/Providers/FPay/Driver.php
    title: FPay 支援人工查詢三方通道命令（代付銀行清單映射成一條代付通道）
  - kind: code
    ref: /app/Models/CashFlowBank.php
    title: 代付銀行碼對照表的模型（人工覆寫標記兩個欄位）
  - kind: code
    ref: /routes/console.php
    title: 每日稽核的排程時間
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2232
    title: 修復 TPower FPay 印尼盾代付銀行代號失效，並補上清單查詢能力
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2236
    title: cash_flow_banks 加人工覆寫標記，同步命令不再沖掉手動修好的代付銀行碼
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2241
    title: 修好 FPay 印尼盾代號二次換碼缺陷，加上以代付設定為準的校正模式
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2251
    title: 新增 bank:reactivate-cfb-rows，把停用的代付銀行碼對照列改回啟用
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2255
    title: 新增 FPay 代付銀行清單每日稽核，代號被換成別家銀行即告警
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2322
    title: sync 同名銀行對齊限定同一支付組別，不再把基底層代碼拖成別組的碼
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3174
    title: "[TPOWER] FPay 印尼幣代付的銀行代碼全部過期，要向 FPay 拿新清單更新設定"
  - kind: ticket
    ref: http://example.invalid:1688/target/op_tickets/-/work_items/3229
    title: "[TPOWER] 每天自動比對 FPay 銀行清單，代號有變就告警"
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4394
    title: "[GFF][VWorld-SG]A3Pay-SGD 代收代付接入"
related:
  - rules/payout-submit-and-status
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
---

## 這個主題在講什麼

代付銀行碼對照是一張表。在某家金流商、某個幣別底下，我方的每家取款銀行（以我方銀行代碼識別）各對應一個代付銀行碼，也就是代付送件時要送給金流商的銀行代碼。

表裡每一列叫一個對照列，有啟用和停用兩種狀態，代付查表時只看啟用中的列。代付送件時怎麼取碼、查不到碼時怎麼表現，見 [rules/payout-submit-and-status](payout-submit-and-status.md)。

這張表平常由同步命令依共用設定檔整批更新。但有些金流商的代付銀行碼是按金流商戶、按幣別各發一套，而且會在不通知的情況下整批換掉，目前實際發生過的是 FPay。這時共用設定檔的值不一定適用每一站，所以另有三種做法：

- 替人工修過的對照列加「人工覆寫標記」，讓同步不再改它的代付銀行碼。
- 用命令把被同步停用的對照列改回啟用。
- 由每日稽核盯住 FPay 的清單有沒有被換掉。

本份目前收錄 MR !2232、!2236、!2241、!2251、!2255、!2322 建立或改變的行為：

- 同步命令怎麼更新對照表，以及人工覆寫保護。
- 同步對齊我方銀行代碼的範圍。
- 重新啟用停用的對照列。
- FPay 代付銀行清單的每日稽核與告警。
- TPower FPay 印尼盾的換碼與校正命令。

其餘條目隨後續 MR 補入。

## 怎麼觸發

沒有後台畫面，全部由命令列或排程觸發。命令在該站的容器內，以該站的資料庫連線執行：

- 同步命令：`php artisan cash-flow:sync`（可指定金流商、幣別；`--dry-run` 為預覽）。
- 重新啟用命令：`php artisan bank:reactivate-cfb-rows --cfb=<對照列編號> --reason=<理由>`（`--from-third`／`--to-third` 改代付銀行碼，`--allow-alias` 放行共用，`--execute` 實際寫入）。
- 每日稽核：排程每天 08:00（系統時區 UTC，即台北時間 16:00）自動跑 `cash-flow:audit-fpay-bank-list --notify`。人工可直接跑同一支命令，處理完差異後加 `--accept` 接受新基準。
- TPower FPay 印尼盾換碼命令：`php artisan cash-flow:remap-tpower-fpay-idr-bankcodes`（`--execute` 實際寫入，`--align-from-payout-config` 為校正模式）。
- 人工查詢金流商通道：`php artisan cash-flow:third-party-query <金流商戶編號> --raw`。

## 規則

### 同步命令怎麼更新對照表

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 執行同步命令同步某家金流商的代付銀行時（有指定幣別就只限該幣別），系統先把該金流商的對照列全部停用，再依共用設定檔的銀行清單逐家比對銀行名稱。比對得到的銀行，既有對照列改回啟用、代付銀行碼改成設定檔的值；沒有對照列就新建一列。設定檔裡沒有的銀行，以及名稱比對信心度不足而被跳過的銀行，對照列維持停用。 | code:SyncCashFlowsCommand |
| R-2 | 同步比對銀行名稱時只認基底層的銀行（不屬於任何支付組別的那一層，各支付組別的銀行由它複製）。對照列綁的若是支付組別層的銀行，同步之後它停在停用，不會被重新啟用，要用 R-11 起的重新啟用命令改回。 | code:SyncCashFlowsCommand |
| R-3 | 以預覽模式執行同步命令時，所有變更在結束時整批回復，畫面只是預覽，資料庫不變。 | code:SyncCashFlowsCommand |

### 人工修正怎麼保住

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-4 | 對照列帶有人工覆寫標記時，同步命令不改它的代付銀行碼，保留人工修好的值。我方銀行代碼照常跟著同步；被預先停用的列只要名稱比對得到，一樣會改回啟用。 | code:SyncCashFlowsCommand、code:CashFlowBank |
| R-5 | 沒有人工覆寫標記的對照列維持原本行為：同步時代付銀行碼一律改成共用設定檔的值。 | code:SyncCashFlowsCommand |
| R-6 | 有標記的列在設定檔的值與現值不同時，同步報表在該銀行底下列出「provider_code 保留人工覆寫值 <現值>（設定檔為 <設定檔的值>；標記原因：<標記原因>）」，沒填原因時顯示「未註明」。兩個值相同時不列。 | code:SyncCashFlowsCommand |
| R-7 | 人工覆寫標記只保護代付銀行碼，不保護對照列被停用。同步一律先把列停用，之後沒有比對回來的列（R-1、R-2 的情況），有標記也一樣停在停用。 | code:SyncCashFlowsCommand |

### 同步對齊我方銀行代碼

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-8 | 同步時若基底層有另一家名稱完全相同（不分大小寫）的啟用中銀行，系統把本銀行的我方銀行代碼對齊成那一家的（有多家時取最早建立的那一家）。名稱只是相近（例如多了國名或「Bank」字樣）時不對齊。 | code:SyncCashFlowsCommand |
| R-9 | R-8 的對齊只在同一層內找。其他支付組別有同名銀行、而且代碼不同時，基底層銀行的我方銀行代碼不變，同步報表也不列出改碼。 | code:SyncCashFlowsCommand |
| R-10 | 要對齊成的代碼已被同層、同類型、同幣別的另一家啟用中銀行使用時，系統跳過對齊，在同步報表列出「略過代碼對齊」與原因，交由人工合併處理。 | code:SyncCashFlowsCommand |

### 重新啟用停用的對照列

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-11 | 執行重新啟用命令時必須填寫理由，沒填就拒絕執行、不寫入。預設只預覽：列出每一列「重新啟用」的金流商、幣別、我方銀行代碼與代付銀行碼變化，不寫入。指定實際執行才寫入。 | code:ReactivateCfbRowsCommand |
| R-12 | 要同時改代付銀行碼時，必須同時給現值與新值。只給其中一個，或兩個值相同時，拒絕執行。給的現值與對照列實際的值不同時擋下，避免改錯列。 | code:ReactivateCfbRowsCommand |
| R-13 | 指定的對照列已是啟用中時跳過，畫面註明「已是啟用狀態」，不寫入、不留審計，也不改它的代付銀行碼。 | code:ReactivateCfbRowsCommand |
| R-14 | 下列任一情況擋下該列。一，綁的銀行不存在、已停用或已刪除。二，同金流商同幣別、同一個我方銀行代碼（逐字比對、區分大小寫）已有另一列啟用中。三，重新啟用後的代付銀行碼已被同金流商同幣別另一啟用中列使用，且操作者沒有明示允許共用。四，同金流商同幣別啟用中的金流商戶，其代付銀行清單有這家銀行，但對應的代付銀行碼與重新啟用後的不同。 | code:ReactivateCfbRowsCommand |
| R-15 | 同金流商同幣別啟用中的金流商戶，代付銀行清單都沒有這家銀行時，只警告「沒有任何啟用中的通道清單含它」，照常重新啟用。 | code:ReactivateCfbRowsCommand |
| R-16 | 一次指定多列時，只要有一列被擋，整批都不寫入，畫面列出「預檢未通過，整批零寫入」與每一列的原因。實際寫入時會鎖定這些列重驗一次，重驗不過就整批回復。 | code:ReactivateCfbRowsCommand |
| R-17 | 寫入時把對照列改回啟用、換上新的代付銀行碼（有指定時），並一併加上人工覆寫標記，原因記為命令名加理由。之後同步不再改它的代付銀行碼（見 R-4）。同時寫一筆審計，記改前改後的啟用狀態、代付銀行碼與理由。 | code:ReactivateCfbRowsCommand |
| R-18 | 依 R-7，同步過該金流商後，這一列可能又被停用，那時要重跑重新啟用命令。重跑時已啟用的列會被跳過（R-13），不會重複寫入。 | code:ReactivateCfbRowsCommand |
| R-19 | 重新啟用命令每次執行都先印出寫入目標的資料庫連線與資料庫名稱。實際寫入時，若連線名稱不是 mysql、dev 或 testing，要操作者再確認一次才寫入（可用跳過確認的選項略過），取消則不寫入。 | code:ReactivateCfbRowsCommand |

### FPay 代付銀行清單的每日稽核

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-20 | 每天 08:00（系統時區 UTC，即台北時間 16:00），系統對本站每一個啟用中的 FPay 代付金流商戶，各自向 FPay 查現行代付銀行清單，與該金流商戶上次存下的基準比對。代收的金流商戶與停用中的金流商戶不查。 | code:AuditFpayBankList、code:console.php |
| R-21 | 某個金流商戶第一次稽核時，系統只把當次清單存成基準，不比對、不告警。 | code:AuditFpayBankList、code:DriftBaseline |
| R-22 | 與基準比對時抓兩種差異。第一種是同一個代號的銀行名稱變了：代號已換成別家銀行，照舊送出不會被退回，錢會匯到別家銀行。第二種是代號從清單上消失：送出會被退回。銀行名稱逐字比對，大小寫或空白不同也算變了。 | code:AuditFpayBankList |
| R-23 | 系統同時檢查我方在用的代號，範圍是金流商戶的代付銀行清單，以及對照表中同金流商同幣別啟用中列的代付銀行碼。任一處有代號不在 FPay 現行清單上，就報出來。這一項只要沒修好就每天都報。 | code:AuditFpayBankList、code:CashFlowBank |
| R-24 | 有改名或消失的差異時，系統不更新基準，所以同一筆差異每天都會再報一次，直到操作者確認處理完畢、執行接受新基準為止。接受之後，以當次清單為新基準。執行接受的那一次仍照常報出這次的差異。 | code:AuditFpayBankList、code:DriftBaseline |
| R-25 | 清單只是多了新銀行、沒有改名也沒有消失時，系統不告警，直接把含新銀行的清單存成新基準。清單與基準完全相同時不另存。 | code:AuditFpayBankList、code:DriftBaseline |
| R-26 | 向 FPay 查清單失敗（包括清單是空的、或有缺代號、缺銀行名稱的列）時，同一個金流商戶第一次失敗不告警，連續第二次起才告警「連續 N 次查不到 FPay 銀行清單」。中間只要成功一次，計數就歸零。失敗計數保存三天。 | code:AuditFpayBankList、code:WithdrawBankList |
| R-27 | 排程執行時，有 R-22、R-23、R-26 任一異常就發一則 BotNotify 告警，標題是「⚠️ FPay 代付銀行清單稽核發現異常」。內容每項一行，並標明金流商戶編號、名稱與幣別。改名寫「代號 <代號> 的銀行從「<基準名稱>」變成「<現在名稱>」…」（後接提醒文字）；消失、代付銀行清單查無、對照表查無，各列出筆數與代號；查詢失敗寫 R-26 的訊息。最後附「查現行清單」與「確認處理完畢後接受新基準」兩行指令。全部正常時不發告警。 | code:AuditFpayBankList |
| R-28 | 排程執行時即使發現異常，排程本身仍回報執行成功，異常一律由 R-27 的告警傳達。人工執行（非排程模式）不發告警，只在畫面列出結果，有異常時命令回報失敗。 | code:AuditFpayBankList |
| R-29 | 每日稽核不改任何業務資料，只寫自己的基準。清單沒有變動的日子，不寫入任何資料。 | code:AuditFpayBankList、code:DriftBaseline |

### TPower FPay 印尼盾的換碼與校正

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-30 | TPower FPay 印尼盾的換碼命令與校正模式是一次性工具，只適用 TPower 的 FPay 印尼盾兩個目標金流商戶。預設只出報表，指定實際執行才寫入。換碼前向 FPay 查現行清單核對；兩份清單不同、新代號不在清單上，或代號新舊混雜判不出來時，整體攔截、零寫入。寫入只改代付銀行碼，並替改到的對照列補上人工覆寫標記，重跑不重複寫入。 | code:RemapTpowerFpayIdrBankCodes |

### 人工查詢 FPay 現行清單

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-31 | 對 FPay 代付金流商戶執行人工查詢金流商通道命令時，系統向 FPay 查代付銀行清單，與金流商戶的代付銀行清單比對，列出「我方有、金流商沒有（送出會被金流商拒）」的代號。FPay 這支查詢不回限額與開通狀態，限額一欄顯示「金流商未回傳」。 | code:Driver、code:WithdrawBankList |

## Examples

FPay 某金流商戶的基準是「11＝UOB Bank Indonesia、13＝Citibank」，以排程模式執行：

| 情境 | FPay 今天回的清單 | 結果 | 依據 |
|---|---|---|---|
| 第一次稽核 | 11、13 | 只存基準，不告警 | R-21 |
| 沒有變動 | 11＝UOB Bank Indonesia、13＝Citibank | 不告警、不寫入 | R-25、R-29 |
| 多了一家 | 再加 999＝Bank Baru | 不告警，基準更新成三家 | R-25 |
| 13 換成別家 | 13＝Bank Central Asia (BCA) | 告警「代號 13 的銀行從「Citibank」變成「Bank Central Asia (BCA)」」，基準不變，隔天再報 | R-22、R-24、R-27 |
| 13 消失 | 只剩 11 | 告警有 1 個代號從 FPay 清單消失：13 | R-22、R-27 |
| 對照表有啟用中列的代付銀行碼是 7 | 11、13 | 告警對照表在用的代號有 1 個不在 FPay 清單上：7 | R-23、R-27 |
| 查詢失敗、隔天又失敗 | 無 | 第一天不告警，第二天告警「連續 2 次查不到 FPay 銀行清單」 | R-26 |

對照列 A 已停用、代付銀行碼 7，綁的銀行啟用中。同金流商同幣別的金流商戶，其代付銀行清單上這家銀行對應 13：

| 操作 | 結果 | 依據 |
|---|---|---|
| 給現值 7、新值 13，不實際執行 | 預覽列出 A 重新啟用、代付銀行碼從 7 改成 13，不寫入 | R-11 |
| 給現值 7、新值 13，實際執行 | A 改回啟用、代付銀行碼 13、加上人工覆寫標記，留一筆審計 | R-17 |
| 給現值 8、新值 13 | 擋下，現值不符 | R-12 |
| 不改碼，直接重新啟用 | 擋下，代付銀行碼 7 與清單的 13 不一致 | R-14 |
| 之後跑同步，A 綁的是支付組別層的銀行 | A 又被停用，代付銀行碼仍是 13；重跑重新啟用命令即可 | R-2、R-7、R-18 |
