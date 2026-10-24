# payout_time 鏡像表建置（WudiSW / FumaSW）

> 目的：`wudisw_bet_records` 與 `fumasw_bet_records` 的 `payout_time` 沒有索引，對帳查詢（`WHERE payout_time >= ? AND payout_time < ?`）會整張表掃描。
> 不分表大小一律建 `{table}_mirror` 完整鏡像表——trigger 同步新資料、MySQL EVENT 每 5 秒分段回填舊資料（三張大表：yes2win 兩張、tpower 一張）。原本寫「小表直接加索引」，2026-09-18 修訂為一律鏡像，原因見下段。
> 本流程已有自動化版：Olympus 後台「資料表加索引／加欄位」頁（`php artisan schema_operation`），兩者建立的物件同名可互通；本文件為人工逐段版。
> 全部 SQL 由人工在 DB 端逐段執行，本文件供複製。**建表、trigger、EVENT 屬生產 DDL，逐段執行、逐段核對，不要整份一次貼。**

為什麼不直接加索引（不分大小）：

1. **主庫**：實測 `ALTER TABLE ... ADD INDEX ALGORITHM=INPLACE, LOCK=NONE` 在 62GB 表上跑到失敗、被迫中斷。它不鎖表，但要把整張表讀一遍，中途不能暫停，失敗就整段白做。616MB 的表也跑了 319 秒（Cloud SQL I/O 約 2MB/s）。鏡像表回填的總讀取量一樣，但可中斷、可調速，失敗只損失一小段。
2. **從庫（read replica）**：`ALTER` 是以一句 statement 寫進 binlog，從庫收到後要**整句重跑、而且排隊序列執行**，`LOCK=NONE` 只保護主庫的寫入、對從庫沒用。TPower 2026-09-15 實測：616MB 的 `spadegamingsw_bet_records` 直接 ALTER，主庫跑完的那一刻起從庫落後 14 → 74 → **134 秒**才追平；後台讀的是從庫，那三分鐘內畫面顯示的是兩分鐘前的資料。同一天 810 萬列的 `ptsw_bet_records` 走鏡像回填 46 分鐘，從庫落後全程 ≤2 秒。

「小表直接加索引」的門檻曾設 1GB、再降 256MB，每次被實測打到就往下修一次——差異不在表大小，在「索引建置有沒有整句進 binlog」，門檻不存在，故取消。

## 名詞說明

| 名詞 | 意思 |
|---|---|
| BR | 各遊戲商原始注單表 `{vendor}_bet_records`，見 [`docs/對帳資料表與時間欄位.md`](../對帳資料表與時間欄位.md) |
| 鏡像表 | `{table}_mirror`，欄位與原表完全相同的副本，額外帶 `payout_time` 索引 |
| 水位（`next_id`） | 回填進度記在哪：主鍵 id 由大到小走，記錄走到哪了 |
| 下限（`min_id`） | 回填走到這個 id 就停（不含此 id）；填 0 = 整張表都補。對帳實務只回看 3 個月，填 90 天前的邊界 id 即可 |

## 現況（2026-09-03 盤點）

| 白標 | wudisw_bet_records | fumasw_bet_records |
|---|---|---|
| yes2win | ❌ 22.4GB / 977 萬列 → **鏡像表（單位二）** | ❌ 61.8GB / 9273 萬列 → **鏡像表（單位一）** |
| tpower | ❌ 23.8GB / 949 萬列 → **鏡像表（單位三）** | ✅ 已有索引（原名 `payout_time`，已改名） |
| vworld | ✅ 已加索引 | ✅ 已加索引 |
| alfawin77 | ✅ 已改名 | ✅ 已加索引 |
| me77 | ⬜ 空表，索引尚未補 | ⬜ 空表，索引尚未補 |
| vplus33 | ⬜ 空表，未驗證 | ⬜ 空表，未驗證 |

索引名一律 `{table}_payout_time_index`。三張大表的主鍵 `id` 實際都是 `int unsigned`（migration 寫 `id()` 但線上是 INT，以線上為準）。

### 小表補索引（me77 / vplus33 待跑）

小表也走鏡像流程，直接用 Olympus 後台「資料表加索引／加欄位」頁或 `php artisan schema_operation --action=index`；空表建立當下進度列就標 `done=1`，馬上可以 `--action=rename` 換表轉正。

