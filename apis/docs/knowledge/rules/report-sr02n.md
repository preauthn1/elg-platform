---
type: Rule
id: rules/report-sr02n
title: SR02N 開洗分明細表
description: 代理後台的 SR02N 開洗分明細表列哪些開分、洗分與充值紀錄，合計、手續費、實際金額怎麼算，篩選、金流商三層樹與選單節點怎麼運作。
domain: 報表
subdomain: 報表
tags: [金流, 報表, 統計報表查詢, 開洗分明細表, SR02, SR02N, SSR02, SR02Y, 開分, 洗分, 上分, 下分, 充值, 取款, 提款, 提現, 會員手續費, 手續費, 實際金額, 總開分, 總洗分, 配對, 篩選, 總代, 客服, 代理, admin, 金流商, 支付商, 金流商戶, 商戶, 支付通道, 通道, 三層樹, 級聯, cash flow tree, 預付網, OTA, 自助洗分, 選單節點, agent menu, report, transaction report]
entities:
  - api: POST agent/api/report/sr02n/list
  - api: POST agent/api/report/sr02n/total
  - api: POST agent/api/report/sr02n/cash-flow-tree
  - class: 'App\Http\Controllers\Agent\Report\Sr02nController'
  - class: 'App\Services\Agent\Report\Sr02nReportService'
  - class: 'App\Services\Agent\Report\Sr02n\Segments\AbstractSr02nSegment'
  - class: 'App\Services\Agent\Report\Sr02n\Segments\MainWalletSegment'
  - class: 'App\Services\Agent\Report\Sr02n\Segments\GameWalletSegment'
  - class: 'App\Services\Agent\Report\Sr02n\Segments\AgentCreditSegment'
  - class: 'App\Services\Agent\Report\Sr02n\Segments\AgentWalletSegment'
  - class: 'App\Enums\Report\Sr02nReportTypeSet'
  - class: 'App\Services\Agent\Report\Sr02n\Sr02nPairCondition'
  - class: 'App\Services\Agent\Report\Sr02n\Sr02nScopeResolver'
  - class: 'App\Services\Agent\Report\Sr02n\Sr02nSourceLayerCondition'
  - class: 'App\Services\Agent\Report\Sr02nCashFlowTreeService'
  - class: 'App\Http\Requests\Agent\Report\Sr02nFilterRequest'
  - class: 'App\Http\Resources\Agent\Report\Sr02nRowResource'
  - class: 'Database\Seeders\SyncAgentMenuSeeder'
evidence:
  - kind: code
    ref: /app/Services/Agent/Report/Sr02nReportService.php
    title: 四種紀錄合併分頁、合計、洗分列的手續費與實際金額
    at: 2026-10-20
  - kind: code
    ref: /app/Services/Agent/Report/Sr02n/Segments/AbstractSr02nSegment.php
    title: 各段共用的會員範圍、充值訂單鏈、期間條件、合計的手續費換算
  - kind: code
    ref: /app/Services/Agent/Report/Sr02n/Segments/MainWalletSegment.php
    title: 會員一般錢包段（取款訂單鏈、來源與單號條件）
  - kind: code
    ref: /app/Services/Agent/Report/Sr02n/Segments/GameWalletSegment.php
    title: 會員遊戲錢包段
  - kind: code
    ref: /app/Services/Agent/Report/Sr02n/Segments/AgentCreditSegment.php
    title: 代理額度段（代理洗分訂單的手續費）
  - kind: code
    ref: /app/Services/Agent/Report/Sr02n/Segments/AgentWalletSegment.php
    title: 代理佔成錢包段（System 列的可見範圍）
  - kind: code
    ref: /app/Enums/Report/Sr02nReportTypeSet.php
    title: 各段列出與計入合計的帳變種類
  - kind: code
    ref: /app/Services/Agent/Report/Sr02n/Sr02nPairCondition.php
    title: 篩選配對的操作者與對象判定
  - kind: code
    ref: /app/Services/Agent/Report/Sr02n/Sr02nScopeResolver.php
    title: 登入者可見範圍與指定代理
  - kind: code
    ref: /app/Services/Agent/Report/Sr02n/Sr02nSourceLayerCondition.php
    title: 來源的金流商、金流商戶、支付通道三層過濾
  - kind: code
    ref: /app/Services/Agent/Report/Sr02nCashFlowTreeService.php
    title: 金流商三層樹
  - kind: code
    ref: /app/Http/Requests/Agent/Report/Sr02nFilterRequest.php
    title: 篩選參數、60 天期間上限、admin 配對限制
  - kind: code
    ref: /app/Http/Resources/Agent/Report/Sr02nRowResource.php
    title: 每列的操作、來源、狀態顯示
  - kind: code
    ref: /database/seeders/SyncAgentMenuSeeder.php
    title: 選單節點「不存在才建」並授權 admin
  - kind: code
    ref: /database/data/agent_menus.php
    title: report_sr02n 與 report_sr02y 選單節點
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2326
    title: SR02N 開洗分明細表列表、合計與支付商三層樹端點
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2435
    title: SR02N 被拒絕的洗分不計手續費與實際金額
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4356
    title: "[GFF][VW2]統計報表查詢 > SR02N 開洗分明細表（新報表）"
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4493
    title: SR02N 被拒絕的洗分不計手續費與實際金額
