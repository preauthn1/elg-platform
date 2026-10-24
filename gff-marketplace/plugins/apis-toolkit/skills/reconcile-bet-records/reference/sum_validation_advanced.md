# SUM 驗證 — 進階與陷阱

> **何時讀這份**：Phase C Step 5「跑完 SBR 仍不對齊」要做差異分析時；或處理跨白標／跨日 correction 相關問題時。

基本 SQL 範本在主 SKILL.md 的「基本 SUM 驗證 SQL 範本」一節。本檔處理：容忍度、時區、SR11 切口差異、跨白標、常見誤判。

---

## 1. SUM 對齊容忍度

實務經驗上達成下列即視為對齊：

- ✅ `BR (已結算) sum_bet` 與 `TBR sum_bet` **完全一致**（核心指標）
- ✅ `TBR sum_valid_bet` 與 `SBR sum_bet` **完全一致**
- ✅ `BR sum_bet` 與 vendor 三方 sum **完全一致**
- ⚠️ status=未結算 殘留視為「待結算負債」，**不計入月帳營收**

不該出現的情況：
- ❌ 「差距很小應該可以接受」— SBR 是十進制 decimal，沒有浮點誤差，差任何值都是 bug
- ❌ 「只差 1 筆少數位」— 通常是時區切口或 correction 漏算

---

## 2. GMT+8 → UTC 對照

| GMT+8 月 | UTC 開始 | UTC 結束（exclusive） |
|---|---|---|
| 4 月（4/1~4/30） | 2026-03-31 16:00:00 | 2026-04-30 16:00:00 |
| 5 月（5/1~5/31） | 2026-04-30 16:00:00 | 2026-05-31 16:00:00 |

**通用公式**：`月份第 1 天 00:00 GMT+8 = 前月最後一天 16:00:00 UTC`

部分 vendor（如 KissH5）後台用 SGT (UTC+8) 對帳，但 callback 是 UTC — 換算前先確認該 vendor 的時區規則。見對應 `docs/reconcile/vendors/{name}.md`。

### 2.1 每日 breakdown：先平移再取日（GMT+8 分組）

Step 2 範本是整段單一 SUM、無分組。若改做**逐日** breakdown（`GROUP BY` 日期），平移與取日的順序會影響每日歸屬：

| 寫法 | 結果 |
|---|---|
| `GROUP BY DATE(payout_time)` | 切 UTC 日界，非 GMT+8 |
| 投影 `DATE_ADD(DATE(payout_time), INTERVAL 8 HOUR)` | 先取 UTC 日再加 8 小時，與 GROUP BY 切口不一致，每日金額錯位 |
| ✅ `DATE(payout_time + INTERVAL 8 HOUR)` | 先平移再取日；GROUP BY 與投影同一套切口，歸屬正確 |

GROUP BY 與 SELECT 投影必須用同一個表達式。

---

## 3. 常見誤判陷阱

| 誤判 | 正確做法 |
|---|---|
| 用 `summary_time` 查 SBR | 改用 `data_time`（correction 列的 summary_time = 補帳當天，會漏） |
| 用 `summary.bet` 對 `total.bet` | 應對 `total.valid_bet`（SBR 存的是 SUM(valid_bet)） |
| 用 `xxx.reference_no` join TBR | 應用 `xxx.bet_id = total.reference_no` |
| 忽略 correction 列日期 | correction：`data_time`=原始小時、`summary_time`=補帳當天 |
| `whereBetween('time', [start, end])` | end 那一秒會被包進去；改用 `>= start AND < end` |
| 直接 `bcmul` / 浮點比較 | 違反專案規範；用 `Common::numAdd/Sub/Mul/Div/Compare` |
| `bet_id` 用 int 比較 | 17~18 位整數 PHP int 會走 float 命中相鄰記錄；必須以 string 處理：`where('bet_id', (string) $betId)` |

---

## 4. SR11 / 外部對帳工具切口（跨日 correction 分析）

**SR11** 等公司端報表（依 OP 經驗推測讀 `summary_bet_records`，未在 APIs repo 驗證，見 `policies.md` 的「下游報表資料來源」一節）若以 `summary_time` 切日（寫入時刻），而工程師內部對齊用 `data_time`（注單實際發生小時）。兩種切口語意不同：

| 切口 | 語意 | 用途 |
|---|---|---|
| `data_time` | 注單實際發生的小時桶 | 與 vendor 後台 Win/Lose Report、BR、TBR 同口徑 |
| `summary_time` | SBR 列被寫入的時刻 | SR11 / 會計「當日入帳」視角 |

