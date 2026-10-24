# apis 專案 Cloud Build 災備 — 交接 Context

> 本文件供 project 526（deploy pipeline）改造時參考使用。
>
> **階段進度**：
> - 階段 A（526 側建立 4 個 deploy trigger）— ✅ 已完成
> - 階段 B（apis 側自動串接 develop + release tag）— ✅ 已完工並通過實測
>   - develop → dev/dev2/devwtt：2026-04-23 驗證通過
>   - release_N tag → alfawin77 進入 PENDING_APPROVAL：2026-04-24 tag `release_202604241603` 驗證通過
> - 階段 C part 1（prod fan-out 擴展到 6 環境）— ✅ 526 側 9 個 trigger 已建立（2026-04-24），apis 側擴 5 個 fan-out step 完成，尚待 release tag 實測
> - 階段 C part 2a（vplus33 on-prem baremetal）— ✅ 526 側第 10 個 trigger 已建立（2026-04-24，走 kubeconfig file 而非 GKE），apis 側擴第 7 個 fan-out step 完成，尚待 release tag 實測
> - 階段 C part 2b（secret 輪替、退役 on-prem GitLab CI）— 待辦

---

## 1. 背景

on-prem GitLab 即將停電一天，且需要長期災備。已完成 apis 專案的 Cloud Build pipeline，**image 產出路徑已確定**，project 526 需據此改造成也能被 CSR trigger 觸發。

## 2. apis 側已完成的工作

### 交付檔案（皆在 `html/online/apis/`）

- `cloudbuild.yaml` — 11 step DAG：MySQL/Redis sidecar → composer install → migrate → test/pint/phpmd 併發 → docker build & push
- `.gcb/setup.sh` — 建立 CSR repo、2 個 trigger、IAM 授權
- `.gcb/mirror.sh` — 推送到 CSR 的輔助腳本
- `.gcb/README.md` — 完整文件

### CSR 設定

- CSR repo：`apis-mirror`
- SSH URL：`ssh://user@example.invalid:2022/p/tp-wz3-rd/r/apis`
- Git remote alias：`gcp`
- GCP Project：`tp-wz3-rd`
- AR Location：`asia-east1`

### Trigger（GCP 上實際命名）

| Trigger | 條件 | 觸發 |
|---|---|---|
| `apis-build-develop` | branch-pattern `^develop$` | develop push |
| `apis-build-release` | tag-pattern `^release_\d+$` | release tag |

> ⚠ 文件早期版本／setup.sh 字面曾寫 `apis-develop` / `apis-release`；GCP 上實際建立時加了 `apis-build-` 前綴以與 `apis-deploy-*` 系列區分。行為介面不受影響。

## 3. Image 產出（project 526 的輸入）

**完整路徑：**

```
asia-east1-docker.pkg.dev/tp-wz3-rd/bucket2024/csr/apis:<tag>
```

**Tag 規則：**

- `latest`（每次 build 覆蓋）
- `<SHORT_SHA>`（8 碼 commit SHA，不可變）

**命名差異：**

- GitLab CI image：`.../bucket2024/<project-path>`
- Cloud Build image：`.../bucket2024/csr/<repo-name>` ← 加了 `csr/` 前綴
- 這是刻意設計，讓 image 來源可辨識、清理方便

## 4. 踩過的雷（project 526 也要避開）

### 4.1 User-defined substitution 不展開 built-in 變數

- `_IMAGE: .../$PROJECT_ID/...` 不會自動展開
- 除非啟用 `options.dynamic_substitutions: true`（實測不穩）
- **建議**：直接在 step args 寫字面路徑，用 `$PROJECT_ID`、`$REPO_NAME`、`$SHORT_SHA` 頂層展開

### 4.2 MySQL 8.0 client 不認 `--ssl=0`

- 要改 `--ssl-mode=DISABLED`

### 4.3 MySQL 8.0 預設 `caching_sha2_password`

- 在無 SSL 連線下會要求 RSA 金鑰交換
- mysqld 啟動要加 `--default-authentication-plugin=mysql_native_password`

### 4.4 Cloud Build 所有 step 共用 `/workspace`

- 早期 step（composer install、artisan key:generate）會汙染 `bootstrap/cache/*.php`、vendor/ 等
- `docker build` 的 `COPY . .` 會把這些髒檔案帶進 image
- **修法**：`.dockerignore` 要排除 `bootstrap/cache/*.php`、vendor dev 內容等
- **與 GitLab CI 行為差異根源**：GitLab 每個 job 獨立 workspace，Cloud Build 不是

### 4.5 跨專案 AR image 拉取

- `cloudbuild.yaml` 用的 phpbase image 在 `tp-wz3-rd` 專案
- 若 Cloud Build 跑在同專案則免授權；跨專案需 `roles/artifactregistry.reader`

### 4.6 Cloud Build substitution 與 bash 語法衝突（階段 B debug 時踩到）

兩個連帶的地雷：

1. **Bash `${VAR:-}` default 語法失效**
   - Cloud Build 只認 `$VAR` / `${VAR}` 做 text 替換，不認 `${VAR:-}`
   - built-in 變數（`TAG_NAME` / `BRANCH_NAME` / `SHORT_SHA` / `REPO_NAME` / `COMMIT_SHA` / `REVISION_ID`）**不會以 ENV var 注入 step container**
   - 結果：step bash 內寫 `${TAG_NAME:-}` → Cloud Build 不替換 → bash 在 container 內找不到 env → 永遠空字串 → 判斷永遠錯
   - **正確寫法**：直接 `"$TAG_NAME"`。需要 default 行為用顯式 `if [ -z "$VAR" ]; then VAR=default; fi`

