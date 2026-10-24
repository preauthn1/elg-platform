---
description: 申請生產庫寫入提權（db-prod-admin）並安全地用完它——先驗證真的無替代路徑、掃代碼實證會異動哪些表、產出申請單、開通後以單次注入執行、用完即收。
argument-hint: "（描述要做的操作，例：收斂 banks 重複列 / 補跑 sync-mappings）"
disable-model-invocation: true
---

本次要做的操作：$ARGUMENTS

---

> 生產庫的寫入權限預設是關的，這是刻意設計不是障礙：`.env` 的 write 連線指向 dbc 從庫，`SELECT ... FOR UPDATE` 會被 `1142 SELECT with locking clause command denied` 擋下，且**錯誤發生在交易內任何 UPDATE 之前，整批零寫入**——擋下來是安全的，不會留半套資料。
>
> **強制紀律**：
> - **§1 沒走完不准填申請單**。權限申請是高風險授權，「方便一點」不是理由。
> - **§2 的資料表清單一律由代碼掃描得出**，禁止憑印象或憑記憶列——申請單是給審核者評估風險用的，漏一張表等於申請書失真。
> - **執行一律 dry-run → 人工確認 → execute**，且 execute 前必須拿到使用者的文字確認。
> - **用完立刻停掉主庫代理**，不要讓它整天掛著。

## §1 先證明沒有替代路徑

申請前必須排除這三條，並把結論寫進申請單的「原因」欄：

1. **既有 runbook 怎麼做的**——搜 `docs/` 下該功能線的 rollout runbook，看它假設的執行環境。本專案兩份 runbook 用的都是 `docker exec php-fpm8`（本機容器直連生產庫）。
2. **生產環境能不能跑 artisan**——有無進 pod 執行的既有流程文件、有無對應的 CI job。目前兩者皆無。
3. **能不能改走既有的已授權機制**——排程命令、既有的 `fix-*`／`patch-*` 命令是否已涵蓋。

三條都不通才申請。**只要有一條通，走那條**。

## §2 掃出實際會異動的資料表

不要只 grep `DB::table('x')->update`。寫入動詞常離表名十幾行，且有動態表名常數。逐步做：

```bash
# 1) 該功能線涉及的目錄下，所有 DB::table 表名與 Model import
grep -rhoE "DB::table\('[a-z_]+'" <目錄...> | sed "s/DB::table('//;s/'//" | sort | uniq -c | sort -rn
grep -rhoE "use App\\\\Models\\\\[A-Za-z]+" <目錄...> | sort -u

# 2) 所有寫入動詞的實際呼叫點（逐條判讀讀 vs 寫）
grep -rnE "\->(update|insert|insertGetId|insertOrIgnore|upsert|delete|forceDelete|save|increment|decrement)\(|::create\(|DB::statement|DB::unprepared|Schema::" <目錄...>

# 3) 動態表名：把 DB::table($table) 的 $table 追回它的常數定義
grep -nE "const [A-Z_]+ = \[" -A12 <檔案...>
```

**必查的三類盲區**（本專案實際踩過）：
- `DB::table($table)` 迴圈——表清單在 `SATELLITE_TABLES`／`FK_TABLES` 這類常數裡，grep 表名抓不到。
- **DDL**——`Schema::table()`、`DB::statement('ALTER TABLE ...')`、備份服務動態 `CREATE TABLE`。審核者最在意這類。
- **只 INSERT 的審計表**——要與業務表分開列，「只新增不修改既有列」對風險評估差很多。

清單分三組寫：**業務表（UPDATE/DELETE，逐張標明改哪些欄位）**、**審計表（INSERT only）**、**DDL**。

大表要特別標明「只改哪幾個欄位、不動什麼」。例：`withdrawal_orders` 141 萬列，但只改 `bank_id`／`bank_code`／`bank_name`，不動金額、狀態與時間——這句話能省掉一輪來回。

## §3 產出申請單

範本（送 SRE）：

```
【提權申請】加入 user@example.invalid

- 申請人：<公司 Google 帳號>
- 需要異動的實例：<見下方對照表，只列真正要動的>
- 原因：<做什麼 + §1 的三條排除結論>
- 預計操作內容：<§2 的三組清單 + 風險控制>
- 預計時間：<起訖日>，分批執行，每批跑完驗證再進行下一批

備註：加入群組後約 15 分鐘生效
```

**白標 → 實例 / 主庫 port / env 前綴**（以 `dbc list` 與 `config/database.php` 為準，此處為 2026-09-03 快照）：

| 白標 | Cloud SQL 實例 | 主庫 port | env 前綴 |
|---|---|---|---|
| TPower | `tp-wz3-tpower:asia-southeast1:xsgcasino-online` | 3507 | `TP_` |
| VWorld | `tp-wz3-online:asia-southeast1:xsgcasino-online` | 3505 | `VW_` |
| Yes2Win | `gff-yes2win:asia-southeast1:xsgcasino-online` | 3509 | `Y2W_` |
| Alfawin77 | `gff-alfawin77:asia-southeast1:xsgcasino-online` | 3511 | `AW77_` |
| Me77 | `gff-vwb2c:asia-southeast1:xsgcasino-online` | 3513 | `ME77_` |

