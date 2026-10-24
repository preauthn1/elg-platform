---
name: large-amount-notify-debug
description: '排查大額通知（LargeAmountNotifyService）沒觸發的原因：提款、存款、遊戲商錢包轉入轉出、大額投注與派彩。當使用者說「大額通知沒發」「為什麼這筆沒觸發大額通知」「withdrawal_orders #N 沒通知」「<站> 大額通知沒響」時載入。'
---

你是 `LargeAmountNotifyService` 大額通知排查專家。

## 觸發與分流

- **常見說法**：「為什麼 XXX 沒觸發大額通知」「大額通知沒發」「withdrawal_orders #N 沒通知」「member #M 轉入 vendor 沒發大額」「大額提款通知失靈」「LargeAmountNotifyService 排查」「VWorld／TPower 大額通知沒響」。
- **做法**：依 `-e DB_CONNECTION` 注入的白標，讀容器內 bot-notify 設定（`large_amount` 的 `watch_agents`／`threshold`，與 `channel.large_amount` 的 chat id），針對訂單金額與會員 hierarchy 兩道 guard 逐項驗證，產出「該過 vs 實際」對照表與修法建議。

## 1. 通知判斷邏輯（核心）

`app/Services/LargeAmountNotifyService.php` 的 `guard()` 是所有通知的共用閘門（行號會變，以方法名 grep 定位）：

```php
private function guard(string $amount, array $hierarchyArray): bool
{
    $threshold = config('bot-notify.large_amount.threshold');   // .env LARGE_AMOUNT_THRESHOLD（預設 5000）

    return Common::numCompare($amount, (string) $threshold) >= 0       // 閘 1：金額過門檻
        && Common::isAgentInLargeAmountWatchList($hierarchyArray);     // 閘 2：hierarchy 含監控代理
}
```

`Common::isAgentInLargeAmountWatchList()`（`app/Helpers/Common.php`，以方法名 grep 定位）：

```php
$watchAgents = explode(',', config('bot-notify.large_amount.watch_agents', ''));  // .env LARGE_AMOUNT_WATCH_AGENTS
return !empty(array_intersect($watchAgents, $hierarchyArray));
```

**兩道閘只要任一不過 → 不發通知**。`guard` 通過後才呼叫 `BotNotify::channel('large_amount')->sendOnCommit(...)`，channel 對應 `.env LARGE_AMOUNT_CHAT_ID`。

---

## 2. 呼叫點地圖（所有觸發路徑）

| 方法 | 呼叫位置 | 觸發時機 | 金額參數 |
|---|---|---|---|
| `notifyWithdrawal` | `Frontend/WithdrawalController::createOrder` | 會員從 frontend 建立提款單後 | `$result['point'] = amount × bank.rate` |
| `notifyDeposit` | （目前沒人呼叫，方法保留） | — | — |
| `notifyVendorTransferOut` | `MemberTransferService::transferToLocal` | 「從遊戲商轉回本地」前 | `$localTransferAmount` |
| `notifyVendorTransferIn` | `MemberTransferService::transferToVendor` | 「從本地轉入遊戲商」前 | `$localTransferAmount` |
| `notifyLargeBetRecord` | `TotalBetRecordService::create` | 經 `create` 寫入 TBR 後 | `bet / valid_bet / rolling_chip` 任一過門檻 |
| `notifyLargePayout` | `TotalBetRecordService::create` 與 `::createOrUpdate` | 新增或更新 TBR 派彩後 | `win_lose / bonus / jackpot` 任一過門檻 |

> ⚠️ 行號會隨改動 drift，定位以**方法名**為準。

> 命名陷阱：`notifyVendorTransferOut` 是 vendor 視角，**指錢「從 vendor 轉出」回到本地餘額**（會員提錢出來），通知文案是「遊戲商大額轉出通知」。
> 反之 `notifyVendorTransferIn` 是錢「從本地轉入 vendor」（會員下分去玩），通知文案是「遊戲商大額轉入通知」。

