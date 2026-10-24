# 大表 DDL 操作流程與 TBR 重建設計

> 產出：2026-09-02｜RB 與 AI 對話式收斂，僅收錄已拍板決策
>
> **適用場景**：`total_bet_records`（TBR）級大表的結構變更（加欄位＋加索引＋改主鍵／分區）。
> 本設計實質構成多幣別錢包 **Gate 1「原表加欄位 vs 新表遷移」的答案**（新結構重建、一次到位），
> 拍板 Gate 1 時以本檔為輸入；`currency_id` 依決策 9 同批整合。

---

## 環境事實（2026-09-02 實測，決策依據）

| 項目 | 實測值 | 出處 |
|---|---|---|
| DB | GCP Cloud SQL，MySQL `8.0.40-google`（無 SUPER） | `@@version` |
| binlog | `log_bin=ON`、`ROW` 格式、`FULL` row image、GTID ON | `@@binlog_format` 等 |
| TBR 量級（TPower 主庫 `xsgcasino`） | **21.1 億列、資料 407GB＋索引 478GB ≈ 885GB** | `information_schema.tables` |
| 現行分區 | `LIST(vendor_id)`，298 分區 | `information_schema.partitions` |
| 主鍵 | 複合 `(id, vendor_id)` | `information_schema.statistics` |
| 唯一鍵 | `(vendor_id, bet_record_id)`（冪等鍵）＋6 個二級索引 | 同上 |
| 寫入量 | 約 2,400 萬筆／日（RB 提供） | — |
| 多庫 | TPower 站另有 `xsgcasino_hk`（283 萬列、198 分區）——**天然彩排場** | `information_schema.partitions` |

---

## 決策表

| # | 決策 | 內容 | 理由 |
|---|---|---|---|
| 1 | **工法＝影子表重建** | 建新結構表（新欄位＋新索引＋新主鍵／分區一次到位）→ trigger 先行 → 批次搬存量 → 增量同交易同步 → 原子 `RENAME` 切換 | 要動主鍵／分區，原生 INSTANT／INPLACE 蓋不到；trigger 先行讓搬遷全程的 INSERT／UPDATE／DELETE 即時反映到新表，不需補差、追平判定單純（先搬後掛會漏「掛前 UPDATE」，補差需全量比對） |
| 2 | **工具＝pt-online-schema-change 主幹** | 搬遷交給 pt-osc；自建 preflight 與事後對帳兩支 artisan（見決策 5） | gh-ost 被複合主鍵（github/gh-ost#1540）＋ Cloud SQL 分區表 `CREATE TABLE LIKE` 問題（#863）刷掉；pt-osc 為業界最老牌工具（2012 起、Percona 維護、Perl 實作），分區表與複合主鍵皆支援，把手工方案的 chunk 邊界／trigger 競態／cutover 原子性／異常清理全部內建 |
| 3 | **節流** | `--chunk-size=N`（固定每 N 筆一批）或 `--chunk-time`（動態批次）＋`--sleep`＋`--max-load Threads_running=N`（超標自動暫停）＋`--critical-load`（保命中止） | 完整重現原構想「每批寫入後 sleep」且更細；壓力從一次性衝擊變成可調速長跑 |
| 4 | **回滾＝舊表唯讀保留、不反向同步** | 切換後舊表凍結存查，觀察期滿才清；回滾＝改名切回＋切換後差量以反向搬遷補回（新欄位丟棄） | 零寫入放大、流程簡單；切換前驗證夠嚴則回滾機率極低。反向 trigger 熱備的雙倍寫入放大在高併發表上代價過高 |
| 5 | **自建兩支 artisan 命令** | ① preflight 檢查：磁碟餘裕（≥ 表大小×2）、長交易（`information_schema.innodb_trx`）、`lock_wait_timeout` 設定、結構快照、逐站**逐 schema** 盤點（`xsgcasino_hk` 教訓）、trigger 權限、執行環境確認；② 事後對帳：按 vendor 三表 SUM（複用 SBR 案工法）＋列數＋唯一鍵抽驗 | pt-osc 管搬遷，正確性驗證由自己人代碼把關；對帳按 vendor 切與既有「遊戲商×時段」並行模型一致 |
| 6 | **執行環境＝GCP 內網 VM** | 正式執行在內網 VM 以 tmux／nohup 掛跑；本地（VPN）只做 dev 庫 PoC 與參數演練 | pt-osc **連線斷＝中止＝砍新表重頭來**（無斷點續傳），週級工程經 VPN 跑不可接受；內網 RTT 1~2ms vs VPN 50~100ms，幾十萬批次的往返開銷差數小時以上 |
| 7 | **分區設計＝`RANGE(bet_time)` 按月** | 時間冷熱分離：歷史分區整月歸檔／卸載，熱表變小；一分區約數千萬列／20~30GB；放棄 vendor pruning（彙總查詢帶時間窗可用時間 pruning，vendor 等值靠既有二級索引） | 21 億列主因是歷史累積、查詢集中近期；按月是歸檔窗口彈性與分區數可控的平衡點。分區時間欄選 `bet_time`（RB 拍板）——業務語意最自然 |
| 8 | **冪等兜底** | 唯一鍵弱化為 `(vendor_id, bet_record_id, bet_time)`（分區規則強制）。兜底三件套：❶ 拿第零期寫入者清單（`database/data/bet_records/writers.php`）盤點「靠唯一鍵擋重」的寫入者（`INSERT IGNORE`／`ON DUPLICATE` 型），改為寫前以 `(vendor_id, bet_record_id)` 查重或保證同單 `bet_time` 恆定；❷ 每日稽核排程掃 `(vendor_id, bet_record_id)` 重複當安全網；❸ 寫入端 `bet_time` 正規化（同一注單重送必須同值） | MySQL 分區規則＝分區鍵必含於每一個唯一鍵，躲不掉；弱化後「同單不同 `bet_time`」會穿透唯一鍵存成兩列，流入下游彙總即金流報表失真——必須制度性兜住 |
| 9 | **主鍵＝`(id, bet_time)`**（建議值） | `vendor_id` 移出主鍵、靠二級索引 | 滿足分區規則的最小主鍵形態；`id` 單調遞增維持聚簇寫入友好 |
| 10 | **`currency_id`／`vendor_info_id` 同批整合** | 多幣別第三期的幣別與商戶兩欄隨本次重建一次進新結構（09-03 決議⑭：BR 表不加欄位，兩欄只記在 TBR，因此 `vendor_info_id` 也必須在此進表） | 885GB 工程不走兩次 |

