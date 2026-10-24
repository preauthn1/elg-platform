# 刻意不做的決定

> 這份管「看起來像 bug、其實是刻意」的寫法清單。動手「修正」既有寫法之前，或審查時覺得某處漏鎖、漏遮罩、錯誤碼不對時讀。

> 這一節收的是**看起來像 bug、但其實是刻意的**寫法。動手「修正」之前先查這裡——這些決定多半有事故或實測背書，好心改掉會製造新問題。
> 每條格式：**不做什麼** ｜ 理由 ｜ 出處。

## 遮罩與 log

| 不做什麼 | 理由 | 出處 |
|---|---|---|
| 注單 `info` **不遮罩、不剝除底線鍵** | 它是對帳與爭議時的原始證據，金額或狀態對不上要靠它還原當時收到什麼。遮罩紅線要求的是「憑證入 log」——log channel 的可及範圍遠大於 DB，兩者風險不同 | RB 2026-09-07 裁決 `op_tickets#3076`；`BaseController::appendParamsToInfo()` docblock |
| 注單 `info` 有**反向守衛測試**：有人把遮罩套上去就要紅 | 同上，防止後人「順手加強安全」而毀掉對帳能力 | `ac5a9cd1c` |
| 簽名／雜湊類 header（`sign`／`digest`／`X-Sign`／`wtoken`）**刻意明文不遮** | 保留「從 log 重算簽名」的排查能力 | `ac5a9cd1c`、`MaskTrait` 政策 |

## 鎖與併發

| 不做什麼 | 理由 | 出處 |
|---|---|---|
| `placeBet`／`bonus`／`eventBonus`／`settleWithoutPayout` 的重複檢查**刻意維持一般讀** | 注單此時通常不存在，`FOR UPDATE` 會取 gap lock；三方單號單調遞增使併發 INSERT 撞同一 gap → 1213 死鎖。防重靠 `bet_id` unique 索引 | `8c86a78e1`、`5e3cae00a`（六家）、`59d2a3722`（再七條路徑：AiGamingSW deduct/bonus/cashBonus、GMSW bet/luckwinBonus、JokerBaccaratSw bet、SexySlotSW debit、SexySlotCq9SW processDeal/endround、Rcb988 cancel、PlayTech firstOrNewBetRecord） |
| RGSW `payout`／`endRound` 的**反向鎖序不動** | 屬既有行為，帳不會壞、只會掉一次回調（1213 → 回 42298） | `RGSW/integration.md` |
| SpadegamingSW `reference_bet_id` **不補索引**（本批不做） | 屬大表 DDL，另案排期；現行量體下 next-key lock 代價實測接近零 | `599fada42` |

## 憑證與驗證

| 不做什麼 | 理由 | 出處 |
|---|---|---|
| `getVendorInfo('')` 的空 operatorId 語意**不動**、**禁止全域 fail-closed** | 另有 10 支 controller 刻意以空 operatorId 走「帳號所屬商戶」語意，一改全斷 | `48defb1b7` |
| Acewin `api.md` 的「注單已成立回 errorCode=6」**刻意未實作** | 本平台 bet 是下注結算合一、注單建立即 SETTLE，照字面實作會讓 `cancelBet` 永遠無效 | `13bbd7f69` |
| 派彩回調**不比對請求帶的玩家**（`playerUniqueID`／`username`），只比 `bet_id` | 舊系統 `PgsController::settle()` 以注單的 username 反查會員，不看請求玩家；無縫錢包 controller 多數只比 `bet_id`；偽造請求的閘門是白名單＋驗簽，在派彩上比玩家等於鎖側門不鎖正門；帳號 username 重生時只有「只比 `bet_id`」處理正確。審查提「跨玩家沖帳」時先查三件事：舊系統怎麼做、同層多數 controller 怎麼做、入口閘門有沒有裝 | RB 2026-09-08 裁決（target 舊快照修正，commit 已 reset） |

## 錯誤碼

| 不做什麼 | 理由 | 出處 |
|---|---|---|
| `bet` 撞同局鎖**維持不可重試碼** | 撞鎖代表同局另一筆正在處理，讓 bet 重試可能變成重複下注；只有 `gameRoundResult` 改可重試 | `c3541a46c` |
| TTHSW 守衛拒絕時**沿用 502／403**，不用語意更貼切的 503 | 沒有 TTHSW 三方文件，無從確認三方收到 503 會不會持續重送；502／403 是線上已在回的碼、行為已知 | `0c6098c2f` |
| RGSW 的 300 秒重送窗**不做成線上可調** | 避免有人設成 0 把整道防線關掉；要改值就得發版 | `RGSW/integration.md` |

## 注單與帳務

| 不做什麼 | 理由 | 出處 |
|---|---|---|
| AiGamingSW `settle` 的 `resultType === 'CANCEL'` 分支**刻意只歸零 `valid_bet`、保留 `bet`** | 本金以 `payoutAmount` 退回、不計有效投注。⚠️ 與 R14 的四欄歸零不同，抄 `cancelBet()` 那條、別抄這條 | `AiGamingSWController` |
| 撞唯一鍵者**整筆 transaction rollback**（扣款與禮碼一併回滾） | 冪等語意不變 | `5e3cae00a` |
| SpadegamingSW `payout()` 的 `referenceId` 缺口**刻意未修** | 歷史零發生（APIs 期間 TPower 19 筆被撤下注皆無派彩、TBR 零筆）；缺口已寫進該 vendor 的冪等表 | `599fada42` |
| PTSW「已退款局再收關局」的注單語意**刻意維持兩邊相反** | 回調路徑改 SETTLE＋0 值 TBR、`refund-unsettled` 命令維持 REFUND 不建 TBR；PM 選定前不動 | `PTSW/integration.md`（`op_tickets#3096` 待 PM） |

## 資料回補

| 不做什麼 | 理由 | 出處 |
|---|---|---|
| 代碼修好後**不自行回補歷史資料** | 是否回補由 PM 決定；且回補前必須先全站掃描確認影響面（Acewin 六站全掃 `status=9` 零筆，故不需回補） | `7f39049fc`、`13bbd7f69` |
| 舊系統（API_External）的同型缺陷**刻意另案** | FumaSW 同一行 `whereRaw`、Acewin 憑證收口都另評估。⚠️ 遊戲商回調有新舊分流，注單表有資料 ≠ 該系統在處理 | `63d518287`、`48defb1b7` |