> 原本此處貼的是 `ALTER TABLE ... ADD INDEX ..., ALGORITHM=INPLACE, LOCK=NONE` 的直接加索引 SQL。錯在只看主庫：從庫會整句重跑同一個 ALTER，分鐘級落後（見開頭第 2 點），故 2026-09-18 改為一律鏡像。

---

## 全部刪除（重跑前的拆除）

⚠️ 最後兩行會把已回填的資料一起刪掉。小站台的 `ADD INDEX` 是最終狀態，拆除時不要動。

```sql
DROP EVENT     IF EXISTS ev_backfill_fumasw_bet_records;
DROP EVENT     IF EXISTS ev_backfill_wudisw_bet_records;
DROP TRIGGER   IF EXISTS trg_fumasw_bet_records_mirror_ai;
DROP TRIGGER   IF EXISTS trg_fumasw_bet_records_mirror_au;
DROP TRIGGER   IF EXISTS trg_fumasw_bet_records_mirror_ad;
DROP TRIGGER   IF EXISTS trg_wudisw_bet_records_mirror_ai;
DROP TRIGGER   IF EXISTS trg_wudisw_bet_records_mirror_au;
DROP TRIGGER   IF EXISTS trg_wudisw_bet_records_mirror_ad;
DROP PROCEDURE IF EXISTS SP_Backfill_Mirror_Step;

DELIMITER $$
CREATE PROCEDURE `SP_Backfill_Mirror_Step`(IN p_src VARCHAR(64), IN p_chunk INT UNSIGNED)
proc: BEGIN
    DECLARE v_dst  VARCHAR(80);
    DECLARE v_lock VARCHAR(64);
    DECLARE v_hi   BIGINT UNSIGNED;
    DECLARE v_lo   BIGINT UNSIGNED;
    DECLARE v_min  BIGINT UNSIGNED;
    DECLARE v_done TINYINT;

    -- SQL 異常時發 TG 通知，並釋放互斥鎖
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1
                @p2 = MESSAGE_TEXT;

            DO RELEASE_LOCK(CONCAT('backfill:', p_src));

            CALL COM_Send_Error_Message(
                CONCAT('[系統通知] SP_Backfill_Mirror_Step(', p_src, ') 異常 ', @p2),
                'db'
            );
        END;

    SET v_dst  = CONCAT(p_src, '_mirror');
    SET v_lock = CONCAT('backfill:', p_src);

    -- 前一次還在跑就跳過本次，不排隊、不疊加
    IF IFNULL(GET_LOCK(v_lock, 0), 0) <> 1 THEN
        LEAVE proc;
    END IF;

    SELECT next_id, min_id, done INTO v_hi, v_min, v_done
      FROM mirror_backfill_state
     WHERE src_table = p_src;

    -- 無進度列（SELECT INTO 查無資料時變數為 NULL）或已完成即跳出
    IF v_hi IS NULL OR v_done = 1 OR v_hi <= v_min THEN
        DO RELEASE_LOCK(v_lock);
        LEAVE proc;
    END IF;

    -- 本段範圍 (v_lo, v_hi]，由大到小走，最低走到 min_id（不含）
    SET v_lo = IF(v_hi > v_min + p_chunk, v_hi - p_chunk, v_min);

    SET @sql = CONCAT('INSERT IGNORE INTO `', v_dst, '` SELECT * FROM `', p_src,
                      '` WHERE id > ', v_lo, ' AND id <= ', v_hi);
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

    UPDATE mirror_backfill_state
       SET next_id = v_lo,
           done    = IF(v_lo <= v_min, 1, 0)
     WHERE src_table = p_src;

    DO RELEASE_LOCK(v_lock);
END$$
DELIMITER ;
```

回填用 `INSERT IGNORE`（只補洞）、trigger 用 `REPLACE`（說了算）。這組不對稱是刻意的：回填讀完資料到寫入之間，那筆若剛好被結算回寫，trigger 已寫入新值，`INSERT IGNORE` 不會拿舊值蓋掉它；反過來配就會產生沒人發現的過期資料。

---

## 單位一：yes2win × FumaSW

9273 萬列 / 61.8GB。3 個月鏡像預估 **20GB 資料 + 3GB 索引**，開跑前先確認 Cloud SQL 可用空間。

執行順序不可換：**鏡像表 → trigger → 水位 → EVENT**。trigger 必須先於水位——先建 trigger 再取 `MAX(id)` 當水位，id 大於水位的新資料由 trigger 顧、小於水位的舊資料由回填顧，中間才不會有缺口。

### 1. 鏡像表

```sql
CREATE TABLE fumasw_bet_records_mirror LIKE fumasw_bet_records;