> 🔑 **兩個最常誤判的觸發盲點**：
> 1. **轉入/轉出只有轉帳錢包會觸發**：`notifyVendorTransferIn/Out` 只在 `MemberTransferService::transferToVendor` / `transferToLocal` 被呼叫，兩個方法都強型別限定 `AbstractTransferWallet`。單一錢包（`AbstractSingleWallet`）共享餘額、靠回調扣款，沒有「本地↔vendor」轉帳動作，**不會進 `MemberTransferService`，也就不觸發轉入/轉出通知**。
> 2. **投注通知只走 `create` 路徑**：`notifyLargeBetRecord` 只在 `create()` 被呼叫；`createOrUpdate()` 若 TBR 已存在，只發 `notifyLargePayout`、**不發投注通知**。投注/派彩分開結算的 vendor（如 TfgamingSW、SpadegamingSW）對既有 TBR 更新時，投注通知不會再發，這是「大額投注沒觸發」的頭號原因。

---

## 3. 排查 SOP（每次照走）

### Step 0 — 解析使用者意圖

從訊息抓三件事：
1. **通知類型**：提款 / 存款 / vendor 轉入 / vendor 轉出 / 大額投注 / 大額派彩
2. **定位資料**：
   - 訂單號（`withdrawal_orders.id` / `deposit_orders.id`）
   - 或會員（`member_id` / `username`）+ 大概時間
   - 或 TBR `reference_no`
3. **是哪個環境**（VWorld / TPower / others）→ 跟 `DB_CONNECTION` 比對

不確定就先列出「我目前理解是 X，對嗎？」再動 DB。

### Step 1 — 環境健檢

環境依 `-e DB_CONNECTION=<白標>` 注入；`LARGE_AMOUNT_*` 讀容器內 config（`config('bot-notify.large_amount.*')`），本機值不等於生產值。

對照表：

| 容器內設定（以 `config()` 讀到的值為準） | 預期 | 用途 |
|---|---|---|
| `config('database.default')`（`-e DB_CONNECTION` 注入） | 與使用者口中的環境一致（VWorld / TPower / …） | 不一致 → 立即停下確認，**不要用錯資料庫的查詢做結論** |
| `config('bot-notify.large_amount.watch_agents')`（`LARGE_AMOUNT_WATCH_AGENTS`） | 監控的「總代 agent_id」清單（逗號分隔） | 空字串 → 整站永遠不發 |
| `config('bot-notify.large_amount.threshold')`（`LARGE_AMOUNT_THRESHOLD`） | 整數（預設 5000） | 沒設用預設 |
| `config('bot-notify.channel.large_amount')`（`LARGE_AMOUNT_CHAT_ID`） | Telegram chat_id | 兩閘都過卻沒收到 → 看這裡是否指錯群 |

> 本機 `.env` 是本機開發用，**不等於 production**。若使用者問的是生產環境的訂單，要在結論前提醒「本機 .env 可能與生產不同，watchlist / threshold 以生產為準」。

### Step 2 — 抓金額 & hierarchy

按通知類型套對應查詢（見 §4）。每次都要得到：

- **金額**（傳給 guard 的 amount）
- **會員 hierarchy array**（傳給 guard 的 hierarchyArray）

### Step 3 — 套 guard 兩道閘

固定產出這張表：

| 檢查項 | 預期 | 實際 | 結論 |
|---|---|---|---|
| DB_CONNECTION | （使用者目標環境） | （容器內 `config('database.default')`） | ✓/✗ |
| 金額（point） | ≥ threshold | （查到的值） | ✓/✗ |
| threshold | 不適用 | （容器內 `config('bot-notify.large_amount.threshold')`，未設為 5000） | 不適用 |
| 會員 hierarchy | 與 watchlist 有交集 | `[-1, ..., 1, -2]` | — |
| watchlist | 不適用 | （容器內 `config('bot-notify.large_amount.watch_agents')` 解析後 array） | ✓/✗ |
| guard 結論 | 過 → 應發通知 | — | — |

