# phpbase/7.4 驗證紀錄

資料截至 2026-10-23。記錄 `phpbase/7.4/Dockerfile` 草稿的審查與本機驗證過程，供交接與團隊審查使用。

## 0. 背景

PHP 7.3 面對 CVE-2026-109618（OOM 記憶體損毀）與 CVE-2026-109619（過期全域狀態），官方沒有 7.3 的修補。決定改用 7.4，並從 PHP 官方的回移分支 `PHP-7.4-CVE-2026-109618-109619` 自行編譯，取代官方已不再更新的 `php:7.4-fpm` 映像檔。

本文件只記錄**驗證過程與結果**，不含漏洞利用測試——記憶體損毀類 RCE 的利用需要精心構造的堆積佈局，重現 exploit 的風險超過它對判斷的價值，因此刻意不做。

## 1. 漏洞真實性查核

第一次用「CVE 編號 + IOC」組合查詢，以及直接查兩個 GHSA ID 的精確字串，WebSearch 都查無結果。改用更具體的詞組（文章標題、網域）後找到多個獨立來源：

| 來源 | 內容 |
|---|---|
| Google Cloud 官方部落格（2026-10-21） | 確認兩個 CVE、官方修補版本號、Google Cloud 自身不受影響但客戶的 PHP 會受影響 |
| Wiz Research | 列為發現者之一 |
| Help Net Security、The Stack、Cybersecurity Dive、Windows Report | 多家媒體在 2026-10-21 前後報導 |
| Wikipedia「PHP2Shell」條目 | 存在，但列出的部分發現者（Calif、OpenAI）無其他來源佐證，可信度低 |

**仍查無結果：** 兩個 GHSA 頁面本身（GitHub Advisory 不易被一般搜尋引擎索引，不代表頁面不存在，建議直接開瀏覽器確認）；任何公開的 Sigma/YARA IOC（與 Huntress「還在準備」的說法一致）。

**教訓：** 查近期事件要用有名稱的報導標題或網域去搜，不要只丟 ID 組合，第一輪的「查無結果」是搜尋策略問題，不是漏洞不存在。

**一手證據：** 直接 clone `php/php-src` 官方 repo，確認分支 `PHP-7.4-CVE-2026-109618-109619` 存在，commit `712e60f9edfd03f0960fc4fc3c2c375f42b90aa2`，作者為真實具名的 PHP 核心開發者，commit message 明確寫出兩個 GHSA ID 與 CVE 編號。

## 2. 官方回移分支的程式碼審查

比對基準：`php-7.4.33` 官方 tag。

- 版本字串顯示 `7.4.31-dev`，但內容**包含** 7.4.33 的既有修補（`ext/gd`、`ext/phar`、`KeccakSponge` 與 7.4.33 相同），編譯後版本號為 `7.4.34-dev`。
- 相對 7.4.33 共 20 個檔案變更，逐檔讀過，未見惡意程式碼。
- **核心修改：** `memory_limit` 超過時改為直接 `_exit(1)`（原本是 `zend_bailout` 走正常錯誤處理與 shutdown）。
- `session.upload_progress` 在程式碼層強制關閉（`rfc1867.c`：`!PS(rfc1867_enabled) || true`）。
- `main.c` 新增 INI `max_multipart_body_parts`，預設 `-1` 等同 `max_input_vars + max_file_uploads`。
- `rfc1867.c` 的 multipart 標頭解析只保留 `Content-Disposition`、`Content-Type` 並忽略重複標頭。
- `SAPI.c`、`zend_execute_API.c`、`main.c` 多處補上請求結束後的指標歸零，對應 CVE-2026-109619 的過期狀態問題。

## 3. Dockerfile 草稿與官方 Dockerfile 的差異

草稿以 docker-library/php 最後一版 `7.4/bullseye/fpm/Dockerfile`（commit `1361ba95`）為底：