ALTER TABLE fumasw_bet_records_mirror
  ADD INDEX fumasw_bet_records_mirror_payout_time_index (payout_time);
```

`LIKE` 帶走全部欄位、主鍵、`bet_id` 唯一鍵與其他索引，但不帶外鍵——這是好事，鏡像表寫入不必每次去 `fumasw_accounts` 檢查外鍵。

### 2. Trigger

任何欄位變動都要同步（不能只看 `payout_time` 有沒有變），鏡像才會完整。

```sql
DELIMITER $$

CREATE TRIGGER trg_fumasw_bet_records_mirror_ai AFTER INSERT ON fumasw_bet_records
FOR EACH ROW
    REPLACE INTO fumasw_bet_records_mirror
        (id, bet_id, round_id, username, game_code, type, status,
         bet, valid_bet, win_lose, rolling_chips,
         bet_time, payout_time, infos, created_at, updated_at, is_free_bet)
    VALUES
        (NEW.id, NEW.bet_id, NEW.round_id, NEW.username, NEW.game_code, NEW.type, NEW.status,
         NEW.bet, NEW.valid_bet, NEW.win_lose, NEW.rolling_chips,
         NEW.bet_time, NEW.payout_time, NEW.infos, NEW.created_at, NEW.updated_at, NEW.is_free_bet)$$

CREATE TRIGGER trg_fumasw_bet_records_mirror_au AFTER UPDATE ON fumasw_bet_records
FOR EACH ROW
    REPLACE INTO fumasw_bet_records_mirror
        (id, bet_id, round_id, username, game_code, type, status,
         bet, valid_bet, win_lose, rolling_chips,
         bet_time, payout_time, infos, created_at, updated_at, is_free_bet)
    VALUES
        (NEW.id, NEW.bet_id, NEW.round_id, NEW.username, NEW.game_code, NEW.type, NEW.status,
         NEW.bet, NEW.valid_bet, NEW.win_lose, NEW.rolling_chips,
         NEW.bet_time, NEW.payout_time, NEW.infos, NEW.created_at, NEW.updated_at, NEW.is_free_bet)$$

CREATE TRIGGER trg_fumasw_bet_records_mirror_ad AFTER DELETE ON fumasw_bet_records
FOR EACH ROW
    DELETE FROM fumasw_bet_records_mirror WHERE id = OLD.id$$

DELIMITER ;
```

用 `REPLACE INTO` 不用 `ON DUPLICATE KEY UPDATE`：表上有兩個唯一鍵（`id` 與 `bet_id`），後者撞到哪一個不確定，可能改到錯的列；`REPLACE` 意思明確——衝突就整列換掉。

⚠️ 欄位是明列的（trigger 不支援 `NEW.*`）。日後原表加欄位，這裡不報錯、只會靜默漏那一欄——**原表改結構必須回來改 trigger**。

### 3. 水位

先找「90 天前的邊界 id」：用主鍵點查逼近（每次查一筆，瞬間），2–3 次就夠。精度不用高，寧可 id 抓小一點多補一段，不要少補。

```sql
-- 從「最大 id × 比例」起猜，看該 id 的 payout_time，太新就往下猜、太舊就往上猜
SELECT id, payout_time FROM fumasw_bet_records WHERE id >= 85000000 ORDER BY id LIMIT 1;
```

把逼近到的 id 填進 `min_id`（填 0 = 整張表都補）：

```sql
INSERT INTO mirror_backfill_state (src_table, next_id, min_id, done)
SELECT 'fumasw_bet_records', IFNULL(MAX(id), 0), {90天前的邊界id}, 0
  FROM fumasw_bet_records