related:
  - rules/withdrawal-member-fee
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T18:00:00+08:00" }
---

## 這個主題在講什麼

代理或客服在代理後台查一段期間內的開分、洗分、充值與相關帳變（錢包餘額的異動），一列一筆。這張表把四種紀錄合在一起列：

- 會員一般錢包的帳變。
- 會員遊戲錢包的帳變。
- 代理額度的開洗分。
- 代理佔成錢包的異動。

它是舊 SR02 開洗分明細表的新版。SR02 本身不動，兩張並存。兩張的差別：

- 「篩選」改成多選的操作者與對象配對。
- 「來源」的金流商可細到金流商戶與支付通道。
- 洗分列多了手續費與實際金額兩欄。
- 拿掉「存入對象」。

本份目前收錄 MR !2326 建立的列表、合計、篩選、金流商三層樹與選單節點，以及 MR !2435（target/tickets#4493）加上的被拒絕的洗分。

## 怎麼觸發

代理後台 › 統計報表查詢 › SR02N 開洗分明細表（新代理後台；選單上的名稱照抄）。

金流商三層樹沒有獨立畫面。它是這個頁面的「來源」選了充值或取款金流商時，下拉出現的金流商、金流商戶、支付通道選項。

選單節點沒有畫面可操作，由站點執行升級（`php artisan app:upgrade`）時同步進選單。

## 規則

### 列哪些紀錄

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 報表把會員一般錢包、會員遊戲錢包、代理額度、代理佔成錢包四種紀錄合在一起列，只列處理成功的紀錄，失敗的不出現。 | code:Sr02nReportService |
| R-2 | 會員一般錢包列出充值（三方充值）、開分、洗分、拒絕洗分、補帳、扣分、VIP 獎勵與活動獎勵類（打卡、首充、充值獎勵、每日一次領取等）。洗分逾期後系統退還的那一筆不列。預付網扣款只在「來源」選取款金流商、金流商選預付網時才列出。 | code:MainWalletSegment、code:Sr02nReportTypeSet |
| R-3 | 會員遊戲錢包列出遊戲錢包的充值、開分、補分、扣分。代理額度列出代理之間的開分、洗分與各類派發，但「主錢包轉入佔成錢包」不列。代理佔成錢包列出轉入、轉出、佔成結算。 | code:GameWalletSegment、code:AgentCreditSegment、code:AgentWalletSegment、code:Sr02nReportTypeSet |

