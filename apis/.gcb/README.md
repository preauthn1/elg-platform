# CSR 災備 Pipeline

## 1. 用途

本目錄封裝一套 **Google Cloud Build + Cloud Source Repositories (CSR)** 災備流程，目的是：

- 當 on-prem **GitLab 停電**或網路中斷，使得主要 CI/CD（`.gitlab-ci.yml`）無法執行時，
- 開發者仍可將程式碼推送到 **GCP 端的 CSR mirror**，由 **Cloud Build** 執行品質檢驗與 image build，
- 確保緊急部署所需的 container image 能持續產出到 Artifact Registry。

GitLab 恢復後，此機制仍保留作為**長期災備**備援。

---

## 2. 架構圖

```
   ┌─────────────────────┐          ┌──────────────────────┐
   │  開發者本機 git repo │          │   on-prem GitLab     │
   │  (origin = gitlab)  │────push──▶│  (主要 CI/CD)        │
   │  (csr    = GCP CSR) │           └──────────────────────┘
   └──────────┬──────────┘                     │
              │                                │（停電時此路斷）
              │ .gcb/mirror.sh                 ▼
              │ (災備路徑)
              ▼
   ┌──────────────────────────────────────────────────────┐
   │           Google Cloud Source Repositories           │
   │                    apis-mirror                        │
   └───────────────────────┬──────────────────────────────┘
                           │ trigger
                           ▼
   ┌──────────────────────────────────────────────────────┐
   │                   Cloud Build                         │
   │  ┌────────┬─────────┬──────────┬────────┬─────────┐  │
   │  │ mysql  │  redis  │ composer │ ...    │ docker  │  │
   │  └────────┴─────────┴──────────┴────────┴─────────┘  │
   └───────────────────────┬──────────────────────────────┘
                           │ push image
                           ▼
   ┌──────────────────────────────────────────────────────┐
   │   Artifact Registry                                   │
   │   asia-east1-docker.pkg.dev/$PROJECT_ID/bucket2024/   │
   │     csr/apis-mirror:latest                            │
   │     csr/apis-mirror:<SHORT_SHA>                       │
   └──────────────────────────────────────────────────────┘
```

---

## 3. 初次設定

### 前置條件

- 已安裝 `gcloud` CLI。
- 已執行 `gcloud auth login` 取得登入 credential。
- 已執行 `gcloud config set project <PROJECT_ID>` 選定目標 GCP 專案。
- 該 GCP 專案已啟用：
  - Cloud Build API
  - Cloud Source Repositories API
  - Artifact Registry API

### 執行步驟

```bash
# 賦予執行權限（僅首次）
chmod +x .gcb/setup.sh .gcb/mirror.sh

# 執行初始化（冪等：可重複執行）
./.gcb/setup.sh
```

`setup.sh` 會自動完成：

1. 建立 CSR repo `apis-mirror`（若不存在）
2. 驗證 Artifact Registry `bucket2024` @ `asia-east1` 存在
3. 建立 2 個 Cloud Build triggers：
   - `apis-develop` — `develop` 分支觸發
   - `apis-release` — `release_<數字>` tag 觸發
4. 授予 Cloud Build default service account 所需 IAM：
   - `roles/artifactregistry.writer`
   - `roles/source.reader`

---

## 4. 停電日 SOP

### Step 1 — 確認 gcloud 狀態

```bash
gcloud config get-value project
gcloud auth list
```

### Step 2 — 推送到 CSR 觸發 build

```bash
# 推送當前分支（預設）
./.gcb/mirror.sh

# 推送指定分支
./.gcb/mirror.sh develop

# 推送指定 tag（正式部署用）
./.gcb/mirror.sh release_20260423

# 一次推送所有分支與 tag（首次 mirror 時使用）
./.gcb/mirror.sh --all
```

### Step 3 — 確認 Cloud Build 有跑

```bash
# 列出最近 5 個 build
gcloud builds list --limit=5

# 追蹤最新 build 的 log
BUILD_ID=$(gcloud builds list --limit=1 --format='value(id)')
gcloud builds log $BUILD_ID --stream
```

或至 GCP Console：
<https://example.invalid/cloud-build/builds>

### Step 4 — 確認 image 產出到 Artifact Registry

```bash
PROJECT_ID=$(gcloud config get-value project)
gcloud artifacts docker images list \
  asia-east1-docker.pkg.dev/${PROJECT_ID}/bucket2024/csr/apis-mirror
```

或至 GCP Console：
<https://example.invalid/artifacts>

### Step 5 — 下游部署

