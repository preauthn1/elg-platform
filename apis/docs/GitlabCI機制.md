# Gitlab CI 機制 & branch 限制

這裡僅記錄 Gitlab CI 跟相關 branch 限制，開發流程在別的地方。

## Branch 限制

1. `master` & `develop` branch 不能直接 push，須走 Merge Request
2. `master` 接受 `develop` / `feature/*` / `hotfix/*` / `task/*` 等分支的 MR（不再強制只能由 `develop` 合併）
3. `develop` 接受其他開發分支的 MR

## CI

### 簡介

* `.gitlab-ci.yml`：啟動檔，定義 `stages` 與 `include` 的子設定檔
* 子設定檔位於 `.gitlab-ci/` 下，按用途分目錄：`lint/`、`prepare/`、`build/`、`approval/`、`templates/`

### 什麼情況才會建立 pipeline（`workflow:rules`）

`.gitlab-ci.yml` 的 `workflow:rules` 只在三種情況建立 pipeline：

```yaml
- if: $CI_PIPELINE_SOURCE == "merge_request_event"                     # 開了 MR
- if: $CI_PIPELINE_SOURCE == "push" && ($CI_COMMIT_BRANCH == "master"
      || $CI_COMMIT_BRANCH == "develop" || $CI_COMMIT_BRANCH == "cicd/stage-build")
- if: $CI_PIPELINE_SOURCE == "push" && $CI_COMMIT_BRANCH
  when: never                                                           # 其餘分支一律不建立
```

⇒ **push 一般工作分支不會有 pipeline，要跑 CI 就必須開 MR**（或把變更合進 develop 再 push）。等不到 pipeline 是規則使然、不是延遲，不必重複輪詢。

⚠️ 舊分支若尚未合入含此規則的 master，push 仍會起 branch pipeline ⇒ **「這條分支以前 push 會有 pipeline」不能當作「現在也會有」的依據**。

### 流程

`.gitlab-ci.yml` 定義了 `lint` → `prepare` → `build` → `deploy` → `approval` 五個 stage。

#### `lint`

執行靜態檢查與規範驗證。下表的 jobs 位於 `.gitlab-ci/lint/`；另有兩支審批相關的 job 也跑在這個 stage，見下方「`approval`」。

| Job | 用途 |
|---|---|
| `lint:no-env` | 檢查 `env()` 禁用規則（禁止在 `config/` 外呼叫 `env()`） |
| `lint:no-debug` | 檢查殘留的 debug 輸出（`dd()`、`dump()` 等） |
| `lint:strict-types` | 檢查 PHP 檔案首行是否宣告 `declare(strict_types=1);` |
| `lint:no-bom` | 檢查檔案是否混入 UTF-8 BOM |
| `lint:no-hardcode` | 檢查新增行的 hardcode 樣態 |
| `lint:no-bc-functions` | 禁止直接呼叫 `bcadd`、`bcmul` 等 bc 系列函式，金額運算一律走 `Common::num*`。唯一放行的檔案是 `app/Helpers/Traits/MathTrait.php`（`Common::num*` 的實作本體） |
| `lint:commit-message` | 對 MR 內所有 commit 重新驗證 commit message 格式 |

#### `prepare`

這個 stage 有四支 job：

| Job | 做什麼 |
|---|---|
| `calculate_migration_hash` | 對 `database/migrations` 與 `database/procedures` 底下所有檔案算一個雜湊值（`MIGRATION_HASH`），交給 `test` 當 `init.sql` 快取的 key。獨立成一支 job，是因為快取 key 必須在其他 job 開始前先算好 |
| `prepare` | 依賴準備與靜態檢查：`composer install`（或沿用快取）、`artisan key:generate`，再同時跑 PSR-4 檢查（`composer dump-autoload`）、`composer pint:test` 與 PHPMD 圈複雜度檢查。這支 job 不碰資料庫 |
| `test` | 建立測試資料庫，並以 `php artisan test --parallel` 同時開 4 個 process 跑全部測試 |
| `build` | 建置 Docker image 並推送至 Artifact Registry（定義在 `.gitlab-ci/build/build.yml`）。develop 分支與 `release_<數字>` tag 的 pipeline 自動跑；`cicd/stage-build` 分支要手動按 |