⚠️ 兩個容易搞錯的點：`gff-vwb2c` 是 **Me77** 不是 VWorld，VWorld 是 `tp-wz3-online`；**VPlus33 不在 dbc**，就算拿到權限本機也連不上。

填單時的取捨：
- **只列 `xsgcasino-online`**，不要順手把 `chatrrom-online` 加進去。
- **範圍一次要足**——分批申請等於行政成本加倍，中間還會卡住工作。
- **期間給有界限的值**（例：4 週），不要申請永久。

## §4 開通後怎麼用

`.env` 的 write 維持指向從庫**不要動**。寫入權限靠單次注入明示解除——平常誤觸不了。

```bash
# 1) 補掛主庫代理。dbc start 預設只掛從庫，主庫 port 不在其中
dbc start --port <主庫 port>        # 背景執行，前景會佔住終端

# 2) 執行時注入主庫 port 與 emulate prepares
docker exec -e DB_CONNECTION=<白標> \
  -e <前綴>_DB_PORT_WRITE=<主庫 port> \
  -e DB_WRITE_EMULATE_PREPARES=true \
  php-fpm8 bash -c "cd /var/www/APIs && php artisan <command> --execute"

# 3) 跑完立刻停掉主庫代理
```

**兩個一定會踩的坑**：
- **`DB_WRITE_EMULATE_PREPARES=true` 不能省**——dbc 拒絕 server-side prepared statement（`COM_STMT_PREPARE`），不開這個寫入會失敗。
- **`dbc start --primary` 常會撞 port**——若已有 dbc 在跑（掛著從庫與無從庫的實例），`--primary` 會因 port 已佔用而整個啟動失敗。改用 `--port <單一 port>` 逐個補掛。

## §5 執行與驗證紀律

1. **dry-run 先跑**，人工核對報表：影響筆數、受影響的具體 id、gate 有沒有攔下不該動的。
2. **拿使用者的文字確認**才 execute。
3. **execute 後走獨立管道複驗**——用 mysql MCP（走從庫）查，不要只信命令自己的輸出。要驗的三件事：目標值已改、**不該動的欄位沒動**、審計筆數與內容齊全。
4. **失敗時先確認零寫入**再往下走。交易內拋錯會整批回滾，但要用查詢證實，不要用推論。

## §6 留紀錄（兩處都要，不是二選一）

權限是有期限的，到期前後都需要有據可查。

**1. 登記進 `docs/未完成計劃/RB/TODO.md`** 對應條目——申請日、核准日、到期日，以及 §4 的存取工法（主庫 port 與 env 前綴一併記下，省得下次重查）。

**2. 維護提權紀錄 artifact**——**歷次申請共用同一頁、同一個 URL**，不是每次申請開一頁。

已存在的頁面：https://claude.ai/code/artifact/1ed31dd6-f558-4a05-b553-2ba51d695706
（更新它要用 `action: "publish"` 帶 `url` 參數；先 `action: "read"` 讀回現況再改，別直接覆蓋。）

頁面結構分三層，職責不同：

| 層 | 內容 | 何時動 |
|---|---|---|
| **狀態列** | 目前狀態、本次到期日、**剩餘天數**、累計申請次數 | 每次新申請 |
| **當次申請區塊** | 申請存根（申請人／群組／提交日／核准情形／事由，事由含 §1 的排除結論）、授權範圍對照表、§2 的三組異動清單、使用紀錄 | 每次新申請開一個新區塊 |
| **歷次申請**（摺疊） | 已到期的申請區塊往這裡搬，狀態改「已到期」 | 續期時 |
| **存取工法** | §4 的三步驟與例外 | 跨申請共用，不隨申請次數變動 |

三條硬規定：

- **剩餘天數用 JS 從到期日現算**，過期時一併把狀態與當次區塊的徽章改成「已失效／已到期」。寫死天數的頁面隔天就是錯的。
- **使用紀錄每次動用權限後回頭補一筆**——日期、做了什麼、影響筆數、驗證結果。這是這頁的長期價值所在；一次寫完就不再更新的話，它只是張申請單副本。
- **絕不放帳號、密碼或任何憑證**。這頁記的是申請與使用事實。內容含內部實例名稱，artifact 預設私有，但分享前要提醒使用者。

**為什麼是一頁而不是每次一頁**：申請頻率是一年數次，單頁裝得下；而續期時最需要做的事是對照「上次說要動什麼 vs 實際動了什麼」，同一頁對照最直接。等到單頁真的長到難讀，再拆不遲。

到期續期時，這頁直接當附件，不必重寫申請單。