- **`develop` 或 `cicd/stage-build` 分支**：build 完成後自動觸發 project 526 的 `apis-deploy-dev` → `apis-deploy-dev2` / `apis-deploy-devwtt` 三個 trigger（dev 先跑 migration，dev2/devwtt 並行；無 approval）
- **`release_\d+` tag**：build 完成後並行觸發 7 個 prod trigger（6 GKE：`alfawin77` / `vworldmy` / `wintoto` / `vwb2c` / `yes2win` / `tpower` + on-prem：`vplus33`），全數於 526 側設 `--require-approval`，build 進入 **PENDING_APPROVAL**；各 prod owner 於 Cloud Build Console → Pending approvals 分別核准
- image 路徑：`asia-east1-docker.pkg.dev/<PROJECT_ID>/bucket2024/csr/apis-mirror:<SHORT_SHA>`

---

## 5. Trigger 與 Image 對應

| 推送目標 | Trigger 名稱 | 產出 image tag |
|---|---|---|
| Push 到 `develop` 分支 | `apis-develop` | `latest` + `<SHORT_SHA>` |
| Push 符合 `^release_\d+$` 的 tag | `apis-release` | `latest` + `<SHORT_SHA>` |

**完整 image 路徑：**

```
asia-east1-docker.pkg.dev/<PROJECT_ID>/bucket2024/csr/<REPO_NAME>:<tag>
```

其中 `<REPO_NAME>` 由 Cloud Build `$REPO_NAME` 變數自動填入（= CSR repo 名稱 `apis-mirror`）。

---

## 6. 與 GitLab CI 的差異

| 項目 | GitLab CI | Cloud Build 災備 |
|---|---|---|
| commit message lint | ✅ | ❌ |
| code quality lint | ✅ | ❌ |
| PSR-4 autoload check | ✅ | ❌ |
| Pint `--test` | ✅ | ✅ |
| PHPMD 圈複雜度 | ✅ | ✅ |
| `php artisan test` | ✅ | ✅ |
| migrate dump / 快取 | ✅（`init.sql` 快取） | ❌（每次 migrate:fresh） |
| composer vendor 快取 | ✅ | ❌ |
| Docker build & push | ✅ | ✅ |
| MR 驗證流程 | ✅ | ❌ |
| project 526 deploy trigger | ✅ | ❌（本次不包含） |

**災備策略取捨：**

- 捨棄 lint 三件套與 MR 驗證 → 降低設定複雜度，專注於「能產出可部署 image」。
- 捨棄 vendor 與 migrate dump 快取 → Cloud Build 每次 build 從零開始較簡單可靠，時間成本可接受。
- 保留 Pint / PHPMD / test → 品質底線不放棄，避免災備期間推送問題 image。

---

## 7. 災備常駐說明

即使 on-prem GitLab 恢復正常，本機制仍**長期保留**，原因：

1. **零切換成本** — 下次任何原因導致 GitLab 不可用時，開發者可立即執行 `.gcb/mirror.sh`，不需重新設定。
2. **雙寫保險** — `csr` 作為獨立 git remote，自然形成一份 off-site mirror，降低 on-prem repo 資料流失風險。
3. **IAM 與 trigger 零維護成本** — 建立後除非 GCP 專案被刪除，否則不需定期維護。
4. **獨立於 GitLab runner 網路** — 即使內網運維作業（更換 runner、升級網通設備）短暫影響 GitLab CI，Cloud Build 路徑不受影響。

---

## 8. 已知限制

1. **所有 10 個部署環境（3 dev + 7 prod）皆已覆蓋** — dev 類 3 個於階段 A 建立；6 GKE prod 於階段 C part 1 建立；vplus33 on-prem 於階段 C part 2a 以 kubeconfig file 路徑補齊。未覆蓋項僅剩 secret 輪替與退役 on-prem GitLab CI（階段 C part 2b）。
2. **無 migrate dump 快取** — 每次 build 會執行完整 `artisan migrate:fresh`，build 時間比 GitLab CI 快取路徑稍長。
3. **secret / token 未移植** — GitLab CI 中使用的 `GCR_RW_KEY_BASE64`、`PRIVATE_TOKEN`、`REPO_ENV_TOKEN` 等 secret 未複製到 Cloud Build 環境；本 pipeline 僅用 Cloud Build default SA 推送 image，不做跨專案 deploy。
4. **不處理 MR 驗證** — Cloud Build trigger 僅對指定分支 / tag 觸發，不處理 merge request 預先驗證流程。災備期間請開發者自行在本機把 feature branch merge 到 develop 後再推送。
5. **phpbase image 依賴 `tp-wz3-rd` 專案的 Artifact Registry** — 若目標 PROJECT_ID 與 phpbase image 所在專案不同，需確保 Cloud Build SA 對該 image 有 `roles/artifactregistry.reader` 權限（跨專案授權）。