ON DUPLICATE KEY UPDATE next_id = VALUES(next_id), min_id = VALUES(min_id), done = 0;
```

### 4. EVENT

```sql
CREATE EVENT ev_backfill_fumasw_bet_records
ON SCHEDULE EVERY 5 SECOND
ON COMPLETION PRESERVE
DO CALL SP_Backfill_Mirror_Step('fumasw_bet_records', 20000);
```

間隔 5 秒不用 1 秒：有鎖之後每秒不會壞，只是等於全速回填，跟當初要避開的長時間 ALTER 一樣壓 I/O。嫌慢再調：`ALTER EVENT ev_backfill_fumasw_bet_records ON SCHEDULE EVERY 2 SECOND;`。

EVENT 只在主庫跑（read replica 上是停用狀態）。本專案兩個 DB 本來就各有五六十支 EVENT 在跑彙總，這不是新機制。

### 5. 監看與驗證

```sql
-- 進度：next_id 往下走到 min_id，done=1 即完成
SELECT src_table, next_id, min_id, done, updated_at
  FROM mirror_backfill_state WHERE src_table = 'fumasw_bet_records';

-- EVENT 活著沒
SELECT EVENT_NAME, STATUS, LAST_EXECUTED
  FROM information_schema.EVENTS
 WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME LIKE 'ev_backfill%';

-- trigger 在動沒（建完隔幾分鐘查，應大於 0）
SELECT COUNT(*) FROM fumasw_bet_records_mirror
 WHERE id > (SELECT next_id FROM mirror_backfill_state
              WHERE src_table = 'fumasw_bet_records');
```

單日抽查，兩邊必須完全一致（⚠️ 會掃原表，只跑一次、範圍一天，不要放大）：

```sql
SELECT 'src' s, COUNT(*) c, SUM(bet) b, SUM(win_lose) w FROM fumasw_bet_records
 WHERE payout_time >= '2026-09-01 00:00:00' AND payout_time < '2026-09-02 00:00:00'
UNION ALL
SELECT 'new', COUNT(*), SUM(bet), SUM(win_lose) FROM fumasw_bet_records_mirror
 WHERE payout_time >= '2026-09-01 00:00:00' AND payout_time < '2026-09-02 00:00:00';
```

### 6. 完成收尾

`done = 1` 後 EVENT 不會自己關，只是每 5 秒空轉一次（單列主鍵讀），成本可忽略。確認 `done = 1` 且抽查一致後：

```sql
DROP EVENT ev_backfill_fumasw_bet_records;
```

---

## 單位二：yes2win × WudiSW

977 萬列 / 22.4GB，每列約 2.5KB（`infos` JSON 較胖），所以每段筆數調小成 8000。共用物件同一個 DB 已建過，直接從鏡像表開始。

### 1. 鏡像表

```sql
CREATE TABLE wudisw_bet_records_mirror LIKE wudisw_bet_records;

ALTER TABLE wudisw_bet_records_mirror
  ADD INDEX wudisw_bet_records_mirror_payout_time_index (payout_time);
```

### 2. Trigger（14 欄，比 FumaSW 少 `round_id`、`type`、`is_free_bet`）

```sql
DELIMITER $$

CREATE TRIGGER trg_wudisw_bet_records_mirror_ai AFTER INSERT ON wudisw_bet_records
FOR EACH ROW
    REPLACE INTO wudisw_bet_records_mirror
        (id, bet_id, username, game_code, status,
         bet, valid_bet, win_lose, rolling_chips,
         bet_time, payout_time, infos, created_at, updated_at)
    VALUES
        (NEW.id, NEW.bet_id, NEW.username, NEW.game_code, NEW.status,
         NEW.bet, NEW.valid_bet, NEW.win_lose, NEW.rolling_chips,
         NEW.bet_time, NEW.payout_time, NEW.infos, NEW.created_at, NEW.updated_at)$$

CREATE TRIGGER trg_wudisw_bet_records_mirror_au AFTER UPDATE ON wudisw_bet_records
FOR EACH ROW
    REPLACE INTO wudisw_bet_records_mirror
        (id, bet_id, username, game_code, status,
         bet, valid_bet, win_lose, rolling_chips,
         bet_time, payout_time, infos, created_at, updated_at)
    VALUES
        (NEW.id, NEW.bet_id, NEW.username, NEW.game_code, NEW.status,
         NEW.bet, NEW.valid_bet, NEW.win_lose, NEW.rolling_chips,
         NEW.bet_time, NEW.payout_time, NEW.infos, NEW.created_at, NEW.updated_at)$$