### 何時兩者會偏離

該日 SBR 含 `correction=1` 列、且 `data_time` 與 `summary_time` 落在不同日。常見觸發：

- vendor 延遲 callback / DataFeed 補抓 / 注單狀態變更（撤單、延後派彩、jackpot 結算）
- `SummaryBetRecordCommand` 在已關帳的歷史小時補帳 → 走 correction 模式
- TW 同理（即使 vendor 未跑 SBR 命令，BR→TBR 同步 SP 走 ON DUPLICATE KEY UPDATE 也可能觸發跨日修正）

### 判讀公式

以下假設 SR11 讀 SBR 且以 summary_time 切日（未驗證，見上）。

```
SR11(date)  =  SBR.data_time(date)
            −  Σ correction 列 with data_time∈(date) ∧ summary_time∉(date)   -- 漏看
            +  Σ correction 列 with data_time∉(date) ∧ summary_time∈(date)   -- 多看
```

**重要推論**：「內部 BR=TBR=SBR(data_time) 對齊」**不保證**「SR11 對齊」，只在當日 SBR 無跨日 correction 時等價。

### 當 SR11 數字與我方對齊不上，但 BR/TBR/SBR(data_time) 三表已對齊時

**第一動作**：跑下方 SQL 找跨日 correction 列，確認是切口問題還是真有差異。

```sql
-- 該日 SR11(summary_time) 與 內部對齊(data_time) 的差額來自哪些 correction 列
SELECT id, member_id, game_id, data_time, summary_time, bet, win_lose, record_count
FROM summary_bet_records
WHERE vendor_id = ?
  AND correction = 1
  AND (
       (data_time    >= @utc_start AND data_time    < @utc_end
          AND (summary_time < @utc_start OR summary_time >= @utc_end))
    OR (summary_time >= @utc_start AND summary_time < @utc_end
          AND (data_time    < @utc_start OR data_time    >= @utc_end))
  )
ORDER BY data_time;
```

### 對 OP / 客服說明範本

> 該日內部三表對齊為 X / Y，與 vendor 後台報表一致；SR11 顯示 X' / Y' 是因為 SR11 以「寫入日」切，當日有 N 筆跨日補帳列（明細見上）。兩個數字都對，只是切口不同 — vendor 對帳取「data_time」切；公司資金流向取「summary_time」切。

---

## 5. 跨白標 DB 循環對比

平台多白標時，逐白標用各自的 mysql MCP 工具跑同一條查詢；白標清單讀 registry（`plugins/apis-toolkit/registry/whitelabels.metadata.yaml`），不在本檔列舉。

> 不可在一個 connection 環境用另一個 connection 的 vendor_id：各白標啟用的 vendor 不同，跨打可能查到空集合或誤判。先在每個白標跑主 SKILL.md「Step 0：vendor 解析 + 判型 + 對帳卡 gate」的查詢，確認 vendor 有啟用。

---

## 6. 「跨日 correction 切口判讀」的正確使用場景

> **⚠️ 注意適用情境**。本判讀僅在 **內部 BR ↔ TBR ↔ SBR(data_time) 三表已對齊、但外部報表（OP/SR11 用 summary_time 切口）不一致** 時使用。

**不可用於**：
- Phase C Step 5 「跑完 SBR 後 TBR↔SBR(data_time) 仍不等」 — 此情境是真實漏算或錯帳，必須 revert + 進 Phase D；跨日 correction 影響的是 `summary_time` 切口，不會造成 `data_time` 切口的差異。把這個情境誤判為切口問題會留下漏彙總在帳上。

### 適用場景判讀順序

進場條件：內部三表（以 `data_time` 切）對齊、但外部報表（OP 抄 summary_time 視角報表 / SR11）數字偏離。

1. **查 correction 列**（上方 §4 SQL）：當日有 `data_time` 與 `summary_time` 落不同日的 correction → 切口差異可能存在
2. **算金額**：跨日 correction 列的 `SUM(bet)` 是否約等於差額 → 確認就是切口
3. **若是切口問題** → 跟 OP/會計對齊用語（解釋 `data_time` vs `summary_time` 兩種視角都對、只是切法不同）→ **結案，不再動 BR/TBR/SBR**
4. **若不是切口問題**（無跨日 correction、或金額對不上）→ 走 Phase D 取三方查源頭

跨日 correction 屬「正常會計現象」，不修。但這只解釋外部 summary_time 切口的對帳差異，不解釋內部 data_time 切口的差異。