---

## 🔴 操作紅線（必記）

- **pt-osc 必帶 `--no-drop-old-table`**：預設在切換成功後**直接 DROP 舊表**——不帶此參數，決策 4 的回滾設計整個失效。
- **一律 `--dry-run` ＋ `--print` 先行**，人工過目它要執行的 SQL 才實跑（符合專案 dry-run 紀律）。
- **MDL 防護**：執行前查無長交易、session 級 `lock_wait_timeout` 設短（約 10 秒）——搶不到鎖退避重試，不掛著堵死全表；pt-osc 以 `--set-vars`＋`--tries` 落實。
- **中途失敗的清理**：pt-osc 正常中止會自動清（drop trigger×3＋新表）；`kill -9` 殘留物照官方程序手動清——殘留 trigger 不影響原表資料正確性，但有寫入開銷，發現即清。
- **切換後才是不可逆區**：切換前原表零風險（只被讀取＋掛 trigger），影子表隨時可整張丟掉重來。

---

## 待確認（open items）

| 項 | 說明 |
|---|---|
| 補單回溯窗口 | 決定「幾個月前的分區才算冷、可歸檔」——遲到寫入（補單／結算修正）會落在舊分區，歸檔線必須大於回溯上限 |
| Cloud SQL 磁碟餘裕 | 重建需 ≥900GB 臨時空間＋binlog 增量，逐站實查 |
| dev 庫 PoC | pt-osc 在 Cloud SQL＋LIST 分區＋複合主鍵組合的完整參數演練 |
| 六站×多庫執行順序 | 建議 `xsgcasino_hk`（283 萬列）當正式環境彩排場，通過後才上主庫；六站順序另定 |
| pt-osc 參數定版 | chunk／sleep／max-load 的具體數值由 PoC 與彩排結果定 |
| 主鍵定版 | 決策 9 為建議值，實作前最終確認 |
| 每日稽核排程實作 | 決策 8 的安全網（掃 `(vendor_id, bet_record_id)` 重複）歸入哪期實作待排 |

---

## 參考來源

- [gh-ost 複合主鍵限制（issue #1540）](https://example.invalid/github/gh-ost/issues/1540)、[Cloud SQL 分區表問題（issue #863）](https://example.invalid/github/gh-ost/issues/863)
- [gh-ost vs pt-osc（Bytebase）](https://example.invalid/blog/gh-ost-vs-pt-online-schema-change/)、[State of Online Schema Migrations in MySQL（PlanetScale）](https://example.invalid/blog/state-of-online-schema-migrations-in-mysql)
- [spirit（Block）](https://example.invalid/block/spirit)——本次未選用（Cloud SQL＋LIST 分區＋複合主鍵組合未驗證），具 checkpoint／resume 能力，未來重評估時的候選