CREATE TRIGGER trg_wudisw_bet_records_mirror_ad AFTER DELETE ON wudisw_bet_records
FOR EACH ROW
    DELETE FROM wudisw_bet_records_mirror WHERE id = OLD.id$$

DELIMITER ;
```

### 3. 水位

邊界 id 逼近方式同單位一（主鍵點查 2–3 次）：

```sql
INSERT INTO mirror_backfill_state (src_table, next_id, min_id, done)
SELECT 'wudisw_bet_records', IFNULL(MAX(id), 0), {90天前的邊界id}, 0
  FROM wudisw_bet_records
ON DUPLICATE KEY UPDATE next_id = VALUES(next_id), min_id = VALUES(min_id), done = 0;
```

### 4. EVENT

```sql
CREATE EVENT ev_backfill_wudisw_bet_records
ON SCHEDULE EVERY 5 SECOND
ON COMPLETION PRESERVE
DO CALL SP_Backfill_Mirror_Step('wudisw_bet_records', 8000);
```

### 5. 監看、驗證、收尾

同單位一，表名換成 `wudisw_bet_records`／`wudisw_bet_records_mirror`，EVENT 名 `ev_backfill_wudisw_bet_records`。

⚠️ 前一輪（窄側表版）曾驗出異常：水位設定後原表 `MAX(id)` 長了 4 筆，trigger 卻一筆都沒寫進側表；同樣寫法在 tpower 正常產出 92 筆。這次重建後**務必**跑步驟 5 的 trigger 檢查——若還是 0，問題不在 SQL，在 yes2win 這張表的寫入路徑，要另查。

---

## 單位三：tpower × WudiSW

949 萬列 / 23.8GB。內容與**單位二完全相同**（同一張表名、同一組 trigger／EVENT 名），只是換到 tpower 的 DB 執行；**共用物件在 tpower 要先各建一次**。

---

## 完成後的兩種結局

| 回填範圍 | 結局 | 怎麼做 |
|---|---|---|
| 全量（`min_id = 0`） | **換表轉正**：鏡像表原子換成原表，查詢照舊查原表名、從此有索引 | 面板／`php artisan schema_operation --action=rename`（防呆較齊），或下方手動 SQL |
| 部分（`min_id > 0`） | 兩種收法：**（a）換表轉正**——線上表只剩 `id > min_id` 的近期資料，`id <= min_id` 的舊列只留在退場表 `{table}_YYYYMMDD`，報表／對帳／會員歷史查不到這段，確認退場表不再需要前不要 `drop_retired`；**（b）留鏡像表**——收掉 EVENT、對帳改查 `{table}_mirror` | （a）面板／`--action=rename`，乾跑會列出將消失的筆數；（b）手動 `DROP EVENT ev_backfill_{table}`，照下節查法 |

### 換表轉正（done = 1；部分回填亦可，舊資料只留退場表）

原子核心是單一多表 `RENAME TABLE`（全有或全無、換表瞬間持 MDL 擋併發寫入）。
無資料縫隙：換表前每筆寫入由 trigger 同交易同步進鏡像，換表後寫入直接進新表——與 pt-online-schema-change 的 cutover 同模型。

換表前先確認：兩表 `MAX(id)` 相同（不同代表 trigger 曾停擺）；沒有其他表以外鍵指向原表；原表對外的外鍵沒有 CASCADE／SET NULL／SET DEFAULT（MySQL 由外鍵級聯改動的列**不觸發 trigger**，鏡像表會漂移，工具在建鏡像與換表兩處都會擋下，需人工處理）；原表沒有鏡像以外的 trigger。另外注意：`LIKE` 建的鏡像表**不帶外鍵**，換表後原表的外鍵不再存在。

```sql
DROP EVENT IF EXISTS ev_backfill_fumasw_bet_records;

-- 原子換表：退場表後綴用換表日期（庫內既有慣例）
RENAME TABLE `fumasw_bet_records` TO `fumasw_bet_records_20260903`,
             `fumasw_bet_records_mirror` TO `fumasw_bet_records`;

-- trigger 已隨原表跟去退場表、不再有作用，移除
DROP TRIGGER IF EXISTS trg_fumasw_bet_records_mirror_ai;
DROP TRIGGER IF EXISTS trg_fumasw_bet_records_mirror_au;
DROP TRIGGER IF EXISTS trg_fumasw_bet_records_mirror_ad;

