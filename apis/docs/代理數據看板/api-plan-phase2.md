# 代理數據看板 — 第二階段 API 規劃（明細下鑽）

> 前置：[api-plan.md](api-plan.md)（第一階段，已實作）。本文件為**二階：明細下鑽**，**已實作**
> （後端 `AgentDashboardDetailService` ＋ `POST agent/api/report/agent-dashboard/detail`；
> 前端 `views/report/components/agent-dashboard-detail-dialog.vue`）。三階匯出另議。
>
> 四條鐵則：
> 1. **一列＝一位會員的期間彙總**，同一會員只出現一次；**沒有交易單號、沒有逐筆交易時間**（示意稿的 `COLS.money` 是錯的）。
> 2. **下鑽對象是「組」不是「指標」**：每個指標格子都能點（同示意稿），但同一組的格子點下去開的是**同一個明細**，一次看完該組全部指標的欄位。資料密度高，不必反覆點開關閉。
> 3. **同組的指標必須有相同的「名單」**，於是每一組的列數都等於該組人數。故「註冊」與「首充」是兩組（同一期間裡「有註冊」與「有首充」是兩份不重合的名單）、「充值」與「提現」是兩組、九項成本各自一組。沒有任何一組用聯集名單。
> 4. **與一階同源**：同一組來源表、同一條時間邊界、同一份 `tz_offset` 日界規則。明細加總必須等於卡片數字。

---

## 1. 端點（1 支）

```
POST agent/api/report/agent-dashboard/detail
```

欄位由 `group` 決定。權限沿用 `report_agent_dashboard`（同一頁的下鑽，不另開 code）。

### Request

```
group            required  string  明細分組，見 §3：registration | first_deposit
                                  | repeat_deposit | deposit | withdrawal
                                  | deposit_third_party | deposit_auto | deposit_admin
                                  | withdrawal_third_party | withdrawal_auto | withdrawal_admin
                                  | bet
                                  | cost_event | cost_rolling_chip | cost_vip
                                  | cost_payment_fee | cost_withdrawal_fee | cost_sms
                                  | cost_vendor_fee | cost_rebate | cost_recycle
                                  | retention
                                  （合法值以 AgentDashboardDetailService::groups() 為準）
retention_day    required  int     僅 group=retention：1 | 7 | 30
start_time       required  datetime  使用者所選時區的牆鐘（同一階）
end_time         required  datetime
tz_offset        required  int       上述時間與輸出時間的時區偏移（−12〜+12）
agent_username   optional  string    指定代理（必過 canSeeAgent）
scope            optional  string    line（預設）| self
agent_id         optional  int       點代理明細列下鑽時帶入
username         optional  string    會員帳號篩選
page / per_page  optional  int
```

`group` 以外的條件與一階完全相同——一階怎麼算，二階就怎麼圈人。

前端把被點的格子對映到它的 `group` 送出。**「UI 卡片」與「明細 group」是兩件事**：卡片依漏斗順序合併給人讀（一階目前 4 張卡＋留存矩陣），明細以「名單」為單位（22 組，清單見上方 Request），所以**對映做在 tile 層級**——在一階 `agent_dashboard.vue` 的 `GROUPS` 裡替每個 tile 加一個 `detailGroup` 欄位，不另維護一份對映表。

| 一階卡片 | 卡內 tile ⇒ 明細 group |
| --- | --- |
| 註冊 / 首充 / 複充 | 註冊人數・註冊轉首充率 ⇒ `registration`；首充人數・首充金額 ⇒ `first_deposit`；複充人數・複充率・複充金額 ⇒ `repeat_deposit` |
| 充值 / 提現 | 總充值人數・總充值金額・人均充值金額 ⇒ `deposit`；總提現人數・總提現金額 ⇒ `withdrawal` |
| 投注與 GGR | 五格全部 ⇒ `bet` |
| 成本與 NGR | 九項成本各自 ⇒ `cost_*`；NGR 與代理貢獻佔比不可點 |
| 留存矩陣 | 任一格 ⇒ `retention` ＋ 該格的 `retention_day` |

