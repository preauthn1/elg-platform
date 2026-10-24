---
name: whitelabel-write-access
description: 對白標正式站寫入前的權限準備：現查該站主庫 port、檢查自己有沒有寫入權限，沒有就擬提權申請，經使用者確認後發到 Slack。當使用者說「查 <站> 主庫 port」「我有沒有 <站> 的寫入權限」「幫我申請 <站> 提權」時載入，站名簡寫或拼錯也算；whitelabel-container-exec 要對正式站寫入時也會叫用。🚫 唯讀查資料走 whitelabel-db-query；跑寫入命令本身走 whitelabel-container-exec。
---

# 白標寫入權限：主庫 port、權限檢查、提權申請

一句話版本：**從 `dbc list` 現查該站主庫 port，確認本機有在監聽，用唯讀的 `SHOW GRANTS` 看自己有沒有寫入權限；沒有就擬一則提權申請，使用者回「發」才發到 Slack。**

| 名詞 | 意思 |
|---|---|
| 白標 | 一個正式站點，例如 TPower、VWorld。「白標」「站點」「站台」同義 |
| dbc | SRE 提供的本機命令列工具，用你的 gcloud 帳號（IAM 身分）把公司 Cloud SQL 正式庫掛到本機 `0.0.0.0:<port>`，免記密碼 |
| 主庫 | 可以寫入的資料庫實例，`dbc list` 裡 `ROLE` 欄是 `primary` 的列 |
| 從庫 | 只能讀的複本，`ROLE` 欄是 `replica` 的列。registry 的 `dbcPort` 多半指向從庫 |
| 提權 | 暫時把你的帳號加進有寫入權限的群組 `user@example.invalid`，要到 Slack 頻道 #0-cloud-sql提權申請 申請 |
| 前綴（PREFIX） | 該站在 APIs `config/database.php` 的環境變數前綴，用來組 `<PREFIX>_DB_PORT_WRITE`、`<PREFIX>_DB_HOST_WRITE`。前綴不含結尾底線，例如 TPower 是 `TP`，組出 `TP_DB_PORT_WRITE` |

## 全程守則

- **只跑唯讀命令**：`dbc list`、`nc -z`、從容器測 TCP 的 `/dev/tcp`、`gcloud config get account`、`SHOW GRANTS` 與 `SELECT @@...`。這支 skill 不對任何資料庫寫入。
- **`dbc start` 由使用者在自己的終端機跑**。它是前景程式、會把稽核記在使用者名下，Claude 只提示要跑哪一行。
- **主庫 port 每次現查**：主庫 port 因 IAM 帳號而異，不是白標常數。查到的值只用在當下這件工作，不寫進 registry、本檔、記憶或任何檔案，下次寫入重新查。
- **申請人 email 只出現在草稿裡**，不寫進任何檔案。
- **每次發 Slack 都要使用者當次回「發」**，不沿用前一次的同意。
- **先檢查權限，沒有才申請**：一定先做步驟 4 的權限檢查，判定沒有寫入權限才擬申請。帳號在申請前就可能已經有寫入權限，實測 Alfawin77 就是這樣；不要因為「還沒申請過」就直接擬申請。

## 步驟 1：確認是哪一個正式站

這步把使用者講的站名對到 registry 的條目，後面每一步都要用它的欄位。

1. 照 `whitelabel-container-exec` 的「讀取白標 registry（SSOT）」節讀 `../../registry/whitelabels.metadata.yaml`，用它的 `canonical`、`aliases` 比對站名（大小寫不敏感，拼錯先跟使用者確認）。
2. 記下該條目的 `canonical`、`connectionId`、`dbcPort`、`sharesConnection`、`env`。
3. `env` 不是 `production` 時停下，告訴使用者這支 skill 只處理正式站。registry 裡 `mysql`、`Dev` 這兩個條目的 `env` 是 `test`，屬於這種。
4. 對不到任何條目、或缺 `dbcPort` 時停下問，不猜。

完成條件：手上有一個 `env: production` 的條目和它的 `dbcPort`。

## 步驟 2：從 `dbc list` 找出主庫 port

這步找出該站「寫入用」的 port。registry 的 `dbcPort` 是讀取用的，多數站指向從庫，不能直接拿來寫。