### Step 4 — 若 guard 兩道都過卻仍沒發

依序檢查：
1. **caller 真的有被執行嗎**？例如 frontend 提款若仍走 legacy `API_Frontend`（PHP 7.4），APIs 的 `notifyWithdrawal` 從沒被呼叫過（看 `storage/logs/` 是否有 `Withdrawal Order Created` log）
2. **TBR 通知**：`notifyLargeBetRecord` 要 `bet / valid_bet / rolling_chip` 任一過門檻；`notifyLargePayout` 要 `win_lose / bonus / jackpot` 任一過門檻——若使用者以為 `bet` 過了就會發 payout 通知就誤會
3. **Telegram chat_id**：`LARGE_AMOUNT_CHAT_ID` 指向的群是否還活著、bot 是否還在群裡
4. **Log 證據**：通知發出時會 `Log::info('大額通知已發送', ...)`（`LargeAmountNotifyService::send()`，緊接在 `sendOnCommit()` 之後），grep log 看有沒有
5. **交易是否 rollback**：通知經 `sendOnCommit()` 送出，外層交易回滾就不送（log 仍可能先寫）。查同一時段該訂單／TBR 是否實際落地。

---

## 4. 各通知類型查詢配方

### 4.1 提款（`notifyWithdrawal`）

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan tinker --execute '
\$orderId = 1376088;   // ← 改這裡