**兩個格子不可點**：`NGR` 與 `代理貢獻佔比`。NGR 的名單只能是「有注單 ∪ 有任一成本」的聯集，攤出來大部分列在大部分成本欄都是 0，是一張稀疏表、不提供任何新資訊（每人的 GGR 在 ⑥ 投注組看得到、每項成本各有明細）；貢獻佔比的分子分母都是代理層級的量，下鑽到會員沒有意義——點它導到一階的代理明細列表。

### Response

```jsonc
{
  "columns": [
    { "key": "username", "label_key": "ad_col_member", "type": "text" },
    { "key": "registered_at", "label_key": "ad_col_registered_at", "type": "datetime" },
    { "key": "first_deposit_at", "label_key": "ad_col_first_deposit_at", "type": "datetime" },
    { "key": "first_deposit_amount", "label_key": "ad_first_deposit_amount", "type": "money" }
  ],
  "list": [
    {
      "member_id": 123,
      "username": "u_882014",
      "agent_username": "AG10086",
      "hierarchy": 1,
      "registered_at": "2026-08-11 09:42:00",
      "first_deposit_at": "2026-08-11 10:15:00",
      "first_deposit_amount": "3200.0000"
    }
  ],
  "time_basis": "+08:00",
  "page_summary": { "first_deposit_amount": "…" },
  "total_summary": { "first_deposit_amount": "…" },
  "pagination": { "total": 87, "per_page": 25, "current_page": 1, "last_page": 4 }
}
```

- `columns` 由後端回（含型別與語言 key），前端照著渲染，不在前端逐組硬寫表頭。
- 加總（`page_summary` / `total_summary`）只含金額與筆數欄；人數類指標的「人數」＝該欄位有值的列數，不另回。
- `time_basis` 為輸出時間欄的基準，等同請求帶進來的 `tz_offset`，見 §2。

---

## 2. 時間欄的基準：使用者所選時區

輸入與輸出的時間都是「使用者所選時區的牆鐘」，時區由 `tz_offset` 指定，規則與一階完全相同（見 [api-plan.md §2.1](api-plan.md)）。

前端要注意兩件事：

- 這支端點的時間欄**不呼叫** `toApiTime()` / `fromApiTime()`——時區已由 `tz_offset` 表達，再平移會錯兩次。既有報表頁那些呼叫是為了補「端點不懂時區」的過渡做法，維持原樣、不受影響。
- 明細視窗常駐顯示 `currentTzLabel()`（如 `GMT+0`），讓使用者知道畫面上的時間屬於哪個時區。

---

## 3. 明細分組（13 張表）

每張表都有的共同欄位：**會員帳號、所屬代理、代理層級、註冊時間**。以下只列各組專屬欄位。

「名單」＝這張表列出哪些會員。**同組指標的名單必須一致**（見鐵則 3），所以每張表的列數就是該組人數卡的數字，不需要「某欄為空」的補救。

> **漏斗是嚴格子集**（一階已定案）：註冊 ⊃ 首充 ⊃ 複充。①②③ 三張表的名單層層收斂，
> 所以率一定 ≤ 100%，而且 ① 的「是否首充」欄加總 ＝ ② 的列數、② 的「是否複充」欄加總 ＝ ③ 的列數。

### 3-1 `registration`（① 註冊）

- **名單**：期間內註冊的會員
- **專屬欄位**：是否首充、首充時間

| 卡片指標 | 從這張表怎麼得到 |
| --- | --- |
| 註冊人數 | 列數 |
| 註冊轉首充率 | 「是否首充」為真的列數 ÷ 列數 |

### 3-2 `first_deposit`（② 首充）

- **名單**：期間內註冊**且**期間內首充的會員
- **專屬欄位**：首充時間、首充金額、是否複充