執行：

```bash
dbc list
```

輸出依 GCP project 分組：每組先是一行 project 名稱（例如 `tp-wz3-tpower (asia-southeast1)`），底下每列是 `CONN_NAME  ROLE  PORT  備註`。

比對規則，依序套用：

1. 找出 `PORT` 等於該站 `dbcPort` 的那一列，記下它在哪一組。
2. 這一列的 `ROLE` 就是 `primary`：它本身就是主庫，主庫 port 等於 `dbcPort`。沒有從庫的站、裸機站屬於這種。
3. 這一列的 `ROLE` 是 `replica`：在**同一組**裡找 `ROLE` 是 `primary`、而且 `CONN_NAME` 最後一個 `:` 之後恰好是 `xsgcasino-online` 的列，它的 `PORT` 就是主庫 port。`chatrrom-online` 這類別的資料庫不算。
4. 找不到、或找到不只一列時停下，把那一組的原始列給使用者看，請對方指定，不猜。

共用連線的子站（`sharesConnection: true`，例如 WTT、VWSG 與 VWorld）`dbcPort` 相同，所以會對到同一個主庫。

例（以當下 `dbc list` 的輸出為準，這裡的數字只示範比對方式）：

| 站 | `dbcPort` 對到的列 | 主庫列 | 說明 |
|---|---|---|---|
| TPower | `tp-wz3-tpower:asia-southeast1:xsgcasino-online-replica  replica  3404` | `tp-wz3-tpower:asia-southeast1:xsgcasino-online  primary  3507` | 規則 3：同組找 `xsgcasino-online` |
| Alfawin77 | `gff-alfawin77:asia-southeast1:xsgcasino-online  primary  3511` | 同一列 | 規則 2：無從庫，本身就是主庫 |
| VPlus33 | `example.invalid:8443/baremetal-vplus33  primary  3701` | 同一列 | 規則 2：備註「裸機 MySQL，非 Cloud SQL」，列在 `tp-wz3-rd` 組下 |

完成條件：得到一個主庫 port，並能指出它是 `dbc list` 的哪一列。

## 步驟 3：確認主庫 port 在本機監聽

這步確認 `dbc start` 有把主庫掛起來。沒在監聽時後面的連線一定失敗，先停下比較省事。

```bash
nc -z -G 2 0.0.0.0 <主庫 port>; echo "exit=$?"
```

- `exit=0`：有在監聽，往下走。
- 其他值：停下，請使用者**另開終端機自己跑**下面其中一行，跑起來後回覆，再重做本步驟：
  - `dbc start --port <主庫 port>`：只掛這一個實例。
  - `dbc start --primary`：掛全部主庫。
- 說明給使用者：不帶參數的 `dbc start` 只掛三種：從庫、沒有從庫的站的主庫（例如 Alfawin77，`dbc list` 該列備註寫「無從庫，dbc start 預設會掛載」）、裸機站（例如 VPlus33）。有從庫的站（例如 TPower、VWorld），主庫預設不會掛，要用上面兩行之一另外掛。

完成條件：`nc` 回 `exit=0`。

這步只證明本機（host）有在監聽，不保證容器連得到。容器連不連得到，要到步驟 4 才看得出來。

## 步驟 4：檢查寫入權限（唯讀）

這步用主庫連線跑 `SHOW GRANTS`，看你的帳號能不能寫。

**走容器、不走 `dbc connect` 的理由**：寫入命令之後就是在 `php-fpm8` 容器裡用同一條 write 連線跑的，檢查它才代表「待會那條命令寫不寫得進去」。`dbc connect` 會自動用 gcloud 帳號開 mysql，但它用的帳號設定跟容器的 `.env` 不一定相同，查到的結果不能直接代表容器那條連線。

1. **查前綴**。在容器裡從 `config/database.php` 取該 connection 的 write port 環境變數名（worktree 工作期間把 `/var/www/APIs` 換成該 worktree 路徑）：

   ```bash
   docker exec php-fpm8 awk -v c="'<connectionId>' =>" \
     'index($0,c){f=1} f&&/_DB_PORT_WRITE/{match($0,/[A-Z0-9_]+_DB_PORT_WRITE/); print substr($0,RSTART,RLENGTH); exit}' \
     /var/www/APIs/config/database.php
   ```

   印出例如 `AW77_DB_PORT_WRITE`，前綴就是去掉 `_DB_PORT_WRITE` 的 `AW77`。沒印出東西時停下回報，不自己拼前綴。