### 合計、手續費與實際金額

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-4 | 合計有四項：總開分、總洗分、手續費合計、實際金額合計。加總的是查詢結果的全部筆數，不是當頁。篩選條件和列表相同時，四項合計等於列表逐列加總。 | code:Sr02nReportService |
| R-5 | 總開分計入：一般錢包的充值、開分、打卡獎勵、拒絕洗分、補帳；遊戲錢包的充值、開分、補分；代理額度的開分與「佔成錢包轉入主錢包」；佔成錢包的轉入。會員拒絕洗分時退回的點數因此計入總開分。 | code:Sr02nReportTypeSet |
| R-6 | 總洗分計入：一般錢包的洗分、預付網扣款、扣分；遊戲錢包的扣分；代理額度的洗分與佔成獎勵。佔成錢包的紀錄不計入總洗分。列表上其他種類（VIP 獎勵、打卡以外的活動獎勵、佔成錢包的轉出與佔成結算等）有列出，但不計入總開分也不計入總洗分。 | code:Sr02nReportTypeSet |
| R-7 | 計入總洗分的那幾種紀錄就是「洗分列」，每一列都有手續費與實際金額，被拒絕的洗分例外（R-38）。其他列兩欄都是空的（畫面顯示橫線，不是 0），也不計入手續費合計與實際金額合計。 | code:AbstractSr02nSegment |
| R-8 | 洗分列對得到會員的取款訂單或代理的洗分訂單時，手續費是那張訂單建單時記下的會員手續費，乘以訂單上的匯率換成平台點數，捨去到小數 4 位。建單時怎麼算會員手續費見 [rules/withdrawal-member-fee#R-1](withdrawal-member-fee.md)。 | code:AbstractSr02nSegment |
| R-9 | 對不到訂單的洗分列（客服直接洗分、扣分、遊戲錢包扣分、預付網扣款、沒有洗分訂單的代理洗分）手續費為 0。代理洗分列只認代理自己的洗分訂單。紀錄上的單號剛好和某張會員取款訂單相同時，仍當成沒有訂單，手續費 0。 | code:AgentCreditSegment |
| R-10 | 實際金額等於變更點數減手續費。被拒絕的洗分不計入合計（R-38），因此實際金額合計等於總洗分減去被拒絕列的變更點數，再減手續費合計。 | code:AbstractSr02nSegment::rejectedCondition |
| R-11 | 同一張取款訂單有多筆成功代付紀錄，或收付款帳戶異動了多次時，這張單在列表只出現一列，手續費也只算一次。 | code:MainWalletSegment |
| R-38 | 被拒絕的洗分：洗分列扣了點數，但會員或代理最終沒拿到錢。這種列的手續費與實際金額兩欄是空的，不計入手續費合計與實際金額合計；總開分與總洗分照舊計入。被拒絕的洗分有兩類，見 R-39、R-40。 | code:AbstractSr02nSegment::rejectedCondition、test:Sr02nTotalTest::test_rejected_rows_excluded_from_totals |
| R-39 | 洗分列對得到的會員取款訂單或代理洗分訂單，最終狀態是失敗或退款時，算被拒絕的洗分。已轉帳的照算；審核中、三方失敗這類還沒定案的狀態也照算。 | code:AbstractSr02nSegment::rejectedByOrder、test:Sr02nListTest::test_rejected_withdrawal_orders |
| R-40 | 會員拒絕洗分時，該會員在這筆拒絕之前、點數相同、最近一筆沒有取款訂單的客服洗分，算被拒絕的洗分。同一筆洗分被重複拒絕時，只有一列算被拒絕。點數不同、別的會員、扣分、有取款訂單的洗分都不算；拒絕洗分不是成功狀態、或發生在洗分之前時也不算。代理洗分沒有拒絕洗分，只有一般錢包適用。 | code:MainWalletSegment::refusedByMember、test:Sr02nListTest::test_refused_cs_withdrawal_is_rejected |
| R-41 | 會員拒絕洗分的配對不設時間窗。拒絕落在報表期間之後時，期間內的洗分列仍算被拒絕，所以已結束期間的手續費合計與實際金額合計可能事後改變。 | code:MainWalletSegment::refusedByMember、test:Sr02nListTest::test_refusal_after_period_still_rejects |

### 看得到誰的資料

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-12 | 會員的列只列登入者線下的會員（子帳號登入時以主帳號的線為準）。Admin 線（admin 本人與其子帳號）看全部。指定代理帳號時，只列該代理線下；該代理不存在或不在登入者線下時，列表是空的、合計全為 0。 | code:Sr02nScopeResolver |
| R-13 | 代理額度的列，未指定代理帳號時，只列登入者主帳號或其子帳號操作的；指定代理帳號時，改列該代理操作的。 | code:Sr02nScopeResolver、code:AgentCreditSegment |
| R-14 | 代理佔成錢包的列，未指定代理帳號時，只列登入者主帳號的子帳號與系統（System）操作的，登入者本人的操作不列（沿用 SR02）。系統操作的列，非 Admin 線只看得到對象在自己線下的代理，Admin 線全部看得到。合計同樣只算看得到的列。 | code:AgentWalletSegment、code:Sr02nScopeResolver |

### 篩選與排序

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-15 | 「篩選」是多選的操作者與對象配對，共七個：總代對客服、總代對代理、總代對會員、客服對代理、客服對會員、代理對代理、代理對會員，Admin 線另多一個「admin」（Admin 線做的全部開洗分）。勾多個時取聯集，全部不勾等於不過濾。 | code:Sr02nPairCondition |
| R-16 | 非 Admin 線的帳號送出「admin」配對時擋下，回應 400，不查資料。 | code:Sr02nFilterRequest |
| R-17 | 配對以帳號角色判定操作者與對象是總代、客服或代理（沒指派角色的帳號算代理）。操作者是沒指派角色的子帳號時，以主帳號的角色算；對象是子帳號時，依它自己的角色算。例如總代對自己沒指派角色的子帳號開分算「總代對代理」，對設為客服的子帳號開分算「總代對客服」。 | code:Sr02nPairCondition |
| R-18 | 「總代對會員」「客服對會員」命中操作者線下任一會員。「代理對會員」只命中操作者的直屬會員，對下線代理的會員不命中。「總代對代理」「客服對代理」「代理對代理」的對象必須在操作者線下（不限直屬），平行代理不命中。「總代對客服」的對象必須是操作者線下的客服。 | code:Sr02nPairCondition |
| R-19 | 會員的列只有代理在後台實際操作的開洗分，才會被配對命中。三方充值、活動獎勵、系統派發這些不是代理操作的列，只在篩選全部不勾時出現。 | code:Sr02nPairCondition |
| R-20 | 只勾對會員的配對時，代理額度與佔成錢包的列不出現。只勾對代理的配對時，會員的列不出現。 | code:Sr02nPairCondition |
| R-21 | 「來源」選客服系統時，一般錢包只列沒有充值訂單、也沒有取款訂單的開分、洗分、拒絕洗分、補帳；遊戲錢包只列沒有充值訂單的開分、補分、扣分；代理額度排除代理傭金派發、分桶獎勵、佔成獎勵。 | code:Sr02nSourceLayerCondition |
| R-22 | 「來源」選充值金流商時，只列對得到充值訂單的充值、首充獎勵、充值獎勵、每日一次領取，可再依金流商、金流商戶、支付通道三層過濾，沒選的那一層不過濾。代理額度與佔成錢包的列不出現。 | code:Sr02nSourceLayerCondition |
| R-23 | 「來源」選取款金流商時，只列對得到取款訂單的洗分，遊戲錢包、代理額度與佔成錢包的列不出現。可依金流商（比對最後一筆成功代付所用的金流商）與金流商戶過濾。金流商選自助洗分時，不比對金流商；選預付網時，改列預付網扣款。取款沒有支付通道這一層，帶了支付通道就擋下，回應 400。 | code:Sr02nSourceLayerCondition |
| R-24 | 會員帳號、會員暱稱、單號任一有填時，代理額度與佔成錢包的列不出現。會員帳號與暱稱都是完全相符才命中。會員帳號有填時不看暱稱。 | code:Sr02nFilterRequest |
| R-25 | 單號比對我方單號。「來源」選充值金流商時，也比對三方充值單號；選取款金流商時，也比對三方代付單號；選全部時，只比對我方單號。 | code:Sr02nSourceLayerCondition |
| R-26 | 執行者帳號比對每一列的操作者帳號，四種紀錄各自比對自己的操作者。 | code:Sr02nReportService |
| R-27 | 期間的起訖以代理後台顯示的時區輸入與顯示，最長 60 天（剛好 60 天可以），超過時擋下，回應 400。 | code:Sr02nFilterRequest |
| R-28 | 勾「以建立期間查詢」時，會員的列改用充值訂單的建立時間比對期間，沒有充值訂單的會員列因此不出現。代理的列仍用紀錄本身的時間。 | code:AbstractSr02nSegment |
| R-29 | 列表依時間新到舊排序，時間相同時依紀錄序號新到舊，跨頁順序固定。每頁最多 100,000 筆（匯出時一次撈全量）。 | code:Sr02nReportService |
| R-30 | 沒有這張報表權限的帳號查詢列表、合計或金流商三層樹，回應「權限不足」（錯誤碼 9003）。 | code:Sr02nController |

### 每列的顯示

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-31 | 「操作」欄的顯示：一般錢包與遊戲錢包的充值，顯示為三方充值。一般錢包的洗分對得到取款訂單時，有成功代付的顯示為三方代付，沒有的顯示為客服付款（畫面另稱「客服提現」）。遊戲錢包的開分、補分、扣分與佔成錢包的轉入、轉出，顯示成一般錢包對應的開分、補帳、扣分。代理額度只有開分、洗分有顯示名稱。 | code:Sr02nRowResource |
| R-32 | 「來源」欄的顯示：充值顯示金流商名稱（依會員所屬代理的個性化設定在當前語系的名稱，沒有就用原名）。有成功代付的洗分，顯示代付所用的金流商名稱。首充獎勵與充值獎勵顯示系統，預付網扣款顯示預付網，其餘都顯示客服系統。 | code:Sr02nRowResource |
| R-33 | 「狀態」欄的顯示：會員的列顯示收付款帳戶的暱稱，沒有就顯示「-」。代理的列顯示成功或失敗。 | code:Sr02nRowResource |

### 金流商三層樹

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-34 | 「來源」的金流商選項（畫面上叫「支付商」）一次回完金流商、金流商戶、支付通道三層，前端選了上層才出現下層。只列啟用中、而且底下掛有金流商戶的金流商。 | code:Sr02nCashFlowTreeService |
| R-35 | 非 Admin 線的帳號只看到自己支付組別的金流商戶（帳號沒設支付組別時不過濾）。Admin 線看全部。 | code:Sr02nCashFlowTreeService |
| R-36 | 金流商名稱用登入者個性化設定在當前語系的名稱，沒有就用原名。支付通道只列第一層，名稱用該金流商戶這條支付通道在當前語系的名稱，沒有就用支付通道原名。金流商戶底下沒有支付通道時，第三層為空。取款金流商只有兩層。 | code:Sr02nCashFlowTreeService |

### 選單節點

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-37 | 站點執行升級時，選單掛在統計報表查詢底下的「SR02N 開洗分明細表」節點若還不存在就建立，並自動授權給 admin 與 admin 預設角色。其他帳號要另外勾權限才看得到。節點已存在時整筆跳過，不改名稱、排序或權限。 | code:SyncAgentMenuSeeder、code:agent_menus.php |

## Examples

一般錢包的列，匯率 1：

| 情境 | 變更點數 | 手續費 | 實際金額 | 依據 |
|---|---|---|---|---|
| 會員取款 1,000，取款訂單記下會員手續費 30 | 1,000 | 30 | 970 | R-8、R-10 |
| 客服直接替會員洗分 500，沒有取款訂單 | 500 | 0 | 500 | R-9 |
| 代理替會員開分 200 | 200 | 空（顯示橫線） | 空（顯示橫線） | R-7 |
| 上面三列的合計 | 總開分 200、總洗分 1,500 | 手續費合計 30 | 實際金額合計 1,470 | R-4、R-10 |

取款訂單的會員手續費 10、匯率 3.3333：手續費 33.3330（10 × 3.3333，捨去到小數 4 位），依據 R-8。

篩選：

| 情境 | 結果 | 依據 |
|---|---|---|
| 一般代理帳號送出「admin」配對 | 擋下，回應 400 | R-16 |
| 只勾「代理對會員」，代理替下線代理的會員開分 | 不命中 | R-18 |
| 只勾「總代對會員」，會員的三方充值列 | 不出現；全部不勾才出現 | R-19 |
| 填了會員帳號 | 代理額度與佔成錢包的列不出現 | R-24 |
| 「來源」選取款金流商又帶支付通道 | 擋下，回應 400 | R-23 |
