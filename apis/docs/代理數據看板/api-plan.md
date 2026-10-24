# 代理數據看板 — API 規劃（第一階段：不含指標明細下鑽）

> 需求來源：[数据报表需求_代理数据看板.md](数据报表需求_代理数据看板.md)、[agent-dashboard-export.md](agent-dashboard-export.md)、`agent-data-dashboard-ui.html`（示意稿）
> 範圍：本文件規劃**第一階段**——看板本體（指標卡 + 留存矩陣 + 代理明細列表）。**點指標下鑽的會員／交易明細（二階）與匯出產檔（三階）不在本階段**。
> 前提：Agent API 面向（`routes/agent/auth.php`），POST + `CheckAgentPermission`，時區與現有報表一致（`Carbon::parse()->toSystemTime()`）。
>
> **前後端專案**
> - 後端：本專案 `apis`（新 APIs）。
> - 前端：**`/root/projects/agent-backend`（新後台，vben-admin 5.7 / Vue 3 + Element Plus，pnpm monorepo）**。本工單**只做在新後台**，舊後台 `WEB_AgentBackend` 不動、不補這個頁面。
> - 前端落點慣例：`apps/web-ele/src/views/report/`（新增看板頁）＋ `apps/web-ele/src/api/`（新增 api 模組，可參考 [api/member-referral-info.ts](../../../agent-backend/apps/web-ele/src/api/member-referral-info.ts)）。
> - **呼叫路徑必須是 `/agent/api/...`**：gateway 以 `agent/api/` 前綴決定走新 APIs、其餘轉舊 API_Agent（`apps/gateway/utils/gateway.ts:104`），對應後端 `bootstrap/app.php:76` 的 `agent/api` prefix。所以 §1 的三支端點前端實際呼叫為 `/agent/api/report/agent-dashboard/{overview,retention,agent-list}`；寫成 `/api/report/...`（舊報表如 sr12 的寫法）會被轉去舊系統而 404。

---

## 0. 工單階段劃分

| 階段 | 交付 | 端點 | 前置 |
| --- | --- | --- | --- |
| **一** | 看板本體：指標卡 ①〜⑤、留存矩陣 ⑥、代理明細列表（樹狀逐層展開） | 本文件 §1 的 3 支 | 無（§7 口徑已全部定案） |
| **二** | 明細下鑽：**一列＝一位會員的期間彙總**（見 §0-3），**一組指標一張明細**（同組的格子點下去開同一個，且同組指標名單相同 ⇒ 列數＝人數），＋分頁＋加總 | 1 支通用 `detail` 端點 ⇒ 規劃見 [api-plan-phase2.md](api-plan-phase2.md) | 一階的範疇／時間邊界／指標口徑已固化（已完成） |
| **三** | Excel 匯出：整頁一份 xlsx ＋每組明細一份 xlsx（**單一工作表**、前端產檔，見 §9） | 不新增端點，沿用一、二階（`per_page` 上限放到 5000） | 一、二階的資料層 |

拆這三階可行，三點要先講清楚，否則二階會回頭改一階：

1. **口徑已於 §7 定案，明細只能是同一份資料的展開**。二階下鑽的加總若和一階卡片對不上，是報表類最常見的客訴。做法：一階選定的來源表與時間欄位（見 §3 / §6），二階明細**必須用同一組來源與同一條時間邊界**展開到會員，不可為了「明細比較好看」換去 `orders` / `total_bet_records` 另一條路。
2. **匯出不必等二階才能開工**：「整頁 4 分頁」只吃一階的資料，一階完成後即可獨立做；真正依賴二階的只有「明細 xlsx」。若要壓時程，三階可先出整頁匯出，明細匯出跟二階一起走。
3. **⚠️ 所有明細的粒度只到「會員」，不到「交易」**（需求方確認）。每一列＝一位符合條件的會員，同一會員在期間內只出現一次；金額欄位是該會員**期間內的彙總**，不是單筆交易。

   因此**示意稿的 `money` 欄位組是錯的**——`agent-data-dashboard-ui.html` 的 `COLS.money`（`交易時間` / `交易單號` / 單筆`金額`）與其說明「一筆交易一列，同一會員多筆就出現多列」都不成立，實作時不可照抄。金額類指標的明細欄位應為：

   | 欄位 | 說明 |
   | --- | --- |
   | 用戶帳號 / 所屬代理 / 代理層級 | 同其他欄位組 |
   | 註冊時間 | 同其他欄位組 |
   | 首次發生時間 | 該會員期間內首次符合此指標的時間（取首次，不取最後一次） |
   | 金額 | 該會員**期間內的彙總金額**（非單筆） |
   | 筆數 | 該會員期間內的成立筆數（可選，用來取代原本「多列」的資訊量） |

   連帶影響：
   - 沒有 `交易單號` 欄，也沒有逐筆的 `交易時間`；要看單筆交易請走既有的訂單／注單報表，不在本看板範圍。
   - 「本頁加總 / 全頁加總」語意不變（會員彙總相加），但**筆數不等於列數**。
   - 五種欄位組實際收斂成四種（people / money / rate / retention）＋代理列的 agent 組；money 與 people 只差在多了金額與筆數欄。

另外，示意稿的匯出按鈕只彈 toast（`實際由後端產檔下載`）。三階實際定案為**前端產檔、不開產檔端點**，理由與限制見 §9。

---

## 1. 端點清單（3 支）

| # | 端點 | 對應畫面 | 說明 |
| --- | --- | --- | --- |
| 1 | `POST report/agent-dashboard/overview` | 指標卡 ①〜⑤（25 項非留存指標） | 一次回全部原始量 + 成本分項；NGR / 佔比等推導值由前端算，切成本開關不重打 API |
| 2 | `POST report/agent-dashboard/retention` | 指標卡 ⑥ 留存矩陣（9 格 + 分母） | 註冊批次 × D1/D7/D30 × 登入/投注/充值 |
| 3 | `POST report/agent-dashboard/agent-list` | 代理明細表 | 分頁 + 逐層展開（帶 `parent_agent_id` 取下一層） |

**不需要 API**：

- 指標定義表（34 項計算方式）→ 靜態文案，前端／i18n 寫死。
- 日期快捷（今日/昨日/近 7 天…）→ 前端換算成 `start_time` / `end_time`。
- 成本開關（九項成本：活動／返水／禮碼／VIP／支付手續費／提款手續費／遊戲商盤費／簡訊費／回收金額）→ 前端勾選後自行加減，見 §3。
- 代理帳號下拉 → 沿用現有輸入框 + 查無回錯誤（照 `ReportController::sr12` 慣例）。