`prepare` 與 `test` 彼此沒有依賴，會同時跑，所以 pint 或 PHPMD 失敗不會擋住測試結果。

MR 的目標分支是 master 時，`calculate_migration_hash`、`prepare`、`test` 會先把來源分支 `git merge --no-ff` 進 master 再跑，測的是合併後的內容。master 分支本身的 push 不跑這三支：MR 已經測過合併後的內容，合併後的把關改由 release tag 的 pipeline 負責。

兩份快取：

1. `vendor/`：cache key 為 `composer.lock` 的檔案內容。job 另外比對 `composer.lock` 的 md5 與 `vendor/.composer_lock_hash`，不一致就重跑 `composer install`。這是為了防止 MR 本地合併後 `composer.lock` 變了，卻命中合併前的舊快取。
2. `init.sql`：cache key 為 `MIGRATION_HASH`。`test` 是唯一產出者：
   - 快取裡沒有 `init.sql`，或 migration、stored procedure、自增起點設定有變時，跑 `migrate:fresh` 與 `db:seed --class=SyncProcedureSeeder`，把所有表的 AUTO_INCREMENT（自增 id）起點抬到 1000，再用 `mysqldump` 存成 `init.sql`。
   - 其餘情況沿用快取裡的 `init.sql`。
   - 之後預建 4 個測試庫（`<資料庫名>_test_1` 到 `_test_4`），逐一灌入 `init.sql`。
   - 自增起點抬到 1000，是因為程式碼裡有用 id 大小表達意思的門檻（例如 `cash_flows.id <= 62` 視為舊系統）。並行後每個測試庫只跑約四分之一的測試，id 從 1 起算就會踩進門檻，造成「一個一個跑會過、並行跑不會過」的假失敗。

#### `build`

這個 stage 目前沒有 job。建置 image 的 `build` job 掛在 `prepare` stage，見上一節。

#### `deploy`

所有部署 job 只負責觸發下游部署專案 `sre/gff-cicd/gffv3`（project 526）的 pipeline 並等它跑完，定義在 `.gitlab-ci/build/build.yml`：

| Job | 何時跑 | 等哪些 job 成功 |
|---|---|---|
| `release-dev (latest)` | develop 分支，自動 | `test`、`prepare`、`build` |
| `release-dev (specify)`、`release-dev (測試 cicd develop 部署腳本)` | develop 或 `cicd/stage-build` 分支，手動；失敗不影響 pipeline 結果 | `test`、`prepare`、`build` |
| `release-prod` | `release_<數字>` tag，自動 | `build`、`test`、`prepare` |

#### `approval`

MR 審批制度：合進 master 的 MR 動到指定路徑時，要由作者以外的審核人核准。判定規則寫在 `.gitlab-ci/approval/lib/approval.php` 開頭，審核人名單在 `.gitlab/approval-rules.json`，job 定義在 `.gitlab-ci/approval/approval.yml`。

| Job | stage | 做什麼 |
|---|---|---|
| `需要審核人核准` | `approval` | 只在「MR 目標是 master，且動到審核路徑」時出現。審核路徑包含金流、遊戲對接、活動系統、會員取款與風控、Olympus 後台、migration、stored procedure 與 CI 設定，確切清單以 `approval.yml` 為準。不等測試跑完，審核人核准後就能按 ▶ |
| `核准名單檢查` | `lint` | MR 改到名單或檢查程式時才跑：跑判定測試，並確認名單與 `approval.yml` 的路徑清單一致 |
| `合併後檢查核准` | `lint` | master 收到 push 後回頭確認合併前有沒有有效核准，沒有就發 Slack。這支失敗不擋 pipeline |