- `FROM debian:11-slim`（= `bullseye-slim`），OS 已 EOL，apt 來源改指向 `example.invalid`，往後不會再有 OS 安全更新。PHP 7.4 不支援 OpenSSL 3，無法改用 bookworm。
- 原始碼來源固定在上述 commit 的 GitHub archive，用自行下載計算的 SHA256 驗證（`c85c8cae…47`）；commit archive 沒有簽章，拿掉原本的 GPG 驗證步驟。
- 新增 `bison`、`libtool`，並在 `./configure` 前執行 `./buildconf --force`，因為 git 原始碼沒有預先產生的 `configure`。
- `./configure` 參數**與官方一致**（見下）。
- 應用層沿用 `phpbase/7.3/Dockerfile`：`gd` 參數改為 7.4 的寫法（`--with-jpeg`/`--with-webp`/`--with-freetype`，`zip` 不帶 `--with-libzip`）；`php.ini` 改用 production 版本取代 7.3 的 development 版本；Tini 改用 apt 套件取代未驗證的 GitHub binary。
- 新增 `phpbase.ini`：關閉 `session.upload_progress.enabled`、限制請求大小與結構（`max_input_vars`、`max_file_uploads`、`max_input_nesting_level`、`max_multipart_body_parts`）、`expose_php=Off`。

## 4. 本機驗證結果

全部在本機 Docker 完成，未部署到叢集。對照組為官方 `php:7.4.33-fpm`（linux/amd64）。

### 4.1 編譯一致性

| 項目 | 結果 |
|---|---|
| `./configure` 完整參數 | 與官方逐項相同 |
| `php -m` | 草稿多 `bcmath`、`gd`、`pdo_mysql`、`zip`（7.3 應用層需要的擴充），其餘模組相同 |
| `php -n -i`（不載入 ini，純編譯期預設值） | 除版本號、Dockerfile 的 ENV（來源 URL/SHA256/commit）、`PHPIZE_DEPS` 外，只多一項 `max_multipart_body_parts => -1`，為修補編入的證據 |

### 4.2 `gd` 功能

`gd_info()` 顯示 FreeType、JPEG、PNG、WebP、GIF、BMP 均為 `true`；實際呼叫 `imagejpeg`、`imagewebp`、`imagepng`、`imagettftext` 均成功產生輸出。`XPM`、JIS 字型為 `false`，與 7.3 時相同（未裝 `libxpm`），非本次改動造成。

### 4.3 CLI 下的 OOM 行為

`php -d memory_limit=16M` 分配 64MB：

| | 官方 7.4.33 | 草稿 |
|---|---|---|
| shutdown handler | 有執行 | **沒有執行** |
| 結束碼 | 255 | **1** |

符合審查結論：超過 `memory_limit` 直接 `_exit(1)`，略過 shutdown。**依賴 shutdown function 或解構子做清理（釋放鎖、寫日誌）的應用，在 OOM 請求上這些動作不會執行。**

### 4.4 FPM 下的 OOM 行為

本機起 nginx + FPM，`memory_limit=16M`，請求分配 64MB：

| | 官方 7.4.33 | 草稿 |
|---|---|---|
| 使用者收到的回應 | HTTP 200，頁面夾雜 Fatal error 文字 | **HTTP 502** |
| worker | 存活，PID 不變 | 被殺掉，log 留下 `child <N> exited with code 1 after … seconds from start`，master 隨即啟動新 worker |
| OOM 之後的下一個請求 | 200（同一 worker） | 200（新 worker） |

服務不中斷，但使用者會看到 502 而非應用層的錯誤頁。**監控要看 worker 異常退出與結束碼，不能依賴 PHP error_log**（修補版 OOM 時不會寫 error_log，FPM 須設 `catch_workers_output = yes`，草稿已沿用官方的 `docker.conf` 設定）。

### 4.5 Multipart part 數限制

送出不同 part 數的 multipart 請求（`max_input_vars=1000`）：

| part 數 | 官方 `$_POST` 數 | 草稿 `$_POST` 數 | 草稿 log |
|---|---|---|---|
| 900 | 900 | 900 | 無 |
| 1100 | 1000（被 `max_input_vars` 截斷） | 1000 | `Multipart body parts limit exceeded 1020` |
| 3000 | 1000 | 1000 | 同上 |

兩者最終 `$_POST` 數相同，差異在草稿**於第 1020 個 part 就停止解析**並記警告，縮短了攻擊者用大量 part 製造堆積佈局的路徑。