2. **確認沒有設定快取**：照 `whitelabel-container-exec` 的「環境健檢」節確認 `bootstrap/cache/config.php` 不存在，否則環境變數注入無效。

3. **跑唯讀檢查**。`DB::connection()->getPdo()` 拿到的是 write 連線，也就是注入的主庫 port：

   ```bash
   docker exec -i -e DB_CONNECTION=<connectionId> -e <PREFIX>_DB_PORT_WRITE=<主庫 port> php-fpm8 \
     bash -c "cd /var/www/APIs && php artisan tinker" <<'PHP'
   $pdo = DB::connection()->getPdo();
   foreach ($pdo->query('SELECT @@read_only AS ro, @@super_read_only AS sro') as $r) { echo "read_only={$r['ro']} super_read_only={$r['sro']}\n"; }
   $roles = $pdo->query('SELECT CURRENT_ROLE()')->fetchColumn();
   echo "current_role={$roles}\n";
   $sql = $roles === 'NONE' ? 'SHOW GRANTS FOR CURRENT_USER()' : "SHOW GRANTS FOR CURRENT_USER() USING {$roles}";
   foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $g) { echo $g, "\n"; }
   PHP
   ```

   `CURRENT_ROLE()` 列出這條連線目前啟用的角色。有啟用角色時，`SHOW GRANTS … USING <角色>` 會把角色帶來的權限展開成一般的 `GRANT` 行，判讀只看展開後的結果。帳號被授與、但這條連線沒啟用的角色，不會出現在 `CURRENT_ROLE()`，它的權限也不算數。

   一次檢查多站時，逐站各跑一次上面的命令，每次把該站的 `connectionId`、前綴、主庫 port 直接寫進命令。不要把多站參數放進一個變數再用 `set -- $var` 拆開跑迴圈：Bash 工具跑在 zsh，zsh 不會依空白拆分變數，整串會被當成一個參數。

4. **判讀**，依序套用：

   | 看到什麼 | 結論 |
   |---|---|
   | `read_only=1` 或 `super_read_only=1` | 連到的是唯讀實例（從庫），主庫 port 不對。停下，回步驟 2 重查 |
   | `current_role` 不是 `NONE`，但輸出仍只有把角色授與帳號的行（形如 `` GRANT `<角色>`@`<網域>` TO `<帳號>`@`%` ``），沒有展開成實際權限 | 無法判定：原始輸出給使用者看，請對方判斷，不擅自申請 |
   | 有任何一行 `REVOKE` 對 `*.*` 或 `` `xsgcasino`.* `` 拿掉這次寫入需要的權限 | 沒有寫入權限 |
   | 對 `*.*` 或 `` `xsgcasino`.* `` 有 `ALL PRIVILEGES`，或含這次寫入需要的每一種權限 | 有寫入權限 |
   | 對 `*.*` 或 `` `xsgcasino`.* `` 只有 `SELECT` 這類讀取權限，或缺少這次寫入需要的任一種權限 | 沒有寫入權限 |

   不能只看到 `cloudsqlsuperuser` 這類角色名稱就判定有權限：角色可能被授與但這條連線沒啟用。一律以 `USING` 展開後的 `GRANT`、`REVOKE` 判讀。

   「這次寫入需要的權限」依要跑的命令決定：只改既有列要 `UPDATE`，會新增列要 `INSERT`，會刪列要 `DELETE`；說不出命令會做哪些動作時，三種都要有。判讀結果寫明對照的是哪幾種權限。

   對 `` `mysql` ``、`` `sys` `` 底下系統表的 `GRANT` 與 `REVOKE` 不列入判讀。MySQL 開啟部分撤銷（partial revokes）時，會用「全域 `GRANT` 加上針對某個資料庫的 `REVOKE`」來表示限制，所以 `REVOKE` 只要對象是 `*.*` 或 `` `xsgcasino`.* ``，就要列入判讀。

   實測例：Alfawin77 主庫回 `read_only=0 super_read_only=0`，看到兩行 `` GRANT ... ON *.* TO ... WITH GRANT OPTION ``，權限清單含 `INSERT`、`UPDATE`、`DELETE`。兩行 `REVOKE` 的對象都是 `` `mysql`.* `` 與 `` `sys`.* ``，不列入判讀。`current_role` 列出 2 個啟用中的角色（其中一個名稱以 `db-prod` 開頭），`USING` 展開後的結果同上。這對到「含這次寫入需要的每一種權限」那一列，判定有寫入權限。

   判讀表的兩個已知限制，兩者出錯時都只會報錯或多申請一次，不會誤判成有權限：

   - **`USING` 語法錯誤**：`CURRENT_ROLE()` 回傳的角色清單要帶反引號，才能直接接在 `USING` 後面，這由連線的 `sql_quote_show_create` 設定決定（實測 Alfawin77 是帶反引號的格式）。檢查命令出現 SQL 語法錯誤時，把錯誤原文給使用者，判定「無法判定」，不自己改寫角色清單重跑。
   - **表層級的授權**：展開後的輸出出現對 `` `xsgcasino`.`<表>` `` 這種單張表的 `GRANT` 或 `REVOKE` 時，判讀表只看資料庫層級，可能判錯。這時改判「無法判定」，把相關的行給使用者看，由使用者決定要不要申請。

