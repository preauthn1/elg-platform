---
type: Decision
id: decisions/0015-bot-notify-sends-after-rollback
title: 告警在交易回滾後照樣送出並標註
description: BotNotify 的告警在交易 commit 或 rollBack 之後都送出，rollBack 那次在訊息開頭標註寫入未生效；只有「沒發生就不該通知」的業務通知在 rollBack 時丟掉。
domain: 系統
tags: [BotNotify, 告警, TG 告警, Telegram, 交易, rollBack, sendOnCommit, SafelyDispatchable, 大額通知, 重複出款, 代付回調, 金流, 系統]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/BotNotifyService.php, title: "send() 與 sendOnCommit()", at: 2026-10-21 }
  - { kind: code, ref: /app/Jobs/Concerns/SafelyDispatchable.php, title: "dispatchSafelyAfterTransaction() 的前提" }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2271 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2285 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2292 }
related: [rules/payout-submit-and-status]
decided_at: 2026-09-23
decision_status: accepted
---

# 告警在交易回滾後照樣送出並標註

## 背景

BotNotify（Telegram 告警）在交易內送出時，要等交易 commit 才推進 queue；交易一 rollBack，告警整則消失，不報錯也不留痕（MR !2285、!2292 的描述）。

代付回調共用的訂單狀態檢查在交易內先發告警、再拋例外讓呼叫端 rollBack，所以「訂單已標記三方失敗(29)仍收到回調」這則防重複出款告警實際上送不出去（MR !2285 的描述，target/op_tickets#3269）。

## 決定

`BotNotify::send()` 在交易內呼叫時，commit 或 rollBack 之後都送出；rollBack 那次在訊息開頭加「【交易已回滾，以下內容描述的寫入未生效】」。「這件事沒發生就不該通知」的業務通知改用 `sendOnCommit()`，rollBack 時丟掉，目前只有大額通知用它。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 只給狀態檢查的兩則告警加一個「交易結束後才送」的送法（MR !2285 採用，MR !2292 取代） | 要作者在每個呼叫點判斷「rollBack 之後這則告警還成立嗎」，判斷錯了不會有任何跡象；`app/` 有 89 個檔案呼叫 BotNotify，其中 67 個同時有交易操作，逐處維護的名單必然再犯（MR !2292 的描述與 commit 內文） |
| 所有告警一律交易結束後才送、不加標註 | 有些告警描述的動作已經被 rollBack，照送會誤導（MR !2285 的描述）。MR !2292 改用標註解決這一點，因為告警無聲消失比「內容需要標註已回滾」難查得多 |
| 把告警放進例外，由各金流商 rollBack 之後自己送 | 28 家金流商和代碼產生器的範本都要改，之後新接的金流商也容易漏（MR !2285 的描述） |
| 交易內禁發告警，收集起來在交易外統一送出 | 要動 28 家金流商的回調程式碼，改動全落在金流路徑上，風險大於收益（MR !2292 的描述） |
| `send()` 回滾後照送並標註，業務通知改用 `sendOnCommit()`（採用） | 無 |

## 後果

- 所有 `BotNotify::send()` 呼叫點在交易 rollBack 之後都會多送一則帶標註的告警，上升幅度沒有量測，已知集中在預設頻道 debug（MR !2292 的描述）。
- 用到這個送法的 Job 與 queue 連線都不得要求 after commit，否則 rollBack 後的推送照樣被丟掉；這條由守門測試檢查（MR !2285 的描述）。
- 巢狀交易內層 rollBack、且 queue 存在資料庫時仍可能漏送；現狀 queue 走 redis 不受影響（MR !2292 的描述）。
- 新增 `sendOnCommit()` 呼叫點會被測試擋下，只能出現在登記的兩個檔案（MR !2292 的描述）。
- KIRA 的「已判拒絕卻收到成功回調」專用告警同樣要等回調交易結束才送，理由相同（MR !2271 的描述）。