-- 鏡像索引改回正式命名（metadata-only，瞬間）
ALTER TABLE fumasw_bet_records
  RENAME INDEX fumasw_bet_records_mirror_payout_time_index TO fumasw_bet_records_payout_time_index;

DELETE FROM mirror_backfill_state WHERE src_table = 'fumasw_bet_records';

-- 已無其他回填 EVENT 時，共用 SP 一併移除
DROP PROCEDURE IF EXISTS SP_Backfill_Mirror_Step;
```

退場表（`fumasw_bet_records_20260903`）先留著觀察，穩定後在 Olympus「資料表結構維運」選「刪除退場表」（或 `schema_operation --action=drop_retired --table=fumasw_bet_records_20260903`）刪除；該動作只接受 `{原表}_YYYYMMDD` 且原表仍存在的表，仍有 trigger／外鍵掛著時會擋下。

⚠️ 單位一〜三原本抓 90 天下限（`min_id > 0`），照那樣跑完**不能**換表轉正。要轉正就把 `min_id` 設 0 全量回填——代價是鏡像表等於整張原表的大小（yes2win fumasw 約再吃 60GB 磁碟），先確認空間再決定走哪種結局。

## 完成後：對帳查詢怎麼用（部分回填、留鏡像表時）

查 `{table}_mirror`，欄位與原表一樣，差別只有 `payout_time` 有索引：

```sql
SELECT COUNT(*), SUM(bet), SUM(win_lose)
  FROM fumasw_bet_records_mirror
 WHERE payout_time >= '2026-08-01 00:00:00'
   AND payout_time <  '2026-09-01 00:00:00';
```

**`done = 1` 之前別拿鏡像表對帳**：回填沒跑完時鏡像表是不完整的，查到還沒覆蓋的區間會回傳一部分資料，看起來就跟「對帳有差異」一模一樣。進度看 `mirror_backfill_state`（`next_id` 往下走到 `min_id` 即完成）。

## 注意事項

- **`payout_time` 存台北時間（UTC+8），MySQL 的 `NOW()` 回 UTC**（實測 `NOW()` 是 07:05 時已有 `payout_time` 15:03 的資料）。逼近邊界 id 時看到的 `payout_time` 是台北時間；對帳查詢的區間邊界也要用台北時間寫死，別拿 `NOW()` 去框。
- **禁止在原表跑沒索引的整表查詢**（`COUNT(*)`、`GROUP BY 時間`、`MIN/MAX(payout_time)`）。看筆數用 `information_schema.TABLES.TABLE_ROWS`（估計值）；看時間端點用主鍵端點 `ORDER BY id ASC/DESC LIMIT 1`。唯一例外：驗證步驟的單日抽查。
- **回填的總讀取量跟直接加索引一樣多**，鏡像表贏在可中斷、可調速、失敗只損失一段。
- 後綴用 `_mirror` 不用 `_new`：專案已有 `bolakk4d_bet_records_new` 這類人工副本表，`_mirror` 不撞名，也標明它是「trigger 持續同步的鏡像」而不是一次性副本。
- yes2win `fumasw_bet_records.id`（int unsigned）已到 1.13 億，上限 42.9 億，照目前速率約 9 年用完——不是現在的問題，但它是 INT 不是 BIGINT。

## 附錄：盤點依據

- 全站掃描與大小：`information_schema.TABLES` × `STATISTICS`（2026-09-03，經各白標 mysql MCP）。
- 資料起訖：yes2win 兩表 id=1 為 2025-09-01，tpower wudisw id=1 為 2026-01-09（主鍵端點查詢）。
- 對帳查詢型態：`app/Console/Commands/Vendor/PragmaticPlay/CompareBetRecordsPipeline.php:98`（`WHERE payout_time >= ? AND payout_time < ?`）。
- 原表結構：`database/migrations/2025_08_14_065356_create_table_wudisw_bet_records.php`、`2025_08_20_085621_create_table_fumasw_bet_records.php`、`2026_05_04_000000_add_is_free_bet_to_fumasw_bet_record_table.php`；欄位與線上 `information_schema.COLUMNS` 核對過。
- EVENT 不防重疊：MySQL 8.0 Reference Manual「Event Scheduler Overview」，官方建議以 `GET_LOCK()` 自行防重。