### 4.6 偵測訊號測試（非漏洞利用）

- 連續觸發 FPM OOM，確認 log 固定輸出 `child <N> exited with code 1 after … seconds from start`，可直接作為告警規則的比對字串。
- 建立 `ps aux` 基線：目前所有子行程均為乾淨的 `php-fpm: pool www`，供之後「PHP 生出 shell」類規則（Falco/Huntress 建議）比對用。
- 不收上傳的測試路徑送 multipart 會被正常處理並回 200；若你的入口層擋了這類路徑的 `multipart/form-data`，403 本身即為告警訊號。
- 異常長 `PHPSESSID` cookie 測試未觀察到異常 `Set-Cookie`，屬陰性結果，不代表該訊號不存在，只代表這次最小測試沒有踩到。

## 5. 來源乾淨度與修補完整性深度查核（2026-10-23）

因應「確保代碼來源乾淨且能解決 php2shell 漏洞」的要求，針對官方回移分支做了比第 2 節更深入的查核。

### 5.1 供應鏈乾淨度

| 查核項目 | 結果 |
|---|---|
| commit 是否可從 `php/php-src` 官方 remote 直接到達 | **是**。重新查詢官方 remote，`refs/heads/PHP-7.4-CVE-2026-109618-109619` 目前的 SHA 與本地 clone 下來的 `712e60f9edfd03f0960fc4fc3c2c375f42b90aa2` 完全一致，沒有被 force-push 置換 |
| commit 簽章 | 有 GPG 簽章（key `259299AD4C7E1F40DE1302FF115CEA7A713E12E9`），但 keyserver 查不到對應公鑰，**無法驗證簽章真偽**。建議另外透過 php-internals 郵件群組或 example.invalid 公告確認 |
| 分支是否混入其他可疑 commit | **沒有**。深化 clone 後確認：修補 commit 的 parent 正好是官方「Bump versions」版本號調整 commit（`004cb827`，只改 `NEWS`/`configure.ac`/`php_version.h`），該 commit 的 parent 正好是 `php-7.4.33` release tag。鏈結乾淨 |
| 作者身份 | Arnaud Le Blanc、Ilija Tovilo，具名真實姓名 |
| Dockerfile 釘住的 SHA256 | 重新下載 tarball 並重算，與 Dockerfile 記錄值一致（`c85c8cae…47`） |

**結論：來源可信度高，但 commit 簽章無法獨立驗證，這點留給你自行用官方管道再確認。**

### 5.2 修補是否真的解決漏洞：讀完整函式，不只看 diff 片段

**CVE-2026-109618（OOM）：** `memory_limit` 超過時改走 `_exit(1)`，略過 `zend_bailout` 的正常清理路徑，消除了「清理程序把無效資料當有效」的根本機制；`rfc1867.c` 的 `substring_conf` 由 `int` 改為 `size_t`，是典型整數溢位修補寫法。邏輯審查與第 4.3–4.4 節的本機實測（OOM 行為、FPM 下 502 與 worker 重啟）一致。**判定：已解決。**

**CVE-2026-109619（過期全域狀態）：** 完整讀出 `php_session_rfc1867_callback()` 全函式（`ext/session/session.c`），確認 `if (!PS(rfc1867_enabled) || true) { return retval; }` 的位置在整個 `switch(event)` 區塊**之前**。真正危險的操作——`php_rinit_session(0)`（請求中途重新初始化 session）、`progress->sid` 操作——全部在 `switch` 裡面，執行不到。**session upload progress 這條官方公告列為「Confirmed」的消費者路徑，是結構性堵死，不只是降低機率。判定：已解決。**

### 5.3 讀取官方 GHSA 公告原文（GHSA-6wmf-4rv5-p3rp）後的更新判斷

直接擷取 GitHub 上的公告原文（先前的 WebSearch 查無結果，是搜尋詞策略問題，見第 1 節；直接用 URL 讀取則成功）。公告關鍵內容：