| 卡片指標 | 從這張表怎麼得到 |
| --- | --- |
| 首充人數 | 列數 |
| 首充金額 | 首充金額加總 |
| 複充率 | 「是否複充」為真的列數 ÷ 列數 |

### 3-3 `repeat_deposit`（③ 複充）

- **名單**：② 的名單中又在期間內複充的會員
- **專屬欄位**：首充時間、複充筆數、複充金額

| 卡片指標 | 從這張表怎麼得到 |
| --- | --- |
| 複充人數 | 列數 |
| 複充金額 | 複充金額加總 |

### 3-4 `deposit`（④ 充值）

- **名單**：期間內有成功充值的會員
- **專屬欄位**：首次充值時間、充值筆數、充值金額

| 卡片指標 | 從這張表怎麼得到 |
| --- | --- |
| 總充值人數 | 列數 |
| 總充值金額 | 充值金額加總 |
| 人均充值金額 | 充值金額加總 ÷ 列數 |

### 3-5 `withdrawal`（⑤ 提現）

- **名單**：期間內有成功提現的會員
- **專屬欄位**：首次提現時間、提現筆數、提現金額

| 卡片指標 | 從這張表怎麼得到 |
| --- | --- |
| 總提現人數 | 列數 |
| 總提現金額 | 提現金額加總 |

### 3-6 `bet`（⑥ 投注與 GGR）

- **名單**：期間內有注單彙總的會員
- **專屬欄位**：首次投注時間、投注筆數、投注金額、派獎金額、GGR

| 卡片指標 | 從這張表怎麼得到 |
| --- | --- |
| 真金投注人數 | 列數（有注單即計入；`bet` 是有效投注，0 是正常值） |
| 總投注 / 總派獎 / GGR | 對應欄位加總 |
| 人均投注金額 | 投注金額加總 ÷ 真金投注人數 |

### 3-7 九項成本（⑦ 卡片內各自獨立）

**每一項成本一張明細**，不合併成一組。各項成本的名單各不相同（有活動成本的會員與有簡訊費的會員是兩批人），合併就違反「名單相同才同組」。

每張成本明細只有一個金額欄，欄名沿用一階的指標名（`AgentDashboardDetailService::COST_LABELS`），沒有筆數欄。

| group | 名單 | 專屬欄位 | 來源 |
| --- | --- | --- | --- |
| `cost_event` | 期間內領過活動獎勵或有活動 V2 成本的會員 | 活動成本 | `transaction_logs`（17 種活動類 type）＋ `wallet_transaction_logs` type 7 ＋ `summary_event_records` `event_id = 7`（與一階同源，理由見 [api-plan.md](api-plan.md) 的 event_id 對照表備註） |
| `cost_rebate` | 期間內有三層代或無限代分潤發給會員的會員 | 返水成本 | 會員層原始表：`three_hierarchy_bonus_report`（`reward_type=1`，`rebate+down_rebate+down2_rebate` > 0）＋ `unlimit_bonusreport`（`TRUNCATE(bonus_direct,2)` > 0），依 `created_at`。代理本人的份額沒有會員可歸戶，所以明細加總會小於一階卡片 |
| `cost_rolling_chip` | 有禮碼的會員 | 禮碼成本 | `summary_event_records` `event_id = 6` |
| `cost_vip` | 有 VIP 獎勵的會員 | VIP 成本 | `summary_event_records` `event_id = 9` |
| `cost_payment_fee` | 有代收手續費的會員 | 支付手續費 | `summary_event_records` `event_id = 3` |
| `cost_withdrawal_fee` | 有代付手續費的會員 | 提款手續費 | `summary_event_records` `event_id = 8`。與代收分開、禁止合併（理由見 [api-plan.md](api-plan.md) 的資料來源表） |
| `cost_sms` | 有簡訊費的會員 | 簡訊費 | `summary_event_records` `event_id = 4` |
| `cost_vendor_fee` | 期間內有盤費的會員 | 遊戲商盤費 | `summary_bet_records.cost` |
| `cost_recycle` | 期間內有活動 V2 錢包回收的會員 | 回收金額 | `event_v2_recycles.recycle_amount`，經 `event_v2_user_joins.member_id` 歸戶，依 `created_at` |