拆三支而不是一支的理由：留存要圈註冊批次再往後掃 3 個 D 日，成本遠高於指標卡；代理明細要分頁。拆開讓指標卡先出畫面，慢查詢不擋整張看板。

---

## 2. 共通請求參數

```
start_time    required  datetime  期間起，使用者所選時區的牆鐘（YYYY-MM-DD HH:mm:ss）
end_time      required  datetime  期間迄，同上
tz_offset     required  int       上述時間的時區偏移小時（−12〜+12），見 §2.1
agent_username optional string    指定代理；空＝登入代理自身範疇
scope         optional  string    line（預設，含所有下級）| self（僅直屬）
```

- **範疇（越權防線）**：沿用 `Agent::visibleScopePath()` / `canSeeAgent()`（[app/Models/Agent.php:342](../../app/Models/Agent.php#L342)、[:366](../../app/Models/Agent.php#L366)）；`agent_username` 指定的代理不在可視範疇 → 直接回錯誤，不可只靠前端。admin(`id=1`) 與其子帳號(`host_id=1`) 不限。
- **agent id 集合**：`scope=line` 時以 `agents.hierarchy_path LIKE '<path>/%'` 展開（`hierarchy_path` 形如 `/1/100092/100213`，**無結尾斜線**，比對要補 `/` 當節點邊界，否則 `/1/10009` 會誤中 `/1/100092`）。取得 id 陣列後各聚合走 `whereIn`，禁止在聚合 query 內逐列 LIKE。
- **權限**：新增 `agent_menus.code` = `report_agent_dashboard`，路由掛 `CheckAgentPermission::class . ':report_agent_dashboard'`（同 [routes/agent/auth.php:42-47](../../routes/agent/auth.php#L42-L47) 的 `report_sr12` 寫法）。
- **時區／邊界**：見 §2.1（輸入即使用者時區牆鐘，`tz_offset` 為唯一時區來源）。⚠️ 各來源表時間欄位基準不同，見 §6-5。

### 2.1 時區：以 `tz_offset` 為唯一來源

**做什麼**：`start_time` / `end_time` 就是使用者在畫面上看到的時間（所選時區的牆鐘），`tz_offset` 說明那是哪個時區。後端用這一個偏移做兩件事——把區間換成 UTC 去查、決定「一天」的界線。前端不需要對時間做任何加減。

**為什麼不沿用既有報表的 GMT+8 基準**：既有報表（`sr12` 等 40 餘頁）的端點本身**不懂時區**，時區是靠前端 [utils/timezone-shift.ts](../../../agent-backend/apps/web-ele/src/utils/timezone-shift.ts) 在送出前後各平移一次補出來的（`API_TZ_BASIS = 8`），屬於過渡做法、不是要遵守的契約。本看板的後端既然本來就必須收 `tz_offset`（理由見下方第 2 點），輸入再套一層固定 +8 只會讓同一支 API 有兩套時區規則。

新後台的時區設定（`target/tickets#4095`）：使用者可選 **GMT−12 〜 GMT+12 共 25 個整數時區**（不涉夏令時），存在 `core-timezone` store；「今日 / 昨日 / 本月…」快捷由 `nowInUserTz()` 以**使用者時區的日界**算出（[utils/quick-range.ts](../../../agent-backend/apps/web-ele/src/utils/quick-range.ts)）。

由此推出四條硬規則：

1. **只收 datetime，不收純日期**。純日期無法表達時區；前端既有的平移工具對長度 < 16 的字串也不處理。只收 `YYYY-MM-DD` 會讓非 +8 時區的使用者整頁日界錯 8 小時。

2. **「換日」只有後端算得出來，所以一定要收 `tz_offset`**。使用者選 GMT+0 按「今日」時，傳進來的區間換算成 UTC 後會跨越 +8 的日界；若後端拿固定 +8 去切「日」，留存的註冊批次與 D 日會全部錯位。
   - **overview / agent-list**：純區間加總，不切日 → 只用 `tz_offset` 換算區間。
   - **retention**：註冊日、D1/D7/D30「當天」都是日粒度 → 日界一律 `DATE(時間欄 + INTERVAL :tz_offset HOUR)`（DB 存 UTC），不可寫死 `+ INTERVAL 8 HOUR`，更不可直接 `DATE(UTC 欄位)`。
   - 驗收條件：同一批資料，切 GMT+8 與 GMT+0 查同一個「今日」，留存的分母與各 D 日人數要跟著日界移動；overview 的加總只隨區間變。

3. **不要照抄 `sr12` 的解析寫法**。`Carbon::parse($s)->toSystemTime()` 在 `config('app.timezone') = 'UTC'`（本專案現值）下是**空操作**——輸入會被當成 UTC 牆鐘。本看板的作法是 `Carbon::parse($s, <tz_offset 換算出的偏移>)->utc()`。

4. **`tz_offset` 放 request body，不放 header**（2026-08-21 決議）。曾評估過比照 `Accept-Language` 改成 header + middleware 統一管理，但 header 為了不打壞既有 client 必須給預設值（+8），漏帶時會**靜默**套用錯誤時區；作為 required body 參數，漏帶是當場 400，失敗模式好得多，也保證「同一份 request body ⇒ 同一份結果」可重現、參數在 API 文件與 `RequestLogger` 都看得見。目前只有 3〜4 支端點需要時區，前端本來就要組時間參數，多一個欄位不痛。
   日後舊報表真要做時區改造、tz-aware 端點數量上到十幾支時再搬，屆時作法是「middleware 讀 header 當**預設值**、body 參數可覆寫」，不是二選一。

5. **只支援整數時區**。`member_hourly_statistics` 是整點桶、彙總表也是小時粒度，GMT±N 都能對齊。日後若要支援 +5:30 / +8:45 這類半小時時區，小時桶會切不齊，得改用逐筆來源；目前前端的時區選項只有整數時區，暫不處理。

---

## 3. 端點 1：overview

### Request
共通參數即可。

### Response（原始量 + 成本分項，推導值前端算）

```jsonc
{
  "registration": { "count": 12486 },
  "first_deposit": { "count": 3142, "amount": "1884200.0000" },
  "repeat_deposit": { "count": 1827, "amount": "6412880.0000" },
  "real_bet_member_count": 4905,
  "deposit": { "member_count": 4969, "amount": "8297080.0000" },
  "withdrawal": { "member_count": 2238, "amount": "5910440.0000" },
  // 充提六類來源明細（member_hourly_statistics 的 *_amount 欄，target/tickets#4333 口徑；2026-09-15 新增）
  "deposit_sources":    { "third_party": "...", "auto": "...", "admin": "..." },   // 三方充值 / 自助上分 / 後台開分
  "withdrawal_sources": { "third_party": "...", "auto": "...", "admin": "..." },   // 三方提現 / 自助洗分 / 後台洗分
  "bet": { "amount": "62340900.0000", "payout": "57918700.0000", "ggr": "4422200.0000" },
  "cost": {                      // 九項皆可在前端勾選是否計入 NGR，預設全勾
    "event": "486300.0000",
    "rebate": "312750.0000",
    "rolling_chip": "...",       // 禮碼
    "vip": "128400.0000",
    "payment_fee": "...",        // 代收手續費（卡片名「支付手續費」）
    "withdrawal_fee": "...",     // 代付手續費（卡片名「提款手續費」），與代收分開、禁止合併
    "vendor_fee": "623409.0000",
    "sms": "18600.0000",
    "recycle": "..."             // 回收金額，回傳正數，計入 NGR 時為加回
  },
  "scope_total": {               // 貢獻佔比的分母＝登入者可視範疇總量，見下方
    "ggr": "...", "cost": { ... }
  }
}
```

前端推導（不佔 API）：`註冊轉首充率`、`複充率`、`人均充值/投注`、`NGR = GGR − 勾選的成本 ＋ 勾選的回收金額`、`代理貢獻佔比 = 本代理 NGR ÷ scope_total 同公式 NGR`。

> **代理貢獻佔比的分母＝「登入者可視範疇」，不是全站**（需求方裁定：代理只能查自己與自己下線）。
> `scope_total` 是**登入代理自己這條線**的同期同公式合計；只有平台端（`isPlatformAdmin()`，`admin id=1` 及其子帳號）登入時，可視範疇才等於全站，此時它才是需求文件寫的「全平台 NGR」。
> 一般代理登入 ⇒ 佔比語意是「該代理（或其下級）對**我這條線**的貢獻佔比」，任何情況都不外洩範疇外的量體。查自己 ⇒ 佔比 100%。

### 資料來源

| 指標 | 來源 | 條件 |
| --- | --- | --- |
| 註冊人數 | `members` | `created_at` 落在期間，`members.parent ∈ agentIds` |
| 首充人數／金額 | `orders` | 子查詢 `MIN(pay_time) GROUP BY member_id`（全歷史）落在期間；`status = 3`（`OrderStatusEnum::DEPOSIT_FINISHED`）。**限期間內註冊的會員**（漏斗子集，見 §7-6）。**不可用 `members.first_deposit`**——該欄位實務上幾乎沒寫入（[MemberReferralInfoService.php:166](../../app/Services/Agent/MemberReferralInfoService.php#L166) 已註明） |
| 複充人數／金額 | `orders` | 期間內成功充值扣掉「屬首充的那筆」；同樣**限期間內註冊的會員**（漏斗子集，見 §7-6） |
| 總充值／總提現（人數・金額） | `member_hourly_statistics` | `summary_hour` 對齊整點；`deposit_count/amount`、`withdrawal_count/amount`；成功狀態定義已固化在寫入端（代收 `status=3`；代付 `24 三方成功` / `4 已轉帳`），見 [CorrectMemberHourlyStatistics.php](../../app/Console/Commands/Schedule/CorrectMemberHourlyStatistics.php) |
| 真金投注人數 | `summary_bet_records` | `COUNT(DISTINCT member_id)`，**有注單即計入**（對齊舊「數據分析」）：`bet` 是有效投注，0 是正常值，有效投注為 0 的注單仍是投注行為 |
| 總投注金額 | `summary_bet_records.bet` + `JOIN members m ON m.id = member_id`（以 `m.parent` 歸代理） | `summary_time` 落在期間、`m.parent ∈ agentIds`。**不用 `summary_bet_record_agents`**，理由見 §6-1 |
| 總派獎／GGR | `summary_bet_records.win_lose` | **已定案**：`派獎 = bet + win_lose`、`GGR = −win_lose`（§7-1） |
| 遊戲商盤費 | `summary_bet_records.cost` | 由 `SummaryBetRecordService::calculateGameFee()` 依 `customized_setting_group_id × vendor_id` 算出，[SummaryBetRecordService.php:214](../../app/Console/Commands/SummaryBetRecord/SummaryBetRecordService.php#L214) |
| 活動成本 | `transaction_logs`（17 種活動類 type）＋ `wallet_transaction_logs`（type 7）＋ `summary_event_records` `event_id = 7` | 對齊 newsr11 的 `-1 Reward` ＋ `-7 Event cost`。**不用 `event_id = 1`**——那份彙總由 lottery SP 產出、TPower 實測有缺漏（見下方對照表備註） |
| 返水成本 | **2026-09-11 改**：`three_hierarchy_bonus_report_agents.rebate_amount`（>0）＋ `three_hierarchy_bonus_report`（`reward_type=1`，`rebate+down_rebate+down2_rebate`>0）＋ `unlimit_bonusreport_agents` / `unlimit_bonusreport` 的 `TRUNCATE(bonus_direct,2)`（>0），皆依 `created_at` | newsr11 `-5` 3 layers of agents mode ＋ `-2` 全民代理（無限代）；代理層表以 `agent_id` 歸戶，故不提供二階明細 |
| 禮碼成本（新） | `summary_event_records` `event_id=6` | newsr11 `-6` Rolling chip（禮碼）；即原「返水成本」的資料 |
| 回收金額（新） | `event_v2_recycles.recycle_amount`（經 `event_v2_user_joins.member_id` 歸戶，依 `created_at`，各狀態皆計） | newsr11 `-7` eventCost 列的回收金額；回傳正數，前端計入 NGR 時為加回 |
| VIP 成本 | `summary_event_records` `event_id=9` | newsr11 `-9` Vip；`SP_summary_event_record_by_vip`（來源 `transaction_logs`） |
| 支付手續費（代收） | `summary_event_records` `event_id=3` | newsr11 `-3` Pay fees；回傳鍵 `payment_fee` |
| 提款手續費（代付） | `summary_event_records` `event_id=8` | newsr11 `-8` Withdrawal fees；回傳鍵 `withdrawal_fee`。**與代收分成兩項、禁止合併**：newsr11 與 newsr11y 也是兩列，合併會讓看板與報表對不起來（target/tickets#4412，見 `AgentDashboardService::COST_EVENT_IDS`） |
| 簡訊費 | `summary_event_records` `event_id=4` | newsr11 `-4` SMS fee |

> 走 `summary_event_records` 的成本（活動 V2 的 `event_id=7`、禮碼、VIP、支付手續費、提款手續費、簡訊費）一律用**會員粒度**那張表，`JOIN members m ON m.id = member_id` 以 `m.parent` 歸代理。這樣一階卡片與二階明細同源（§0-1、§0-3），也避開 `summary_event_record_agents` 漏資料的問題（§6-2）。
> 其餘成本另有來源（見上表）：活動成本的主體讀 `transaction_logs` 與 `wallet_transaction_logs`，返水讀三層代與無限代的原始表，回收金額讀 `event_v2_recycles`，遊戲商盤費讀 `summary_bet_records.cost`。

#### `event_id` 對照表（以 newsr11 的「特殊平台」負值 id 為準）

來源：[reconciliation-page.vue 的 `specialOptions`](../../../agent-backend/apps/web-ele/src/views/report/components/reconciliation-page.vue)（負值 id ＝ `-event_id`）＋ `database/procedures/SP_summary_event_record_by_*.sql` 的 `EventId DEFAULT`。

| event_id | newsr11 項目 | 寫入來源 | 看板用途 |
| --- | --- | --- | --- |
| 1 | 活動／獎勵（TPower 站台標籤為 `Reward`） | `SP_summary_event_record_by_lottery`（來源 `transaction_logs` type 10/13/15/19/20/21/24 ＋ `promotion_wallet_logs` type 3/5/6/7） | **不用**（改直接讀交易表，見下） |
| 2 | 無限代獎勵 | `SP_summary_event_record_by_unlimited` | 不用 |
| 3 | Pay fees（代收手續費） | `SP_summary_event_record_by_payment` | 支付手續費（`payment_fee`） |
| 4 | SMS fee | `SP_summary_event_record_by_sms` | 簡訊費 |
| 5 | 三層代 | 三層代相關 SP | 不用 |
| 6 | Rolling chip（返水／禮碼） | `SP_summary_event_record_by_rolling_chip` | 禮碼成本（`rolling_chip`）；返水成本不讀它，改讀三層代與無限代原始表 |
| 7 | 活動 V2 成本（前端 fallback 標籤 `Event cost`） | `EventV2UserJoinObserver`（apis，`COST_ID = 7`） | **活動成本** |
| 8 | Withdrawal fees（代付手續費） | `SP_summary_event_record_by_payment_W` | 提款手續費（`withdrawal_fee`），與 3 分開、不合併 |
| 9 | Vip | `SP_summary_event_record_by_vip` | VIP 成本 |

> ⚠️ **`summary_event_records.event_id` 的 DB 欄位註解把 7 寫成「回收」，是舊註解、不可信**。實際上 `event_id = 7` 就是活動成本：apis 的 `EventV2UserJoinObserver::COST_ID = 7`（[app/Observers/EventV2UserJoinObserver.php:16](../../app/Observers/EventV2UserJoinObserver.php#L16)），newsr11 也把 `-7` 標為 Event cost。早先本文件依該註解誤把「活動成本」指向 `event_id=1`，已更正。
>
> ⚠️ **newsr11 那些負值項目的名稱是每個站台自己設定的**（`agent_customized_settings.setting_key` 3001〜3xxx，經 `/api/translate` 下發），前端程式裡的 `V-World` / `V-Reward` 只是 fallback 文字，不能拿來判定語意。實測 TPower：`setting_key 3009`（`-1` 的標籤）＝ **`Reward`**，`3017`（`-7`）該站沒設定所以吃 fallback `Event cost`。
>
> 因此**活動成本 ＝ `event_id IN (1, 7)`**：`1` 是舊活動／獎勵（V1，走 `transaction_logs` / `promotion_wallet_logs`），`7` 是活動 V2（apis Observer）。只取 `7` 會在舊活動仍在跑的站台幾乎全漏——TPower 近 7 天 `event_id=1` 有 `17,132`、`event_id=7` 只有 1 筆 `100`。
>
> `2` 無限代、`5` 三層代屬代理分潤，原本不計入。**2026-09-11 起併入「返水成本」**（target/tickets#4335），但不走 `summary_event_records` 的 event 2 / 5（TPower 實測沒資料），直接讀原始表，見上表。
>
> **成本開關（2026-09-11 起）**：九項成本（活動／返水／禮碼／VIP／支付手續費／提款手續費／遊戲商盤費／簡訊費／回收金額）全部可勾選、預設勾選；NGR ＝ GGR − 勾選的成本 ＋ 勾選的回收金額。
>
> ⚠️ **`event_id = 1` 不可信，活動成本改直接讀交易表**（2026-08-21 TPower 驗證）：
> - 該彙總由 `SP_summary_event_record_by_lottery` 產出，逐小時比對全站發現**零星缺漏**——
>   2026-08-05 23:00 整個小時沒寫入（`transaction_logs` 有 34、彙總為空）、02:00 少 18。
>   同時段 VIP（`event_id = 9`）與返水（`event_id = 6`）的彙總逐時／逐日完全相符，故問題出在那支 SP。
> - 它只抓 7 種 transaction type，newsr11 的 `-1 Reward` 抓 **17 種**（`TransactionEnum::REPORT_EVENT_TYPES`）。
> - 改抽交易表後，BFT100 線 2026-08-01〜08-07 的活動成本 1,148 ⇒ **1,234，與 newsr11 一致**；
>   二階明細全頁加總同為 1,234（30 人），卡片與明細對得上。
> - 代價：多一次 `transaction_logs` 掃描，overview 由 5.11s 增至 7.95s（舊 newsr11 自己的
>   `max_execution_time` 設 120s，屬同等量級）。
> - newsr11 另把活動 V2 的**回收**（`event_v2_recycles`）當獨立欄位顯示、不從 eventCost 扣除，
>   看板需求沒有回收欄位，故同樣不扣。

---

## 4. 端點 2：retention

### Request
共通參數（`start_time`/`end_time` 圈定**註冊批次**）。**`tz_offset` 在本端點是必用參數**——註冊日與 D1/D7/D30 的日界皆以它決定，見 §2.1-3。

### Response

```jsonc
{
  "denominator": 12486,
  "matrix": {
    "login":   { "d1": 5283, "d7": 2632, "d30": 1219 },
    "bet":     { "d1": 3576, "d7": 1688, "d30": 738 },
    "deposit": { "d1": 1227, "d7": 577,  "d30": 268 }
  }
}
```

留存率前端除。

> **未到期批次照算，不做特殊處理**（§7-2）：分母固定＝期間內註冊人數；D 日還沒到的批次自然不會有回訪紀錄，命中數就是 0。所以查「近 7 天」時 D30 通常整排是 0，這是預期行為，不回 `mature_denominator`、不縮小分母、後端也不擋。

### 資料來源

| 行為 | 來源 | 判定 |
| --- | --- | --- |
| 分母 | `members` | `created_at` 落在期間、`parent ∈ agentIds` |
| 登入留存 | `member_login_logs` | `status = 1`，`created_at` 落在「該會員註冊日 + N 天」**當天**（D 日是特定一天，不是區間） |
| 投注留存 | `summary_bet_records` | `summary_time` 落在該日、`bet > 0` |
| 充值留存 | `member_hourly_statistics` | `summary_hour` 落在該日、`deposit_count > 0` |

實作要點：以「註冊日 → 會員 id 清單」分組，一次 join 出 D 日命中，**禁止逐會員迴圈查**。日界一律帶時區：

```sql
DATE(行為時間 + INTERVAL :tz_offset HOUR) = DATE(註冊時間 + INTERVAL :tz_offset HOUR) + INTERVAL :n DAY
```

（`:tz_offset` 為使用者時區偏移小時，DB 時間欄位為 UTC；參數化綁定，不可字串拼接。）

---

## 5. 端點 3：agent-list

### Request

```
共通參數
parent_agent_id  optional int   空＝回第一層（登入代理的直屬下級）；帶值＝回該代理的下一層
page / per_page  optional
```

樹狀展開採**逐層懶載**：一次只回一層，前端點 `▸` 再打一次。不做一次撈整棵樹（層數不可控、父子重複計算風險高）。

### Response（每列一個代理）

```jsonc
{
  "list": [{
    "agent_id": 102259, "username": "AG10086", "nickname": "星耀渠道",
    "parent_username": "—", "hierarchy": 1, "has_children": true,
    "registration_count": 4205, "first_deposit_count": 1082,
    "deposit_amount": "2810400.0000", "bet_amount": "21104300.0000",
    "ggr": "1498100.0000",
    "cost": { "event": "...", "rebate": "...", "rolling_chip": "...", "vip": "...", "payment_fee": "...", "withdrawal_fee": "...", "vendor_fee": "...", "sms": "...", "recycle": "..." }
  }],
  "page_summary": { ... },   // 本頁加總（當頁列相加）
  "total_summary": { ... },  // 全頁加總（當層全部頁相加，非整條線總計）
  "pagination": { ... }
}
```

- `scope=line` 時每列數字＝該代理的線（含所有下級），**父列已包含子列** → 加總只能加最外層代理，否則父子重複計算（示意稿已如此處理）。
- `scope=self` 時每列＝直屬，加總為全列相加。
- 兩份加總的範圍都是**當前展開的那一層**（同層皆兄弟，天然去重）：`page_summary` 只加當頁列，`total_summary` 加該層所有頁。整條線的總計看 overview 的 `target`，不放在明細表裡——只有一頁時兩個加總必須相等，否則使用者會當成算錯（target/tickets#4180 QA 回報）。
- 每列的成本分項一起回，NGR / 佔比同樣前端算，跟 overview 的開關共用一份邏輯。
- 只對**當頁代理**做聚合（先分頁再聚合），不對全表 GROUP BY——同 [MemberReferralInfoService::list()](../../app/Services/Agent/MemberReferralInfoService.php#L56) 的做法。

---

## 6. 效能與資料坑

1. **投注面不要用 `summary_bet_record_agents`（sbra），改直接聚合 `summary_bet_records`（SBR）**。實測（2026-08-21，TPower / VWorld 唯讀直連）：
   - **數值是準的**：sbra（`vendor_id > 0`）與「SBR join members 現算」在 TPower 逐日比對 2026-08-10〜08-19 共 10 天，`bet` / `win_lose` / `cost` / 代理數四項**每日完全一致**（例：08/18 `bet 16,859,923.98`、`win_lose 160,432.33`、`cost 47,708.18` 兩邊同值）。
   - **但只有 T+1**：sbra 是**每日一批**（`created_at` 固定 06:30 UTC ≒ 14:30 GMT+8）寫入前一個 GMT+8 日；查詢當下 TPower 與 VWorld 的 `MAX(summary_time)` 都是 `2026-08-19 15:00`，而 SBR 已到 `2026-08-21 01:00`、`summary_event_record_agents` 已到 `2026-08-21 02:00`。**落後約 15〜39 小時，撐不起看板的「今日 / 昨日」快捷。**
   - **新 APIs 從來沒讀過它**：`apis` 內只有建表 migration，讀取端全在舊系統（`API_Agent` 的 `ReportController` / `AgentSimpleSettlementRepository` / `ShareholderController`、`RD-Studio-01` 的 `ProduceSimpleSettlementReport`，以及 `SP_calc_point_barrels` / `SP_calc_shareholder_winlose` 等 SP）。要動它得連舊報表一起評估，不划算。
   - ⇒ **結論**：投注／GGR／盤費一律現算 SBR（TPower 全站 10 天 join 聚合實測 2.17s，單一代理線範圍更小），sbra 只留作對帳驗算的旁證。成本面的 `summary_event_records` 是小時級即時（`MAX(summary_time)` 2026-08-21 02:00），照用——但要用會員粒度那張，不是 agents 版，見下一條。
2. **成本面也不要用 `summary_event_record_agents`（sera），改用 `summary_event_records`（ser）＋ join members**。sera 是由 ser `INNER JOIN members JOIN agents` 派生（見 `SP_summary_event_record_by_vip.sql` 的 `# agent` 段），但實測會**整小時漏資料**：TPower 2026-08-17 16:00〜08-18 16:00 同一窗比對，`event_id=9` ser `7,499.71` vs sera `5,107.53`（少 32%），逐小時看是 9 個小時 sera 完全沒寫（非 orphan——該窗 ser 的 `member_id` 全部都 join 得到 members/agents）；`event_id=1` 差 `124`、`event_id=4` 差 `0.46`；只有 `3`、`6` 兩項完全一致。
   ⇒ 成本一律現算 ser。順帶好處：與二階明細同源（§0-1），且 sera 本來也只有直屬粒度、線模式照樣要自己展開 hierarchy，用它並沒有省事。sera 的 `self_point` 欄位實測全為 0（`Sr11CostTrait::inserCost` 也只寫 `point`），不可使用。
3. **若日後仍要用 sbra，記得 `vendor_id` 有負值**：`SP_summary_bet_record_fee_by_agent` 把成本寫成 `vendor_id = -event_id`（dev 實測有 `-1 / -3 / -5` 列），不加 `vendor_id > 0` 會把成本混進投注。
4. **代理歸屬的粒度與時點**：sbra 與 sera 都以 `members.parent` 歸戶（`SP_summary_bet_record_by_agent` 的 `JOIN agents a ON a.id = m.parent`），沒有預先展開代理線 → 線模式一律自己展開 `hierarchy_path` 取 id 集合。另外 sbra 記的是**當時**歸屬、現算 SBR 是**當下**歸屬，會員換代理時兩者會分歧；TPower `agent_migrate_logs` 目前 0 列，實務上尚無此情形，但若日後開始搬會員，歷史數字要固定就得回頭補一張代理線彙總。
4. **`hierarchy_path` 有兩種歷史格式**（`/1/102259/` 帶結尾斜線 與 `/1/4249/59554` 不帶），比對前先 `rtrim('/')` 再補 `/`，並對空值 fail-closed 只看直屬。
5. **時間欄位基準不統一**（需求文件第 3 節「全系統統一時區及日期邊界」正是指這件事）：
   - `orders.pay_time`（成功當下寫入，不變）
   - `withdrawal_orders.payment_time`（同上，**不可用 `updated_at`**）
   - `member_hourly_statistics.summary_hour`（整點桶 → 期間起點要 `startOfHour()` 才不漏半桶）
   - `summary_bet_records.summary_time`、`summary_event_records.summary_time`（UTC / 小時粒度）
   - `member_login_logs.created_at`
6. **首充要全歷史掃 `orders`**：`MIN(pay_time) GROUP BY member_id` 是最貴的一段。上線前確認 `orders` 有覆蓋 `(member_id, status, pay_time)` 的索引，必要時補；期間內註冊的會員可先用「註冊時間 ≥ 期間起」剪枝。
7. **⚠️ `member_hourly_statistics` 缺自助洗分（2026-08-17 前的歷史資料）**：TPower 實測，mhs 的提現金額在 08-17 之前等於 `withdrawal_orders` 只算 `status = 24`（三方成功），`status = 4`（已轉帳／自助洗分）整批沒進桶——08-05 少 544,608、08-15 少 828,189（約佔提現量三成）。原因是補上 status 4 的修正（`13f91e26b`，2026-08-13）約在 08-17 才上線，而每小時校正命令只重算最近 2 個整點，歷史桶沒被回填。08-17 之後逐日核對 mhs = status 24 + status 4，管線本身是對的。
   ⇒ 看板查 08-17 之前的區間會少算提現。**2026-05-01 起已在 TPower / Yes2Win / VWorld / VPlus33
   回填完成**（見 §8-1）；05-01 之前仍缺，需要時再往前補。

8. **正式站效能實測（TPower，2026-08-21）**：全部端點 0.76〜2.73s。過程中修掉兩個會炸的寫法——
   - **首充／複充的候選集合**原本取「期間內有成功充值的全站會員」，但漏斗子集只算「期間內註冊」的人，白掃幾萬人：35.5s ⇒ 0.5s。
   - **貢獻佔比的分母**原本讓卡片跟著掃全站（admin 登入時 `whereIn` 塞上千個 agent id 直接逾時）。改成卡片只聚合查詢對象那條線；分母走單列聚合，且「查詢對象＝可視範疇」時直接沿用卡片數字（佔比恆為 100%），其餘情況快取 5 分鐘。

9. **快取**：整張看板屬重查詢且非即時性需求，建議 Redis cache 5–10 分鐘，key ＝ `agent_id + scope + 期間 + 端點`。切成本開關不打 API，天然不吃 cache。
10. **金額一律走 `Common::numAdd/Sub/Mul/Div`**，DB decimal 讀出後先正規化（`Common::numAdd('0', (string) $v, 4)`），比照現有報表輸出 4 位小數。

---

## 7. 口徑定案（2026-08-21 需求方回覆）

1. **總派獎金額** ✅ 定案：`總派獎 = SUM(bet) + SUM(win_lose)`、`GGR = −SUM(win_lose)`（`win_lose` 為會員淨輸贏，平台視角取負）。不逐筆掃 `total_bet_records`。
2. **留存未到期批次** ✅ 定案：**照算**，分母固定＝期間註冊人數，未到期批次命中數為 0。查「近 7 天」時 D30 通常整排是 0，屬預期行為，不做成熟度校正、不縮分母（見 §4）。
3. **活動成本範圍** ✅ 定案：**依 newsr11 標準**，且**活動獎勵改抽交易表**——活動成本＝
   `transaction_logs`（17 種活動類 type）＋ `wallet_transaction_logs` type 7 ＋ `summary_event_records`
   `event_id = 7`（活動 V2）。原本用 `event_id = 1` 的彙總，TPower 實測有整小時漏跑（§3 對照表備註）。
   其餘走 `summary_event_records` 的：禮碼 `6`、VIP `9`、支付手續費 `3`、提款手續費 `8`（兩項分開、不合併）、簡訊 `4`。
   返水成本不讀 event `2`（無限代）、`5`（三層代），改讀三層代與無限代的原始表（target/tickets#4335，見 §3 對照表備註）。
   完整對照、站台標籤可變的陷阱、以及「DB 註解把 7 誤寫成回收」的更正見 §3 對照表。
4. **代理貢獻佔比的分母** ✅ 定案：**代理只能查自己與自己下線，看不到全站**。分母改為「登入者可視範疇（自己這條線）」的同期同公式合計；只有平台端登入時該範疇才等於全站。實作與語意見 §3 的 `scope_total` 備註。
6. **漏斗為嚴格子集** ✅ 定案（2026-08-21 追加）：**註冊 ⊃ 首充 ⊃ 複充**。
   - 首充人數／金額只算**期間內註冊且期間內首充**的會員；複充再往下收一層。
   - 因此註冊轉首充率與複充率一定 ≤ 100%，二階明細的布林欄加總等於卡片數字。
   - 原本的問題：兩個率的分子含期間外註冊／首充的老會員，不是分母的子集，比率可能 > 100%。
   - ⚠️ **代價**：老會員在本期首次入金、或老會員本期複充，都不會出現在這兩組指標裡。要看「本期所有首充／複充金額」（含老會員）得另加指標——**已確認先不做**（2026-08-21），日後有需要再提新需求。充提組（總充值／總提現）**不受此限制**，名單仍是「期間內有成功充提」的全部會員。

7. **幣別／匯率**（原題敘述不清，重寫）✅ 無需決策，照現況：
   - 所有金額在**交易當下**就已用當時匯率換算成**站點主幣別**存進資料表。
   - 看板只做加總，**不會用查詢當下的匯率重新換算**歷史資料——同一筆訂單今天查、明天查，金額都一樣。
   - 所以本看板沒有幣別參數，也沒有匯率邏輯要寫；Excel 匯出檔「匯率基準」那一列講的就是這件事。

---

## 8. 驗證待辦（2026-08-21 TPower 對照後留下）

指標對照的結論見各節；以下是還沒收尾的事項，依重要性排序。

1. ~~**回填 `member_hourly_statistics` 的自助洗分歷史**~~ ✅ **已回填 2026-05-01 起（2026-08-21）**
   - 原況：mhs 在 2026-08-17 之前只收 `withdrawal_orders.status = 24`，`status = 4`（自助洗分）
     整批沒進桶。逐站盤點（以 08-14 為樣本）：Yes2Win 少 502,552.95（近半）、TPower 少 856,255.06、
     VWorld 少 94,571.00、VPlus33 少 500.00；Alfawin77 與 Me77 無自助洗分、不受影響。
   - 已在 TPower / Yes2Win / VWorld / VPlus33 跑
     `patch:backfill-member-hour-summaries --start="2026-05-01 00:00:00" --end="2026-08-18 00:00:00"`
     （需求方指定只補近 3 個月）。回填後逐日核對 mhs 提現 = `status 24 + status 4`、且充值側未被改動。
   - **2026-05-01 之前仍缺**（TPower 的 mhs 從 2023-05-31 就有資料）。若日後要查更早區間的提現，
     需再往前回填；命令冪等（小時匯總全量覆蓋、`last_*` 走 GREATEST），可安全重跑。
   - 附帶：`summary_event_records.event_id = 8`（代付成本）不受此回填影響——它由獨立的
     `SP_summary_event_record_by_payment_W` 產出，需另行確認是否也有漏跑。

2. ~~**`SP_summary_event_record_by_payment.sql` 與正式站不同步**~~ ✅ **已修（2026-08-21）**
   - 原況：repo 檔案內層寫 `SUM(amount) AS amount`，TPower / VWorld 實際跑的是 `SUM(point) AS amount`。
     因為別名相同，外層 `acf.cost * SUM(r.amount)` 兩版長得一模一樣，只有內層一個字不同。
   - 正解是 `point`：`cost` 是費率、`point = amount * rate` 即站點主幣別；多幣別站的 `amount` 是
     會員下單幣別原值（實測某筆 `amount 50,000,000` 對 `point 7,749.7`），乘費率會差幾個量級。
     代付那支（`_W`，`event_id 8`）本來就用 `wo.amount * wo.rate`，同組成本不可能一個外幣一個主幣別。
   - repo 檔案已改為 `SUM(point)` 並加註原因，測試庫已同步。**dev DB 仍跑舊版**
     （TPower / VWorld 已是新版），需在 dev 跑 `db:seed --class=SyncProcedureSeeder` 才會生效
     ——在那之前 dev 上看到的支付手續費偏高。
   - 同類檢查：`SP_calc_profit_share_payment_cost.sql` 與 `_W` 皆已用 `point`，無同樣問題。

3. ~~**簡訊費 0.44 的未解差額**~~ ✅ **已查明（2026-08-21）**：不是 join 條件（`sgi.status = 1`
   我已補上、部署版也有），而是**簡訊 SP 也會漏跑整個小時**。TPower 實測 2026-08-03 12:00 全站
   77 筆簡訊、彙總 0 筆；該週 168 小時中有 6 小時整段遺失（活動獎勵 `event_id = 1` 同期則有 5 小時、
   漏 734.00）。
   ⇒ 這是 hourly SP 的通病：每次只處理自己那一小時、delete + insert，某次 MySQL EVENT 沒跑成功
   就永久遺失，沒有像代收／代付那樣的補算機制（`CorrectMemberHourlyStatistics` 每小時重算近 2 個整點）。
   **本看板的簡訊費仍讀這份彙總、會少算約 3%**；要根治得替 `summary_event_records` 這批 SP 加補算機制，
   或把簡訊費也改抽源頭（`sms_send_records × sms_gateway_infos.cost`，算式已驗證可重現）。

4. ~~**盤點誰在讀 `event_id = 1`**~~ ✅ **已盤點（2026-08-21）**：只有**舊版 sr11**
   （`API_Agent` 的 `ReportController@platformReconciliation{Total,Detail,Member}`，
   `ReportController.php:6014`、`:6254`）。newsr11 的 `-1` 走 `transaction_logs`、不受影響；
   其餘報表只讀 `$ar_fee`（3/4/6/7/8/9）與 `event_id = 2`；API_Frontend / API_External /
   RD-Studio / 兩個後台前端完全沒讀。
   ⇒ 現行使用者看的 sr11 頁面打的是 newsr11，所以畫面不受影響，這也解釋了缺漏為何一直沒被發現。
   要不要修那支 lottery SP 仍待決定（見上一項的根治建議）。

5. ~~**留存 9 格沒有舊報表可對照**~~ ✅ **改以單元測試把關（2026-08-21 定案）**——不做人工抽樣驗收。
   `AgentDashboardTest` 的四個留存測試涵蓋：D1／D7／D30 三格 × 登入／投注／充值三種行為各自命中、
   D2 的行為不得落進任何一格（off-by-one）、未到期批次計 0 但仍進分母、同一筆行為在 GMT+8 算 D1
   而 GMT+0 算 D0（日界隨 `tz_offset` 移動）。二階明細另有 `retention` 組的對應測試。

6. ~~**真金投注人數的口徑**~~ ✅ **定案：跟舊報表一致（2026-08-21）**——數「有注單的會員」，
   不要求有效投注 > 0。理由：`summary_bet_records.bet` 是**有效投注**，0 是正常值（零流水／取消
   的注單仍是投注行為）。BFT100 線 8/1〜8/7 因此由 420 改為 421，與舊「數據分析」一致。
   留存矩陣的「投注留存」與明細的「首次投注時間」同步移除 `bet > 0` 條件，全站口徑一致。

---

## 8. 延後（本階段不做）

- **二階**：明細下鑽（12 張表：註冊 / 首充 / 複充 / 充值 / 提現 / 投注與 GGR / 六項成本各一張 / 留存）。NGR 與代理貢獻佔比不提供明細。**粒度只到會員、無交易單號**——示意稿的逐筆交易列是錯的，見 §0-3。規劃已另立 [api-plan-phase2.md](api-plan-phase2.md)。
- ~~**三階**：匯出 Excel~~ → 已完成，做法與定案見 §9。
- `summary_bet_record_agent_teams`（代理線預彙總，目前 0 列）：若線模式在正式站量體上撐不住，再考慮補這張表的產製排程，避免每次查詢都展開 hierarchy。
- 讓 `summary_bet_record_agents` 補上即時性（改成小時排程）：本階段不碰，因為讀取端全在舊系統（見 §6-1），改排程要連舊報表與結算一起回歸測試。

---

## 9. 三階：Excel 匯出（2026-08-24 完成）

### 9-1 做法

前端產檔，**不開後端產檔端點**。新後台已有共用匯出工具（`utils/export-excel.ts` 走 `xlsx-js-style`
產真 .xlsx，`composables/use-excel-export.ts` 管 loading 與成功碼，`utils/export-audit.ts` 每次匯出
先寫一筆代理操作紀錄、寫不進去就不產檔），所有既有報表都走這條，本看板照走即可。因此三階沒有新增
任何端點，也不需要「匯出任務狀態」查詢。

兩顆按鈕：

| 按鈕 | 位置 | 分頁 |
| --- | --- | --- |
| 整頁匯出 | 查詢條件卡 | 四頁：`查詢條件`（期間 / 時區 / 代理帳號 / 統計範圍 / 計入成本 ＋ 通用規則）、`指標總覽`（含算法）、`留存矩陣`、`代理明細`（含全頁加總） |
| 明細匯出 | 明細彈窗 footer | 兩頁：該組明細的全部會員（欄位沿用後端回的 `columns`，不在前端寫第二份表頭）、`查詢條件` |

多分頁不需要新套件——`xlsx-js-style` 本來就支援，只是共用工具原本只寫一個 sheet。作法是把「組 AOA →
套樣式／欄寬／合併」抽成內部的 `buildSheet()`，`exportExcel()` 維持原簽章（既有二十幾支報表不受影響），
另外開一支 `exportExcelWorkbook({ sheets })` 逐頁寫入，稽核紀錄整份檔仍只寫一筆。

### 9-2 定案紀錄

| 項目 | 定案 | 為什麼 |
| --- | --- | --- |
| 工作表數 | **整頁 4 分頁、明細 2 分頁**（2026-08-24 改版） | 原先依需求方「整頁匯出全部集中在一頁」做成單一工作表：因為一張表只有一列表頭，只能把最寬的代理明細擺在表頭底下、其餘往下堆，結果代理沒有下級時最上面那塊只剩一列加總，看起來像重複列了一次指標。改成分頁後每頁各有正確的表頭與欄寬，這個問題消失 |
| 查詢條件 | 獨立一頁，列出期間 / 時區 / 代理帳號 / 統計範圍 / 計入成本 | 拿到檔案的人要看得出這份數字是用什麼條件跑出來的；夾在資料表裡會被當成資料列 |
| 匯出取資料 | 明細走專用端點 `detail-export`：一次回 10 萬列、**不算總筆數也不做加總**；整頁匯出沿用 `agent-list` 的 `per_page=5000`（代理列表逐層展開，同一層不會有這麼多） | 匯出檔只要資料列。畫面端點為了分頁與加總，同一份名單查詢要跑三次（`count()`、分頁、加總前的 `pluck`），投注明細在全站 × 整月光這部分就 15 秒；匯出只跑名單一次。列數頂到上限才提示截斷（`ad_export_capped`） |
| 加總列 | 取匯出這一次請求回的 `total_summary` | 畫面上的「本頁加總」只涵蓋當前 10 列，放進匯出檔會對不上 |
| 成本開關 | 把當下計入 NGR 的可選成本寫進「查詢條件」分頁 | 總成本 / NGR / 貢獻佔比會隨開關變動，拿到檔案的人要看得出是哪個組合 |
| 時區 | 檔名與區間列標 `GMT±N`，值為畫面所選時區 | 與 target/tickets#4095 全站規則一致；本看板端點本來就是時區感知的 |

### 9-3 效能實測（TPower，Admin ＝ 全站，2026-07 整月）

| 明細組 | 名單人數 | `per_page=25` | `per_page=5000` |
| --- | --- | --- | --- |
| 註冊 | 3,391 | — | 1.4s |
| 充值 | 13,649 | 5.1s | 5.9s |
| 投注 | 16,010 | 35.9s | 37.3s |
| 活動成本 | 1,314 | — | 10.4s |

**分頁大小幾乎不影響耗時**（投注組 25 筆與 5000 筆差 1.4s），成本落在「名單查詢＋全頁加總」這兩段固定
開銷，所以匯出把 `per_page` 開到 5000 不會讓事情變糟。

反過來說，**投注明細在「全站 × 整月」約 36 秒是二階就存在的問題，不是匯出造成的**。逐段量測（同上條件）：

| 段落 | 耗時 | 說明 |
| --- | --- | --- |
| `roster()->count()`（總筆數） | 5.4s | `whereIn` 掛的是子查詢（`betMemberIds()` 回 Builder，不是陣列），但 MySQL 每次都要把一個月的 `summary_bet_records` 去重物化一次 |
| 分頁取當頁 25 筆 | 4.9s | 同一份 roster 再跑一次 |
| `totalSummary()` 的 `pluck('m.id')` | 4.8s | 同一份 roster 再跑第三次，把 16,010 個 id 撈回 PHP |
| `totalSummary()` 的 `betValues()` | **21.7s** | 帶著 16,010 個 id 的 `whereIn` 回頭聚合 `summary_bet_records`，再在 PHP 逐列 `Common::numAdd` 加三個金額欄 |

也就是說瓶頸有兩處：**同一份 roster 被執行三次**（約 15s），以及**全頁加總把整份名單撈回 PHP 再 `whereIn` 聚合**
（約 22s，見 `AgentDashboardDetailService::totalSummary()`）。代理層級的查詢範圍小得多、不受影響；若要讓全站
查詢也能用，方向是把全頁加總改成單一聚合 SQL（roster 當子查詢 join，不要把 id 撈回 PHP）、並讓 `count()` 與
分頁共用一次物化，或另做預彙總（見 §8 `summary_bet_record_agent_teams`）。