生效模式由專案 CI 變數 `APPROVAL_ENFORCE` 決定：值是 `"true"` 時為正式模式，`需要審核人核准` 是手動 job，沒通過不能合併；其他值或沒設時為試行模式，自動跑、只顯示警告，合併後檢查也不發 Slack。

---

## 已知的非代碼失敗 pattern

看到「本地全綠、CI 卻紅」時，先比對下列已知樣態，不要一開始就回頭 debug 代碼。

### 1. `build` job 推 image 到 Artifact Registry 授權逾時（週期性復發）

`prepare` stage 的 `build` job 以 `script_failure` 掛掉，log 尾巴是：

```
#17 pushing layers 60.3s done
#17 ERROR: failed to push .../target/gffv3/apis:latest: failed to authorize: DeadlineExceeded: context deadline exceeded
ERROR: failed to solve: DeadlineExceeded: context deadline exceeded
```

判定要點：**image 本身 build 成功**（`exporting layers ... done`），只卡在推 registry 的授權，同 pipeline 的 lint 與 `test` job 全 success。

🔴 **這是間歇性逾時，retry 可能要跑到第三次才過**——曾實測連兩次 retry 都卡在逐字相同的位置、第三次才成功（且只花 54 秒，因為 layer 多已存在）。**連兩次失敗不構成找 SRE 的理由。** build 綠之後，原本被 skip 的 `release-dev (latest)` 會自動重跑。

判斷「是不是自己這條的問題」：查同分支上一支 pipeline 的 `build` job，若它 success 且**用同一台 runner**（比對 `runner.id`），就證明問題出在那之後的區間、與本次變更無關。

### 2. 下游 Helm 部署衝突（與代碼無關，MR 不必因此卡關）

APIs 的 `deploy` stage 只是**觸發器＋輪詢包裝**，真正的部署在下游 `sre/gff-cicd/gffv3`（project 526）。兩種常見樣態：

- `deploy to dev2 (DEV) split service` 回 `Error: UPGRADE FAILED: release: already exists` ＝ dev2 namespace 的 Helm release 殘留衝突。同一個 image 在 dev / devwtt 通常成功 ⇒ **lint 全綠 ＋ dev 部署成功即代表代碼沒問題**。
- `release-dev (latest)` 掛掉、log 幾乎全是 `Waiting for pipeline to finish... running`，下游實際錯誤是 `post-upgrade hooks failed: timed out waiting for the condition` ＝ **同一個 SHA 短時間內有多條 pipeline 互踩同一個 Helm release**。處置是找同 SHA 或其超集的綠燈 pipeline 當依據，不要重跑自己的。

### 3. 假性的大量測試失敗

典型訊息是 `Add [<column>] to fillable property to allow mass assignment`，即使該欄位真的存在、Model 也用 `$guarded`。成因在 CI 配置層（cache 陳舊導致 schema 缺欄），**不該動分支代碼**。

## 查 pipeline 狀態

- **pipeline 層的 status 會落後於實況**：曾實測 pipeline 連兩次回 `running`、`updated_at` 凍住，但同一時間 job 層級查詢回傳的每個 job 都已是 `success`。**卡在 running 疑似不動時，改查 job 列表**，別誤判成 CI 掛住。
- **retry 之後要用 sha 查，不要用 ref 查**：`ref` 只回該分支最新那條，別人 push 之後就會抓到不是自己的那條。
- **`release-dev (latest)` success 即代表 Dev 環境已實際部署**。同 stage 的 `release-dev (specify)` 與 `release-dev (測試 cicd develop 部署腳本)` 是 `manual` + `allow_failure: true`，沒跑不影響 pipeline 綠、也不代表沒部署。
- APIs 的 develop pipeline 實測約 5–8 分鐘。