5. **連不上或認證失敗**時，把錯誤訊息原文照錄給使用者，不推測原因。其中一種要另外處理：步驟 3 有在監聽，容器卻回 `SQLSTATE[HY000] [2002] Connection refused`。

   這種情況常見的原因是該站的寫入主機設定（APIs `.env` 的 `<PREFIX>_DB_HOST_WRITE`）沒有指向本機的 dbc。實測 VPlus33 就是這樣：它的寫入主機指向外部位址，不經本機 dbc，所以本機雖然在 3701 監聽，容器還是被拒；同方法連 Alfawin77（3511）可以連上。

   判斷方法，不必讀 `.env`：

   1. **從容器直接測 TCP**。`host.docker.internal` 是容器裡指向本機的主機名，這行看容器能不能連到本機的主庫 port：

      ```bash
      docker exec php-fpm8 bash -c 'timeout 3 bash -c ": </dev/tcp/host.docker.internal/<主庫 port>" && echo tcp=open || echo tcp=fail'
      ```

   2. **判讀**：
      - `tcp=open`：容器連得到本機的 dbc，被拒的是 `.env` 設定的那個寫入主機。可以斷定是寫入主機設定的問題。
      - `tcp=fail`：容器連不到本機這個 port。回步驟 3 確認 dbc，並把兩次輸出原文給使用者。

   3. **`tcp=open` 時的處理**：把判斷結果告訴使用者，請對方確認。使用者同意後，這次執行在命令裡多加 `-e <PREFIX>_DB_HOST_WRITE=host.docker.internal`，只覆蓋這一次執行，不改 `.env`。之後的權限檢查與寫入命令都要帶這個參數，重做本步驟。

   4. 覆蓋主機之後，連到的是哪一站更要確認。寫入前一樣要過 `whitelabel-container-exec`「生產白標預設連得上但寫不進去」節的站別資料指紋防呆，不能省。

   Claude 不讀 `.env`，也不在回覆或任何檔案寫出設定值。使用者沒確認原因前，回報時寫「未確認」。

結果：

- 有寫入權限：把「主庫 port、前綴、判讀依據的那一行 `GRANT`」交回給使用者或 `whitelabel-container-exec`；有用到寫入主機覆蓋時，連同 `-e <PREFIX>_DB_HOST_WRITE=host.docker.internal` 一起交回。交回時提醒：真正寫入前，要照 `whitelabel-container-exec` 的站別資料指紋防呆確認連到的是目標站。到此結束。
- 沒有寫入權限：往步驟 5。

## 步驟 5：擬提權申請草稿

這步產出要貼到 Slack 頻道 #0-cloud-sql提權申請（頻道 id `C0BUAG5QJAJ`）的申請，格式照該頻道固定格式。