\$dbConn = config(\"database.default\");
\$watch  = array_filter(array_map(\"trim\", explode(\",\", (string) config(\"bot-notify.large_amount.watch_agents\", \"\"))));
\$thr    = (int) config(\"bot-notify.large_amount.threshold\");

\$order = DB::table(\"withdrawal_orders\")->where(\"id\", \$orderId)->first();
if (! \$order) { echo \"❌ order #\$orderId 不存在於 \$dbConn\\n\"; return; }

\$member = DB::table(\"members\")->where(\"id\", \$order->member_id)->first();
\$agent  = DB::table(\"agents\")->where(\"id\", \$member->parent)->first();
\$hier   = json_decode(\$agent->hierarchy, true) ?? [];

\$point  = App\\Helpers\\Common::numMul((string) \$order->amount, (string) \$order->rate, 4);
\$thrOk  = App\\Helpers\\Common::numCompare(\$point, (string) \$thr) >= 0;
\$watchOk= !empty(array_intersect(\$watch, array_map(\"strval\", \$hier)));

printf(\"🌐 DB=%s  order #%d  amount=%s rate=%s → point=%s\\n\", \$dbConn, \$orderId, \$order->amount, \$order->rate, \$point);
printf(\"👤 member=%s (id=%d) parent_agent=%s (id=%d)\\n\", \$member->username, \$member->id, \$agent->username, \$agent->id);
printf(\"   hierarchy=%s\\n\", \$agent->hierarchy);
printf(\"🚪 閘1 threshold: point(%s) >= %d ? %s\\n\", \$point, \$thr, \$thrOk ? \"✓\" : \"✗\");
printf(\"🚪 閘2 watchlist: [%s] ∩ hierarchy ? %s\\n\", implode(\",\", \$watch), \$watchOk ? \"✓\" : \"✗ 無交集\");
printf(\"📣 結論: %s\\n\", (\$thrOk && \$watchOk) ? \"應發通知（若沒收到 → 查 caller 是否執行 / chat_id / log）\" : \"不該發（guard 擋下）\");
'"
```

### 4.2 遊戲商錢包轉入 / 轉出（`notifyVendorTransferIn/Out`）

轉帳通知沒有「訂單表」可查（它是即時 service 呼叫）。要靠 `transaction_logs` + member.parent.hierarchy 還原。

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan tinker --execute '
\$memberId = 891751;                  // ← 會員 id
\$vendorId = 83;                       // ← 遊戲商 id（可選，留 0 = 不過濾）
\$from     = \"2026-05-14 00:00:00\";   // ← 起
\$to       = \"2026-05-15 00:00:00\";   // ← 訖

\$dbConn = config(\"database.default\");
\$watch  = array_filter(array_map(\"trim\", explode(\",\", (string) config(\"bot-notify.large_amount.watch_agents\", \"\"))));
\$thr    = (int) config(\"bot-notify.large_amount.threshold\");

\$member = DB::table(\"members\")->where(\"id\", \$memberId)->first();
\$agent  = DB::table(\"agents\")->where(\"id\", \$member->parent)->first();
\$hier   = json_decode(\$agent->hierarchy, true) ?? [];
\$watchOk= !empty(array_intersect(\$watch, array_map(\"strval\", \$hier)));

\$q = DB::table(\"transaction_logs\")->where(\"member_id\", \$memberId)
    ->whereBetween(\"created_at\", [\$from, \$to])
    ->whereIn(\"type\", [1, 2]);   // 1 = TO_VENDOR（本地→vendor）, 2 = FROM_VENDOR（vendor→本地）；見 app/Enums/Transaction/TransactionEnum.php
if (\$vendorId) \$q->where(\"vendor_id\", \$vendorId);
\$rows = \$q->orderBy(\"id\")->get();

printf(\"🌐 DB=%s  member=%s (id=%d)  hierarchy=%s\\n\", \$dbConn, \$member->username, \$memberId, \$agent->hierarchy);
printf(\"🚪 watchlist [%s] vs hierarchy: %s\\n\", implode(\",\", \$watch), \$watchOk ? \"✓\" : \"✗ 無交集\");
foreach (\$rows as \$r) {
    \$dir = \$r->type == 1 ? \"轉入vendor\" : \"轉出vendor\";
    \$thrOk = App\\Helpers\\Common::numCompare((string) \$r->point, (string) \$thr) >= 0;
    \$expect = (\$thrOk && \$watchOk) ? \"應發\" : \"不發\";
    printf(\"  [%s] %s point=%s threshold=%s → %s | %s\\n\",
        \$r->created_at, \$dir, \$r->point, \$thr, \$thrOk ? \"閘1✓\" : \"閘1✗\", \$expect);
}
'"
```

### 4.3 大額投注 / 大額派彩（`notifyLargeBetRecord` / `notifyLargePayout`）

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan tinker --execute '
\$tbrId = 0;                          // ← total_bet_records.id（任一擇一即可）
\$referenceNo = \"ppsw_xxxxxx\";       // ← 或 reference_no

\$dbConn = config(\"database.default\");
\$watch  = array_filter(array_map(\"trim\", explode(\",\", (string) config(\"bot-notify.large_amount.watch_agents\", \"\"))));
\$thr    = (int) config(\"bot-notify.large_amount.threshold\");

\$tbr = \$tbrId
    ? DB::table(\"total_bet_records\")->where(\"id\", \$tbrId)->first()
    : DB::table(\"total_bet_records\")->where(\"reference_no\", \$referenceNo)->first();
if (! \$tbr) { echo \"❌ 找不到 TBR\\n\"; return; }

\$member = DB::table(\"members\")->where(\"id\", \$tbr->member_id)->first();
\$agent  = DB::table(\"agents\")->where(\"id\", \$member->parent)->first();
\$hier   = json_decode(\$agent->hierarchy, true) ?? [];
\$watchOk= !empty(array_intersect(\$watch, array_map(\"strval\", \$hier)));

printf(\"🌐 DB=%s  TBR #%d ref=%s vendor=%d\\n\", \$dbConn, \$tbr->id, \$tbr->reference_no, \$tbr->vendor_id);
printf(\"👤 member=%s hierarchy=%s\\n\", \$member->username, \$agent->hierarchy);
printf(\"🚪 watchlist 交集: %s\\n\", \$watchOk ? \"✓\" : \"✗\");
echo \"-- notifyLargeBetRecord（bet / valid_bet / rolling_chip）--\\n\";
foreach ([\"bet\", \"valid_bet\", \"rolling_chip\"] as \$f) {
    \$ok = App\\Helpers\\Common::numCompare((string) \$tbr->\$f, (string) \$thr) >= 0;
    printf(\"  %s=%s vs %d → %s\\n\", \$f, \$tbr->\$f, \$thr, \$ok ? \"超標\" : \"未過\");
}
echo \"-- notifyLargePayout（win_lose / bonus / jackpot）--\\n\";
foreach ([\"win_lose\", \"bonus\", \"jackpot\"] as \$f) {
    \$ok = App\\Helpers\\Common::numCompare((string) \$tbr->\$f, (string) \$thr) >= 0;
    printf(\"  %s=%s vs %d → %s\\n\", \$f, \$tbr->\$f, \$thr, \$ok ? \"超標\" : \"未過\");
}
'"
```

> `notifyLargeBetRecord` 與 `notifyLargePayout` 是兩個獨立判斷——投注通知看 `bet/valid_bet/rolling_chip`，派彩通知看 `win_lose/bonus/jackpot`。使用者常以為「下大注就會發 payout 通知」是錯的。

---

## 5. 常見失敗原因排序

依過去命中率高→低：

| # | 原因 | 怎麼判斷 | 修法 |
|---|---|---|---|
| 1 | **watchlist 對不上**（換環境後 `.env` 沒同步） | guard 表閘 2 ✗ | 把當前環境的「總代 agent_id」加進 `LARGE_AMOUNT_WATCH_AGENTS` |
| 2 | **金額不過門檻** | guard 表閘 1 ✗ | 確認 amount 是否如使用者所想；若門檻訂太高 → 改 `LARGE_AMOUNT_THRESHOLD` |
| 3 | **沒用 `-e DB_CONNECTION` 注入白標，查到的是容器預設連線** | Step 1 顯示的 DB connection 與使用者目標白標不符 | 依 `whitelabel-container-exec` 帶 `-e DB_CONNECTION=<白標>` 重跑 |
| 4 | **TBR 通知欄位看錯邊**（看了 bet 卻問 payout 沒發，反之亦然） | 兩個方法分開判，看 §4.3 表 | 跟使用者澄清要查哪個方向 |
| 5 | **caller 從沒被執行**（如提款仍走 legacy API_Frontend） | log 沒 `Withdrawal Order Created` / `大額通知已發送` | 確認 ingress 路由；若決定改走 APIs → 切流量 |
| 6 | **`LARGE_AMOUNT_CHAT_ID` 指錯群 / bot 被踢出群** | log 有 `大額通知已發送` 但 Telegram 沒收到 | 換 chat_id 或重邀 bot |
| 7 | **watchlist 設成了某個下級代理，不是總代** | watchlist 與 hierarchy 交集偏低，常常剛好不中 | 統一改填「總代 role_id=11」 |

---

## 6. 輸出規範

每次排查回覆給使用者時，固定包含：

1. **訂單事實**（一個 markdown 表）
2. **guard 兩道閘結果**（§3 Step 3 的表）
3. **結論**（一句話：要發 / 不該發 + 真正卡在哪）
4. **下一步**（編號清單，2~4 個選項供使用者回「1」「2」）

不要省略表格直接給結論——對方需要看到「實際 vs 預期」對照才能決定是否動 .env。

---

## 7. 相關檔案

| 用途 | 路徑 |
|---|---|
| 服務本體 | `app/Services/LargeAmountNotifyService.php` |
| watchlist 解析 | `app/Helpers/Common.php::isAgentInLargeAmountWatchList` |
| config | `config/bot-notify.php` |
| 呼叫點 | `Http/Controllers/Frontend/WithdrawalController.php`、`Services/MemberTransferService.php`、`Services/TotalBetRecordService.php` |
| BotNotify channel | `LARGE_AMOUNT_CHAT_ID` → `large_amount` channel |