各表的卡片指標都是「對應金額欄加總」，列數即該項成本發生過的人數。

`NGR` 與 `代理貢獻佔比` 不提供明細（理由見 §1）。

### 3-8 `retention`（⑧ 留存矩陣，需帶 `retention_day`）

- **名單**：期間內註冊的會員（＝留存分母，固定不變）
- **專屬欄位**：登入回訪時間、是否登入留存、投注回訪時間、是否投注留存、充值回訪時間、是否充值留存

**三個行為放在同一張表**，D 日由 `retention_day` 決定。所以 9 格留存用 3 次查詢就看完，而不是 9 次；未到期批次照算（三個「是否」皆為 false）。點矩陣任一格即帶該格的 D 日開啟。

---

## 4. 實作骨架

沿用一階的 `AgentDashboardService`，新增 `AgentDashboardDetailService`：

```
AgentDashboardDetailController::detail(AgentDashboardDetailRequest)
  → AgentDashboardDetailService::list(viewer, target, input)
      1. group ⇒ 名單 query ＋ 欄位定義（僅成本組為聯集名單）
      2. 名單先分頁（只取 member_id），再對「當頁會員」逐來源表彙總
      3. total_summary 以名單 query 的聚合版本取得（不撈全部列）
```

- **先分頁再聚合**（同 `MemberReferralInfoService::list()`）：`COUNT` 名單一次、`forPage` 取當頁 member_id、再以 `whereIn(member_id, 當頁)` 對各來源表各查一次。禁止全量 GROUP BY 後在 PHP 分頁。
- 每組只需 1〜2 張來源表（六項成本各一張、留存組是 members ＋ 行為表），所以每頁的查詢次數固定在個位數。
- 十三個 group 用一張 match 表對映（名單 query、欄位、來源表），不做 class-per-group 的抽象——六項成本的差異只有 `event_id` 與欄名，是同一段程式的參數。
- 一階的 `resolveTargetAgent()` / 範疇展開 / 時間解析直接複用，不重寫。

---

## 5. 已知限制（要寫進前端提示）

1. **「筆數」欄是交易次數，不是列數**：一列是一位會員，他那列的「充值筆數 3」代表他在期間內充值三次；加總列的筆數是所有人的次數總和。所以「筆數合計」通常大於列數，兩者不該互相對照。
2. **明細的時間欄只到小時**（`member_hourly_statistics` / `summary_bet_records` / `summary_event_records` 都是整點桶），例外是首充時間（來自 `orders.pay_time`，精確到秒）。查詢條件只到年月日（前端只給日期選擇器），所以這件事不影響篩選結果，只影響明細上顯示的時間精度。要精確到秒得改讀 `orders` / `total_bet_records`，那會破壞「與一階同源」的鐵則（加總會對不上），不做。
3. **明細粒度只到會員**（已確認）：成本欄看不出是哪個活動發的錢、投注欄看不出是哪一款遊戲——整份報表都不下到「活動 / 遊戲 / 訂單」層級，要追那層走各自的專門報表。
4. **GGR / 盤費 可為負**（已確認）：會員贏錢的期間某些列會是負值，加總也可能為負，前端不要當異常。

---

## 6. 口徑定案紀錄

二階的待確認事項已全部收斂，開工前無待決項：

- **老會員的首充／複充：先不做**（2026-08-21 定案）。漏斗維持嚴格子集（註冊 ⊃ 首充 ⊃ 複充），期間前註冊、期間內才首次入金的老會員不出現在 ②③ 兩組。日後若營運要看「本期全部首充／複充金額（含老會員）」，屬新增指標、另提需求。
- **排序**：固定 `member_id`，不提供排序切換。
- **被點的指標**：明細裡不高亮、不移位。
- **NGR 與代理貢獻佔比**：不提供明細（見 §1）。