1. **取申請人**：

   ```bash
   gcloud config get account
   ```

   結果只填進草稿，不寫進任何檔案。

2. **填欄位**：從當下的任務自動帶，使用者可以改。

   | 欄位 | 怎麼填 |
   |---|---|
   | 申請人 | 上一步取得的 IAM 帳號 email |
   | 需要異動的實例 | 站名小寫，例如 `tpower`、`alfawin77`、`vplus33`；多站用逗號分隔，例如 `alfawin77, vplus33`；全部正式站都要動時寫 `全站`。共用連線的子站（WTT、VWSG）實例是母站的，寫 `vworld` |
   | 原因 | 工單號加一句話，例如「target/tickets#1234 補發漏派彩」 |
   | 預計操作內容 | 要動的表，或要跑的 `UPDATE` 語句、artisan 命令 |
   | 預計時間 | 算法是「預估操作時數加 1，再向上取整到整數小時」，預設 `2 hr`（預估操作 1 小時）。多加的 1 小時是預留給核准後約 15 分鐘的生效時間。申請的最小單位是 1 小時，只能填整數小時，例如 `2 hr`、`3 hr` |

3. **把整則草稿給使用者看**，格式如下（範例值是假值）：

   ```
   【提權申請】加入 user@example.invalid
   - 申請人：user@example.invalid
   - 需要異動的實例：tpower
   - 原因：target/tickets#1234 補發漏派彩
   - 預計操作內容：summary_bet_records 補 SBR（php artisan xxx:fix-yyy）
   - 預計時間：2 hr
   ```

   草稿下方另附一行算法說明讓使用者核對，例如「預計時間：預估操作 1 小時，加 1 小時生效緩衝，申請 2 hr；要改請直接說」。這行只給使用者看，不放進要發出的訊息。

4. **裸機站（例如 VPlus33）**照同一格式申請，實例欄寫 `vplus33`，並在草稿下方提醒使用者：裸機站核准後是否生效未驗證，核准後要重跑權限檢查確認。

5. 請使用者回「發」才送出；要改內容就照改，改完再給一次全文。

## 步驟 6：使用者回「發」才發到 Slack

這步代發申請，並留下發送成功的證據。

1. 只有使用者在**這一次**草稿之後回「發」才送。草稿改過、或換了一站，要重新取得「發」。
2. 用 Slack MCP 的 `slack_send_message`：`channel_id` 填 `C0BUAG5QJAJ`，`message` 填使用者確認過的草稿全文，一字不改。
3. 發完從工具回傳讀出 `message_context.message_ts`（訊息時間戳）與 `message_link`（訊息連結），兩個都回報給使用者當證據。回傳裡沒有這兩個欄位時，明說「發送結果未驗證」。
4. 沒有 Slack MCP 可用時，把草稿全文交給使用者自己貼。

## 步驟 7：核准之後重跑權限檢查

這步確認提權真的生效，再交回寫入流程。

- 不輪詢 Slack。等使用者說「核准了」。
- 提權核准後大約 15 分鐘才生效。使用者說核准後，先提醒對方約 15 分鐘後再重跑權限檢查，並記下核准的時間。
- 對方說可以檢查了，重做步驟 3 與步驟 4。
- 仍然沒有寫入權限時，先確認距離核准是否已滿 15 分鐘：
  - 還沒滿：告訴使用者還要等多久再檢查，不判定申請失敗。
  - 已經滿了：把判讀結果給使用者，請對方到頻道追問；不再自動重發申請。

## 被 `whitelabel-container-exec` 叫用時

對方需要的是三樣東西：主庫 port、前綴、權限結果。用到寫入主機覆蓋時，連同那個 `-e` 參數一起交回。

- 有權限：交回這三樣，寫入命令照 `whitelabel-container-exec` 的「生產白標預設連得上但寫不進去」節組裝，該節的站別資料指紋防呆照做。
- 沒權限：走步驟 5 到步驟 7，核准並重驗通過後才交回。寫入工作在那之前暫停。

## 相關 skill

- `whitelabel-container-exec`：在白標容器裡跑 artisan／tinker，寫入命令的組裝與確認規則在那裡。
- `whitelabel-db-query`：唯讀 SQL 分析，走 mysql MCP 與從庫，不需要這支。