- CVSS v4：`CVSS:4.0/AV:N/AC:H/AT:P/PR:N/UI:N/VC:H/VI:H/VA:H/SC:N/SI:N/SA:N`，總分 9.2（Critical），**Attack Complexity: High**。
- 公告明確列出只有兩個「**Confirmed**」（已驗證可做到 RCE）的案例：
  1. PHP 8.5 的 `fatal_error_backtraces`——與 7.4 無關，此設定不存在於 7.4。
  2. **Session upload progress 生命週期（OOM 觸發）**——mod_php 與 FPM 皆已驗證，對應第 5.2 節已確認堵死的路徑。
- **「早期 INI 警告」這整類，公告自己標註為「Conditional」**，不是已驗證的 RCE，是「依應用程式、extension、發布環境而定」的理論性路徑。公告唯一舉出的具體確認案例（`fatal_error_backtraces`）與 7.4 無關。
- **對 FPM 部署的關鍵原文：**「FPM pool values are applied when a worker starts, and `.user.ini` is applied after normal initialization.」也就是 `www.conf` 的 pool 設定、`php.ini`、`.user.ini` 都不算早期 consumer，因為不是每個請求都重新套用。這條路徑在 FPM 上**只有在前端（nginx/Apache）把 `PHP_VALUE` 或 `PHP_ADMIN_VALUE` 當成 FastCGI 參數、逐請求傳給 FPM 時才「conditionally exposed」**。
- 公告重申：**改 `error_reporting`、用 `@`、關掉 `display_errors`/`log_errors` 都不能防護這個漏洞**。
- 公告的立場：「Updating to a release containing the complete state reset patch is the only reliable mitigation.」——即官方自己也認為，完整修補只存在於主線（8.2.35+），7.4 這個回移分支是 commit message 自稱的「Minimal mitigation for EOL version」，不等於完整修補。

**判定（更正第 2 節原先「未處理，保守視為未解決」的結論）：**

| 消費者路徑 | 官方公告評等 | 本分支是否處理 | 對本專案（FPM）的暴露程度 |
|---|---|---|---|
| Session upload progress | Confirmed | 已處理（5.2 節驗證） | 已堵死 |
| 早期 INI 警告（通用類別） | Conditional，非已驗證案例 | 未處理 | 預設不暴露，僅當前端逐請求傳遞 `PHP_VALUE`/`PHP_ADMIN_VALUE` 給 FPM 時才成立；本專案目前的 `nginx.conf` 測試設定未傳遞此類參數，但未查核實際產線 ingress 設定 |

**結論：這個殘留落差機率低，但不是零，堵住它的成本也低，建議當作防禦縱深處理，不要因機率低而略過：**
1. 確認產線 ingress-nginx／GCLB 設定沒有把 `PHP_VALUE`/`PHP_ADMIN_VALUE` 當 FastCGI 參數逐請求傳遞給 FPM（**待查，見第 6 節**）。
2. 繼續遵守交接文件步驟 2 的「移除 `.htaccess`/`PHP_VALUE`，改到 `php.ini`/pool 設定」，公告本身也要求如此。
3. 若效能允許，`pm.max_requests=1` 可降低 producer/consumer 必須落在同一 worker 的成功率（公告原文也提到此點）。

## 6. 未驗證項目

- **實際漏洞利用**：刻意不做，原因見第 0 節。
- **你的應用在 PHP 7.4 上的相容性**：需要用實際應用程式碼與 canary Pod 測試。
- **CI 整合、推送、部署**：草稿目前只存在於本機，尚未進入 CI 或叢集。
- **`composer self-update 1.8.5` 在 7.4 上是否正常運作**：沿用 7.3 設定，未單獨測試。
- **公開 IOC（Sigma/YARA）**：搜尋查無，需等待發布。
- **產線 ingress-nginx／GCLB 設定是否把 `PHP_VALUE`/`PHP_ADMIN_VALUE` 當 FastCGI 參數逐請求傳給 FPM**：這是第 5.3 節判定「早期 INI 警告」路徑是否對本專案成立的關鍵，尚未查核。

## 7. 相關檔案

- `phpbase/7.4/Dockerfile`、`phpbase/7.4/phpbase.ini`、`phpbase/7.4/www.conf`
- `phpbase/7.4/docker-php-{source,entrypoint,ext-configure,ext-enable,ext-install}`（原封不動取自 docker-library/php commit `1361ba95`）