2. **`args` 的 `|` block 連註解都被掃 substitution**
   - 寫在 bash `#` 註解裡的 `$VAR` / `${FOO}` 示例文字，Cloud Build parser 會當成 substitution key 去驗
   - 若出現非合法 built-in 名稱會直接拒絕 build config：
     ```
     invalid value for 'build.substitutions':
     key in the template "VAR" is not a valid built-in substitution
     ```
   - **正確寫法**：註解內不要寫 `$` 前綴變數示例；要提及變數就純文字描述（例：「TAG_NAME 必須用 text substitution 形式」）

**影響範圍**：任何在 `args: - |` 區塊內的 bash script（不限 apis）都適用。526 側改 pipeline 時一併檢查。

## 5. project 526 改造範圍（建議）

### 5.1 必做

1. 在 project 526 repo 加 `cloudbuild.yaml`，內容類似：
   - Step 1：從 AR 拉取上游 image（`asia-east1-docker.pkg.dev/tp-wz3-rd/bucket2024/csr/apis:$_SRC_SHORT_SHA`）
   - Step 2：執行 deploy（原 project 526 GitLab CI 中 `kubectl` / `helm` / 或其他 trigger 邏輯）
2. 建立 CSR mirror（例如 `apis-deploy`）
3. 設定 trigger：push 到對應 branch/tag 時觸發
4. 若原本 deploy 流程依賴 GitLab API 接收上游參數（SRC_SHA、SRC_PROJECT、Message 等），改成由 Cloud Build substitutions 傳入

### 5.2 接收 upstream image 的介面（已落地）

apis 側於 `cloudbuild.yaml` 末段以 `gcloud builds triggers run` 呼叫 526 側 trigger，傳入：

```
--substitutions=_SRC_PROJECT=csr/apis,_SRC_SHORT_SHA=$SHORT_SHA
```

526 側 trigger 組合出完整 image 路徑：

```
asia-east1-docker.pkg.dev/tp-wz3-rd/bucket2024/${_SRC_PROJECT}:${_SRC_SHORT_SHA}
```

**apis 側自動串接規則（互斥條件）**：

| 觸發條件 | 串接 trigger | 行為 |
|---|---|---|
| `BRANCH_NAME` = `develop` 或 `cicd/stage-build` | `apis-deploy-dev` → `apis-deploy-dev2` / `apis-deploy-devwtt` | dev 先跑 migration，dev2/devwtt 並行；無 approval |
| `TAG_NAME` 符合 `^release_[0-9]+$` | 7 個 prod trigger 並行提交（6 GKE + vplus33 on-prem） | 全數進入 PENDING_APPROVAL，由各 prod owner 分別核准 |

**526 側建立的 trigger（命名對齊 CSR repo `apis-deploy`）**：

| Trigger | Cluster / Project | Migration | Approval |
|---|---|---|---|
| `apis-deploy-dev` | tp-wz3-rd / cluster-1 | ✅ | ❌ |
| `apis-deploy-dev2` | tp-wz3-rd / cluster-1 | ❌ | ❌ |
| `apis-deploy-devwtt` | tp-wz3-rd / cluster-1 | ❌ | ❌ |
| `apis-deploy-alfawin77` | gff-alfawin77 / cluster-alfawin77 | ✅ | ✅ |
| `apis-deploy-vworldmy` | tp-wz3-online / xsgcasino-cluster | ✅ | ✅ |
| `apis-deploy-wintoto` | tp-wz3-online / wintoto-cluster | ❌ | ✅ |
| `apis-deploy-vwb2c` | gff-vwb2c / cluster-vwb2c | ✅ | ✅ |
| `apis-deploy-yes2win` | gff-yes2win / cluster-yes2win | ✅ | ✅ |
| `apis-deploy-tpower` | tp-wz3-tpower / cluster-1 | ✅ | ✅ |
| `apis-deploy-vplus33` | on-prem baremetal（kubeconfig file） | ✅ | ✅ |

## 6. 首次全 fan-out smoke test 建議

階段 B alfawin77 單點路徑已驗過，但 7 個 prod step 同時提交（含 vplus33 on-prem 分支）尚未經 release tag 實測。首次全 fan-out 建議：

1. 用非產線編號 tag 例如 `release_999`（或時間戳格式 `release_<YYYYMMDDHHMM>`）推 gcp remote
2. `apis-build-release` build 完成後，至 Cloud Build Console → Pending approvals 確認**有 7 個** pending build
3. 核准順序由低風險到高風險：
   - **wintoto**（無 migration）→ 驗 kubeconfig / image pull 路徑
   - 其他 GKE prod（有 migration）→ 逐一核准
   - **vplus33** 最後 → 驗 on-prem kubeconfig file 路徑
4. 若 vplus33 approve 後卡在 `auth-cluster` step 報 `kubeconfig file not found`：表示 CSR 上 `deploy-keys/vplus33-production` 尚未同步，`git push -o nokeycheck` 推 main 補齊

## 7. 階段進度快照

| 階段 | 內容 | 狀態 |
|---|---|---|
| A | 526 側建 4 個初版 deploy trigger | ✅ 完成 |
| B | apis 側串接 develop + release_N tag→alfawin77 | ✅ 完成並實測 |
| C part 1 | 擴 526 側 5 個 GKE prod trigger + apis 側 5 個 fan-out step | ✅ 完成，待全 fan-out 實測 |
| C part 2a | vplus33 on-prem baremetal（kubeconfig file） | ✅ 完成，待實測 |
| C part 2b | secret 輪替（deploy-keys、helm 明文 secret → Secret Manager） | ⏳ 待辦 |
| C part 2b | 退役 on-prem GitLab CI | ⏳ 待辦（災備穩定後） |

## 8. 參考

apis 側完整設定與 SOP：見本 repo 的 `.gcb/README.md`
